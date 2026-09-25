<?php
declare(strict_types=1);

// O que o card de uma loja mostra na Home (2.1) e o que os filtros da Busca
// (2.2) usam: nota, frete e tempo até chegar -- "4,8 · 25–35 min · R$ 6,90".
//
// Nada disto é coluna de `restaurants`, de propósito: são números que
// MUDAM (a nota com cada avaliação, o frete com a política e o endereço, o
// tempo com a fila da cozinha). Guardar cópia seria ter duas verdades.
// Tudo é calculado das mesmas fontes que o checkout e o acompanhamento usam:
//   nota  → reviews (5.5) dos pedidos da loja
//   frete → delivery_quote() com a política da loja (o MESMO cálculo que o
//           checkout cobra -- a promessa do card é a cobrança do checkout)
//   tempo → effective_prep_minutes() (11.2, com a fila) + viagem à
//           velocidade média DELIVERY_AVG_KMH

// Nota com uma ou duas avaliações é ruído: não aparece nem entra no "4,5+".
const RATING_MIN_REVIEWS = 3;

/**
 * @param list<string> $restaurantIds
 * @return array<string, array{rating:?float, rating_count:int, delivery_fee:?float, in_area:?bool, eta_minutes:?int}>
 */
function restaurant_card_facts(PDO $pdo, array $restaurantIds, ?float $lat, ?float $lng): array
{
    if ($restaurantIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($restaurantIds), '?'));

    $ratings = [];
    $stmt = $pdo->prepare(
        "SELECT o.restaurant_id, round(avg(rv.rating), 1) AS rating, count(*) AS n
           FROM reviews rv JOIN orders o ON o.id = rv.order_id
          WHERE o.restaurant_id IN ({$placeholders})
          GROUP BY o.restaurant_id"
    );
    $stmt->execute($restaurantIds);
    foreach ($stmt->fetchAll() as $row) {
        $ratings[$row['restaurant_id']] = ['rating' => (float) $row['rating'], 'n' => (int) $row['n']];
    }

    $stores = $pdo->prepare("SELECT id, lat, lng, prep_minutes, prep_auto_bump FROM restaurants WHERE id IN ({$placeholders})");
    $stores->execute($restaurantIds);
    $stores = $stores->fetchAll();

    // Política e fila de todas as lojas de uma vez (resolve_policies,
    // kitchen_queues): antes eram ~15 consultas por loja (PERF-01).
    try {
        $policies = resolve_policies($pdo, array_map('strval', array_column($stores, 'id')));
    } catch (RuntimeException) {
        $policies = []; // sem política cadastrada: o card fica sem frete, não quebra a lista
    }
    $queues = kitchen_queues($pdo, array_map('strval', array_column($stores, 'id')));

    $facts = [];
    foreach ($stores as $store) {
        $id = (string) $store['id'];
        $rating = $ratings[$id] ?? ['rating' => null, 'n' => 0];

        $fee = null;
        $inArea = null;
        $travel = null;
        if ($lat !== null && $lng !== null && isset($policies[$id])) {
            $quote = delivery_quote($store, ['lat' => $lat, 'lng' => $lng], $policies[$id]);
            $fee = $quote['fee'] === null ? null : (float) $quote['fee'];
            $inArea = $quote['in_area'];
            if ($quote['distance_km'] !== null) {
                $travel = (int) ceil((float) $quote['distance_km'] / DELIVERY_AVG_KMH * 60);
            }
        }
        $prep = prep_minutes_for_queue($store, $queues[$id] ?? 0)['effective'];

        $facts[$id] = [
            'rating' => $rating['n'] >= RATING_MIN_REVIEWS ? $rating['rating'] : null,
            'rating_count' => $rating['n'],
            'delivery_fee' => $fee,
            'in_area' => $inArea,
            'eta_minutes' => $travel === null ? null : $prep + $travel,
        ];
    }

    return $facts;
}
