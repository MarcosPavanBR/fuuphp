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
 * Os dois primeiros dígitos do código IBGE de um município são o código da
 * UF: 3509502 (Campinas) começa com 35, São Paulo. Serve pra recusar cidade
 * cadastrada no estado errado.
 */
const UF_IBGE_PREFIX = [
    'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
    'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
    'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
    'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
];

/**
 * Os fusos do Brasil que o sistema aceita por cidade (migração 038), com o
 * rótulo que o admin vê. Sem horário de verão desde 2019.
 */
const CITY_TIMEZONES = [
    'America/Sao_Paulo' => 'Brasília (UTC−3) — a maioria do país',
    'America/Campo_Grande' => 'Mato Grosso do Sul (UTC−4)',
    'America/Cuiaba' => 'Mato Grosso (UTC−4)',
    'America/Manaus' => 'Amazonas (UTC−4)',
    'America/Porto_Velho' => 'Rondônia (UTC−4)',
    'America/Boa_Vista' => 'Roraima (UTC−4)',
    'America/Rio_Branco' => 'Acre (UTC−5)',
    'America/Noronha' => 'Fernando de Noronha (UTC−2)',
];

/** O fuso padrão de uma UF (o admin pode trocar, ex.: o oeste do Amazonas). */
function uf_default_timezone(string $uf): string
{
    return match ($uf) {
        'MS' => 'America/Campo_Grande',
        'MT' => 'America/Cuiaba',
        'AM' => 'America/Manaus',
        'RO' => 'America/Porto_Velho',
        'RR' => 'America/Boa_Vista',
        'AC' => 'America/Rio_Branco',
        default => 'America/Sao_Paulo',
    };
}

/**
 * O relógio de uma loja: o fuso da cidade dela (restaurant_timezone(), no
 * banco). Tudo que é hora de parede da loja -- faixa de agendamento, "hoje"
 * da conciliação, hora carimbada no comprovante -- passa por aqui. O que é
 * da plataforma (relatórios, acerto semanal) fica no de Brasília.
 */
function store_timezone(PDO $pdo, string $restaurantId): DateTimeZone
{
    static $cache = [];
    if (!isset($cache[$restaurantId])) {
        $stmt = $pdo->prepare('SELECT restaurant_timezone(:id)');
        $stmt->execute(['id' => $restaurantId]);
        $cache[$restaurantId] = new DateTimeZone((string) ($stmt->fetchColumn() ?: 'America/Sao_Paulo'));
    }
    return $cache[$restaurantId];
}

/**
 * Cidades atendidas com o número de lojas aprovadas de cada uma.
 * `$activeOnly` = o que o app mostra; o admin vê também as desligadas.
 */
function service_cities(PDO $pdo, bool $activeOnly = true): array
{
    $rows = $pdo->query(
        'SELECT c.ibge_code AS ibge, c.name, c.uf, c.lat::float AS lat, c.lng::float AS lng, c.timezone,
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
