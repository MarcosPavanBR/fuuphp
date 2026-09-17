<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$claims = require_auth();

$pdo = db();

$userStmt = $pdo->prepare('SELECT id, full_name, email, phone, cpf, created_at FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$user = $userStmt->fetch();
if ($user === false) {
    error_response(404, 'user_not_found', 'Usuário não encontrado.');
}
$cpf = $user['cpf'];
unset($user['cpf']); // não devolve CPF completo numa tela de perfil (minimização de dado, LGPD)

$ordersCountStmt = $pdo->prepare('SELECT count(*) FROM orders WHERE user_id = :id AND status <> :cart');
$ordersCountStmt->execute(['id' => $claims['sub'], 'cart' => 'cart']);
$ordersCount = (int) $ordersCountStmt->fetchColumn();

// Cupons resgatados: coupon_redemptions só guarda CPF, não user_id -- só
// dá pra contar se o usuário tiver CPF cadastrado (nem todo cadastro por
// OTP de telefone pede CPF). Pontos de fidelidade não têm tabela no
// esquema (Parte II não modela isso); fica null até existir decisão de
// produto sobre o sistema de pontos.
$couponsCount = 0;
if (is_string($cpf) && $cpf !== '') {
    $couponsStmt = $pdo->prepare('SELECT count(*) FROM coupon_redemptions WHERE cpf = :cpf');
    $couponsStmt->execute(['cpf' => $cpf]);
    $couponsCount = (int) $couponsStmt->fetchColumn();
}

json_response(200, [
    'user' => $user,
    'stats' => [
        'orders_count' => $ordersCount,
        'coupons_count' => $couponsCount,
        'loyalty_points' => null,
    ],
]);
