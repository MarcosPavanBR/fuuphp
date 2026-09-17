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
    'SELECT id, public_code, restaurant_id, status, total, payment_method, created_at
     FROM orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 50'
);
$stmt->execute(['user_id' => $claims['sub']]);

json_response(200, ['orders' => $stmt->fetchAll()]);
