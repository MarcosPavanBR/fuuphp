<?php
declare(strict_types=1);

// TOTP (RFC 6238) pro segundo fator do admin (migração 042, auditoria SEG-04):
// HMAC-SHA1 do contador de 30 s, 6 dígitos -- o que Google Authenticator,
// Microsoft Authenticator, 2FAS e afins calculam. Sem biblioteca: é hash_hmac
// e aritmética.

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_ISSUER = 'FUUdelivery';

/** Segredo novo: 20 bytes aleatórios em base32 (32 caracteres), como os apps esperam. */
function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }

    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) {
            throw new InvalidArgumentException('base32 inválido');
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }

    return $out;
}

/** O código de um intervalo (step = unix time / 30). */
function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);

    return str_pad((string) ($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Confere o código com tolerância de um intervalo pra cada lado (relógio do
 * celular meio fora). Devolve o intervalo que bateu, ou null. Um intervalo
 * igual ou anterior a $lastStep não vale: o mesmo código não entra duas vezes.
 */
function totp_verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
{
    if (preg_match('/^\d{6}$/', $code) !== 1) {
        return null;
    }
    $current = intdiv($now ?? time(), TOTP_PERIOD);
    foreach ([0, -1, 1] as $delta) {
        $step = $current + $delta;
        if ($lastStep !== null && $step <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }

    return null;
}

/** otpauth:// que os apps entendem (colar, ou digitar o segredo à mão). */
function totp_uri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER . ':' . $account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode(TOTP_ISSUER)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}
