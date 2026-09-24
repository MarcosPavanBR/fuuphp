<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 2.1 — lojas aprovadas de uma cidade, abertas por padrão, com
// distância, nota, frete e tempo quando o app manda a posição (lat/lng).

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
$restaurants = $stmt->fetchAll();

// Nota, frete e tempo do card (tela 2.1): lib/catalog/restaurant_facts.php.
$facts = restaurant_card_facts($pdo, array_column($restaurants, 'id'), $lat, $lng);
foreach ($restaurants as &$r) {
    $r += $facts[$r['id']] ?? [];
}
unset($r);

// Os atalhos da Home: só as categorias que têm loja aprovada na cidade,
// abertas ou não, na ordem de STORE_CATEGORIES. Sem isso, a Home ofereceria
// atalho pra categoria vazia.
$catStmt = $pdo->prepare(
    'SELECT DISTINCT category FROM restaurants
      WHERE city_ibge_code = :city AND approved_at IS NOT NULL AND category IS NOT NULL'
);
$catStmt->execute(['city' => $cityIbge]);
$present = $catStmt->fetchAll(PDO::FETCH_COLUMN);
$categories = array_values(array_filter(STORE_CATEGORIES, static fn (string $c): bool => in_array($c, $present, true)));

json_response(200, ['restaurants' => $restaurants, 'categories' => $categories]);
