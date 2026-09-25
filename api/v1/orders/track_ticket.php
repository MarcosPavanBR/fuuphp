<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Ticket do acompanhamento ao vivo (orders/track.php, SSE). O EventSource do
// navegador não manda cabeçalho, então a credencial vai na URL -- e URL vai
// parar em log (Nginx, Cloudflare, histórico). Em vez do access token, que
// abre tudo, vai este ticket: só abre o acompanhamento deste pedido, por 5
// minutos, e não vale como access token em rota nenhuma (auditoria SEG-03).
//
// POST {order_id}  ->  {ticket, expires_in}

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$orderId = is_int($body['order_id'] ?? null) || ctype_digit((string) ($body['order_id'] ?? ''))
    ? (int) $body['order_id']
    : 0;
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
// Mesma regra do acompanhamento: quem não pode ver o pedido não ganha ticket.
authorize_order_access($order, $claims);

json_response(200, ['ticket' => issue_track_ticket($claims, $orderId), 'expires_in' => TRACK_TICKET_TTL_SECONDS]);
