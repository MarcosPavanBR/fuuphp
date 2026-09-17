<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.2 — Adicionar cartão. Mesmo limite já documentado em
// payments/pay.php: sem MercadoPago.js real integrado neste ambiente, o
// "token" que chega aqui é o placeholder que CardForm.svelte manda (os
// dígitos do cartão), não uma tokenização de verdade -- funciona porque o
// backend está em MERCADOPAGO_MODE=fake. "Guardamos apenas bandeira e 4
// últimos dígitos" (mock): é literalmente tudo que este endpoint grava.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente salva cartão.');
}
$body = read_json_body();

$cardToken = $body['card_token'] ?? null;
if (!is_string($cardToken) || $cardToken === '') {
    error_response(422, 'card_token_required', 'Token do cartão ausente.', fields: ['card_token' => 'obrigatório']);
}
$kind = $body['kind'] ?? null;
if ($kind !== null && !in_array($kind, ['debit', 'credit'], true)) {
    error_response(422, 'invalid_kind', 'kind precisa ser debit ou credit.', fields: ['kind' => 'inválido']);
}

$pdo = db();

$userStmt = $pdo->prepare('SELECT email, mp_customer_id FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$user = $userStmt->fetch();

$mpCustomerId = $user['mp_customer_id'];
if ($mpCustomerId === null) {
    $customer = mp_create_customer((string) ($user['email'] ?? 'cliente@fuudelivery.com.br'));
    $mpCustomerId = $customer['mp_customer_id'];
    $pdo->prepare('UPDATE users SET mp_customer_id = :id WHERE id = :user_id')
        ->execute(['id' => $mpCustomerId, 'user_id' => $claims['sub']]);
}

$card = mp_create_card($mpCustomerId, $cardToken);

$countStmt = $pdo->prepare('SELECT count(*) FROM saved_cards WHERE user_id = :id');
$countStmt->execute(['id' => $claims['sub']]);
$isFirstCard = (int) $countStmt->fetchColumn() === 0;

try {
    $insert = $pdo->prepare(
        'INSERT INTO saved_cards (user_id, mp_card_id, brand, last4, kind, exp_month, exp_year, is_default)
         VALUES (:user_id, :mp_card_id, :brand, :last4, :kind, :exp_month, :exp_year, :is_default) RETURNING *'
    );
    $insert->execute([
        'user_id' => $claims['sub'],
        'mp_card_id' => $card['mp_card_id'],
        'brand' => $card['brand'],
        'last4' => $card['last4'],
        'kind' => $kind,
        'exp_month' => $card['exp_month'],
        'exp_year' => $card['exp_year'],
        'is_default' => pg_bool($isFirstCard),
    ]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'saved_cards_user_id_mp_card_id_key')) {
        error_response(409, 'card_already_saved', 'Esse cartão já está salvo.');
    }
    throw $e;
}

json_response(201, ['card' => $insert->fetch()]);
