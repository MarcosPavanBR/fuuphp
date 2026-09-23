<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 3.3 — tira um item do carrinho do próprio cliente.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$orderItemId = (int) ($body['order_item_id'] ?? 0);
if ($orderItemId <= 0) {
    error_response(422, 'order_item_id_required', 'Informe order_item_id.');
}

$pdo = db();

// Só apaga item de um carrinho (status='cart') do próprio usuário -- depois
// de pending_payment, a única porta pra mexer no pedido é advance_order()
// (Especificação, Parte II §9), não um DELETE direto em order_items.
$stmt = $pdo->prepare(
    "SELECT oi.order_id FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE oi.id = :item_id AND o.user_id = :user_id AND o.status = 'cart'"
);
$stmt->execute(['item_id' => $orderItemId, 'user_id' => $claims['sub']]);
$orderId = $stmt->fetchColumn();

if ($orderId === false) {
    error_response(404, 'item_not_found', 'Item não encontrado nesse carrinho.');
}

$pdo->prepare('DELETE FROM order_items WHERE id = :id')->execute(['id' => $orderItemId]);
recompute_cart_subtotal($pdo, (int) $orderId);

$order = fetch_order($pdo, (int) $orderId);
json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, (int) $orderId),
]);
