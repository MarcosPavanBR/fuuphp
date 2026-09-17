<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

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

json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, $orderId),
]);
