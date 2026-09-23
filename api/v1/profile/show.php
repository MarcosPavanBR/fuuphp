<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 2.5 — o perfil do cliente: dados da conta (CPF só mascarado) e os
// números do topo (pedidos, cupons; pontos ainda sem tabela).

require_method('GET');
$claims = require_auth();

$pdo = db();

$userStmt = $pdo->prepare('SELECT id, full_name, email, phone, cpf, birth_date, created_at FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$user = $userStmt->fetch();
if ($user === false) {
    error_response(404, 'user_not_found', 'Usuário não encontrado.');
}
$cpf = $user['cpf'];
unset($user['cpf']); // não devolve CPF completo numa tela de perfil (minimização de dado, LGPD)
// Só o miolo, no formato que a Receita usa pra mascarar: "***.456.789-**".
// Basta pra pessoa reconhecer o próprio documento na tela "Editar perfil".
$user['cpf_masked'] = is_string($cpf) && strlen($cpf) === 11
    ? '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**'
    : null;

$ordersCountStmt = $pdo->prepare('SELECT count(*) FROM orders WHERE user_id = :id AND status <> :cart');
$ordersCountStmt->execute(['id' => $claims['sub'], 'cart' => 'cart']);
$ordersCount = (int) $ordersCountStmt->fetchColumn();

// Cupons resgatados: coupon_redemptions só guarda CPF, não user_id -- só
// dá pra contar se o usuário tiver CPF cadastrado (nem todo cadastro por
// OTP de telefone pede CPF). Pontos: o saldo do livro de fidelidade (2.3,
// migração 029).
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
        'loyalty_points' => loyalty_balance($pdo, (string) $claims['sub']),
    ],
]);
