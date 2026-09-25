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
//
// O carrinho guarda QUAL cupom foi aplicado (orders.coupon_id, migração
// 044), e o checkout confere tudo de novo com ele -- não com um código que o
// app mande na hora de fechar. As regras moram em lib/ordering/coupons.php
// (coupon_rejection, coupon_discount), as mesmas nos dois momentos.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente usa cupom.');
}
$body = read_json_body();

$restaurantId = is_string($body['restaurant_id'] ?? null) ? $body['restaurant_id'] : '';
$code = strtoupper(body_text($body, 'code', 40) ?? '');

if (!is_valid_uuid($restaurantId)) {
    error_response(422, 'restaurant_id_required', 'Informe um restaurant_id válido.', fields: ['restaurant_id' => 'obrigatório (uuid)']);
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
    $pdo->prepare('UPDATE orders SET discount = 0, coupon_id = NULL WHERE id = :id')->execute(['id' => $cart['id']]);
    json_response(200, ['cart' => fetch_order($pdo, (int) $cart['id']), 'coupon' => null]);
}

$userStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$cpf = $userStmt->fetchColumn();
if (!is_string($cpf) || $cpf === '') {
    error_response(409, 'cpf_required', 'Cupom exige CPF no cadastro — é um uso por CPF, não por conta.');
}

$coupon = fetch_coupon($pdo, $code);
if ($coupon === null) {
    error_response(404, 'coupon_not_found', 'Cupom inválido ou fora do prazo.');
}
$rejection = coupon_rejection($pdo, $coupon, $cart, (string) $cpf, (string) $claims['sub']);
if ($rejection !== null) {
    error_response(...$rejection);
}

// free_delivery: no carrinho ainda não há endereço, então não há frete pra
// descontar -- o desconto sai 0 aqui e é aplicado no checkout, sobre o frete
// calculado (orders/checkout.php). A resposta avisa com `applies_at`.
$discount = coupon_discount($coupon, (float) $cart['subtotal']);

$pdo->prepare('UPDATE orders SET discount = :d, coupon_id = :c WHERE id = :id')
    ->execute(['d' => $discount, 'c' => $coupon['id'], 'id' => $cart['id']]);

json_response(200, [
    'cart' => fetch_order($pdo, (int) $cart['id']),
    'coupon' => [
        'code' => $coupon['code'],
        'kind' => $coupon['kind'],
        'discount' => $discount,
        'applies_at' => $coupon['kind'] === 'free_delivery' ? 'checkout' : 'cart',
    ],
]);
