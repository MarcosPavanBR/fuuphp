<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');

$cityIbge = $_GET['city_ibge_code'] ?? null;
if (!is_string($cityIbge) || $cityIbge === '') {
    error_response(422, 'city_ibge_code_required', 'Informe ?city_ibge_code=.');
}

$category = $_GET['category'] ?? null;
$openOnly = ($_GET['open_only'] ?? '1') !== '0';
$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;

$pdo = db();

// Distancia por Haversine só quando cliente manda lat/lng E a loja tem
// coordenadas cadastradas (restaurants.lat/lng, migração 010) -- sem os
// dois lados, a coluna vem NULL e o front decide como mostrar isso.
$distanceExpr = ($lat !== null && $lng !== null)
    ? 'CASE WHEN r.lat IS NOT NULL AND r.lng IS NOT NULL THEN
         round((6371 * acos(least(1.0, greatest(-1.0,
           cos(radians(:lat)) * cos(radians(r.lat)) * cos(radians(r.lng) - radians(:lng))
           + sin(radians(:lat)) * sin(radians(r.lat))
         ))))::numeric, 1)
       ELSE NULL END'
    : 'NULL';

// `paused` sai junto com `is_open` porque são coisas diferentes pra quem
// lê: loja fechada volta amanhã, loja pausada volta em minutos.
$sql = "SELECT r.id, r.name, r.category, r.logo_key, r.is_open,
               (r.pause_until IS NOT NULL AND r.pause_until > now()) AS paused,
               {$distanceExpr} AS distance_km
        FROM restaurants r
        WHERE r.city_ibge_code = :city_ibge_code
          AND r.approved_at IS NOT NULL";

$params = ['city_ibge_code' => $cityIbge];
if ($lat !== null && $lng !== null) {
    $params['lat'] = $lat;
    $params['lng'] = $lng;
}
if ($openOnly) {
    // Tela 11.2: "pausa esconde a loja do app e para novos pedidos". Sem a
    // segunda linha, a loja pausada continuava na lista de "abertos agora" e
    // o cliente só descobria no checkout, com o pedido montado.
    $sql .= ' AND r.is_open = true';
    $sql .= ' AND (r.pause_until IS NULL OR r.pause_until <= now())';
}
if (is_string($category) && $category !== '') {
    $sql .= ' AND r.category = :category';
    $params['category'] = $category;
}
$sql .= ($lat !== null && $lng !== null)
    ? ' ORDER BY distance_km NULLS LAST, r.name'
    : ' ORDER BY r.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

json_response(200, ['restaurants' => $stmt->fetchAll()]);
