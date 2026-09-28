<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 14.3 — "A área de cobertura é validada no servidor e a taxa aparece
// antes de salvar — não na hora de pagar."
//
// Serve dois momentos:
//   • cadastrando endereço (lat/lng ainda não salvos): diz se alguma loja da
//     cidade cobre aquele ponto, e qual a tarifa da política;
//   • já com loja escolhida (restaurant_id): diz a taxa exata daquele
//     endereço para aquela loja -- o MESMO cálculo que o checkout vai refazer.

require_method('GET');
$claims = require_auth();

$pdo = db();

// Ponto: ou o endereço salvo (que precisa ser de quem perguntou), ou um par
// lat/lng que a tela ainda está montando.
$address = null;
if (isset($_GET['address_id'])) {
    $stmt = $pdo->prepare('SELECT id, lat, lng, city_ibge_code FROM addresses WHERE id = :id AND user_id = :uid AND archived_at IS NULL');
    $stmt->execute(['id' => positive_id($_GET['address_id']) ?? 0, 'uid' => $claims['sub']]);
    $address = $stmt->fetch();
    if ($address === false) {
        error_response(404, 'address_not_found', 'Endereço não encontrado.');
    }
} else {
    $lat = $_GET['lat'] ?? null;
    $lng = $_GET['lng'] ?? null;
    if (!is_number_between($lat, -90, 90) || !is_number_between($lng, -180, 180)) {
        error_response(422, 'point_required', 'Informe address_id, ou lat e lng.', fields: ['lat' => 'obrigatório', 'lng' => 'obrigatório']);
    }
    $address = [
        'lat' => (float) $lat,
        'lng' => (float) $lng,
        'city_ibge_code' => is_valid_ibge($_GET['city_ibge_code'] ?? null) ? $_GET['city_ibge_code'] : null,
    ];
}

$restaurantId = $_GET['restaurant_id'] ?? null;

if ($restaurantId !== null && $restaurantId !== '') {
    if (!is_string($restaurantId) || !is_valid_uuid($restaurantId)) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }
    $restStmt = $pdo->prepare('SELECT id, name, lat, lng, prep_minutes, prep_auto_bump FROM restaurants WHERE id = :id');
    $restStmt->execute(['id' => $restaurantId]);
    $restaurant = $restStmt->fetch();
    if ($restaurant === false) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }

    $policy = resolve_policy($pdo, (string) $restaurantId);
    $quote = delivery_quote($restaurant, $address, $policy);

    // Tempo até chegar, pela mesma conta do card da Home (restaurant_facts):
    // preparo efetivo (com a fila da cozinha) + viagem à velocidade média.
    // Sem distância não há estimativa -- a tela não inventa uma.
    $etaMinutes = $quote['distance_km'] === null ? null
        : effective_prep_minutes($pdo, (string) $restaurantId, $restaurant)['effective']
          + (int) ceil((float) $quote['distance_km'] / DELIVERY_AVG_KMH * 60);

    json_response(200, [
        'scope' => 'restaurant',
        'restaurant_name' => $restaurant['name'],
        'quote' => $quote,
        'eta_minutes' => $etaMinutes,
        'tariff' => [
            'base' => $policy['delivery_base_fee'],
            'per_km' => $policy['delivery_per_km'],
            'max_km' => $policy['delivery_max_km'],
        ],
    ]);
}

// Sem loja escolhida: a pergunta vira "esse endereço é atendido?". A resposta
// honesta é quantas lojas da cidade alcançam aquele ponto -- "dentro da área
// de entrega" sem dizer de quem é a área não quer dizer nada.
if (!is_string($address['city_ibge_code']) || $address['city_ibge_code'] === '') {
    error_response(422, 'city_required', 'Informe city_ibge_code pra saber se o endereço é atendido.', fields: ['city_ibge_code' => 'obrigatório']);
}

// A tarifa mostrada é a da cidade: a da plataforma com as exceções da praça
// (aba Políticas). O raio de cada loja vem de resolve_policy(), que soma a
// exceção da própria loja -- a mesma conta que o checkout vai fazer.
$policy = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
if ($policy !== false) {
    $policy = array_merge($policy, city_policy_patch($pdo, (string) $address['city_ibge_code']));
}
$maxKm = $policy === false || $policy['delivery_max_km'] === null ? null : (float) $policy['delivery_max_km'];

$storeStmt = $pdo->prepare(
    'SELECT id, name, lat, lng FROM restaurants
      WHERE city_ibge_code = :city AND approved_at IS NOT NULL'
);
$storeStmt->execute(['city' => $address['city_ibge_code']]);
$stores = $storeStmt->fetchAll();

// O raio de cada loja (com a exceção dela) numa passada só.
try {
    $storePolicies = resolve_policies($pdo, array_map('strval', array_column($stores, 'id')));
} catch (RuntimeException) {
    $storePolicies = []; // sem política cadastrada: fica o raio da cidade
}

$covering = 0;
$nearest = null;
foreach ($stores as $store) {
    if ($store['lat'] === null || $store['lng'] === null) {
        // Loja sem coordenada não conta como cobertura nem como fora dela:
        // não dá pra medir, e chutar qualquer um dos dois lados seria pior.
        continue;
    }
    $km = haversine_km(
        (float) $store['lat'],
        (float) $store['lng'],
        (float) $address['lat'],
        (float) $address['lng']
    );
    if ($nearest === null || $km < $nearest['distance_km']) {
        $nearest = ['name' => $store['name'], 'distance_km' => round($km, 1)];
    }
    $storePolicy = $storePolicies[(string) $store['id']] ?? null;
    $storeMaxKm = $storePolicy === null
        ? $maxKm
        : ($storePolicy['delivery_max_km'] === null ? null : (float) $storePolicy['delivery_max_km']);
    if ($storeMaxKm === null || $km <= $storeMaxKm) {
        $covering++;
    }
}

json_response(200, [
    'scope' => 'city',
    'stores_in_city' => count($stores),
    'stores_covering' => $covering,
    'in_area' => $covering > 0,
    'nearest' => $nearest,
    'tariff' => [
        'base' => $policy === false ? 0 : (float) $policy['delivery_base_fee'],
        'per_km' => $policy === false ? 0 : (float) $policy['delivery_per_km'],
        'max_km' => $maxKm,
    ],
]);
