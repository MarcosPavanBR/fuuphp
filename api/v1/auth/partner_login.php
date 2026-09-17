<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$body = read_json_body();

$kind = $body['kind'] ?? null;
if (!in_array($kind, ['restaurant', 'courier'], true)) {
    error_response(422, 'invalid_kind', 'Informe kind: restaurant ou courier.');
}

$loginCode = isset($body['login_code']) ? only_digits((string) $body['login_code']) : '';
$secret = (string) ($body['secret'] ?? '');
$deviceId = isset($body['device_id']) ? trim((string) $body['device_id']) : null;

if ($kind === 'restaurant' && !is_valid_cnpj($loginCode)) {
    error_response(422, 'invalid_cnpj', 'CNPJ inválido.', fields: ['login_code' => 'inválido']);
}
if ($kind === 'courier' && !is_valid_cpf($loginCode)) {
    error_response(422, 'invalid_cpf', 'CPF inválido.', fields: ['login_code' => 'inválido']);
}
if ($secret === '') {
    error_response(422, 'secret_required', $kind === 'restaurant' ? 'Senha obrigatória.' : 'Código obrigatório.');
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT pa.*, u.role, u.blocked FROM partner_accounts pa
     JOIN users u ON u.id = pa.user_id
     WHERE pa.kind = :kind AND pa.login_code = :login_code'
);
$stmt->execute(['kind' => $kind, 'login_code' => $loginCode]);
$account = $stmt->fetch();

if ($account === false) {
    error_response(401, 'invalid_credentials', 'Credenciais inválidas.');
}
if ($account['blocked']) {
    error_response(403, 'user_blocked', 'Conta bloqueada. Fale com o suporte.');
}

$secretOk = $kind === 'restaurant'
    ? password_verify($secret, (string) ($account['password_hash'] ?? ''))
    : hash_equals((string) ($account['access_code_hash'] ?? ''), hash('sha256', $secret));

if (!$secretOk) {
    error_response(401, 'invalid_credentials', 'Credenciais inválidas.');
}

// 2FA por aparelho, na forma simples que o esquema suporta: confiança no
// primeiro uso. Troca de aparelho exige reset manual (suporte/admin) --
// um fluxo de re-verificação automática não está especificado.
if ($deviceId !== null) {
    if ($account['device_id'] === null) {
        $pdo->prepare('UPDATE partner_accounts SET device_id = :d WHERE id = :id')
            ->execute(['d' => $deviceId, 'id' => $account['id']]);
    } elseif ($account['device_id'] !== $deviceId) {
        error_response(403, 'device_mismatch', 'Este login está vinculado a outro aparelho. Peça ao suporte para liberar a troca.');
    }
}

$extraClaims = ['kind' => $kind];
if ($kind === 'restaurant') {
    $extraClaims['restaurant_id'] = $account['restaurant_id'];
} else {
    $extraClaims['courier_id'] = $account['courier_id'];
}

$tokens = issue_tokens(
    $pdo,
    (string) $account['user_id'],
    (string) $account['role'],
    extraClaims: $extraClaims,
    deviceLabel: $deviceId,
    ip: client_ip()
);

json_response(200, [
    'user_id' => $account['user_id'],
    'role' => $account['role'],
    'kind' => $kind,
    ...$tokens,
]);
