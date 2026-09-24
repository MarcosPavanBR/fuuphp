<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.2 — "Pausa com volta automática e o custo estimado ao lado:
// decisão com número, não no escuro."
//
// O painel lateral do mock mostra quatro números (pedidos em andamento,
// quantos continuam, pedidos/hora perdidos, faturamento/hora perdido), o
// horário de hoje e a pausa registrada. Todos saem de pedido real desta
// loja -- "≈ 7 pedidos/hora" só vale como aviso se for a média DELA.

require_method('GET');
$claims = require_auth();
$restaurantId = require_store_staff($claims);

$pdo = db();

$storeStmt = $pdo->prepare(
    'SELECT name, is_open, pause_until, prep_minutes, prep_auto_bump, approved_at, rejected_at, rejection_reason
       FROM restaurants WHERE id = :id'
);
$storeStmt->execute(['id' => $restaurantId]);
$store = $storeStmt->fetch();
if ($store === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}

// "Pedidos em andamento" e "continuam normalmente" são o mesmo conjunto: o
// texto do mock diz que pausar não cancela nada, e a tela prova isso com o
// número em verde ao lado.
$inFlightStmt = $pdo->prepare(
    "SELECT count(*) FROM orders
      WHERE restaurant_id = :id AND status IN ('paid','preparing','ready','delivering')"
);
$inFlightStmt->execute(['id' => $restaurantId]);
$inFlight = (int) $inFlightStmt->fetchColumn();

// Custo da pausa: média por hora DESTA loja, nesta faixa de horário, nas
// últimas quatro semanas. Uma média do dia inteiro diria que pausar às 20h
// custa o mesmo que pausar às 15h, que é justamente a decisão errada.
$costStmt = $pdo->prepare(
    "SELECT COALESCE(count(*)::numeric / 4, 0) AS orders_per_hour,
            COALESCE(SUM(total) / 4, 0)        AS revenue_per_hour
       FROM orders
      WHERE restaurant_id = :id
        AND status NOT IN ('cart','pending_payment','rejected','cancelled')
        AND created_at >= now() - interval '28 days'
        AND EXTRACT(hour FROM timezone(restaurant_timezone(:id), created_at))
            = EXTRACT(hour FROM timezone(restaurant_timezone(:id), now()))"
);
$costStmt->execute(['id' => $restaurantId]);
$cost = $costStmt->fetch();

// Horário de hoje: o que a loja combinou, incluindo feriado quando houver.
$today = $pdo->prepare(
    "SELECT shift, opens, closes, last_order, active
       FROM business_hours
      WHERE restaurant_id = :id
        AND dow = EXTRACT(dow FROM timezone(restaurant_timezone(:id), now()))::int
      ORDER BY opens"
);
$today->execute(['id' => $restaurantId]);

$holidayStmt = $pdo->prepare(
    "SELECT day, closed, opens, closes, last_order, note
       FROM holiday_overrides
      WHERE restaurant_id = :id AND day = timezone(restaurant_timezone(:id), now())::date"
);
$holidayStmt->execute(['id' => $restaurantId]);
$holiday = $holidayStmt->fetch();

// Pausa em curso (a "pausa registrada 20:14 – 20:44" do mock).
$pauseStmt = $pdo->prepare(
    'SELECT id, kind, reason, started_at, until
       FROM store_pauses
      WHERE restaurant_id = :id AND ended_at IS NULL
      ORDER BY started_at DESC LIMIT 1'
);
$pauseStmt->execute(['id' => $restaurantId]);
$pause = $pauseStmt->fetch();

// "Acima de 2 h por dia, a loja perde o selo de Confiável." O aviso só é
// honesto com o número de hoje do lado.
$todayPauseStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(EXTRACT(epoch FROM (COALESCE(ended_at, now()) - started_at))), 0)::int
       FROM store_pauses
      WHERE restaurant_id = :id
        -- Meia-noite de hoje NA CIDADE DA LOJA, como instante.
        AND started_at >= (timezone(restaurant_timezone(:id), now())::date)::timestamp
                          AT TIME ZONE restaurant_timezone(:id)"
);
$todayPauseStmt->execute(['id' => $restaurantId]);
$pausedSecondsToday = (int) $todayPauseStmt->fetchColumn();

// "34 minutos / ajustado pela fila atual": o número que o cliente está
// vendo agora, não só o combinado.
$prep = effective_prep_minutes($pdo, $restaurantId, $store);

json_response(200, [
    'store' => [
        'name' => $store['name'],
        'is_open' => $store['is_open'],
        'pause_until' => $store['pause_until'],
        'prep_minutes' => (int) $store['prep_minutes'],
        'prep_auto_bump' => $store['prep_auto_bump'],
        // Cadastro feito pela própria loja (restaurants/signup.php): até a
        // plataforma aprovar, o painel avisa que a loja não aparece pros
        // clientes -- e, se recusou, mostra o motivo pra corrigir.
        'approval' => $store['approved_at'] !== null ? 'approved' : ($store['rejected_at'] !== null ? 'rejected' : 'review'),
        'rejection_reason' => $store['rejection_reason'],
    ],
    'prep' => $prep,
    'effect' => [
        'orders_in_flight' => $inFlight,
        'orders_per_hour' => round((float) $cost['orders_per_hour'], 1),
        'revenue_per_hour' => round((float) $cost['revenue_per_hour'], 2),
        // Sem histórico nessa faixa de horário o número seria "0", que lido
        // como "pausar não custa nada" é pior que não mostrar.
        'has_history' => (float) $cost['orders_per_hour'] > 0,
    ],
    'today' => [
        'shifts' => $today->fetchAll(),
        'holiday' => $holiday === false ? null : $holiday,
    ],
    'pause' => $pause === false ? null : $pause,
    'paused_seconds_today' => $pausedSecondsToday,
    // 7200 s: as 2 h do mock, escritas num lugar só.
    'ranking_limit_seconds' => 7200,
    'reasons' => [
        ['code' => 'busy_kitchen', 'label' => 'Cozinha cheia'],
        ['code' => 'out_of_stock', 'label' => 'Falta de insumo'],
        ['code' => 'no_courier', 'label' => 'Sem entregador'],
        ['code' => 'technical', 'label' => 'Problema técnico'],
    ],
]);
