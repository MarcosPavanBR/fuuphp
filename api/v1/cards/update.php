<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Só is_default muda depois de salvo -- os outros campos do cartão vêm
// direto do Mercado Pago no momento da criação e não fazem sentido editar
// por aqui (mudar a validade de um cartão salvo não é uma operação real).

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$cardId = positive_id($body['id'] ?? null) ?? 0;
if ($cardId <= 0) {
    error_response(422, 'id_required', 'Informe id.', fields: ['id' => 'obrigatório']);
}
if (!array_key_exists('is_default', $body) || !$body['is_default']) {
    error_response(422, 'is_default_required', 'Só é possível marcar um cartão como padrão por aqui.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM saved_cards WHERE id = :id AND user_id = :user_id');
$stmt->execute(['id' => $cardId, 'user_id' => $claims['sub']]);
if ($stmt->fetchColumn() === false) {
    error_response(404, 'card_not_found', 'Cartão não encontrado.');
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE saved_cards SET is_default = false WHERE user_id = :user_id AND id <> :id')
        ->execute(['user_id' => $claims['sub'], 'id' => $cardId]);
    $pdo->prepare('UPDATE saved_cards SET is_default = true WHERE id = :id')->execute(['id' => $cardId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$freshStmt = $pdo->prepare('SELECT * FROM saved_cards WHERE id = :id');
$freshStmt->execute(['id' => $cardId]);

json_response(200, ['card' => $freshStmt->fetch()]);
