<?php

declare(strict_types=1);

// Cidades atendidas (migração 036): onde a plataforma opera. Alimentam o
// onboarding do cliente (telas 1.2 e 1.3), o cadastro de loja e a aba
// Cidades do admin. Contagem de lojas é sempre a real: loja aprovada.

/** Os nomes das 27 UFs, pra agrupar as cidades por estado na tela 1.2. */
const UF_NAMES = [
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia',
    'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás',
    'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
    'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí',
    'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul',
    'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo',
    'SE' => 'Sergipe', 'TO' => 'Tocantins',
];

/**
 * Cidades atendidas com o número de lojas aprovadas de cada uma.
 * `$activeOnly` = o que o app mostra; o admin vê também as desligadas.
 */
function service_cities(PDO $pdo, bool $activeOnly = true): array
{
    $rows = $pdo->query(
        'SELECT c.ibge_code AS ibge, c.name, c.uf, c.lat::float AS lat, c.lng::float AS lng,
                array_to_json(c.neighborhoods) AS neighborhoods, c.active,
                (SELECT count(*) FROM restaurants r
                  WHERE r.city_ibge_code = c.ibge_code AND r.approved_at IS NOT NULL)::int AS stores
           FROM service_cities c'
        . ($activeOnly ? ' WHERE c.active' : '')
        . ' ORDER BY c.uf, c.name'
    )->fetchAll();

    return array_map(static function (array $row): array {
        $row['neighborhoods'] = json_decode((string) $row['neighborhoods'], true) ?? [];
        return $row;
    }, $rows);
}
