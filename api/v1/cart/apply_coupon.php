<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Resgate de cupom no carrinho. A tabela `coupons` existe desde a migração
// 008 e o campo já estava na tela 3.3 esperando backend.
//
// O desconto entra em `orders.discount`, que é uma das parcelas da coluna
// gerada `total` -- então o carrinho recalcula sozinho, sem ninguém somar
// nada no PHP.
//
// A validação é toda do lado do servidor porque cada regra dessas é dinheiro:
//   - cupom ativo, dentro da janela de datas;
//   - da plataforma ou desta loja;
//   - pedido mínimo atingido (sobre o SUBTOTAL, não sobre o total: senão o
//     frete ajudaria a alcançar o mínimo, que é o oposto do que o cupom quer);
//   - orçamento não estourado (`within_budget` é CHECK no banco);
//   - um uso por CPF, não por conta -- é o que a UNIQUE de
//     coupon_redemptions garante, e é por isso que quem não tem CPF no
//     cadastro não consegue resgatar.
//
// A gravação em `coupon_redemptions` acontece só no checkout (é lá que existe
// pedido de verdade pra referenciar). Aqui é aplicação no carrinho, e o
// carrinho ainda pode ser abandonado -- reservar orçamento agora seria
// segurar dinheiro que ninguém gastou.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente usa cupom.');
}
$body = read_json_body();

$restaurantId = (string) ($body['restaurant_id'] ?? '');
$code = strtoupper(trim((string) ($body['code'] ?? '')));

if ($restaurantId === '') {
    error_response(422, 'restaurant_id_required', 'Informe restaurant_id.', fields: ['restaurant_id' => 'obrigatório']);
}

$pdo = db();

$cartStmt = $pdo->prepare(
    "SELECT * FROM orders WHERE user_id = :user_id AND restaurant_id = :restaurant_id AND status = 'cart'"
);
$cartStmt->execute(['user_id' => $claims['sub'], 'restaurant_id' => $restaurantId]);
$cart = $cartStmt->fetch();
if ($cart === false) {
    error_response(404, 'cart_not_found', 'Você não tem um carrinho aberto nessa loja.');
}

// Código vazio remove o cupom -- o mesmo endpoint desfaz, sem rota nova.
if ($code === '') {
    $pdo->prepare('UPDATE orders SET discount = 0 WHERE id = :id')->execute(['id' => $cart['id']]);
    json_response(200, ['cart' => fetch_order($pdo, (int) $cart['id']), 'coupon' => null]);
}

$userStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$cpf = $userStmt->fetchColumn();
if (!is_string($cpf) || $cpf === '') {
    error_response(409, 'cpf_required', 'Cupom exige CPF no cadastro — é um uso por CPF, não por conta.');
}

$couponStmt = $pdo->prepare(
    "SELECT * FROM coupons
     WHERE code = :code AND active
       AND starts_at <= now() AND (ends_at IS NULL OR ends_at > now())"
);
$couponStmt->execute(['code' => $code]);
$coupon = $couponStmt->fetch();
if ($coupon === false) {
    error_response(404, 'coupon_not_found', 'Cupom inválido ou fora do prazo.');
}
if ($coupon['restaurant_id'] !== null && $coupon['restaurant_id'] !== $restaurantId) {
    error_response(409, 'coupon_other_store', 'Esse cupom é de outra loja.');
}

$subtotal = (float) $cart['subtotal'];
if ($subtotal < (float) $coupon['min_order']) {
    error_response(409, 'coupon_min_order', sprintf(
        'Esse cupom vale a partir de R$ %s em itens.',
        number_format((float) $coupon['min_order'], 2, ',', '.')
    ));
}

if (coupon_used_by($pdo, (int) $coupon['id'], (string) $cpf, (string) $claims['sub'])) {
    error_response(409, 'coupon_already_used', 'Você já usou esse cupom.');
}

// O público da campanha (tela 15.3: primeiro pedido, inativos há 15/30 dias)
// era só usado pra PROJETAR o alcance -- qualquer pessoa resgatava. Agora é
// a mesma condição (lib/ordering/coupons.php) que conta o público e barra.
if (!coupon_audience_includes($pdo, $coupon, (string) $claims['sub'])) {
    error_response(409, 'coupon_audience', coupon_audience_message((string) $coupon['audience'], ($coupon['owner_user_id'] ?? null) !== null));
}

if ((float) $coupon['spent'] >= (float) $coupon['budget_cap']) {
    error_response(409, 'coupon_exhausted', 'Esse cupom acabou (orçamento da campanha esgotou).');
}

// free_delivery: no carrinho ainda não há endereço, então não há frete pra
// descontar -- o desconto sai 0 aqui e é aplicado no checkout, sobre o frete
// calculado (orders/checkout.php). A resposta avisa com `applies_at`.
$discount = match ($coupon['kind']) {
    'fixed' => (float) $coupon['value'],
    'percent' => round($subtotal * (float) $coupon['value'] / 100, 2),
    'free_delivery' => (float) $cart['delivery_fee'],
    default => 0.0,
};
// Desconto nunca passa do subtotal: cupom não vira crédito nem paga o frete
// de quem não pediu nada.
$discount = min($discount, $subtotal);

$pdo->prepare('UPDATE orders SET discount = :d WHERE id = :id')
    ->execute(['d' => $discount, 'id' => $cart['id']]);

json_response(200, [
    'cart' => fetch_order($pdo, (int) $cart['id']),
    'coupon' => [
        'code' => $coupon['code'],
        'kind' => $coupon['kind'],
        'discount' => $discount,
        'applies_at' => $coupon['kind'] === 'free_delivery' ? 'checkout' : 'cart',
    ],
]);
