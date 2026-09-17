<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

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
       AND r.is_open = true
       AND mi.available = true
       AND mi.name ILIKE '%' || :query || '%'
     ORDER BY mi.name
     LIMIT 50"
);
$stmt->execute(['city_ibge_code' => $cityIbge, 'query' => $query]);

json_response(200, ['products' => $stmt->fetchAll()]);
