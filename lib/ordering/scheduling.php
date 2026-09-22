<?php
declare(strict_types=1);

// Tela 14.4 — "Pedido agendado".
//
// "Faixa com vaga limitada pela capacidade real da cozinha, não pelo
// relógio. Cobrança só no início do preparo — agendar sem cobrar evita
// estorno em massa se a loja não abrir."

// Faixas de 30 minutos, como no mock (18:00–18:30, 19:00–19:30...).
const SLOT_MINUTES = 30;

// Quanto tempo antes da faixa o pedido aparece pra cozinha. É o preparo da
// própria loja (tela 11.2) mais uma folga de coleta -- antes disso, o pedido
// agendado não tem o que fazer na fila do KDS.
const SLOT_PICKUP_MARGIN_MINUTES = 10;

// "Você pode cancelar sem taxa até 1 h antes da faixa."
const SLOT_FREE_CANCEL_MINUTES = 60;

// Quantos dias pra frente a loja aceita agendamento (hoje + três, como no
// mock). É um limite do SERVIDOR, não só da tela: sem ele, uma loja aberta
// 24h aceitaria um pedido marcado para 2030 -- a tela nunca ofereceria, mas
// a requisição direta passaria.
const SLOT_HORIZON_DAYS = 4;

/**
 * As faixas de um dia para uma loja, com vaga e ocupação reais.
 *
 * As faixas nascem do horário declarado (business_hours + holiday_overrides,
 * migração 018), não de um relógio fixo: loja que fecha às 15h não oferece
 * faixa às 16h. A capacidade é a que a loja declarou (restaurants
 * .slot_capacity); zero significa que ela não aceita agendamento.
 */
function delivery_slots_for_day(PDO $pdo, array $restaurant, string $day): array
{
    $capacity = (int) $restaurant['slot_capacity'];
    if ($capacity <= 0) {
        return [];
    }

    // Feriado manda no dia inteiro; senão vale o horário do dia da semana.
    $holidayStmt = $pdo->prepare(
        'SELECT closed, opens, closes, last_order FROM holiday_overrides
          WHERE restaurant_id = :id AND day = :day'
    );
    $holidayStmt->execute(['id' => $restaurant['id'], 'day' => $day]);
    $holiday = $holidayStmt->fetch();

    $windows = [];
    if ($holiday !== false) {
        if ($holiday['closed'] === true) {
            return [];
        }
        $windows[] = ['opens' => $holiday['opens'], 'closes' => $holiday['last_order'] ?? $holiday['closes']];
    } else {
        $dow = (int) date('w', strtotime($day));
        $hoursStmt = $pdo->prepare(
            'SELECT opens, closes, last_order FROM business_hours
              WHERE restaurant_id = :id AND dow = :dow AND active
              ORDER BY opens'
        );
        $hoursStmt->execute(['id' => $restaurant['id'], 'dow' => $dow]);
        foreach ($hoursStmt->fetchAll() as $row) {
            $windows[] = ['opens' => $row['opens'], 'closes' => $row['last_order'] ?? $row['closes']];
        }
    }
    if ($windows === []) {
        return [];
    }

    $slots = [];
    foreach ($windows as $window) {
        $start = strtotime("{$day} {$window['opens']}");
        $end = strtotime("{$day} {$window['closes']}");
        // Turno que atravessa a meia-noite termina no dia seguinte.
        if ($end <= $start) {
            $end += 86400;
        }
        for ($t = $start; $t + SLOT_MINUTES * 60 <= $end; $t += SLOT_MINUTES * 60) {
            $slots[] = [
                'start' => date('c', $t),
                'end' => date('c', $t + SLOT_MINUTES * 60),
            ];
        }
    }

    if ($slots === []) {
        return [];
    }

    // Ocupação: a linha de delivery_slots existe a partir da primeira
    // reserva. Não existir é o mesmo que zero -- e é por isso que a linha só
    // nasce quando alguém reserva, em vez de a plataforma pré-criar milhares
    // de faixas vazias todo dia.
    $takenStmt = $pdo->prepare(
        'SELECT lower("window") AS starts_at, capacity, taken FROM delivery_slots
          WHERE restaurant_id = :id
            AND "window" && tstzrange(:from, :to)'
    );
    $takenStmt->execute([
        'id' => $restaurant['id'],
        'from' => $slots[0]['start'],
        'to' => $slots[count($slots) - 1]['end'],
    ]);
    $taken = [];
    foreach ($takenStmt->fetchAll() as $row) {
        $taken[date('c', strtotime((string) $row['starts_at']))] = [
            'capacity' => (int) $row['capacity'],
            'taken' => (int) $row['taken'],
        ];
    }

    $now = time();
    $out = [];
    foreach ($slots as $slot) {
        // Faixa que já começou não é escolha: a cozinha não volta no tempo.
        if (strtotime($slot['start']) <= $now) {
            continue;
        }
        $row = $taken[$slot['start']] ?? null;
        $slotCapacity = $row['capacity'] ?? $capacity;
        $slotTaken = $row['taken'] ?? 0;
        $out[] = [
            'start' => $slot['start'],
            'end' => $slot['end'],
            'capacity' => $slotCapacity,
            'taken' => $slotTaken,
            'free' => max(0, $slotCapacity - $slotTaken),
        ];
    }

    return $out;
}

/**
 * Reserva a vaga da faixa. Devolve o range pronto pra gravar em
 * `orders.scheduled_for`.
 *
 * Quem garante que não passa da capacidade é o CHECK (taken <= capacity) da
 * migração 004, não um `if` aqui: dois clientes apertando ao mesmo tempo na
 * última vaga é exatamente o caso em que o `if` perde.
 */
function reserve_slot(PDO $pdo, array $restaurant, string $start, string $end): string
{
    $range = '[' . $start . ',' . $end . ')';

    try {
        $pdo->prepare(
            'INSERT INTO delivery_slots (restaurant_id, "window", capacity, taken)
             VALUES (:id, tstzrange(:from, :to, \'[)\'), :capacity, 1)
             ON CONFLICT (restaurant_id, "window")
             DO UPDATE SET taken = delivery_slots.taken + 1'
        )->execute([
            'id' => $restaurant['id'],
            'from' => $start,
            'to' => $end,
            'capacity' => (int) $restaurant['slot_capacity'],
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'delivery_slots_taken_check')) {
            error_response(409, 'slot_full', 'Essa faixa acabou de esgotar — escolha outra.');
        }
        throw $e;
    }

    return $range;
}
