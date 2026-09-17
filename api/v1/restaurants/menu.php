<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$restaurantId = $_GET['id'] ?? '';
if (!is_string($restaurantId) || $restaurantId === '') {
    error_response(422, 'id_required', 'Informe ?id=.');
}

$pdo = db();

// Item esgotado vem na lista, marcado como indisponível -- desabilitado no
// servidor, não escondido (Especificação, Fase 3, tela 3.1: "item esgotado
// desabilitado no servidor, não escondido"). Esconder mudaria o cardápio
// sob os pés do cliente, o que é pior do que mostrar cinza.
$itemsStmt = $pdo->prepare(
    'SELECT id, name, description, price, category, photo_key, position, available
     FROM menu_items
     WHERE restaurant_id = :id
     ORDER BY category, position, name'
);
$itemsStmt->execute(['id' => $restaurantId]);
$items = $itemsStmt->fetchAll();

if ($items === []) {
    json_response(200, ['items' => []]);
}

$itemIds = array_column($items, 'id');
$placeholders = implode(',', array_fill(0, count($itemIds), '?'));
$variantsStmt = $pdo->prepare(
    "SELECT id, menu_item_id, group_name, name, price_delta, max_selections, required, position
     FROM item_variants WHERE menu_item_id IN ($placeholders) ORDER BY menu_item_id, position"
);
$variantsStmt->execute($itemIds);

$variantsByItem = [];
foreach ($variantsStmt->fetchAll() as $variant) {
    $variantsByItem[$variant['menu_item_id']][] = $variant;
}

foreach ($items as &$item) {
    $item['variants'] = $variantsByItem[$item['id']] ?? [];
}
unset($item);

json_response(200, ['items' => $items]);
