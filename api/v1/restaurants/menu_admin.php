<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.3 — o cardápio pelos olhos da loja.
//
// Diferente de `restaurants/menu.php` (público) em duas coisas que importam:
// mostra o que está indisponível (o cliente não precisa ver o que não pode
// pedir; a loja precisa) e traz quanto cada item vendeu na semana --
// "é esse número que decide preço".

require_method('GET');
$claims = require_auth();
$restaurantId = require_store_staff($claims);

$pdo = db();

// A venda da semana sai de order_items, não de um contador guardado: pedido
// cancelado não conta como venda, e um campo incrementado no checkout ia
// contar. O JOIN por menu_item_id é o que amarra as duas coisas -- itens
// apagados do cardápio somem daqui junto, que é o certo: a tela é de edição.
$stmt = $pdo->prepare(
    "SELECT mi.id, mi.name, mi.description, mi.price, mi.category, mi.photo_key,
            mi.available, mi.sold_out_at, mi.position,
            COALESCE(sold.qty, 0) AS sold_week,
            (SELECT json_agg(json_build_object(
                      'id', iv.id, 'group_name', iv.group_name, 'name', iv.name,
                      'price_delta', iv.price_delta, 'required', iv.required,
                      'max_selections', iv.max_selections, 'position', iv.position)
                    ORDER BY iv.position, iv.id)
               FROM item_variants iv WHERE iv.menu_item_id = mi.id) AS variants
       FROM menu_items mi
       LEFT JOIN (
         SELECT oi.menu_item_id, SUM(oi.quantity) AS qty
           FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
          WHERE o.restaurant_id = :rid
            AND o.created_at >= now() - interval '7 days'
            AND o.status NOT IN ('cart','pending_payment','rejected','cancelled')
          GROUP BY oi.menu_item_id
       ) sold ON sold.menu_item_id = mi.id
      WHERE mi.restaurant_id = :rid2
      ORDER BY mi.category NULLS LAST, mi.position, mi.id"
);
$stmt->execute(['rid' => $restaurantId, 'rid2' => $restaurantId]);
$items = $stmt->fetchAll();

// A coluna da esquerda do mock: categorias com contagem. Sai dos próprios
// itens -- não há tabela de categoria na especificação, `menu_items.category`
// é texto livre, e inventar uma tabela aqui seria escopo novo.
$categories = [];
foreach ($items as $item) {
    $name = $item['category'] ?? 'Sem categoria';
    $categories[$name] = ($categories[$name] ?? 0) + 1;
}
$categoryList = [];
foreach ($categories as $name => $count) {
    $categoryList[] = ['name' => $name, 'count' => $count];
}

json_response(200, [
    'items' => $items,
    'categories' => $categoryList,
]);
