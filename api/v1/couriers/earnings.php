<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 8.7 — "Livro de lançamentos, não um campo de saldo: cada corrida,
// bônus, gorjeta e repasse é uma linha — é o que permite fechar o caixa sem
// discussão."
//
// Então esta rota devolve as LINHAS, não um resumo bonitinho: o saldo é a
// soma delas, calculada no banco, e qualquer divergência é conferível
// lançamento a lançamento. Correção aqui nunca é edição -- ledger_entries é
// append-only por permissão de role (migração 006), então uma correção
// aparece como contrapartida, e some na lista.

require_method('GET');
$claims = require_auth();
$courierId = require_courier($claims);

$pdo = db();

$limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

$stmt = $pdo->prepare(
    "SELECT le.id, le.account, le.amount, le.origin, le.origin_id, le.order_id,
            le.memo, le.created_at, o.public_code,
            o.restaurant_id, r.name AS restaurant_name
     FROM ledger_entries le
     LEFT JOIN orders o ON o.id = le.order_id
     LEFT JOIN restaurants r ON r.id = o.restaurant_id
     WHERE le.party_id = :id AND le.account IN ('courier_cash','courier_payable')
     ORDER BY le.created_at DESC, le.id DESC
     LIMIT {$limit}"
);
$stmt->execute(['id' => $courierId]);

// Total de hoje separado do saldo acumulado: são perguntas diferentes
// ("quanto rendeu hoje" x "quanto tenho em mãos").
$todayStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount) FILTER (WHERE account = 'courier_payable'), 0) AS payable_today,
            count(DISTINCT order_id) FILTER (WHERE origin = 'order')            AS rides_today
     FROM ledger_entries
     WHERE party_id = :id AND created_at >= now()::date"
);
$todayStmt->execute(['id' => $courierId]);

json_response(200, [
    'entries' => $stmt->fetchAll(),
    'balances' => [
        'cash' => courier_cash_balance($pdo, $courierId),
        'payable' => courier_payable_balance($pdo, $courierId),
    ],
    'today' => $todayStmt->fetch(),
]);
