<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.1 — Endereços salvos. "CRUD com endereço padrão." Edição parcial
// (só os campos mandados mudam) e troca de padrão: marcar um endereço
// como is_default=true desmarca os outros do mesmo usuário na mesma
// transação -- nunca dois padrões ao mesmo tempo, sem depender de UNIQUE
// parcial no banco (a tabela não tem essa constraint, é regra de aplicação).

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$addressId = (int) ($body['id'] ?? 0);
if ($addressId <= 0) {
    error_response(422, 'id_required', 'Informe id.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT * FROM addresses WHERE id = :id AND user_id = :user_id');
$existingStmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
$existing = $existingStmt->fetch();
if ($existing === false) {
    error_response(404, 'address_not_found', 'Endereço não encontrado.');
}

$fields = ['label', 'street', 'number', 'complement', 'reference', 'neighborhood', 'city', 'city_ibge_code', 'state', 'postal_code', 'lat', 'lng'];
$updates = [];
foreach ($fields as $field) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = $body[$field];
    }
}
if (isset($updates['postal_code'])) {
    $postalCode = only_digits((string) $updates['postal_code']);
    if (strlen($postalCode) !== 8) {
        error_response(422, 'invalid_postal_code', 'CEP inválido.', fields: ['postal_code' => 'inválido']);
    }
    $updates['postal_code'] = $postalCode;
}
if (isset($updates['state'])) {
    $state = strtoupper(trim((string) $updates['state']));
    if (strlen($state) !== 2) {
        error_response(422, 'invalid_state', 'UF inválida.', fields: ['state' => 'inválido']);
    }
    $updates['state'] = $state;
}

$makeDefault = array_key_exists('is_default', $body) && (bool) $body['is_default'];

$pdo->beginTransaction();
try {
    if ($updates !== []) {
        $setClauses = implode(', ', array_map(static fn (string $f) => "{$f} = :{$f}", array_keys($updates)));
        $stmt = $pdo->prepare("UPDATE addresses SET {$setClauses} WHERE id = :id");
        $stmt->execute([...$updates, 'id' => $addressId]);
    }
    if ($makeDefault) {
        $pdo->prepare('UPDATE addresses SET is_default = false WHERE user_id = :user_id AND id <> :id')
            ->execute(['user_id' => $claims['sub'], 'id' => $addressId]);
        $pdo->prepare('UPDATE addresses SET is_default = true WHERE id = :id')->execute(['id' => $addressId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$freshStmt = $pdo->prepare('SELECT * FROM addresses WHERE id = :id');
$freshStmt->execute(['id' => $addressId]);

json_response(200, ['address' => $freshStmt->fetch()]);
