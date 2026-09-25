<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.2 — confere o código OTP e devolve access token (15 min) e refresh
// token (30 dias, com rotação). Código tem prazo e limite de tentativas; conta
// bloqueada ou excluída (LGPD, migração 026) não entra.

require_method('POST');
$body = read_json_body();

$purpose = $body['purpose'] ?? null;
if (!in_array($purpose, ['login', 'signup', 'phone_verify'], true)) {
    error_response(422, 'invalid_purpose', 'Informe purpose: login, signup ou phone_verify.');
}

$code = isset($body['code']) ? only_digits((string) $body['code']) : '';
if (strlen($code) !== 6) {
    error_response(422, 'invalid_code', 'Código deve ter 6 dígitos.', fields: ['code' => 'inválido']);
}

$phone = isset($body['phone']) ? only_digits((string) $body['phone']) : null;
$email = isset($body['email']) ? trim((string) $body['email']) : null;
if ($phone === null && $email === null) {
    error_response(422, 'contact_required', 'Informe phone ou email.');
}

$pdo = db();

$userStmt = $phone !== null
    ? $pdo->prepare('SELECT id, role, blocked FROM users WHERE phone = :v')
    : $pdo->prepare('SELECT id, role, blocked FROM users WHERE email = :v');
$userStmt->execute(['v' => $phone ?? $email]);
$user = $userStmt->fetch();

if ($user === false) {
    error_response(404, 'user_not_found', 'Conta não encontrada.');
}
if ($user['blocked']) {
    error_response(403, 'user_blocked', 'Conta bloqueada. Fale com o suporte.');
}

$otpStmt = $pdo->prepare(
    'SELECT id, code_hash, attempts, expires_at FROM otp_codes
     WHERE user_id = :user_id AND purpose = :purpose AND consumed_at IS NULL
     ORDER BY created_at DESC LIMIT 1'
);
$otpStmt->execute(['user_id' => $user['id'], 'purpose' => $purpose]);
$otp = $otpStmt->fetch();

if ($otp === false) {
    error_response(404, 'otp_not_found', 'Nenhum código pendente. Peça um novo em /v1/auth/otp/request.');
}
if (strtotime((string) $otp['expires_at']) < time()) {
    error_response(410, 'otp_expired', 'Código expirado. Peça um novo.', detail: 'purpose=' . $purpose);
}
if ((int) $otp['attempts'] >= OTP_MAX_ATTEMPTS) {
    error_response(429, 'otp_locked', 'Muitas tentativas. Peça um novo código.');
}

if (!hash_equals((string) $otp['code_hash'], hash_otp($code))) {
    $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $otp['id']]);
    error_response(401, 'otp_invalid', 'Código incorreto.', detail: 'tentativas restantes: ' . (OTP_MAX_ATTEMPTS - (int) $otp['attempts'] - 1));
}

// Segundo fator do admin (migração 042, SEG-04): com ele confirmado, o SMS
// sozinho não entra. O código SMS certo NÃO é consumido enquanto falta o do
// autenticador -- o app pergunta e manda os dois de novo -- e cada código de
// autenticador errado conta no mesmo limite de tentativas do SMS.
if ($user['role'] === 'admin') {
    $totpStmt = $pdo->prepare('SELECT secret, last_step FROM admin_totp WHERE user_id = :u AND confirmed_at IS NOT NULL');
    $totpStmt->execute(['u' => $user['id']]);
    $totp = $totpStmt->fetch();
    if ($totp !== false) {
        $totpCode = isset($body['totp']) && is_scalar($body['totp']) ? only_digits((string) $body['totp']) : '';
        if ($totpCode === '') {
            error_response(401, 'totp_required', 'Digite também o código do app autenticador.');
        }
        $step = totp_verify((string) $totp['secret'], $totpCode, $totp['last_step'] === null ? null : (int) $totp['last_step']);
        if ($step === null) {
            $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $otp['id']]);
            error_response(401, 'totp_invalid', 'Código do autenticador incorreto.');
        }
        // Marca o passo usado só se ninguém usou esse (ou um mais novo) antes:
        // dois logins simultâneos com o mesmo código, um entra e o outro não.
        $mark = $pdo->prepare(
            'UPDATE admin_totp SET last_step = :s
              WHERE user_id = :u AND (last_step IS NULL OR last_step < :s)
              RETURNING 1'
        );
        $mark->execute(['s' => $step, 'u' => $user['id']]);
        if ($mark->fetchColumn() === false) {
            $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $otp['id']]);
            error_response(401, 'totp_invalid', 'Código do autenticador incorreto.');
        }
    }
}

$pdo->prepare('UPDATE otp_codes SET consumed_at = now() WHERE id = :id')->execute(['id' => $otp['id']]);

$tokens = issue_tokens(
    $pdo,
    (string) $user['id'],
    (string) $user['role'],
    deviceLabel: isset($body['device_label']) ? (string) $body['device_label'] : null,
    ip: client_ip()
);

json_response(200, [
    'user_id' => $user['id'],
    'role' => $user['role'],
    ...$tokens,
]);
