<?php
declare(strict_types=1);

// Tela 14.3 — "A área de cobertura é validada no servidor e a taxa aparece
// antes de salvar — não na hora de pagar."
//
// Até a migração 019 o frete era o único número do dinheiro que vinha do
// cliente: `orders/checkout.php` aceitava `delivery_fee` no corpo. Preço de
// item, mínimo de pedido, comissão e total sempre foram decididos aqui; o
// frete ficou de fora porque a especificação não modela tarifa. A regra
// abaixo é a decisão registrada -- simples de propósito, e com os
// parâmetros na política versionada em vez de constantes no código.

/**
 * Distância em linha reta entre dois pontos, em km (Haversine).
 *
 * Linha reta subestima a rota real de moto. Fica assim porque roteamento
 * exige um provedor de mapas, que não está na cláusula zero -- e um número
 * inflado "pra compensar" seria tarifa inventada. O que existe é honesto e
 * está documentado: a distância é a menor possível entre os dois pontos.
 */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $r * 2 * asin(min(1.0, sqrt($a)));
}

/**
 * O frete deste endereço para esta loja, com a política que vale agora (ou a
 * congelada no pedido, quando quem chama já tem uma).
 *
 * Devolve sempre a mesma forma, inclusive quando não dá pra calcular -- é a
 * tela que decide o que mostrar, e ela precisa saber POR QUE não deu.
 */
function delivery_quote(array $restaurant, array $address, array $policy): array
{
    $base = (float) ($policy['delivery_base_fee'] ?? 0);
    $perKm = (float) ($policy['delivery_per_km'] ?? 0);
    $maxKm = $policy['delivery_max_km'] ?? null;
    $maxKm = $maxKm === null ? null : (float) $maxKm;

    // Loja sem coordenada (restaurants.lat/lng é nullable desde a migração
    // 010) não tem como ter distância medida. Bloquear o pedido por causa
    // disso puniria o cliente por um cadastro que não é dele; cobrar por km
    // sem saber os km seria pior. Então cobra-se só a base, e a resposta diz
    // que a distância é desconhecida.
    if ($restaurant['lat'] === null || $restaurant['lng'] === null) {
        return [
            'distance_km' => null,
            'fee' => round($base, 2),
            'in_area' => true,
            'reason' => 'Essa loja ainda não tem coordenada cadastrada — dá pra pedir, mas não dá pra medir a distância.',
            'max_km' => $maxKm,
        ];
    }

    $distance = haversine_km(
        (float) $restaurant['lat'],
        (float) $restaurant['lng'],
        (float) $address['lat'],
        (float) $address['lng']
    );

    $inArea = $maxKm === null || $distance <= $maxKm;

    return [
        'distance_km' => round($distance, 1),
        // O frete arredonda pra cima no centavo: cobrar R$ 6,895 não existe,
        // e arredondar pra baixo faria a plataforma pagar a diferença.
        'fee' => $inArea ? round($base + $perKm * $distance, 2) : null,
        'in_area' => $inArea,
        'reason' => $inArea
            ? null
            : sprintf(
                'Esse endereço está a %s km da loja, e o limite de entrega é %s km.',
                number_format($distance, 1, ',', '.'),
                number_format($maxKm, 1, ',', '.')
            ),
        'max_km' => $maxKm,
    ];
}

/**
 * O mesmo cálculo, buscando loja e endereço no banco. É o que o checkout usa
 * -- e é por isso que o valor que o cliente manda no corpo não importa mais.
 */
function delivery_quote_for(PDO $pdo, string $restaurantId, int $addressId, ?array $policy = null): array
{
    $restStmt = $pdo->prepare('SELECT id, lat, lng FROM restaurants WHERE id = :id');
    $restStmt->execute(['id' => $restaurantId]);
    $restaurant = $restStmt->fetch();
    if ($restaurant === false) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }

    $addrStmt = $pdo->prepare('SELECT id, lat, lng FROM addresses WHERE id = :id');
    $addrStmt->execute(['id' => $addressId]);
    $address = $addrStmt->fetch();
    if ($address === false) {
        error_response(404, 'address_not_found', 'Endereço não encontrado.');
    }

    return delivery_quote($restaurant, $address, $policy ?? resolve_policy($pdo, $restaurantId));
}
