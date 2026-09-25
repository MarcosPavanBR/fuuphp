<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Aba Cidades do painel da plataforma: onde o FUU opera (migração 036).
// Ligar uma cidade é o que faz ela aparecer no onboarding do cliente
// (1.2/1.3) e no cadastro de loja.
//
// GET   todas as cidades (ligadas e desligadas), com as lojas aprovadas.
// POST  {ibge_code, name, uf, lat, lng, neighborhoods[], timezone?, active?}
//       cria ou atualiza pela chave ibge_code (o código IBGE do município).
//       Sem `active`: cidade nova nasce ligada e a existente fica como está
//       (editar bairros não religa uma cidade desligada). Sem `timezone`,
//       vale o fuso da UF (migração 038): é o relógio das lojas da cidade.
//
// Desligar não apaga nada: some do app, lojas e pedidos continuam. Toda
// mudança vai pro audit_log com o valor anterior.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_response(200, ['cities' => service_cities($pdo, activeOnly: false), 'timezones' => CITY_TIMEZONES]);
}

require_method('POST');
$body = read_json_body();

$ibge = only_digits((string) ($body['ibge_code'] ?? ''));
$name = body_text($body, 'name', 100) ?? '';
$uf = strtoupper(body_text($body, 'uf', 2) ?? '');
$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;
$timezone = body_text($body, 'timezone', 60) ?? '';
$activeGiven = array_key_exists('active', $body);
$active = ($body['active'] ?? true) !== false;
$neighborhoods = $body['neighborhoods'] ?? [];
if (is_string($neighborhoods)) {
    $neighborhoods = explode(',', $neighborhoods);
}
$neighborhoods = is_array($neighborhoods)
    ? array_values(array_unique(array_filter(array_map(
        static fn ($n): string => mb_substr(trim((string) $n), 0, 60),
        $neighborhoods
    ), static fn (string $n): bool => $n !== '')))
    : [];

$fields = [];
if (strlen($ibge) !== 7) {
    $fields['ibge_code'] = 'código IBGE de 7 dígitos';
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
    $fields['name'] = 'nome da cidade';
}
if (!array_key_exists($uf, UF_NAMES)) {
    $fields['uf'] = 'UF inválida';
} elseif (strlen($ibge) === 7 && !str_starts_with($ibge, UF_IBGE_PREFIX[$uf])) {
    $fields['uf'] = "o código IBGE {$ibge} não é de {$uf}";
}
// Faixas do território brasileiro: pega lat/lng trocados ou sem o sinal.
if (!is_numeric($lat) || (float) $lat < -34 || (float) $lat > 6) {
    $fields['lat'] = 'latitude do centro da cidade (ex.: -22.9056)';
}
if (!is_numeric($lng) || (float) $lng < -74 || (float) $lng > -34) {
    $fields['lng'] = 'longitude do centro da cidade (ex.: -47.0608)';
}
if ($neighborhoods === []) {
    $fields['neighborhoods'] = 'ao menos um bairro';
}
if ($timezone === '' && array_key_exists($uf, UF_NAMES)) {
    $timezone = uf_default_timezone($uf);
} elseif ($timezone !== '' && !array_key_exists($timezone, CITY_TIMEZONES)) {
    $fields['timezone'] = 'fuso não aceito';
}
if ($fields !== []) {
    error_response(422, 'invalid_request', 'Confira os campos marcados.', fields: $fields);
}

$pdo->beginTransaction();
try {
    $prev = $pdo->prepare('SELECT name, uf, lat::float AS lat, lng::float AS lng, array_to_json(neighborhoods) AS neighborhoods, timezone, active
                             FROM service_cities WHERE ibge_code = :c FOR UPDATE');
    $prev->execute(['c' => $ibge]);
    $before = $prev->fetch() ?: null;
    if (!$activeGiven && $before !== null) {
        $active = (bool) $before['active'];
    }

    $pdo->prepare(
        'INSERT INTO service_cities (ibge_code, name, uf, lat, lng, neighborhoods, timezone, active)
         VALUES (:c, :name, :uf, :lat, :lng, ARRAY(SELECT json_array_elements_text(CAST(:n AS json))), :tz, :active)
         ON CONFLICT (ibge_code) DO UPDATE
            SET name = EXCLUDED.name, uf = EXCLUDED.uf, lat = EXCLUDED.lat, lng = EXCLUDED.lng,
                neighborhoods = EXCLUDED.neighborhoods, timezone = EXCLUDED.timezone,
                active = EXCLUDED.active, updated_at = now()'
    )->execute([
        'c' => $ibge, 'name' => $name, 'uf' => $uf, 'lat' => (float) $lat, 'lng' => (float) $lng,
        'n' => json_encode($neighborhoods, JSON_UNESCAPED_UNICODE), 'tz' => $timezone,
        'active' => $active ? 'true' : 'false',
    ]);

    $after = ['name' => $name, 'uf' => $uf, 'lat' => (float) $lat, 'lng' => (float) $lng,
        'neighborhoods' => $neighborhoods, 'timezone' => $timezone, 'active' => $active];
    if ($before !== null) {
        $before['neighborhoods'] = json_decode((string) $before['neighborhoods'], true);
    }
    $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, 'service_city.saved', :target, :before, :after, :ip)"
    )->execute([
        'actor' => $adminId,
        'target' => 'service_cities:' . $ibge,
        'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
        'ip' => client_ip(),
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response($before === null ? 201 : 200, ['city' => ['ibge' => $ibge] + $after]);
