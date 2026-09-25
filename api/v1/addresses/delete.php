<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.1 — apaga um endereço do cliente logado.
//
// Endereço já usado em pedido é ARQUIVADO, não apagado (migração 043): some
// da lista e do checkout, mas o pedido continua apontando pra ele -- é o
// histórico de pra onde a comida foi. Antes a rota respondia 409
// address_in_use e o cliente ficava com o endereço velho na lista pra
// sempre. Endereço nunca usado é apagado de verdade.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$addressId = positive_id($body['id'] ?? null);
if ($addressId === null) {
    error_response(422, 'id_required', 'Informe id.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :user_id AND archived_at IS NULL');
$stmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
if ($stmt->fetchColumn() === false) {
    error_response(404, 'address_not_found', 'Endereço não encontrado.');
}

if (address_in_use($pdo, $addressId)) {
    $pdo->prepare('UPDATE addresses SET archived_at = now(), is_default = false WHERE id = :id')
        ->execute(['id' => $addressId]);
    json_response(200, ['deleted' => true, 'archived' => true]);
}

try {
    $pdo->prepare('DELETE FROM addresses WHERE id = :id')->execute(['id' => $addressId]);
} catch (PDOException $e) {
    // Corrida rara: um checkout usou o endereço entre a conferência e o
    // DELETE. A chave estrangeira segura; arquiva no lugar.
    if ($e->getCode() === '23503') {
        $pdo->prepare('UPDATE addresses SET archived_at = now(), is_default = false WHERE id = :id')
            ->execute(['id' => $addressId]);
        json_response(200, ['deleted' => true, 'archived' => true]);
    }
    throw $e;
}

json_response(200, ['deleted' => true, 'archived' => false]);
