<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// O carrinho do cliente numa loja (um pedido em status 'cart' por loja).
//
//   GET ?restaurant_id=<uuid>  o carrinho daquela loja (Fase 3, tela da loja)
//   GET (sem parâmetro)         o carrinho com item mais recente, de qualquer
//                               loja -- é o que a aba "Carrinho" da barra
//                               inferior abre (Fase 2, BottomNav.svelte)

require_method('GET');
$claims = require_auth();

$restaurantId = $_GET['restaurant_id'] ?? null;
if ($restaurantId !== null && (!is_string($restaurantId) || $restaurantId === '')) {
    error_response(422, 'restaurant_id_required', 'restaurant_id inválido.');
}

$pdo = db();
if ($restaurantId !== null) {
    $stmt = $pdo->prepare(
        "SELECT * FROM orders WHERE user_id = :user_id AND restaurant_id = :restaurant_id AND status = 'cart'"
    );
    $stmt->execute(['user_id' => $claims['sub'], 'restaurant_id' => $restaurantId]);
} else {
    // Só carrinho com item: carrinho vazio não é "o seu carrinho".
    $stmt = $pdo->prepare(
        "SELECT o.* FROM orders o
          WHERE o.user_id = :user_id AND o.status = 'cart'
            AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)
          ORDER BY (SELECT max(i.id) FROM order_items i WHERE i.order_id = o.id) DESC
          LIMIT 1"
    );
    $stmt->execute(['user_id' => $claims['sub']]);
}
$cart = $stmt->fetch();

if ($cart === false) {
    json_response(200, ['order' => null, 'items' => []]);
}

json_response(200, [
    'order' => $cart,
    'items' => fetch_order_items($pdo, (int) $cart['id']),
]);
