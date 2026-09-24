<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// "Queridinhos" da Home (tela 2.1): os itens mais pedidos da cidade.
//
// GET ?city_ibge_code=  {items: [{id, name, price, photo_key, restaurant_id,
//                        restaurant_name, sold}]}
//
// Conta o que foi ENTREGUE nos últimos POPULAR_WINDOW_DAYS dias -- pedido
// cancelado não é gosto de ninguém. Só entra item disponível de loja
// aprovada e aberta agora: a faixa é pra pedir, não pra olhar. Uma loja
// entra com no máximo POPULAR_PER_STORE itens, senão a campeã ocupa a faixa
// inteira. Sem venda no período, a lista vem vazia e a Home não mostra a faixa.

const POPULAR_WINDOW_DAYS = 30;
const POPULAR_LIMIT = 12;
const POPULAR_PER_STORE = 2;

require_method('GET');

$city = only_digits((string) ($_GET['city_ibge_code'] ?? ''));
if (strlen($city) !== 7) {
    error_response(422, 'city_ibge_code_required', 'Informe ?city_ibge_code=.');
}

$stmt = db()->prepare(
    "WITH sold AS (
       SELECT oi.menu_item_id, SUM(oi.quantity) AS sold
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
        WHERE o.status = 'delivered'
          AND o.created_at >= now() - make_interval(days => :days)
        GROUP BY oi.menu_item_id
     ), ranked AS (
       SELECT mi.id, mi.name, mi.price, mi.photo_key,
              r.id AS restaurant_id, r.name AS restaurant_name, s.sold,
              row_number() OVER (PARTITION BY r.id ORDER BY s.sold DESC, mi.id) AS nth
         FROM sold s
         JOIN menu_items mi ON mi.id = s.menu_item_id AND mi.available
         JOIN restaurants r ON r.id = mi.restaurant_id
        WHERE r.city_ibge_code = :city
          AND r.approved_at IS NOT NULL
          AND r.is_open
          AND (r.pause_until IS NULL OR r.pause_until <= now())
     )
     SELECT id, name, price, photo_key, restaurant_id, restaurant_name, sold::int AS sold
       FROM ranked
      WHERE nth <= :per_store
      ORDER BY sold DESC, id
      LIMIT :lim"
);
$stmt->execute(['days' => POPULAR_WINDOW_DAYS, 'city' => $city, 'per_store' => POPULAR_PER_STORE, 'lim' => POPULAR_LIMIT]);

json_response(200, ['items' => $stmt->fetchAll()]);
