<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Converte o carrinho incremental (status='cart', montado por api/v1/cart/*.php,
// Fase 3) num pedido aguardando pagamento -- o passo que faltava entre
// "Ir para pagamento" (CartDrawer.svelte) e a Fase 4 (seleção de método).
// Diferente de orders/create.php (checkout de um só passo, que cria um
// pedido novo a partir de uma lista de itens no corpo da requisição), este
// endpoint reaproveita o pedido em status='cart' que o cliente já vinha
// montando -- não cria um segundo pedido nem duplica itens.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente faz checkout de carrinho.');
}
$body = read_json_body();

$restaurantId = $body['restaurant_id'] ?? null;
$addressId = $body['address_id'] ?? null;
$paymentMethod = $body['payment_method'] ?? null;

if (!is_string($restaurantId) || $restaurantId === '') {
    error_response(422, 'restaurant_id_required', 'Informe restaurant_id.', fields: ['restaurant_id' => 'obrigatório']);
}
if (!is_int($addressId) && !is_string($addressId)) {
    error_response(422, 'address_id_required', 'Informe address_id.', fields: ['address_id' => 'obrigatório']);
}
if (!in_array($paymentMethod, ['mp_card', 'pix_auto', 'pix_manual', 'cash', 'pos_machine'], true)) {
    error_response(422, 'invalid_payment_method', 'payment_method inválido.', fields: ['payment_method' => 'inválido']);
}

$pdo = db();

$cartStmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = :user_id AND restaurant_id = :restaurant_id AND status = 'cart'");
$cartStmt->execute(['user_id' => $claims['sub'], 'restaurant_id' => $restaurantId]);
$cart = $cartStmt->fetch();
if ($cart === false) {
    error_response(404, 'cart_not_found', 'Você não tem um carrinho aberto nessa loja.');
}

$itemCountStmt = $pdo->prepare('SELECT count(*) FROM order_items WHERE order_id = :id');
$itemCountStmt->execute(['id' => $cart['id']]);
if ((int) $itemCountStmt->fetchColumn() === 0) {
    error_response(422, 'cart_empty', 'Seu carrinho está vazio.');
}

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

$addressStmt = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :user_id');
$addressStmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
if ($addressStmt->fetchColumn() === false) {
    error_response(404, 'address_not_found', 'Endereço não encontrado para esse usuário.');
}

$policy = resolve_policy($pdo, $restaurantId);
if (!in_array($paymentMethod, $policy['enabled_methods'], true)) {
    error_response(422, 'payment_method_not_allowed', 'Essa loja não aceita esse método de pagamento.', fields: ['payment_method' => 'não habilitado por esta loja']);
}

$subtotal = (float) $cart['subtotal'];
if ($subtotal < (float) $policy['min_order']) {
    error_response(422, 'below_minimum_order', "Pedido mínimo dessa loja é R$ {$policy['min_order']}.");
}

$changeFor = isset($body['change_for']) ? (float) $body['change_for'] : null;
if ($paymentMethod === 'cash' && $changeFor !== null && $changeFor < $subtotal) {
    error_response(422, 'invalid_change_for', 'Troco precisa ser maior ou igual ao subtotal.', fields: ['change_for' => 'inválido']);
}

$machineKind = $body['machine_kind'] ?? null;
if ($paymentMethod === 'pos_machine' && !in_array($machineKind, ['debit', 'credit'], true)) {
    error_response(422, 'machine_kind_required', 'Informe machine_kind: debit ou credit.', fields: ['machine_kind' => 'obrigatório']);
}

$deliveryFee = isset($body['delivery_fee']) ? round((float) $body['delivery_fee'], 2) : 0.0;
if ($deliveryFee < 0) {
    error_response(422, 'invalid_delivery_fee', 'Frete inválido.');
}
$tip = isset($body['tip']) ? round((float) $body['tip'], 2) : 0.0;
if ($tip < 0) {
    error_response(422, 'invalid_tip', 'Gorjeta inválida.');
}

$commission = round($subtotal * $policy['commission_bps'] / 10000, 2);

// Cupom: o desconto já está no carrinho; o que falta é gravar o resgate. O
// código vem do corpo porque `orders` não tem coluna de cupom -- quem guarda
// o vínculo é `coupon_redemptions`, criada logo abaixo.
$couponCode = isset($body['coupon_code']) && trim((string) $body['coupon_code']) !== ''
    ? strtoupper(trim((string) $body['coupon_code']))
    : null;
$customerCpf = null;
if ($couponCode !== null) {
    $cpfStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
    $cpfStmt->execute(['id' => $claims['sub']]);
    $customerCpf = $cpfStmt->fetchColumn();
    if (!is_string($customerCpf) || $customerCpf === '') {
        error_response(409, 'cpf_required', 'Cupom exige CPF no cadastro — é um uso por CPF, não por conta.');
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE orders SET address_id = :address_id, delivery_fee = :delivery_fee, tip = :tip,
                            payment_method = :payment_method, change_for = :change_for, machine_kind = :machine_kind,
                            policy_snapshot = :policy_snapshot, commission = :commission
         WHERE id = :id'
    )->execute([
        'address_id' => $addressId,
        'delivery_fee' => $deliveryFee,
        'tip' => $tip,
        'payment_method' => $paymentMethod,
        'change_for' => $paymentMethod === 'cash' ? $changeFor : null,
        'machine_kind' => $machineKind,
        'policy_snapshot' => json_encode($policy, JSON_UNESCAPED_UNICODE),
        'commission' => $commission,
        'id' => $cart['id'],
    ]);

    call_advance_order($pdo, (int) $cart['id'], 'pending_payment', (string) $claims['sub'], 'customer');

    // O cupom foi aplicado no carrinho (cart/apply_coupon.php), mas o consumo
    // do orçamento só vira registro aqui: é neste ponto que existe pedido de
    // verdade pra referenciar, e carrinho abandonado não pode segurar
    // dinheiro de campanha. A UNIQUE (coupon_id, cpf) e o CHECK
    // `within_budget` fazem o resto -- se o orçamento estourou entre aplicar
    // e fechar, o banco recusa e o checkout inteiro volta atrás.
    if ((float) $cart['discount'] > 0 && $couponCode !== null) {
        $couponStmt = $pdo->prepare('SELECT * FROM coupons WHERE code = :code FOR UPDATE');
        $couponStmt->execute(['code' => $couponCode]);
        $coupon = $couponStmt->fetch();
        if ($coupon === false) {
            throw new RuntimeException('cupom sumiu entre aplicar e fechar o pedido');
        }
        $pdo->prepare(
            'INSERT INTO coupon_redemptions (coupon_id, order_id, cpf, amount)
             VALUES (:coupon_id, :order_id, :cpf, :amount)'
        )->execute([
            'coupon_id' => $coupon['id'],
            'order_id' => $cart['id'],
            'cpf' => $customerCpf,
            'amount' => $cart['discount'],
        ]);
        $pdo->prepare('UPDATE coupons SET spent = spent + :amount WHERE id = :id')
            ->execute(['amount' => $cart['discount'], 'id' => $coupon['id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$order = fetch_order($pdo, (int) $cart['id']);
json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, (int) $cart['id']),
]);
