<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Essa listagem é para o próprio cliente. Loja usa /v1/restaurants/orders.');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT o.id, o.public_code, o.restaurant_id, r.name AS restaurant_name,
            o.status, o.total, o.payment_method, o.verification_deadline, o.created_at,
            (SELECT count(*) FROM order_items oi WHERE oi.order_id = o.id) AS items_count
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.user_id = :user_id
     ORDER BY o.created_at DESC LIMIT 50'
);
$stmt->execute(['user_id' => $claims['sub']]);

json_response(200, ['orders' => $stmt->fetchAll()]);
