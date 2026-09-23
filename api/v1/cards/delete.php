<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.2 — remove um cartão salvo, aqui e no Mercado Pago (Customer/Card).

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$cardId = (int) ($body['id'] ?? 0);
if ($cardId <= 0) {
    error_response(422, 'id_required', 'Informe id.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT sc.*, u.mp_customer_id FROM saved_cards sc JOIN users u ON u.id = sc.user_id
     WHERE sc.id = :id AND sc.user_id = :user_id'
);
$stmt->execute(['id' => $cardId, 'user_id' => $claims['sub']]);
$card = $stmt->fetch();
if ($card === false) {
    error_response(404, 'card_not_found', 'Cartão não encontrado.');
}

mp_delete_card((string) $card['mp_customer_id'], (string) $card['mp_card_id']);
$pdo->prepare('DELETE FROM saved_cards WHERE id = :id')->execute(['id' => $cardId]);

json_response(200, ['deleted' => true]);
