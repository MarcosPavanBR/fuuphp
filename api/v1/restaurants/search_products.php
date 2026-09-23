<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 2.2 — busca de produto por nome nas lojas abertas da cidade (índice
// trigram), cada resultado com nota, frete e tempo da loja pros filtros.

require_method('GET');

$cityIbge = $_GET['city_ibge_code'] ?? null;
$query = trim((string) ($_GET['q'] ?? ''));

if (!is_string($cityIbge) || $cityIbge === '') {
    error_response(422, 'city_ibge_code_required', 'Informe ?city_ibge_code=.');
}
if (mb_strlen($query) < 2) {
    error_response(422, 'query_too_short', 'Digite ao menos 2 caracteres para buscar.');
}

$pdo = db();

// ILIKE '%...%' usa o índice GIN trigram de menu_items.name (migração 010)
// em vez de varrer a tabela inteira -- é o "PostgreSQL trigram" que a
// própria tela 2.2 cita no chip de tecnologia.
$stmt = $pdo->prepare(
    "SELECT mi.id, mi.name, mi.price, mi.photo_key,
            r.id AS restaurant_id, r.name AS restaurant_name
     FROM menu_items mi
     JOIN restaurants r ON r.id = mi.restaurant_id
     WHERE r.city_ibge_code = :city_ibge_code
       AND r.approved_at IS NOT NULL
       AND r.is_open = true
       AND (r.pause_until IS NULL OR r.pause_until <= now())
       AND mi.available = true
       AND mi.name ILIKE '%' || :query || '%'
     ORDER BY mi.name
     LIMIT 50"
);
$stmt->execute(['city_ibge_code' => $cityIbge, 'query' => $query]);
$products = $stmt->fetchAll();

// Os filtros da tela 2.2 (Entrega grátis / Até 30 min / 4,5+) são sobre a
// LOJA do produto: cada resultado leva os números da loja dele, calculados
// como no card da Home (lib/catalog/restaurant_facts.php). Com ?lat&lng, frete e
// tempo são do lugar de quem busca.
$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float) $_GET['lng'] : null;
$facts = restaurant_card_facts($pdo, array_values(array_unique(array_column($products, 'restaurant_id'))), $lat, $lng);
foreach ($products as &$p) {
    $f = $facts[$p['restaurant_id']] ?? [];
    $p['restaurant_rating'] = $f['rating'] ?? null;
    $p['delivery_fee'] = $f['delivery_fee'] ?? null;
    $p['eta_minutes'] = $f['eta_minutes'] ?? null;
}
unset($p);

json_response(200, ['products' => $products]);
