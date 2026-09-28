<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 2.4 — "Repetir" um pedido: põe os mesmos itens (e variações e
// observações) no carrinho daquela loja, e o cliente segue pro checkout
// normal.
//
// Regras:
//   - PREÇO DE HOJE, não o do pedido antigo: o carrinho sempre é precificado
//     pelo cardápio atual (price_line), senão repetir viraria um jeito de
//     comprar pelo preço de meses atrás;
//   - item que saiu do cardápio, ficou indisponível ou perdeu a variação é
//     PULADO e listado em `skipped` -- a tela avisa o que não voltou, em vez
//     de falhar tudo por um item;
//   - soma ao carrinho que já existe naquela loja (não apaga o que a pessoa
//     já tinha escolhido).

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente repete pedido.');
}
$body = read_json_body();
$orderId = positive_id($body['order_id'] ?? null) ?? 0;
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}

$pdo = db();
$source = fetch_order($pdo, $orderId);
if ($source === null || $source['status'] === 'cart' || $source['user_id'] !== $claims['sub']) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
$restaurantId = (string) $source['restaurant_id'];

// Existe, foi aprovada pela plataforma e está aberta (lib/catalog/store.php).
require_store_accepting_orders($pdo, $restaurantId);

$availableStmt = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id AND restaurant_id = :r AND available = true');
$variantStmt = $pdo->prepare('SELECT 1 FROM item_variants WHERE id = :id AND menu_item_id = :item');

$added = [];
$skipped = [];

$pdo->beginTransaction();
try {
    $cart = find_or_create_cart($pdo, (string) $claims['sub'], $restaurantId);
    $insert = $pdo->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, name_snapshot, unit_price, quantity, variants_snapshot, notes)
         VALUES (:order_id, :menu_item_id, :name_snapshot, :unit_price, :quantity, :variants_snapshot, :notes)'
    );

    foreach (fetch_order_items($pdo, $orderId) as $item) {
        $menuItemId = (int) ($item['menu_item_id'] ?? 0);
        $availableStmt->execute(['id' => $menuItemId, 'r' => $restaurantId]);
        if ($menuItemId <= 0 || $availableStmt->fetchColumn() === false) {
            $skipped[] = ['name' => $item['name_snapshot'], 'reason' => 'indisponível hoje'];
            continue;
        }

        $snapshot = is_string($item['variants_snapshot'])
            ? (json_decode($item['variants_snapshot'], true) ?: [])
            : (array) $item['variants_snapshot'];
        $variantIds = array_map(static fn (array $v): int => (int) $v['id'], $snapshot);
        foreach ($variantIds as $vid) {
            $variantStmt->execute(['id' => $vid, 'item' => $menuItemId]);
            if ($variantStmt->fetchColumn() === false) {
                $skipped[] = ['name' => $item['name_snapshot'], 'reason' => 'uma opção escolhida não existe mais'];
                continue 2;
            }
        }

        $priced = price_line($pdo, $restaurantId, $menuItemId, $variantIds, (int) $item['quantity']);
        $insert->execute([
            'order_id' => $cart['id'],
            'menu_item_id' => $priced['menu_item_id'],
            'name_snapshot' => $priced['name_snapshot'],
            'unit_price' => $priced['unit_price'],
            'quantity' => (int) $item['quantity'],
            'variants_snapshot' => json_encode($priced['variants_snapshot'], JSON_UNESCAPED_UNICODE),
            'notes' => $item['notes'],
        ]);
        $added[] = ['name' => $priced['name_snapshot'], 'quantity' => (int) $item['quantity'], 'unit_price' => $priced['unit_price']];
    }

    if ($added === []) {
        $pdo->rollBack();
        error_response(409, 'nothing_to_reorder', 'Nenhum item desse pedido está disponível hoje.');
    }

    recompute_cart_subtotal($pdo, (int) $cart['id']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'restaurant_id' => $restaurantId,
    'order' => fetch_order($pdo, (int) $cart['id']),
    'added' => $added,
    'skipped' => $skipped,
]);
