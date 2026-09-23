<?php
declare(strict_types=1);

// Código de uso único (OTP) do login do cliente (tela 10.2): 6 dígitos,
// guardado só como hash, com prazo, limite de tentativas por código e limite
// de pedidos de código por janela -- o que impede adivinhar por força bruta e
// usar o envio de SMS como arma contra um número.

const OTP_TTL_SECONDS = 5 * 60;
const OTP_MAX_ATTEMPTS = 5;
const OTP_MAX_REQUESTS_PER_WINDOW = 3;
const OTP_REQUEST_WINDOW_MINUTES = 10;

/**
 * Código de 6 dígitos com gerador criptográfico (zeros à esquerda contam).
 */
function generate_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Hash do código pro banco: o código em claro nunca é guardado.
 */
function hash_otp(string $code): string
{
    return hash('sha256', $code);
}

/**
 * Quantos códigos esta pessoa pediu pra esta finalidade na janela atual
 * (limite de envio: protege o número e a conta de SMS).
 */
function otp_requests_in_window(PDO $pdo, string $userId, string $purpose): int
{
    $stmt = $pdo->prepare(
        "SELECT count(*) FROM otp_codes
         WHERE user_id = :user_id AND purpose = :purpose
           AND created_at > now() - (:minutes || ' minutes')::interval"
    );
    $stmt->execute(['user_id' => $userId, 'purpose' => $purpose, 'minutes' => OTP_REQUEST_WINDOW_MINUTES]);
    return (int) $stmt->fetchColumn();
}
