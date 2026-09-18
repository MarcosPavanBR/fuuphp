<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.1 — "Sem entregador disponível".
//
// "O momento que mais gera ticket e ninguém desenha: em vez de 'aguarde',
// três saídas concretas e a promessa escrita de cancelamento automático com
// devolução integral."
//
// Esta rota é a tela inteira numa chamada: há quanto tempo procura, quando o
// cancelamento automático entra, e quais saídas estão disponíveis PARA ESTE
// pedido -- com o motivo quando alguma não está.

require_method('GET');
$claims = require_auth();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'id_required', 'Informe id do pedido.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

$policy = policy_for_order($pdo, $order);
$timeout = (int) ($policy['no_courier_timeout_seconds'] ?? 900);

$waitingSince = $order['no_courier_since'];
$waitingSeconds = $waitingSince === null ? 0 : max(0, time() - strtotime((string) $waitingSince));
$searching = $order['courier_id'] === null
    && $order['status'] === 'ready'
    && $order['pickup_by_customer'] !== true
    && $waitingSince !== null;

// "Adicionar R$ 4,00 ao frete": no mock quem paga o turbo é o cliente. Isso
// só é honesto quando o dinheiro ainda não andou -- em pedido já pago,
// cobrar mais exigiria uma segunda transação no Mercado Pago, que este
// módulo não faz. Então a opção existe de verdade pra dinheiro e maquininha,
// e vem desabilitada com o motivo escrito nos outros.
$payOnDelivery = in_array((string) $order['payment_method'], ['cash', 'pos_machine'], true);

$restaurantStmt = $pdo->prepare('SELECT name, lat, lng FROM restaurants WHERE id = :id');
$restaurantStmt->execute(['id' => $order['restaurant_id']]);
$restaurant = $restaurantStmt->fetch();

// "· 1,2 km de você": a distância decide, sozinha, se a pessoa sai de casa --
// então ou é a real ou não aparece. restaurants.lat/lng é nullable desde a
// migração 010, e endereço do cliente sem coordenada não existe (addresses
// exige lat/lng NOT NULL); quando a loja não tem a dela, a tela mostra a
// retirada sem distância em vez de um número inventado.
$distance = null;
if ($order['address_id'] !== null && $restaurant !== false && $restaurant['lat'] !== null) {
    // Haversine em SQL: a alternativa seria trazer as duas coordenadas e
    // fazer a conta em PHP, o que dá o mesmo número com mais código.
    $distStmt = $pdo->prepare(
        'SELECT round((2 * 6371 * asin(sqrt(
             power(sin(radians(a.lat - :rlat) / 2), 2) +
             cos(radians(:rlat2)) * cos(radians(a.lat)) *
             power(sin(radians(a.lng - :rlng) / 2), 2)
         )))::numeric, 1)
         FROM addresses a WHERE a.id = :address_id'
    );
    $distStmt->execute([
        'rlat' => $restaurant['lat'],
        'rlat2' => $restaurant['lat'],
        'rlng' => $restaurant['lng'],
        'address_id' => $order['address_id'],
    ]);
    $value = $distStmt->fetchColumn();
    $distance = $value === false || $value === null ? null : (float) $value;
}

$refund = refund_plan($order, $policy, 'no_courier');

json_response(200, [
    'searching' => $searching,
    'waiting_seconds' => $waitingSeconds,
    // "Está mais difícil que o normal" aparece depois de um terço do prazo:
    // antes disso é só o tempo normal de achar alguém.
    'harder_than_usual' => $searching && $waitingSeconds > $timeout / 3,
    'auto_cancel_in' => $searching ? max(0, $timeout - $waitingSeconds) : null,
    'timeout_seconds' => $timeout,
    'order' => [
        'id' => $order['id'],
        'public_code' => $order['public_code'],
        'status' => $order['status'],
        'total' => $order['total'],
        'delivery_fee' => $order['delivery_fee'],
        'surge_fee' => $order['surge_fee'],
        'payment_method' => $order['payment_method'],
        'pickup_by_customer' => $order['pickup_by_customer'],
        'courier_id' => $order['courier_id'],
        // "Pizzaria Nonna · pronto desde 20:12" -- a hora do cabeçalho é o
        // mesmo carimbo que faz o relógio correr.
        'ready_since' => $order['no_courier_since'],
        'restaurant_name' => $restaurant === false ? null : $restaurant['name'],
    ],
    'options' => [
        'boost' => [
            'available' => $searching && $payOnDelivery,
            'amount' => 4.00,
            'reason' => $payOnDelivery
                ? null
                : 'Esse pedido já foi pago — cobrar a mais exigiria uma nova transação, que este módulo não faz.',
        ],
        'pickup' => [
            'available' => $searching,
            'refund' => round((float) $order['delivery_fee'] + (float) $order['surge_fee'], 2),
            'distance_km' => $distance,
        ],
        'cancel' => [
            'available' => $searching,
            // Cancelar aqui é falha nossa: devolução integral, sem taxa, e a
            // comida já feita sai do nosso bolso -- não do da loja.
            'refund' => $refund['amount'],
            'payer' => $refund['payer'],
            'how' => $refund['how'],
            'eta' => $refund['eta'],
        ],
    ],
]);
