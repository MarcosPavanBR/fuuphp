<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$restaurantId = $body['restaurant_id'] ?? null;
$menuItemId = (int) ($body['menu_item_id'] ?? 0);
$quantity = (int) ($body['quantity'] ?? 1);
$variantIds = array_map('intval', $body['variant_ids'] ?? []);
$notes = isset($body['notes']) ? trim((string) $body['notes']) : null;

if (!is_string($restaurantId) || $restaurantId === '') {
    error_response(422, 'restaurant_id_required', 'Informe restaurant_id.', fields: ['restaurant_id' => 'obrigatório']);
}
if ($menuItemId <= 0) {
    error_response(422, 'menu_item_id_required', 'Informe menu_item_id.', fields: ['menu_item_id' => 'obrigatório']);
}

$pdo = db();

$restaurantStmt = $pdo->prepare('SELECT is_open, pause_until FROM restaurants WHERE id = :id');
$restaurantStmt->execute(['id' => $restaurantId]);
$restaurant = $restaurantStmt->fetch();
if ($restaurant === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}
if (!$restaurant['is_open']) {
    error_response(409, 'store_closed', 'Essa loja está fechada agora.');
}

$priced = price_line($pdo, $restaurantId, $menuItemId, $variantIds, $quantity);

$pdo->beginTransaction();
try {
    $cart = find_or_create_cart($pdo, (string) $claims['sub'], $restaurantId);

    $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, name_snapshot, unit_price, quantity, variants_snapshot, notes)
         VALUES (:order_id, :menu_item_id, :name_snapshot, :unit_price, :quantity, :variants_snapshot, :notes)'
    )->execute([
        'order_id' => $cart['id'],
        'menu_item_id' => $priced['menu_item_id'],
        'name_snapshot' => $priced['name_snapshot'],
        'unit_price' => $priced['unit_price'],
        'quantity' => $quantity,
        'variants_snapshot' => json_encode($priced['variants_snapshot'], JSON_UNESCAPED_UNICODE),
        'notes' => $notes !== '' ? $notes : null,
    ]);

    recompute_cart_subtotal($pdo, (int) $cart['id']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$order = fetch_order($pdo, (int) $cart['id']);
json_response(201, [
    'order' => $order,
    'items' => fetch_order_items($pdo, (int) $cart['id']),
]);
