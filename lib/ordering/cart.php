<?php
declare(strict_types=1);

// Carrinho é um pedido em status='cart' (Especificação, Fase 3: "Carrinho
// vive num store Svelte e é espelhado no PostgreSQL como pedido em
// status = 'cart', então sobrevive a troca de aparelho"). Este arquivo é
// o que api/v1/cart/*.php (adicionar item por item, como a tela 3.1/3.2
// funciona) usa: preço de uma linha nunca confia no que o cliente mandou.
// (O checkout de um passo só, orders/create.php, foi removido: era um
// segundo caminho sem cupom nem carteira que o app não usava -- auditoria
// ARQ-01.)

/**
 * Quantidade máxima de uma linha do carrinho. Pedido de 100 pizzas iguais é
 * encomenda, não delivery -- e sem teto 1e30 chegava na coluna integer e
 * dava 500 (fuzz profundo de 25/09/2026).
 */
const CART_MAX_QUANTITY = 99;

/** Observação de um item ("sem cebola"): vai impressa na comanda. */
const CART_NOTES_MAX = 200;

/**
 * Valida e precifica UMA linha de pedido (item + variações) contra o
 * cardápio atual da loja. Encerra a requisição com error_response() se o
 * item/variação for inválido -- nunca devolve preço não confiável.
 *
 * @param int[] $variantIds
 * @return array{menu_item_id:int,name_snapshot:string,unit_price:float,variants_snapshot:array}
 */
function price_line(PDO $pdo, string $restaurantId, int $menuItemId, array $variantIds, int $quantity): array
{
    if ($quantity < 1 || $quantity > CART_MAX_QUANTITY) {
        error_response(422, 'invalid_quantity', "Quantidade inválida para o item {$menuItemId} (de 1 a " . CART_MAX_QUANTITY . ').');
    }

    $itemStmt = $pdo->prepare(
        'SELECT id, name, price FROM menu_items WHERE id = :id AND restaurant_id = :restaurant_id AND available = true'
    );
    $itemStmt->execute(['id' => $menuItemId, 'restaurant_id' => $restaurantId]);
    $menuItem = $itemStmt->fetch();
    if ($menuItem === false) {
        error_response(422, 'item_unavailable', "Item {$menuItemId} não existe ou está indisponível nessa loja.");
    }

    $unitPrice = (float) $menuItem['price'];
    $variantsSnapshot = [];

    if ($variantIds !== []) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $variantStmt = $pdo->prepare("SELECT id, menu_item_id, name, price_delta FROM item_variants WHERE id IN ($placeholders)");
        $variantStmt->execute($variantIds);
        $variantsById = [];
        foreach ($variantStmt->fetchAll() as $row) {
            $variantsById[(int) $row['id']] = $row;
        }
        foreach ($variantIds as $vid) {
            if (!isset($variantsById[$vid]) || (int) $variantsById[$vid]['menu_item_id'] !== $menuItemId) {
                error_response(422, 'invalid_variant', "Variação {$vid} não pertence ao item {$menuItemId}.");
            }
            $variant = $variantsById[$vid];
            $unitPrice += (float) $variant['price_delta'];
            $variantsSnapshot[] = ['id' => $vid, 'name' => $variant['name'], 'price_delta' => (float) $variant['price_delta']];
        }
    }

    return [
        'menu_item_id' => $menuItemId,
        'name_snapshot' => $menuItem['name'],
        'unit_price' => round($unitPrice, 2),
        'variants_snapshot' => $variantsSnapshot,
    ];
}

/**
 * Acha o carrinho aberto do usuário para esta loja, ou cria um novo.
 *
 * Um usuário só pode ter carrinho aberto em UMA loja por vez (orders.restaurant_id
 * é fixo por pedido -- misturar loja no mesmo carrinho não existe no esquema).
 * Se já existir carrinho vazio em outra loja, ele é descartado em silêncio
 * (trocar de loja sem ter posto nada é o caso comum). Se tiver item, a troca
 * é barrada -- decidir "esvaziar e trocar" fica pro cliente, não pra este
 * endpoint inventar sozinho.
 */
function find_or_create_cart(PDO $pdo, string $userId, string $restaurantId): array
{
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = :user_id AND status = 'cart' ORDER BY created_at DESC");
    $stmt->execute(['user_id' => $userId]);
    $carts = $stmt->fetchAll();

    foreach ($carts as $cart) {
        if ($cart['restaurant_id'] === $restaurantId) {
            return $cart;
        }
    }

    foreach ($carts as $cart) {
        $countStmt = $pdo->prepare('SELECT count(*) FROM order_items WHERE order_id = :id');
        $countStmt->execute(['id' => $cart['id']]);
        if ((int) $countStmt->fetchColumn() > 0) {
            error_response(
                409,
                'cart_restaurant_conflict',
                'Você já tem um carrinho aberto em outra loja. Finalize ou esvazie antes de começar um novo.',
                detail: "restaurant_id atual do carrinho: {$cart['restaurant_id']}"
            );
        }
        // carrinho vazio de outra loja: descarta, é lixo de navegação, não pedido de verdade.
        $pdo->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $cart['id']]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO orders (user_id, restaurant_id, subtotal) VALUES (:user_id, :restaurant_id, 0) RETURNING *'
    );
    $insert->execute(['user_id' => $userId, 'restaurant_id' => $restaurantId]);
    return $insert->fetch();
}

/**
 * Recalcula o subtotal do carrinho pela soma das linhas (o total é coluna
 * gerada no banco e acompanha sozinho) -- e o desconto do cupom aplicado,
 * que depende do subtotal (lib/ordering/coupons.php).
 */
function recompute_cart_subtotal(PDO $pdo, int $orderId): void
{
    $pdo->prepare(
        "UPDATE orders SET subtotal = (SELECT COALESCE(SUM(line_total), 0) FROM order_items WHERE order_id = :id)
         WHERE id = :id"
    )->execute(['id' => $orderId]);
    refresh_cart_coupon($pdo, $orderId);
}
