<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 12.3 — "Os números que mudam decisão".
//
// O mock escolhe quatro, e a escolha é o ponto: mix de pagamento ("quanto do
// GMV depende de gente conferindo"), custo de entrega por pedido, perda por
// fraude como percentual do GMV, e o que há pra cobrar. Nada de "total de
// acessos".
//
// Tudo é calculado no banco, no período pedido. Onde não há dado pra
// sustentar o número, ele não é inventado -- vem null com o motivo.

require_method('GET');
$claims = require_auth();
require_admin($claims);

$days = min(90, max(1, (int) ($_GET['days'] ?? 30)));
$pdo = db();

$paid = "status IN ('paid','preparing','ready','delivering','delivered')";

$gmvStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total) FILTER (WHERE {$paid}), 0)          AS gmv,
            count(*) FILTER (WHERE {$paid})                          AS orders,
            COALESCE(SUM(delivery_fee) FILTER (WHERE {$paid}), 0)    AS delivery_cost,
            COALESCE(SUM(commission) FILTER (WHERE {$paid}), 0)      AS commission,
            count(*) FILTER (WHERE status = 'cancelled')             AS cancelled,
            count(*) FILTER (WHERE status = 'rejected')              AS rejected
     FROM orders
     WHERE created_at >= now() - (:days || ' days')::interval"
);
$gmvStmt->execute(['days' => $days]);
$totals = $gmvStmt->fetch();

// Mix por método: o número que responde "quanto do faturamento depende de
// alguém conferindo comprovante na mão".
$mixStmt = $pdo->prepare(
    "SELECT payment_method, count(*) AS orders, COALESCE(SUM(total), 0) AS amount
     FROM orders
     WHERE created_at >= now() - (:days || ' days')::interval AND {$paid}
       AND payment_method IS NOT NULL
     GROUP BY payment_method ORDER BY 3 DESC"
);
$mixStmt->execute(['days' => $days]);
$mix = $mixStmt->fetchAll();

$manual = 0.0;
$gmv = (float) $totals['gmv'];
foreach ($mix as $row) {
    if (in_array($row['payment_method'], ['pix_manual', 'cash', 'pos_machine'], true)) {
        $manual += (float) $row['amount'];
    }
}

// Perda por fraude: o que foi estornado por causa de fraude ou prova falsa.
// Não é "chute de risco" -- é dinheiro que saiu.
$fraudStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM refunds
     WHERE created_at >= now() - (:days || ' days')::interval AND cause = 'fraud'"
);
$fraudStmt->execute(['days' => $days]);
$fraudLoss = (float) $fraudStmt->fetchColumn();

$refundStmt = $pdo->prepare(
    "SELECT cause, count(*) AS n, COALESCE(SUM(amount), 0) AS amount
     FROM refunds WHERE created_at >= now() - (:days || ' days')::interval
     GROUP BY cause ORDER BY 3 DESC"
);
$refundStmt->execute(['days' => $days]);

// "O que há para cobrar na terça": saldo das lojas no livro.
$owed = $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'store_receivable'"
)->fetchColumn();

$cashOut = $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'courier_cash'"
)->fetchColumn();

json_response(200, [
    'period_days' => $days,
    'totals' => [
        'gmv' => $gmv,
        'orders' => (int) $totals['orders'],
        'commission' => (float) $totals['commission'],
        'delivery_cost' => (float) $totals['delivery_cost'],
        // Custo de entrega POR PEDIDO, que é a forma que dá pra comparar
        // entre semanas de tamanhos diferentes.
        'delivery_cost_per_order' => (int) $totals['orders'] > 0
            ? round((float) $totals['delivery_cost'] / (int) $totals['orders'], 2)
            : null,
        'cancelled' => (int) $totals['cancelled'],
        'rejected' => (int) $totals['rejected'],
    ],
    'payment_mix' => $mix,
    'manual_share' => $gmv > 0 ? round($manual * 100 / $gmv, 1) : null,
    'fraud' => [
        'loss' => $fraudLoss,
        'share_of_gmv' => $gmv > 0 ? round($fraudLoss * 100 / $gmv, 2) : null,
    ],
    'refunds_by_cause' => $refundStmt->fetchAll(),
    'balances' => [
        'store_receivable' => (float) $owed,
        'courier_cash_out' => (float) $cashOut,
    ],
]);
