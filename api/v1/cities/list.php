<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Telas 1.2 e 1.3 — onde você está / cidade e bairro. Público: é o que o app
// mostra antes de qualquer login, e o cadastro de loja usa a mesma lista.
//
// GET  {states: [{uf, name, stores, cities: [{ibge, name, lat, lng,
//       neighborhoods, stores}]}]}
//
// Só as cidades que a plataforma liga na aba Cidades (migração 036), e a
// contagem de lojas é a real (aprovadas). Nada de número de vitrine: no mock
// eram fixos ("SP 1.284 lojas ativas"), e mostrar isso no lançamento seria
// prometer o que não existe.

require_method('GET');

$states = [];
foreach (service_cities(db()) as $city) {
    $uf = $city['uf'];
    $states[$uf] ??= ['uf' => $uf, 'name' => UF_NAMES[$uf] ?? $uf, 'stores' => 0, 'cities' => []];
    $states[$uf]['stores'] += $city['stores'];
    unset($city['uf'], $city['active']);
    $states[$uf]['cities'][] = $city;
}

// Estado com mais lojas primeiro: é onde está quem abre o app.
$states = array_values($states);
usort($states, static fn (array $a, array $b): int => [$b['stores'], $a['name']] <=> [$a['stores'], $b['name']]);

json_response(200, ['states' => $states]);
