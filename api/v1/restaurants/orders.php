<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja acessa a fila de pedidos.');
}

$restaurantId = $_GET['id'] ?? '';
if ($restaurantId === '' || $restaurantId !== ($claims['restaurant_id'] ?? null)) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}

$pdo = db();
// Mesmo filtro do índice orders_kds_idx (db/migrations/004): só o que a
// cozinha precisa ver agora.
$stmt = $pdo->prepare(
    "SELECT id, public_code, status, subtotal, delivery_fee, surge_fee, tip, discount,
            total, payment_method, created_at
     FROM orders
     WHERE restaurant_id = :id AND status IN ('paid','preparing','ready')
     ORDER BY created_at ASC"
);
$stmt->execute(['id' => $restaurantId]);

json_response(200, ['orders' => $stmt->fetchAll()]);
