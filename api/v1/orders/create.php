<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$restaurantId = $body['restaurant_id'] ?? null;
$addressId = $body['address_id'] ?? null;
$items = $body['items'] ?? null;
$paymentMethod = $body['payment_method'] ?? null;

if (!is_string($restaurantId) || $restaurantId === '') {
    error_response(422, 'restaurant_id_required', 'Informe restaurant_id.', fields: ['restaurant_id' => 'obrigatório']);
}
if (!is_array($items) || $items === []) {
    error_response(422, 'items_required', 'O carrinho precisa de ao menos um item.', fields: ['items' => 'obrigatório']);
}
if (!in_array($paymentMethod, ['mp_card', 'pix_auto', 'pix_manual', 'cash', 'pos_machine'], true)) {
    error_response(422, 'invalid_payment_method', 'payment_method inválido.', fields: ['payment_method' => 'inválido']);
}

$pdo = db();

$restaurantStmt = $pdo->prepare('SELECT * FROM restaurants WHERE id = :id');
$restaurantStmt->execute(['id' => $restaurantId]);
$restaurant = $restaurantStmt->fetch();
if ($restaurant === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}
if (!$restaurant['is_open']) {
    error_response(409, 'store_closed', 'Essa loja está fechada agora.');
}
if ($restaurant['pause_until'] !== null && strtotime((string) $restaurant['pause_until']) > time()) {
    error_response(409, 'store_paused', 'Essa loja está pausada no momento.', detail: 'volta às ' . $restaurant['pause_until']);
}

if ($addressId !== null) {
    $addressStmt = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :user_id');
    $addressStmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
    if ($addressStmt->fetchColumn() === false) {
        error_response(404, 'address_not_found', 'Endereço não encontrado para esse usuário.');
    }
}

$policy = resolve_policy($pdo, $restaurantId);
if (!in_array($paymentMethod, $policy['enabled_methods'], true)) {
    error_response(422, 'payment_method_not_allowed', 'Essa loja não aceita esse método de pagamento.', fields: ['payment_method' => 'não habilitado por esta loja']);
}

$changeFor = isset($body['change_for']) ? (float) $body['change_for'] : null;
if ($paymentMethod === 'cash' && $changeFor !== null && $changeFor < 0) {
    error_response(422, 'invalid_change_for', 'Troco inválido.', fields: ['change_for' => 'inválido']);
}

$machineKind = $body['machine_kind'] ?? null;
if ($paymentMethod === 'pos_machine' && !in_array($machineKind, ['debit', 'credit'], true)) {
    error_response(422, 'machine_kind_required', 'Informe machine_kind: debit ou credit.', fields: ['machine_kind' => 'obrigatório']);
}

// Preço que vale é o que a loja tem cadastrado agora, nunca o que o
// cliente mandou (Especificação, Parte I §2, módulo catalog) -- price_line()
// é a mesma validação usada pelo carrinho incremental (api/v1/cart/*.php).
$orderLines = [];
$subtotal = 0.0;
foreach ($items as $line) {
    $menuItemId = (int) ($line['menu_item_id'] ?? 0);
    $quantity = (int) ($line['quantity'] ?? 0);
    $variantIds = array_map('intval', $line['variant_ids'] ?? []);

    $priced = price_line($pdo, $restaurantId, $menuItemId, $variantIds, $quantity);
    $subtotal += $priced['unit_price'] * $quantity;
    $orderLines[] = [...$priced, 'quantity' => $quantity, 'notes' => $line['notes'] ?? null];
}

$subtotal = round($subtotal, 2);
if ($subtotal < (float) $policy['min_order']) {
    error_response(422, 'below_minimum_order', "Pedido mínimo dessa loja é R$ {$policy['min_order']}.");
}
if ($paymentMethod === 'cash' && $changeFor !== null && $changeFor < $subtotal) {
    error_response(422, 'invalid_change_for', 'Troco precisa ser maior ou igual ao subtotal.', fields: ['change_for' => 'inválido']);
}

// Tela 14.3 — o frete é calculado aqui, não recebido. Mesma regra do
// checkout do carrinho (orders/checkout.php): era o último número do
// dinheiro que confiava no cliente.
$quote = delivery_quote_for($pdo, (string) $restaurantId, (int) $addressId, $policy);
if (!$quote['in_area']) {
    error_response(409, 'out_of_delivery_area', $quote['reason'], detail: 'endereço fora do raio de entrega');
}
$deliveryFee = (float) $quote['fee'];
$tip = isset($body['tip']) ? round((float) $body['tip'], 2) : 0.0;
if ($tip < 0) {
    error_response(422, 'invalid_tip', 'Gorjeta inválida.');
}

$pdo->beginTransaction();
try {
    $orderStmt = $pdo->prepare(
        'INSERT INTO orders (user_id, restaurant_id, address_id, subtotal, delivery_fee, tip,
                              payment_method, change_for, machine_kind, policy_snapshot, commission)
         VALUES (:user_id, :restaurant_id, :address_id, :subtotal, :delivery_fee, :tip,
                 :payment_method, :change_for, :machine_kind, :policy_snapshot, :commission)
         RETURNING id, public_code'
    );
    $commission = round($subtotal * $policy['commission_bps'] / 10000, 2);
    $orderStmt->execute([
        'user_id' => $claims['sub'],
        'restaurant_id' => $restaurantId,
        'address_id' => $addressId,
        'subtotal' => $subtotal,
        'delivery_fee' => $deliveryFee,
        'tip' => $tip,
        'payment_method' => $paymentMethod,
        'change_for' => $paymentMethod === 'cash' ? $changeFor : null,
        'machine_kind' => $machineKind,
        'policy_snapshot' => json_encode($policy, JSON_UNESCAPED_UNICODE),
        'commission' => $commission,
    ]);
    $orderRow = $orderStmt->fetch();
    $orderId = (int) $orderRow['id'];

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, name_snapshot, unit_price, quantity, variants_snapshot, notes)
         VALUES (:order_id, :menu_item_id, :name_snapshot, :unit_price, :quantity, :variants_snapshot, :notes)'
    );
    foreach ($orderLines as $line) {
        $itemStmt->execute([
            'order_id' => $orderId,
            'menu_item_id' => $line['menu_item_id'],
            'name_snapshot' => $line['name_snapshot'],
            'unit_price' => $line['unit_price'],
            'quantity' => $line['quantity'],
            'variants_snapshot' => json_encode($line['variants_snapshot'], JSON_UNESCAPED_UNICODE),
            'notes' => $line['notes'],
        ]);
    }

    call_advance_order($pdo, $orderId, 'pending_payment', (string) $claims['sub'], 'customer');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$order = fetch_order($pdo, $orderId);
json_response(201, [
    'order' => $order,
    'items' => fetch_order_items($pdo, $orderId),
]);
