<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 9.3, lado da loja — o que está esperando conferência no caixa e o que
// já foi baixado hoje.
//
// Não devolve o código (nem poderia: o banco só tem o hash). O atendente
// digita o que está na tela do entregador; esta lista existe pra ele saber
// QUEM está esperando e QUANTO foi declarado, que é o número que ele vai
// conferir contra o dinheiro em mãos.

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja vê o caixa.');
}
$restaurantId = $claims['restaurant_id'] ?? null;
if ($restaurantId === null) {
    error_response(403, 'forbidden', 'Esse login não está vinculado a uma loja.');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT csi.id, csi.amount, csi.method, csi.state, csi.expires_at,
            csi.counted_amount, csi.confirmed_at, csi.created_at,
            u.full_name AS courier_name
     FROM cash_settlement_intents csi
     JOIN couriers c ON c.id = csi.courier_id
     JOIN users u ON u.id = c.user_id
     WHERE csi.restaurant_id = :id
       AND (csi.state = 'open' OR csi.created_at >= now()::date)
     ORDER BY (csi.state = 'open') DESC, csi.created_at DESC
     LIMIT 30"
);
$stmt->execute(['id' => $restaurantId]);

json_response(200, ['settlements' => $stmt->fetchAll()]);
