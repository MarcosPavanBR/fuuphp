<?php
declare(strict_types=1);

/**
 * Exige um access token válido no header Authorization e devolve os claims
 * decodificados. Termina a requisição com 401 se ausente/inválido/expirado.
 */
function require_auth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        error_response(401, 'unauthorized', 'Token de acesso ausente.');
    }

    try {
        return Jwt::decode(substr($header, 7), jwt_secret());
    } catch (Throwable $e) {
        error_response(401, 'invalid_token', 'Token inválido ou expirado. Use /v1/auth/refresh.');
    }
}

function client_ip(): ?string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip === null) {
        return null;
    }
    return trim(explode(',', $ip)[0]);
}
