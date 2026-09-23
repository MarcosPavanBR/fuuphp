<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 9.4 — "Entregador — recibo e saldo zerado": "Baixa confirmada. Ana
// (caixa) recebeu R$ 262,80 às 23:14. Saldo em espécie R$ 0,00 ... Recibo
// #BX-4417 · hash 9f2c…4a1b · via impressa ficou na loja."
//
//   GET              as baixas do entregador (últimos 30 dias) e o recibo da
//                    mais recente confirmada, com a mesma assinatura que sai
//                    no papel da loja (lib/printing/documents.php) -- "Recibo
//                    com hash dos dois lados".
//   GET ?intent_id=  o estado de UMA baixa: a tela do código (9.2) consulta
//                    isto pra virar o recibo sozinha quando a loja confirmar.
//
// Junto vão os números da 9.4: saldo em espécie, o que a plataforma deve a
// ele e o dia do próximo repasse (`courier_payout_dow` da política; terça no mock).

require_method('GET');
$claims = require_auth();
$courierId = require_courier($claims);
$pdo = db();

$policy = $pdo->query('SELECT courier_payout_dow FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
$payoutDow = (int) ($policy['courier_payout_dow'] ?? 2);
$nextPayout = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
while ((int) $nextPayout->format('w') !== $payoutDow || $nextPayout <= new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'))) {
    $nextPayout = $nextPayout->modify('+1 day');
}

$balances = [
    'cash' => courier_cash_balance($pdo, $courierId),
    'payable' => courier_payable_balance($pdo, $courierId),
    'next_payout' => $nextPayout->format('Y-m-d'),
];

if (isset($_GET['intent_id'])) {
    $stmt = $pdo->prepare('SELECT id, state, method, amount, expires_at FROM cash_settlement_intents WHERE id = :id AND courier_id = :c');
    $stmt->execute(['id' => (int) $_GET['intent_id'], 'c' => $courierId]);
    $intent = $stmt->fetch();
    if ($intent === false) {
        error_response(404, 'intent_not_found', 'Baixa não encontrada.');
    }
    $receipt = $intent['state'] === 'settled' ? settlement_receipt($pdo, (int) $intent['id']) : null;
    json_response(200, ['intent' => $intent, 'receipt' => $receipt, 'balances' => $balances]);
}

$list = $pdo->prepare(
    "SELECT i.id, i.amount, i.method, i.state, i.created_at, i.confirmed_at, r.name AS restaurant_name
       FROM cash_settlement_intents i JOIN restaurants r ON r.id = i.restaurant_id
      WHERE i.courier_id = :c AND i.created_at > now() - interval '30 days'
      ORDER BY i.created_at DESC"
);
$list->execute(['c' => $courierId]);
$rows = $list->fetchAll();

$lastSettled = null;
foreach ($rows as $row) {
    if ($row['state'] === 'settled') {
        $lastSettled = settlement_receipt($pdo, (int) $row['id']);
        break;
    }
}

json_response(200, ['settlements' => $rows, 'last_receipt' => $lastSettled, 'balances' => $balances]);
