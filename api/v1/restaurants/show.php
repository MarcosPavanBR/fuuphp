<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$id = $_GET['id'] ?? '';
if (!is_string($id) || $id === '') {
    error_response(422, 'id_required', 'Informe ?id=.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, name, cnpj, city_ibge_code, is_open, pause_until, approved_at, prep_minutes FROM restaurants WHERE id = :id');
$stmt->execute(['id' => $id]);
$restaurant = $stmt->fetch();

if ($restaurant === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}

$hoursStmt = $pdo->prepare('SELECT dow, shift, opens, closes, last_order, active FROM business_hours WHERE restaurant_id = :id ORDER BY dow, shift');
$hoursStmt->execute(['id' => $id]);

// Tela 11.2 -> 5.3: a previsão que o cliente lê passa a ser o tempo que a
// loja informou, já com o acréscimo da fila quando ela ligou isso. Antes era
// uma janela fixa escrita no front, que ninguém na loja podia corrigir.
$prep = effective_prep_minutes($pdo, (string) $restaurant['id'], $restaurant);

json_response(200, [
    'restaurant' => $restaurant,
    'prep' => $prep,
    'business_hours' => $hoursStmt->fetchAll(),
]);
