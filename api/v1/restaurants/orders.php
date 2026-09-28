<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 7.3/11.1 — a fila de pedidos da loja: `scope=kds` (o que a cozinha
// precisa preparar agora) ou `scope=recent` (a tabela do painel).

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja acessa a fila de pedidos.');
}

$restaurantId = $_GET['id'] ?? '';
if ($restaurantId === '' || $restaurantId !== ($claims['restaurant_id'] ?? null)) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}

// scope=kds (padrão) é a fila da cozinha: mesmo filtro do índice
// orders_kds_idx (migração 004), só o que precisa ser preparado agora.
// scope=recent é a tabela "Pedidos recentes" do painel (tela 7.3), que
// mostra também recusado/entregue/em validação -- outra janela do mesmo
// recurso, não outro recurso.
$scope = $_GET['scope'] ?? 'kds';
if (!in_array($scope, ['kds', 'recent'], true)) {
    error_response(422, 'invalid_scope', 'scope precisa ser "kds" ou "recent".');
}

$pdo = db();
// O KDS (tela 11.1) mostra a comanda inteira e um cronômetro por pedido. O
// cronômetro conta desde a ENTRADA no status atual, não desde a criação do
// pedido -- "em preparo há 6 min" é o que a cozinha lê --, daí o
// max(created_at) em order_events para o status corrente.
$sql = $scope === 'kds'
    ? "SELECT o.id, o.public_code, o.status, o.subtotal, o.delivery_fee, o.surge_fee, o.tip,
              o.discount, o.total, o.payment_method, o.change_for, o.machine_kind, o.created_at,
              o.pickup_by_customer, o.scheduled_for,
              lower(o.scheduled_for) AS scheduled_start,
              u.full_name AS customer_name,
              cu.full_name AS courier_name,
              (SELECT max(ev.created_at) FROM order_events ev
                WHERE ev.order_id = o.id AND ev.to_status = o.status) AS status_since,
              (SELECT json_agg(json_build_object(
                        'name', oi.name_snapshot,
                        'quantity', oi.quantity,
                        'variants', oi.variants_snapshot,
                        'notes', oi.notes) ORDER BY oi.id)
                 FROM order_items oi WHERE oi.order_id = o.id) AS items
       FROM orders o
       JOIN restaurants r ON r.id = o.restaurant_id
       JOIN users u ON u.id = o.user_id
       LEFT JOIN couriers c ON c.id = o.courier_id
       LEFT JOIN users cu ON cu.id = c.user_id
       WHERE o.restaurant_id = :id AND o.status IN ('paid','preparing','ready')
         -- 14.4: pedido agendado só entra na fila perto da faixa. Sem isto,
         -- a cozinha faria às 15h a comida que o cliente pediu pras 21h --
         -- e o mock é explícito: a vaga é da capacidade da cozinha, não do
         -- relógio de quem pediu.
         AND (
           o.scheduled_for IS NULL
           OR lower(o.scheduled_for) - make_interval(mins => r.prep_minutes + " . SLOT_PICKUP_MARGIN_MINUTES . ") <= now()
         )
       ORDER BY o.created_at ASC"
    : "SELECT o.id, o.public_code, o.status, o.subtotal, o.delivery_fee, o.surge_fee, o.tip,
              o.discount, o.total, o.payment_method, o.change_for, o.machine_kind, o.created_at,
              u.full_name AS customer_name
       FROM orders o JOIN users u ON u.id = o.user_id
       WHERE o.restaurant_id = :id AND o.status <> 'cart'
       ORDER BY o.created_at DESC
       LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $restaurantId]);

json_response(200, ['orders' => $stmt->fetchAll()]);
