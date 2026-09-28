<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Lojas favoritas do cliente: o coração no card da Home e o atalho
// "Favoritas" (migração 037).
//
// GET   {restaurant_ids: [...]}  as favoritas, da mais recente pra mais antiga
// POST  {restaurant_id, favorite: true|false}  marca ou desmarca (idempotente:
//       marcar duas vezes não duplica, desmarcar o que não está não falha)
//
// Só loja aprovada entra: favoritar é atalho pra pedir. Dado pessoal: sai no
// "baixar meus dados" e é apagado no "excluir conta" (account_privacy.php).

$claims = require_auth();
$userId = (string) $claims['sub'];
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT f.restaurant_id FROM favorite_restaurants f
           JOIN restaurants r ON r.id = f.restaurant_id AND r.approved_at IS NOT NULL
          WHERE f.user_id = :u ORDER BY f.created_at DESC'
    );
    $stmt->execute(['u' => $userId]);
    json_response(200, ['restaurant_ids' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

require_method('POST');
$body = read_json_body();
$restaurantId = input_str($body, 'restaurant_id');
$favorite = $body['favorite'] ?? null;
if (!is_valid_uuid($restaurantId) || !is_bool($favorite)) {
    error_response(422, 'invalid_request', 'Informe restaurant_id e favorite (true ou false).');
}

if ($favorite) {
    $store = $pdo->prepare('SELECT 1 FROM restaurants WHERE id = :id AND approved_at IS NOT NULL');
    $store->execute(['id' => $restaurantId]);
    if ($store->fetchColumn() === false) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }
    $pdo->prepare(
        'INSERT INTO favorite_restaurants (user_id, restaurant_id) VALUES (:u, :r) ON CONFLICT DO NOTHING'
    )->execute(['u' => $userId, 'r' => $restaurantId]);
} else {
    $pdo->prepare('DELETE FROM favorite_restaurants WHERE user_id = :u AND restaurant_id = :r')
        ->execute(['u' => $userId, 'r' => $restaurantId]);
}

json_response(200, ['restaurant_id' => $restaurantId, 'favorite' => $favorite]);
