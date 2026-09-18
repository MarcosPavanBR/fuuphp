<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 8.1 — "Turno e saldo em espécie". Tudo que o app do entregador precisa
// pra desenhar a tela inicial numa chamada só: turno aberto ou não, saldo em
// dinheiro vivo (que é passivo dele com a loja), o que a plataforma deve, e as
// duas travas da política -- teto de espécie e prazo de baixa.
//
// "O saldo em dinheiro vivo fica visível sempre": por isso ele vem aqui e não
// numa tela separada de ganhos.

require_method('GET');
$claims = require_auth();
$courierId = require_courier($claims);

$pdo = db();

$courierStmt = $pdo->prepare(
    'SELECT c.id, c.city_ibge_code, c.rating, c.cash_blocked, c.active, u.full_name
     FROM couriers c JOIN users u ON u.id = c.user_id WHERE c.id = :id'
);
$courierStmt->execute(['id' => $courierId]);
$courier = $courierStmt->fetch();
if ($courier === false) {
    error_response(404, 'courier_not_found', 'Entregador não encontrado.');
}

$shiftStmt = $pdo->prepare(
    'SELECT id, started_at FROM courier_shifts WHERE courier_id = :id AND ended_at IS NULL'
);
$shiftStmt->execute(['id' => $courierId]);
$shift = $shiftStmt->fetch();

$policyStmt = $pdo->query(
    'SELECT cash_ceiling, cash_settle_deadline, allow_partial_settle FROM platform_policies
     ORDER BY version DESC LIMIT 1'
);
$policy = $policyStmt->fetch();

// Corrida em andamento: o app volta direto pra ela se o aparelho reiniciar.
$currentStmt = $pdo->prepare(
    "SELECT o.id, o.public_code, o.status, o.total, o.delivery_fee, o.payment_method,
            o.change_for, o.machine_kind, r.name AS restaurant_name
     FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.courier_id = :id AND o.status IN ('ready','delivering')
     ORDER BY o.updated_at DESC LIMIT 1"
);
$currentStmt->execute(['id' => $courierId]);
$current = $currentStmt->fetch();

$cash = courier_cash_balance($pdo, $courierId);
$ceiling = (float) ($policy['cash_ceiling'] ?? 0);

json_response(200, [
    'courier' => [
        'id' => $courier['id'],
        'name' => $courier['full_name'],
        'rating' => $courier['rating'],
        'active' => $courier['active'],
        // cash_blocked é a trava: acima do teto ou fora do prazo, sem corrida
        // em dinheiro até baixar o caixa (Fase 9).
        'cash_blocked' => $courier['cash_blocked'],
    ],
    'shift' => $shift === false ? null : $shift,
    'balances' => [
        'cash' => $cash,
        'payable' => courier_payable_balance($pdo, $courierId),
        'cash_ceiling' => $ceiling,
        'cash_headroom' => max(0, round($ceiling - $cash, 2)),
        'settle_deadline' => $policy['cash_settle_deadline'] ?? null,
        'allow_partial_settle' => $policy['allow_partial_settle'] ?? true,
    ],
    'current_order' => $current === false ? null : $current,
]);
