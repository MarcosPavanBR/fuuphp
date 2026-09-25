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
        $claims = Jwt::decode($rawToken, jwt_secret());
    } catch (Throwable $e) {
        error_response(401, 'invalid_token', 'Token inválido ou expirado. Use /v1/auth/refresh.');
    }
    // Token de propósito único (o ticket do acompanhamento ao vivo) nunca
    // vale como access token: é assinado com o mesmo segredo, e sem esta
    // linha um ticket que vazasse num log daria acesso a tudo por 5 min.
    if (isset($claims['purpose'])) {
        error_response(401, 'invalid_token', 'Token inválido ou expirado. Use /v1/auth/refresh.');
    }

    return $claims;
}

/** Validade do ticket do acompanhamento ao vivo (orders/track_ticket.php). */
const TRACK_TICKET_TTL_SECONDS = 300;

/**
 * Emite o ticket do acompanhamento ao vivo de UM pedido: EventSource, a API
 * nativa do navegador, não manda cabeçalho, então a credencial vai na URL --
 * e URL vai parar em log. Por isso não é o access token: é um JWT que só
 * abre orders/track.php, só desse pedido, só por 5 minutos (auditoria
 * SEG-03). Os claims de parceiro (restaurant_id, courier_id) vão junto,
 * porque authorize_order_access() olha pra eles.
 */
function issue_track_ticket(array $claims, int $orderId): string
{
    $ticket = array_intersect_key($claims, array_flip(['sub', 'role', 'kind', 'restaurant_id', 'courier_id']));
    $ticket['purpose'] = 'track';
    $ticket['order_id'] = $orderId;

    return Jwt::encode($ticket, jwt_secret(), TRACK_TICKET_TTL_SECONDS);
}

/**
 * Só pra orders/track.php: aceita o cabeçalho Authorization normal ou o
 * ?ticket= de issue_track_ticket() -- que precisa ser DESTE pedido. O access
 * token na URL (?token=) não é mais aceito.
 */
function require_track_access(int $orderId): array
{
    $ticket = $_GET['ticket'] ?? null;
    if (!is_string($ticket) || $ticket === '') {
        return require_auth();
    }
    try {
        $claims = Jwt::decode($ticket, jwt_secret());
    } catch (Throwable) {
        error_response(401, 'invalid_ticket', 'Ticket vencido ou inválido. Peça outro.');
    }
    if (($claims['purpose'] ?? null) !== 'track' || (int) ($claims['order_id'] ?? 0) !== $orderId) {
        error_response(401, 'invalid_ticket', 'Esse ticket não é deste pedido.');
    }

    return $claims;
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
