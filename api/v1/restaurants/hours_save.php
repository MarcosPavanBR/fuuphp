<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.4 — "Salvar horário", com o "APLICAR PARA" do lado (só terça,
// seg a sex, todos os dias).
//
// O escopo é do endpoint, não da tela: mandar sete requisições pra "todos os
// dias" deixaria a semana meio salva se a terceira falhasse.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$days = $body['days'] ?? null;
$shifts = $body['shifts'] ?? null;

// 14.4 — quantos pedidos agendados cabem numa faixa de 30 min. Só a loja
// sabe; zero (o padrão) significa "não aceito agendamento", não "cabe zero".
$slotCapacity = null;
if (array_key_exists('slot_capacity', $body)) {
    $slotCapacity = is_int_between($body['slot_capacity'], 0, 100) ? (int) $body['slot_capacity'] : -1;
    if ($slotCapacity < 0) {
        error_response(422, 'invalid_slot_capacity', 'Capacidade por faixa vai de 0 (sem agendamento) a 100.', fields: ['slot_capacity' => 'inválida']);
    }
}

if ($slotCapacity !== null && (!is_array($days) || $days === [])) {
    // Salvar só a capacidade é um caminho legítimo: a loja pode ligar o
    // agendamento sem mexer no horário.
    $pdo = db();
    $pdo->prepare('UPDATE restaurants SET slot_capacity = :c WHERE id = :id')
        ->execute(['c' => $slotCapacity, 'id' => $restaurantId]);
    json_response(200, ['slot_capacity' => $slotCapacity]);
}

if (!is_array($days) || $days === [] || !is_array($shifts)) {
    error_response(422, 'invalid_request', 'Informe days (0–6) e shifts.');
}
foreach ($days as $day) {
    if (!is_int($day) || $day < 0 || $day > 6) {
        error_response(422, 'invalid_day', 'Dia da semana vai de 0 (domingo) a 6.', fields: ['days' => 'inválido']);
    }
}

// Duas checagens que o banco não faz e que estragariam o dia de alguém:
// horário sem fim depois do começo é aceito de propósito (turno que vira a
// madrugada), mas último pedido DEPOIS do fechamento não é -- seria aceitar
// pedido com a cozinha apagada.
$clean = [];
foreach ($shifts as $shift) {
    $name = $shift['shift'] ?? null;
    if (!in_array($name, ['lunch', 'dinner'], true)) {
        error_response(422, 'invalid_shift', 'Turno é lunch ou dinner.', fields: ['shifts' => 'inválido']);
    }
    foreach (['opens', 'closes', 'last_order'] as $field) {
        if (!is_valid_time($shift[$field] ?? null)) {
            error_response(422, 'invalid_time', 'Horário no formato HH:MM.', fields: ["shifts.{$name}.{$field}" => 'inválido']);
        }
    }

    $opens = (string) $shift['opens'];
    $closes = (string) $shift['closes'];
    $lastOrder = (string) $shift['last_order'];
    $crossesMidnight = $closes < $opens;
    $lastOrderCrosses = $lastOrder < $opens;

    // Com turno que atravessa a meia-noite, "depois do fechamento" muda de
    // sentido: 00:30 é depois de 18:00 e antes de 01:00.
    $lastOrderTooLate = $crossesMidnight
        ? ($lastOrderCrosses && $lastOrder > $closes)
        : ($lastOrder > $closes || $lastOrder < $opens);
    if ($lastOrderTooLate) {
        error_response(
            422,
            'last_order_after_close',
            'O último pedido tem que caber dentro do turno.',
            fields: ["shifts.{$name}.last_order" => 'fora do turno']
        );
    }

    $clean[] = [
        'shift' => $name,
        'opens' => $opens,
        'closes' => $closes,
        'last_order' => $lastOrder,
        'active' => ($shift['active'] ?? true) === true,
    ];
}

$pdo = db();
$pdo->beginTransaction();
try {
    $upsert = $pdo->prepare(
        'INSERT INTO business_hours (restaurant_id, dow, shift, opens, closes, last_order, active)
         VALUES (:rid, :dow, :shift, :opens, :closes, :last_order, :active)
         ON CONFLICT (restaurant_id, dow, shift) DO UPDATE
           SET opens = EXCLUDED.opens, closes = EXCLUDED.closes,
               last_order = EXCLUDED.last_order, active = EXCLUDED.active'
    );
    foreach ($days as $day) {
        foreach ($clean as $shift) {
            $upsert->execute([
                'rid' => $restaurantId,
                'dow' => $day,
                'shift' => $shift['shift'],
                'opens' => $shift['opens'],
                'closes' => $shift['closes'],
                'last_order' => $shift['last_order'],
                'active' => $shift['active'] ? 'true' : 'false',
            ]);
        }
    }
    if ($slotCapacity !== null) {
        $pdo->prepare('UPDATE restaurants SET slot_capacity = :c WHERE id = :id')
            ->execute(['c' => $slotCapacity, 'id' => $restaurantId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// Salvar horário pode abrir ou fechar a loja agora mesmo -- esperar o
// próximo minuto do pg_cron faria a tela mostrar um estado que já mudou.
$pdo->query('SELECT apply_business_hours()');

$stmt = $pdo->prepare(
    'SELECT dow, shift, opens, closes, last_order, active
       FROM business_hours WHERE restaurant_id = :id ORDER BY dow, shift'
);
$stmt->execute(['id' => $restaurantId]);
$openStmt = $pdo->prepare('SELECT is_open, slot_capacity FROM restaurants WHERE id = :id');
$openStmt->execute(['id' => $restaurantId]);
$store = $openStmt->fetch();

json_response(200, [
    'hours' => $stmt->fetchAll(),
    'is_open' => $store['is_open'],
    'slot_capacity' => (int) $store['slot_capacity'],
]);
