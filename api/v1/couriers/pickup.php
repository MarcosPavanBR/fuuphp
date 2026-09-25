<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Telas 8.3 e 8.4 — "Cheguei" grava evento com carimbo de GPS, e a coleta
// mostra o troco calculado pelo servidor.
//
// "O troco é calculado pelo servidor e mostrado em corpo grande — é o erro
// mais comum do delivery em dinheiro." Por isso a conta do troco sai daqui,
// não da tela: change_for - total, com o total que é coluna gerada no banco.
//
// Nada disto muda o status do pedido: chegar na loja e pegar a sacola são
// eventos da corrida, não transições de `orders.status` -- quem faz
// ready -> delivering é a loja, quando entrega a sacola em mãos (tela 11.1).

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);
$body = read_json_body();

$orderId = (int) ($body['order_id'] ?? 0);
$event = $body['event'] ?? null;
if ($orderId <= 0 || !in_array($event, ['arrived_at_store', 'picked_up'], true)) {
    error_response(422, 'invalid_request', 'Informe order_id e event (arrived_at_store ou picked_up).');
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
if ($order['courier_id'] !== $courierId) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

$meta = ['event' => $event];
foreach (['lat' => 90, 'lng' => 180] as $coord => $limit) {
    $value = coord_input($body[$coord] ?? null, $limit);
    if ($value !== null) {
        $meta[$coord] = $value;
    }
}

// order_events guarda a trilha da corrida sem mexer no status: from_status e
// to_status ficam iguais, e o `meta` carrega o que aconteceu. É a mesma
// tabela que a linha do tempo do cliente lê -- um lugar só pra história do
// pedido, não duas.
$pdo->prepare(
    'INSERT INTO order_events (order_id, from_status, to_status, actor_id, actor_kind, meta)
     VALUES (:order_id, :status, :status, :actor, :kind, :meta::jsonb)'
)->execute([
    'order_id' => $orderId,
    'status' => $order['status'],
    'actor' => $claims['sub'],
    'kind' => 'courier',
    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
]);

$change = null;
if ($order['payment_method'] === 'cash' && $order['change_for'] !== null) {
    $change = round((float) $order['change_for'] - (float) $order['total'], 2);
}

// A loja tem coordenada, mas não tem endereço nem telefone no esquema (só
// `addresses` modela logradouro, e ela é do cliente). A tela 8.3 mostra
// "telefone da loja" e o endereço escrito -- o app mostra o que existe
// (nome e ponto no mapa) em vez de inventar campo. Registrado no README.
$restaurantStmt = $pdo->prepare('SELECT name, lat, lng FROM restaurants WHERE id = :id');
$restaurantStmt->execute(['id' => $order['restaurant_id']]);

json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, $orderId),
    'restaurant' => $restaurantStmt->fetch(),
    // Troco em corpo grande na tela: a conta é daqui, não do app.
    'change_due' => $change,
]);
