<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$body = read_json_body();

$rawRefresh = $body['refresh_token'] ?? null;
if (!is_string($rawRefresh) || $rawRefresh === '') {
    error_response(422, 'refresh_token_required', 'Informe refresh_token.', fields: ['refresh_token' => 'obrigatório']);
}

$pdo = db();

// A sessão não carrega o papel do usuário -- busca antes de rotacionar,
// pelo user_id que o refresh_hash aponta.
$lookup = $pdo->prepare(
    'SELECT u.role FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.refresh_hash = :h'
);
$lookup->execute(['h' => hash('sha256', $rawRefresh)]);
$role = $lookup->fetchColumn();

if ($role === false) {
    error_response(401, 'invalid_refresh_token', 'Sessão inválida. Faça login novamente.');
}

$tokens = rotate_refresh_token(
    $pdo,
    $rawRefresh,
    (string) $role,
    ip: client_ip()
);

json_response(200, $tokens);
