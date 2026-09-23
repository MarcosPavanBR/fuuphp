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
    return decode_access_token(substr($header, 7));
}

/**
 * Valida o JWT de acesso (assinatura e prazo) e devolve as claims; token
 * ruim ou vencido encerra com 401.
 */
function decode_access_token(string $rawToken): array
{
    try {
        return Jwt::decode($rawToken, jwt_secret());
    } catch (Throwable $e) {
        error_response(401, 'invalid_token', 'Token inválido ou expirado. Use /v1/auth/refresh.');
    }
}

/**
 * Só pra orders/track.php (SSE, Fase 5.3): EventSource, a API nativa do
 * navegador, não deixa mandar headers customizados, então não tem como
 * usar Authorization: Bearer do jeito normal nesta rota. Aceita o token
 * por query string como exceção documentada -- só nesta função, só GET,
 * nunca numa rota que muda estado. Toda outra rota continua exigindo o
 * header.
 */
function require_auth_header_or_query(): array
{
    if (isset($_GET['token']) && is_string($_GET['token']) && $_GET['token'] !== '') {
        return decode_access_token($_GET['token']);
    }
    return require_auth();
}

/**
 * Exige que quem está chamando seja a equipe de uma loja, e devolve o
 * restaurant_id do token -- o mesmo padrão de require_courier() (Fase 8).
 *
 * O id vem do TOKEN, nunca do corpo da requisição: é o que impede uma loja
 * de pausar, editar cardápio ou mudar horário da loja vizinha.
 */
function require_store_staff(array $claims): string
{
    if (($claims['role'] ?? null) !== 'restaurant_staff') {
        error_response(403, 'forbidden', 'Só a equipe da loja acessa esta parte.');
    }
    $restaurantId = $claims['restaurant_id'] ?? null;
    if ($restaurantId === null) {
        error_response(403, 'forbidden', 'Esse login não está vinculado a uma loja.');
    }
    db_scope_to_restaurant((string) $restaurantId);

    return (string) $restaurantId;
}

/**
 * IP de quem chamou, pra prova de consentimento e registro de sessão.
 *
 * Só REMOTE_ADDR: X-Forwarded-For vem do cliente e qualquer um escreve o que
 * quiser nele. Atrás do Cloudflare, quem acerta REMOTE_ADDR é o Nginx
 * (real_ip_header CF-Connecting-IP, confiando só nas faixas do Cloudflare --
 * deploy/nginx/fuuphp.conf).
 */
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    return $ip === null || $ip === '' ? null : $ip;
}
