<?php
declare(strict_types=1);

const OTP_TTL_SECONDS = 5 * 60;
const OTP_MAX_ATTEMPTS = 5;
const OTP_MAX_REQUESTS_PER_WINDOW = 3;
const OTP_REQUEST_WINDOW_MINUTES = 10;

function generate_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function hash_otp(string $code): string
{
    return hash('sha256', $code);
}

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
