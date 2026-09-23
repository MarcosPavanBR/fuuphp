<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 3.3 — muda a quantidade de um item do carrinho (mínimo 1; pra tirar,
// remove_item.php) e recalcula o subtotal.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$orderItemId = (int) ($body['order_item_id'] ?? 0);
$quantity = (int) ($body['quantity'] ?? 0);

if ($orderItemId <= 0) {
    error_response(422, 'order_item_id_required', 'Informe order_item_id.');
}
if ($quantity < 1) {
    error_response(422, 'invalid_quantity', 'Quantidade precisa ser pelo menos 1 (use remove_item.php pra tirar o item).');
}

$pdo = db();

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

$pdo->prepare('UPDATE order_items SET quantity = :q WHERE id = :id')
    ->execute(['q' => $quantity, 'id' => $orderItemId]);
recompute_cart_subtotal($pdo, (int) $orderId);

$order = fetch_order($pdo, (int) $orderId);
json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, (int) $orderId),
]);
