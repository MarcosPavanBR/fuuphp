<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 5.3 — Tracking em tempo real. "O aviso chega por SSE alimentado por
// LISTEN/NOTIFY do PostgreSQL." advance_order() já faz PERFORM
// pg_notify('order_changed', order_id) a cada transição (migração 004);
// este endpoint só escuta.
//
// Conexão SSE deliberadamente CURTA (25s, não pra sempre): o servidor
// embutido do PHP (php -S), usado neste ambiente de desenvolvimento,
// processa uma requisição PHP por vez -- segurar uma conexão aberta
// indefinidamente travaria o resto da API pra todo mundo. O EventSource
// do navegador reconecta sozinho quando a conexão cai (comportamento
// nativo do protocolo SSE), então isto funciona como long-poll encadeado:
// nenhum evento se perde, porque cada reconexão manda um snapshot
// completo primeiro. Em produção, atrás de PHP-FPM com múltiplos workers,
// o mesmo código aguentaria uma janela bem maior sem esse limite ser
// necessário -- o limite existe pela limitação deste ambiente de teste,
// não da técnica em si.

require_method('GET');

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'id_required', 'Informe ?id= com o número do pedido.');
}
// Credencial: ?ticket= de orders/track_ticket.php (o EventSource não manda
// cabeçalho) ou o Authorization normal. O access token na URL não vale mais.
$claims = require_track_access($orderId);

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
while (ob_get_level() > 0) {
    ob_end_clean();
}

function sse_send(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

function sse_snapshot(PDO $pdo, int $orderId): void
{
    $order = fetch_order($pdo, $orderId);
    sse_send('order_update', [
        'order' => $order,
        'events' => fetch_order_events($pdo, $orderId),
    ]);
}

sse_snapshot($pdo, $orderId);

$raw = raw_pg_connect();
pg_query($raw, 'LISTEN order_changed');

$deadline = time() + 25;
$lastHeartbeat = time();
while (time() < $deadline) {
    if (connection_aborted()) {
        break;
    }

    $notify = pg_get_notify($raw, PGSQL_ASSOC);
    if ($notify !== false && (string) ($notify['payload'] ?? '') === (string) $orderId) {
        sse_snapshot($pdo, $orderId);
    }

    // O heartbeat não é só pra manter proxy acordado: connection_aborted()
    // do PHP só vira true depois de uma escrita que falha, então é ELE que
    // faz o servidor perceber que o cliente foi embora. A cada 2s (em vez de
    // 8) um cliente que fechou a aba libera o processo quatro vezes mais
    // rápido -- o que importa de verdade sob `php -S`, que atende uma
    // requisição por vez. São ~30 bytes por ping.
    if (time() - $lastHeartbeat >= 2) {
        sse_send('heartbeat', ['ts' => time()]);
        $lastHeartbeat = time();
    }

    usleep(400000);
}

pg_close($raw);
