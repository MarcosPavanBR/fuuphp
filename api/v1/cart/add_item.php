<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 3.2 — põe um item (com variações e observação) no carrinho da loja,
// criando o carrinho se preciso. O preço vem do cardápio atual, nunca do
// corpo da requisição (lib/ordering/cart.php).

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$restaurantId = $body['restaurant_id'] ?? null;
$menuItemId = positive_id($body['menu_item_id'] ?? null) ?? 0;
$quantity = $body['quantity'] ?? 1;
if (!is_int_between($quantity, 1, CART_MAX_QUANTITY)) {
    error_response(422, 'invalid_quantity', 'Quantidade de 1 a ' . CART_MAX_QUANTITY . '.', fields: ['quantity' => 'de 1 a ' . CART_MAX_QUANTITY]);
}
$quantity = (int) $quantity;
// variant_ids é LISTA de ids: objeto {"a": 1} virava parâmetro nomeado no
// PDO e dava 500.
$rawVariants = $body['variant_ids'] ?? [];
if (!is_array($rawVariants) || !array_is_list($rawVariants) || (isset($body['notes']) && !is_string($body['notes']))) {
    error_response(422, 'invalid_request', 'variant_ids precisa ser uma lista e notes, um texto.',
        fields: ['variant_ids' => 'lista de ids', 'notes' => 'texto']);
}
$variantIds = [];
foreach ($rawVariants as $vid) {
    $id = positive_id($vid);
    if ($id === null) {
        error_response(422, 'invalid_request', 'variant_ids precisa ser uma lista de ids.', fields: ['variant_ids' => 'lista de ids']);
    }
    $variantIds[] = $id;
}
$notes = isset($body['notes']) ? trim($body['notes']) : null;
if ($notes !== null && mb_strlen($notes) > CART_NOTES_MAX) {
    // Vai impressa na comanda: 10 mil letras eram metros de papel.
    error_response(422, 'notes_too_long', 'A observação vai até ' . CART_NOTES_MAX . ' caracteres.', fields: ['notes' => 'até ' . CART_NOTES_MAX . ' caracteres']);
}
if ($notes === '') {
    $notes = null;
}

if (!is_string($restaurantId) || !is_valid_uuid($restaurantId)) {
    error_response(422, 'restaurant_id_required', 'Informe um restaurant_id válido.', fields: ['restaurant_id' => 'obrigatório (uuid)']);
}
if ($menuItemId <= 0) {
    error_response(422, 'menu_item_id_required', 'Informe menu_item_id.', fields: ['menu_item_id' => 'obrigatório']);
}

$pdo = db();

// Existe, foi aprovada pela plataforma e está aberta (lib/catalog/store.php).
$restaurant = require_store_accepting_orders($pdo, $restaurantId);

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
