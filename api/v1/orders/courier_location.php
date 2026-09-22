<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 5.3 — o mapa da entrega: "1,4 km · 8 min", "Jonas está levando ·
// Honda Biz · placa QQP-1B34".
//
// Devolve os três pontos do mapa (loja, destino, entregador) e a distância
// e o tempo que faltam. A posição vem de `courier_positions`, que o app do
// entregador atualiza a cada 15 s durante o turno (couriers/position.php).
//
// PRIVACIDADE DO ENTREGADOR: a posição dele só sai daqui enquanto ESTE
// pedido está 'delivering' -- antes disso ele pode estar em qualquer lugar,
// fazendo outra corrida, e depois da entrega a pessoa não tem mais por que
// saber onde ele está. Nome, só o primeiro. Posição velha (sem sinal há mais
// de COURIER_POSITION_FRESH_SECONDS) volta marcada `stale`, e a tela diz
// "última posição" em vez de fingir que é ao vivo.

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

$points = $pdo->prepare(
    'SELECT r.lat AS store_lat, r.lng AS store_lng, r.name AS store_name,
            a.lat AS dest_lat, a.lng AS dest_lng
       FROM orders o
       JOIN restaurants r ON r.id = o.restaurant_id
       LEFT JOIN addresses a ON a.id = o.address_id
      WHERE o.id = :id'
);
$points->execute(['id' => $orderId]);
$p = $points->fetch();

$payload = [
    'status' => $order['status'],
    'store' => ['lat' => (float) $p['store_lat'], 'lng' => (float) $p['store_lng'], 'name' => $p['store_name']],
    'destination' => $p['dest_lat'] === null ? null : ['lat' => (float) $p['dest_lat'], 'lng' => (float) $p['dest_lng']],
    'courier' => null,
    'remaining_km' => null,
    'eta_minutes' => null,
];

if ($order['status'] === 'delivering' && $order['courier_id'] !== null) {
    $courier = $pdo->prepare(
        'SELECT u.full_name, ca.vehicle, ca.plate, pos.lat, pos.lng, pos.heading, pos.updated_at,
                EXTRACT(EPOCH FROM (now() - pos.updated_at)) AS age_seconds
           FROM couriers c
           JOIN users u ON u.id = c.user_id
           LEFT JOIN courier_applications ca ON ca.id = c.id
           LEFT JOIN courier_positions pos ON pos.courier_id = c.id
          WHERE c.id = :id'
    );
    $courier->execute(['id' => $order['courier_id']]);
    $c = $courier->fetch();

    if ($c !== false) {
        $hasPosition = $c['lat'] !== null;
        $payload['courier'] = [
            'first_name' => strtok((string) $c['full_name'], ' ') ?: 'Entregador',
            'vehicle' => $c['vehicle'],
            'plate' => $c['plate'],
            'position' => $hasPosition ? [
                'lat' => (float) $c['lat'],
                'lng' => (float) $c['lng'],
                'heading' => $c['heading'] === null ? null : (float) $c['heading'],
                'updated_at' => $c['updated_at'],
                'stale' => (float) $c['age_seconds'] > COURIER_POSITION_FRESH_SECONDS,
            ] : null,
        ];

        // Distância em linha reta até o destino, e tempo à velocidade média
        // declarada (a mesma do push "chega em torno de"). É estimativa: não
        // há roteamento por ruas neste projeto.
        if ($hasPosition && $payload['destination'] !== null) {
            $km = haversine_km((float) $c['lat'], (float) $c['lng'], $payload['destination']['lat'], $payload['destination']['lng']);
            $payload['remaining_km'] = round($km, 1);
            $payload['eta_minutes'] = max(1, (int) ceil($km / DELIVERY_AVG_KMH * 60));
        }
    }
}

json_response(200, $payload);
