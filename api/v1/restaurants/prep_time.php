<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.2 — "Tempo de preparo informado ao cliente".
//
// Até a migração 018 esse número não existia: a previsão de entrega que o
// cliente lê era uma janela fixa escrita no front (25–45 min), que ninguém
// na loja tinha como corrigir num dia de cozinha cheia.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$minutes = isset($body['prep_minutes']) ? (int) $body['prep_minutes'] : null;
$autoBump = $body['prep_auto_bump'] ?? null;

if ($minutes === null && $autoBump === null) {
    error_response(422, 'nothing_to_change', 'Informe prep_minutes e/ou prep_auto_bump.');
}
// O mesmo intervalo do CHECK da coluna: o banco recusaria de qualquer jeito,
// mas com 500 em vez de uma mensagem que explica.
if ($minutes !== null && ($minutes < 5 || $minutes > 180)) {
    error_response(422, 'invalid_prep_minutes', 'Tempo de preparo vai de 5 a 180 minutos.', fields: ['prep_minutes' => 'inválido']);
}
if ($autoBump !== null && !is_bool($autoBump)) {
    error_response(422, 'invalid_auto_bump', 'prep_auto_bump é true ou false.', fields: ['prep_auto_bump' => 'inválido']);
}

$pdo = db();
$sets = [];
$params = ['id' => $restaurantId];
if ($minutes !== null) {
    $sets[] = 'prep_minutes = :m';
    $params['m'] = $minutes;
}
if ($autoBump !== null) {
    $sets[] = 'prep_auto_bump = :b';
    $params['b'] = $autoBump ? 'true' : 'false';
}

$stmt = $pdo->prepare(
    'UPDATE restaurants SET ' . implode(', ', $sets) .
    ' WHERE id = :id RETURNING prep_minutes, prep_auto_bump'
);
$stmt->execute($params);

json_response(200, ['store' => $stmt->fetch()]);
