<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.7 / 8.1 — login de parceiro: loja com CNPJ + senha, entregador com
// CPF + código de acesso. Devolve o token com o papel e o vínculo
// (restaurant_id / courier_id) que os guardas de cada rota conferem.
//
// Limite de tentativas (migração 032): 5 erros no mesmo login ou 30 no mesmo
// IP em 15 minutos dão 429, conferidos ANTES da senha -- senão o bloqueio
// ainda deixaria descobrir a certa. Sem isso, o código de acesso do
// entregador (6 dígitos) cairia por força bruta.

const PARTNER_LOGIN_MAX_FAILURES = 5;
const PARTNER_LOGIN_MAX_FAILURES_PER_IP = 30;
const PARTNER_LOGIN_WINDOW_MINUTES = 15;

require_method('POST');
$body = read_json_body();

$kind = $body['kind'] ?? null;
if (!in_array($kind, ['restaurant', 'courier'], true)) {
    error_response(422, 'invalid_kind', 'Informe kind: restaurant ou courier.');
}

$loginCode = isset($body['login_code']) ? only_digits(input_str($body, 'login_code')) : '';
$secret = input_str($body, 'secret');
$deviceId = body_text($body, 'device_id', 100);

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
$ip = client_ip();

$failures = $pdo->prepare(
    "SELECT count(*) FILTER (WHERE kind = :kind AND login_code = :login_code),
            count(*) FILTER (WHERE :ip::inet IS NOT NULL AND ip = :ip2::inet)
       FROM partner_login_failures
      WHERE created_at > now() - make_interval(mins => :window)"
);
$failures->execute([
    'kind' => $kind, 'login_code' => $loginCode, 'ip' => $ip, 'ip2' => $ip,
    'window' => PARTNER_LOGIN_WINDOW_MINUTES,
]);
[$loginFailures, $ipFailures] = array_map('intval', $failures->fetch(PDO::FETCH_NUM));
if ($loginFailures >= PARTNER_LOGIN_MAX_FAILURES || $ipFailures >= PARTNER_LOGIN_MAX_FAILURES_PER_IP) {
    error_response(429, 'login_locked',
        'Muitas tentativas erradas. Espere ' . PARTNER_LOGIN_WINDOW_MINUTES . ' minutos ou fale com o suporte.');
}

/** Registra o erro e responde 401 -- conta inexistente e senha errada são a mesma resposta. */
$refuse = static function () use ($pdo, $kind, $loginCode, $ip): never {
    $pdo->prepare('INSERT INTO partner_login_failures (kind, login_code, ip) VALUES (:k, :l, :ip)')
        ->execute(['k' => $kind, 'l' => $loginCode, 'ip' => $ip]);
    error_response(401, 'invalid_credentials', 'Credenciais inválidas.');
};

$stmt = $pdo->prepare(
    'SELECT pa.*, u.role, u.blocked FROM partner_accounts pa
     JOIN users u ON u.id = pa.user_id
     WHERE pa.kind = :kind AND pa.login_code = :login_code'
);
$stmt->execute(['kind' => $kind, 'login_code' => $loginCode]);
$account = $stmt->fetch();

if ($account === false) {
    $refuse();
}
if ($account['blocked']) {
    error_response(403, 'user_blocked', 'Conta bloqueada. Fale com o suporte.');
}

// Loja: senha em bcrypt. Entregador: código de acesso em bcrypt desde a
// migração 034; o formato antigo (SHA-256 em hex, 64 caracteres) ainda é
// aceito e trocado por bcrypt no primeiro acerto, logo abaixo.
$storedCode = (string) ($account['access_code_hash'] ?? '');
$legacyCode = $kind === 'courier' && preg_match('/^[0-9a-f]{64}$/', $storedCode) === 1;
$secretOk = match (true) {
    $kind === 'restaurant' => password_verify($secret, (string) ($account['password_hash'] ?? '')),
    $legacyCode => hash_equals($storedCode, hash('sha256', $secret)),
    default => password_verify($secret, $storedCode),
};

if (!$secretOk) {
    $refuse();
}
// Acertou: os erros anteriores deste login não contam mais.
$pdo->prepare('DELETE FROM partner_login_failures WHERE kind = :k AND login_code = :l')
    ->execute(['k' => $kind, 'l' => $loginCode]);
if ($legacyCode) {
    $pdo->prepare('UPDATE partner_accounts SET access_code_hash = :h WHERE id = :id')
        ->execute(['h' => password_hash($secret, PASSWORD_DEFAULT), 'id' => $account['id']]);
}

// 2FA por aparelho, na forma simples que o esquema suporta: confiança no
// primeiro uso. A troca de aparelho é liberada pelo suporte, na aba
// Aparelhos do painel da plataforma (admin/partner_devices.php).
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
    ip: $ip
);

json_response(200, [
    'user_id' => $account['user_id'],
    'role' => $account['role'],
    'kind' => $kind,
    ...$tokens,
]);
