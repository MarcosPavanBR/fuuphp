<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$body = read_json_body();

$purpose = $body['purpose'] ?? null;
if (!in_array($purpose, ['login', 'signup', 'phone_verify'], true)) {
    error_response(422, 'invalid_purpose', 'Informe purpose: login, signup ou phone_verify.', fields: ['purpose' => 'obrigatório']);
}

$phone = isset($body['phone']) ? only_digits((string) $body['phone']) : null;
$email = isset($body['email']) ? trim((string) $body['email']) : null;

if ($phone === null && $email === null) {
    error_response(422, 'contact_required', 'Informe phone ou email.', fields: ['phone' => 'obrigatório (ou email)']);
}
if ($phone !== null && !is_valid_phone($phone)) {
    error_response(422, 'invalid_phone', 'Telefone inválido.', fields: ['phone' => 'inválido']);
}
if ($email !== null && !is_valid_email($email)) {
    error_response(422, 'invalid_email', 'E-mail inválido.', fields: ['email' => 'inválido']);
}

$pdo = db();

if ($phone !== null) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = :phone');
    $stmt->execute(['phone' => $phone]);
} else {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
}
$existingUserId = $stmt->fetchColumn();

if ($purpose === 'login') {
    if ($existingUserId === false) {
        // Erro sempre com saída (Especificação I.6): a ação possível é ir para o cadastro.
        error_response(404, 'user_not_found', 'Não encontramos essa conta. Cadastre-se para continuar.', detail: 'use purpose=signup');
    }
    $userId = (string) $existingUserId;
} else {
    // signup / phone_verify: cria o usuário agora, se ainda não existir.
    // full_name é obrigatório no esquema (LGPD: dado necessário para o cadastro).
    if ($existingUserId !== false) {
        $userId = (string) $existingUserId;
    } else {
        $fullName = trim((string) ($body['full_name'] ?? ''));
        if ($fullName === '') {
            error_response(422, 'full_name_required', 'Nome completo é obrigatório para cadastro.', fields: ['full_name' => 'obrigatório']);
        }
        $userId = uuid_v4();
        $pdo->prepare(
            'INSERT INTO users (id, role, full_name, phone, email) VALUES (:id, :role, :full_name, :phone, :email)'
        )->execute([
            'id' => $userId,
            'role' => 'customer',
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
        ]);
    }
}

if (otp_requests_in_window($pdo, $userId, $purpose) >= OTP_MAX_REQUESTS_PER_WINDOW) {
    error_response(429, 'otp_rate_limited', 'Muitos pedidos de código. Aguarde alguns minutos e tente de novo.');
}

$code = generate_otp_code();
$channel = $phone !== null ? 'sms' : 'email';

$pdo->prepare(
    'INSERT INTO otp_codes (user_id, channel, code_hash, purpose, expires_at)
     VALUES (:user_id, :channel, :code_hash, :purpose, now() + (:ttl || \' seconds\')::interval)'
)->execute([
    'user_id' => $userId,
    'channel' => $channel,
    'code_hash' => hash_otp($code),
    'purpose' => $purpose,
    'ttl' => OTP_TTL_SECONDS,
]);

// Envio real (SMS/WhatsApp/e-mail) é integração externa, fora deste módulo.
// Por ora, registra em log estruturado para quem for ligar o provedor depois.
error_log(sprintf('[%s] otp issued user=%s purpose=%s channel=%s', trace_id(), $userId, $purpose, $channel));

$response = [
    'sent' => true,
    'channel' => $channel,
    'expires_in' => OTP_TTL_SECONDS,
];

// Nunca em produção: sem isso, testar o fluxo localmente exigiria ler o
// banco a cada chamada. O gate por APP_ENV é a única coisa que impede isso
// de vazar um código real.
if (env('APP_ENV', 'development') !== 'production') {
    $response['dev_code'] = $code;
}

json_response(201, $response);
