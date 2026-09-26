<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 2.5 — "Notas e comprovantes". O recibo de um pedido: quem vendeu
// (nome e CNPJ da loja), o que foi comprado, quanto foi cobrado e como, e o
// que voltou (estornos, crédito em carteira, gorjeta cobrada à parte).
//
// É RECIBO, não nota fiscal: a NF-e/NFC-e é emitida pela loja, que é quem
// vende (white-label). A resposta diz isso em `fiscal_notice`, e a tela
// repete -- ninguém deve achar que isto substitui a nota.
//
// Só o dono do pedido (ou admin/suporte, via authorize_order_access) lê.

require_method('GET');
$claims = require_auth();

$orderId = positive_id($_GET['id'] ?? null) ?? 0;
if ($orderId <= 0) {
    error_response(422, 'id_required', 'Informe ?id= com o número do pedido.');
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null || $order['status'] === 'cart') {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

$store = $pdo->prepare('SELECT name, cnpj FROM restaurants WHERE id = :id');
$store->execute(['id' => $order['restaurant_id']]);

// Só os pagamentos que contam: tentativa descartada (troca de método) ou
// recusada não é cobrança e não entra no recibo.
$payments = $pdo->prepare(
    "SELECT id, provider, amount, status, created_at FROM payments
      WHERE order_id = :id AND status IN ('approved','refunded','charged_back','created')
      ORDER BY created_at"
);
$payments->execute(['id' => $orderId]);

$refunds = $pdo->prepare(
    "SELECT amount, fee, channel, state, created_at, executed_at FROM refunds
      WHERE order_id = :id ORDER BY created_at"
);
$refunds->execute(['id' => $orderId]);

$tip = $pdo->prepare("SELECT courier_tip, tip_charged_at FROM reviews WHERE order_id = :id AND tip_state = 'charged'");
$tip->execute(['id' => $orderId]);
$tipRow = $tip->fetch();

json_response(200, [
    'order' => [
        'id' => $order['id'],
        'public_code' => $order['public_code'],
        'status' => $order['status'],
        'created_at' => $order['created_at'],
        'payment_method' => $order['payment_method'],
        'subtotal' => $order['subtotal'],
        'delivery_fee' => $order['delivery_fee'],
        'surge_fee' => $order['surge_fee'],
        'tip' => $order['tip'],
        'discount' => $order['discount'],
        'total' => $order['total'],
    ],
    'store' => $store->fetch() ?: null,
    'items' => fetch_order_items($pdo, $orderId),
    'payments' => $payments->fetchAll(),
    'refunds' => $refunds->fetchAll(),
    'review_tip' => $tipRow === false ? null : $tipRow,
    'fiscal_notice' => 'Recibo do FUUdelivery. A nota fiscal é emitida pela loja, que é quem vende.',
]);
