<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$orderId = (int) ($body['order_id'] ?? 0);
$to = $body['to'] ?? null;
$reason = isset($body['reason']) ? (string) $body['reason'] : null;

if ($orderId <= 0 || !is_string($to)) {
    error_response(422, 'invalid_request', 'Informe order_id e to.');
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

authorize_order_access($order, $claims);

$role = $claims['role'];
// Quem pode pedir qual transição -- a legalidade da transição em si (de/para)
// continua só na função do banco; isto aqui é só "quem tem permissão de pedir".
$allowedTargetsByRole = [
    'customer' => ['cancelled'],
    // 'delivering' está aqui porque quem entrega a sacola em mãos é a loja:
    // o botão "Entregue ao motoboy" da tela 11.1 é dela, não do entregador.
    // Quando o app do entregador existir, ele ganha o mesmo alvo -- são duas
    // pessoas que podem registrar a mesma passagem de bastão.
    'restaurant_staff' => ['preparing', 'ready', 'delivering', 'cancelled', 'rejected'],
];
$allowed = $allowedTargetsByRole[$role] ?? [];
if (!in_array($to, $allowed, true)) {
    error_response(403, 'forbidden', "Esse papel não pode pedir a transição para \"{$to}\".");
}

$meta = $reason !== null ? ['reason' => $reason] : [];
if ($to === 'cancelled' && $reason !== null) {
    $pdo->prepare('UPDATE orders SET cancel_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $orderId]);
}
if ($to === 'rejected' && $reason !== null) {
    $pdo->prepare('UPDATE orders SET reject_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $orderId]);
}

// order_events.actor_kind não usa os mesmos rótulos de users.role
// (Especificação, Parte II §9: 'customer','store','courier','admin','system').
$actorKind = $role === 'restaurant_staff' ? 'store' : 'customer';

call_advance_order($pdo, $orderId, $to, (string) $claims['sub'], $actorKind, $meta);

json_response(200, ['order' => fetch_order($pdo, $orderId)]);
