<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$addressId = (int) ($body['id'] ?? 0);
if ($addressId <= 0) {
    error_response(422, 'id_required', 'Informe id.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :user_id');
$stmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
if ($stmt->fetchColumn() === false) {
    error_response(404, 'address_not_found', 'Endereço não encontrado.');
}

try {
    $pdo->prepare('DELETE FROM addresses WHERE id = :id')->execute(['id' => $addressId]);
} catch (PDOException $e) {
    // orders.address_id não tem ON DELETE -- apagar um endereço usado por
    // um pedido já existente é bloqueado pela própria FK (é histórico,
    // não pode sumir por baixo do pedido).
    if ($e->getCode() === '23503') {
        error_response(409, 'address_in_use', 'Esse endereço já foi usado num pedido e não pode ser apagado.');
    }
    throw $e;
}

json_response(200, ['deleted' => true]);
