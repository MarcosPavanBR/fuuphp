<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 14.1 — "cada um abre um fluxo automático antes de chamar gente".
//
// Este é o fluxo automático: a resposta sai do estado real do pedido de
// quem perguntou. Só lê -- abrir chamado é outra rota, e só depois disto.

require_method('GET');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'A central de ajuda é do cliente.');
}

$topic = $_GET['topic'] ?? '';
$valid = array_column(SUPPORT_TOPICS, 'code');
if (!in_array($topic, $valid, true)) {
    error_response(422, 'invalid_topic', 'Escolha um dos atalhos da ajuda.', fields: ['topic' => 'inválido']);
}

$pdo = db();

// O assunto é o pedido em andamento por padrão, mas a tela pode apontar
// para outro (a lista de pedidos, por exemplo) -- e aí a dona do pedido
// continua sendo checada.
$order = null;
if (isset($_GET['order_id'])) {
    $order = fetch_order($pdo, (positive_id($_GET['order_id']) ?? 0));
    if ($order === null) {
        error_response(404, 'order_not_found', 'Pedido não encontrado.');
    }
    authorize_order_access($order, $claims);

    $nameStmt = $pdo->prepare('SELECT name FROM restaurants WHERE id = :id');
    $nameStmt->execute(['id' => $order['restaurant_id']]);
    $order['restaurant_name'] = $nameStmt->fetchColumn();
} else {
    $order = support_subject_order($pdo, (string) $claims['sub']);
}

$answer = support_auto_answer($pdo, (string) $topic, $order);

json_response(200, [
    'topic' => $topic,
    'sla_minutes' => SUPPORT_SLA_MINUTES[$topic],
    'answer' => $answer,
    'order' => $order === null ? null : [
        'id' => $order['id'],
        'public_code' => $order['public_code'],
        'status' => $order['status'],
        'restaurant_name' => $order['restaurant_name'] ?? null,
    ],
]);
