<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 5 — um pedido com itens, linha do tempo (order_events) e a avaliação,
// se houver. Cliente vê só o próprio; loja, só os da loja.

require_method('GET');
$claims = require_auth();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'id_required', 'Informe ?id= com o número do pedido.');
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

authorize_order_access($order, $claims);

$reviewStmt = $pdo->prepare('SELECT * FROM reviews WHERE order_id = :id');
$reviewStmt->execute(['id' => $orderId]);
$review = $reviewStmt->fetch();

json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, $orderId),
    'events' => fetch_order_events($pdo, $orderId),
    // null se ainda não avaliado -- a Fase 5.5 usa isto pra não oferecer
    // "Avaliar pedido" de novo (reviews.order_id é UNIQUE, uma tentativa
    // repetida já dá 409 no backend, mas não faz sentido nem mostrar o
    // botão quando já existe).
    'review' => $review === false ? null : $review,
]);
