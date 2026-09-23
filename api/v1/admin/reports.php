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
//
// E os números do dia a dia, pra decidir onde pôr esforço:
//   - ticket médio (o que o cliente paga por pedido), com o período
//     anterior do mesmo tamanho ao lado, pra ver se sobe ou desce;
//   - pedidos por hora do dia e por dia da semana (horário de Brasília):
//     quando precisa de mais entregador e quando vale campanha;
//   - as lojas que mais vendem;
//   - clientes: quantos compraram, quantos voltaram (2+ pedidos) e quantos
//     compraram pela primeira vez.

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

// Período anterior, do mesmo tamanho: a régua do "subiu ou desceu".
$prevStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total), 0) AS gmv, count(*) AS orders
     FROM orders
     WHERE {$paid}
       AND created_at >= now() - (:twice || ' days')::interval
       AND created_at <  now() - (:days || ' days')::interval"
);
$prevStmt->execute(['twice' => $days * 2, 'days' => $days]);
$prev = $prevStmt->fetch();

$ticket = static fn (float $gmv, int $orders): ?float => $orders > 0 ? round($gmv / $orders, 2) : null;

// Hora do dia e dia da semana no fuso de Brasília: é o relógio da cidade.
$hourStmt = $pdo->prepare(
    "SELECT extract(hour FROM created_at AT TIME ZONE 'America/Sao_Paulo')::int AS h,
            count(*) AS orders, COALESCE(SUM(total), 0) AS gmv
     FROM orders
     WHERE created_at >= now() - (:days || ' days')::interval AND {$paid}
     GROUP BY 1"
);
$hourStmt->execute(['days' => $days]);
$byHour = array_fill(0, 24, ['orders' => 0, 'gmv' => 0.0]);
foreach ($hourStmt->fetchAll() as $row) {
    $byHour[(int) $row['h']] = ['orders' => (int) $row['orders'], 'gmv' => (float) $row['gmv']];
}

// 0 = domingo ... 6 = sábado (dow do Postgres).
$dowStmt = $pdo->prepare(
    "SELECT extract(dow FROM created_at AT TIME ZONE 'America/Sao_Paulo')::int AS d,
            count(*) AS orders, COALESCE(SUM(total), 0) AS gmv
     FROM orders
     WHERE created_at >= now() - (:days || ' days')::interval AND {$paid}
     GROUP BY 1"
);
$dowStmt->execute(['days' => $days]);
$byWeekday = array_fill(0, 7, ['orders' => 0, 'gmv' => 0.0]);
foreach ($dowStmt->fetchAll() as $row) {
    $byWeekday[(int) $row['d']] = ['orders' => (int) $row['orders'], 'gmv' => (float) $row['gmv']];
}

$topStmt = $pdo->prepare(
    "SELECT r.id, r.name, count(*) AS orders, COALESCE(SUM(o.total), 0) AS gmv
     FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at >= now() - (:days || ' days')::interval AND o.{$paid}
     GROUP BY r.id, r.name
     ORDER BY gmv DESC, orders DESC
     LIMIT 5"
);
$topStmt->execute(['days' => $days]);
$topStores = array_map(static fn (array $r): array => [
    'id' => $r['id'],
    'name' => $r['name'],
    'orders' => (int) $r['orders'],
    'gmv' => (float) $r['gmv'],
    'average_ticket' => $ticket((float) $r['gmv'], (int) $r['orders']),
], $topStmt->fetchAll());

// "Voltou" = 2+ pedidos pagos no período; "novo" = o primeiro pedido pago da
// vida dele caiu no período.
$custStmt = $pdo->prepare(
    "WITH buyers AS (
       SELECT user_id, count(*) AS n
       FROM orders
       WHERE created_at >= now() - (:days || ' days')::interval AND {$paid}
       GROUP BY user_id
     )
     SELECT count(*) AS buyers,
            count(*) FILTER (WHERE n >= 2) AS returning,
            count(*) FILTER (WHERE NOT EXISTS (
              SELECT 1 FROM orders o
              WHERE o.user_id = buyers.user_id AND o.{$paid}
                AND o.created_at < now() - (:days2 || ' days')::interval
            )) AS first_time
     FROM buyers"
);
$custStmt->execute(['days' => $days, 'days2' => $days]);
$customers = $custStmt->fetch();
$buyers = (int) $customers['buyers'];

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
        // Ticket médio: o que o cliente pagou por pedido (itens, frete e
        // gorjeta, já com desconto).
        'average_ticket' => $ticket($gmv, (int) $totals['orders']),
        'cancelled' => (int) $totals['cancelled'],
        'rejected' => (int) $totals['rejected'],
    ],
    'previous' => [
        'gmv' => (float) $prev['gmv'],
        'orders' => (int) $prev['orders'],
        'average_ticket' => $ticket((float) $prev['gmv'], (int) $prev['orders']),
    ],
    'by_hour' => $byHour,
    'by_weekday' => $byWeekday,
    'top_stores' => $topStores,
    'customers' => [
        'buyers' => $buyers,
        'returning' => (int) $customers['returning'],
        'first_time' => (int) $customers['first_time'],
        'returning_share' => $buyers > 0 ? round((int) $customers['returning'] * 100 / $buyers, 1) : null,
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
