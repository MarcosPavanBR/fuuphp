<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$claims = require_auth();

$restaurantId = $_GET['restaurant_id'] ?? null;
if (!is_string($restaurantId) || $restaurantId === '') {
    error_response(422, 'restaurant_id_required', 'Informe ?restaurant_id=.');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT * FROM orders WHERE user_id = :user_id AND restaurant_id = :restaurant_id AND status = 'cart'"
);
$stmt->execute(['user_id' => $claims['sub'], 'restaurant_id' => $restaurantId]);
$cart = $stmt->fetch();

if ($cart === false) {
    json_response(200, ['order' => null, 'items' => []]);
}

json_response(200, [
    'order' => $cart,
    'items' => fetch_order_items($pdo, (int) $cart['id']),
]);
