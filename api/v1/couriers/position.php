<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

/*
 * POST /v1/couriers/position.php — onde o entregador está agora.
 *
 * Especificação, Parte II §7: "A posição do entregador é escrita a cada
 * 15 s, então não pode ficar junto do histórico: vive em courier_positions,
 * tabela UNLOGGED com uma linha por entregador (UPSERT, sem histórico)."
 *
 * É esta posição que decide quem enxerga uma corrida em cada rodada do
 * despacho (lib/dispatch.php): o raio cresce a partir da loja, e só entra
 * quem está dentro dele. Só vale com turno aberto -- fora do turno não há
 * motivo pra saber onde a pessoa está.
 */

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);
$body = read_json_body();

$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;
if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
    error_response(422, 'invalid_position', 'Latitude e longitude inválidas.', fields: ['lat' => 'inválido', 'lng' => 'inválido']);
}

$pdo = db();
$shift = $pdo->prepare('SELECT 1 FROM courier_shifts WHERE courier_id = :id AND ended_at IS NULL');
$shift->execute(['id' => $courierId]);
if ($shift->fetchColumn() === false) {
    error_response(409, 'no_open_shift', 'Posição só é registrada com o turno aberto.');
}

$pdo->prepare(
    'INSERT INTO courier_positions (courier_id, lat, lng, heading, updated_at)
     VALUES (:id, :lat, :lng, :heading, now())
     ON CONFLICT (courier_id) DO UPDATE
       SET lat = EXCLUDED.lat, lng = EXCLUDED.lng, heading = EXCLUDED.heading, updated_at = now()'
)->execute([
    'id' => $courierId,
    'lat' => round((float) $lat, 6),
    'lng' => round((float) $lng, 6),
    'heading' => isset($body['heading']) && is_numeric($body['heading']) ? round((float) $body['heading'], 1) : null,
]);

json_response(200, ['ok' => true, 'fresh_seconds' => COURIER_POSITION_FRESH_SECONDS]);
