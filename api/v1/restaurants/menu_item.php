<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.3 — o painel lateral: criar ou publicar um item com seus tamanhos.
//
// "Publicar" é este POST. O rascunho ("alterações não publicadas") vive no
// navegador até aqui: guardar rascunho no servidor pediria uma coluna ou uma
// tabela de versão que a especificação não tem, e o efeito prático seria o
// mesmo -- o que o cliente vê só muda quando alguém publica. Fica registrado
// no README que fechar o painel sem publicar perde a edição.
//
// Preço continua sendo decidido pelo servidor no checkout (price_line(),
// lib/cart.php): esta rota muda a fonte da verdade, não o cálculo.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$itemId = isset($body['id']) ? (int) $body['id'] : 0;
$name = trim((string) ($body['name'] ?? ''));
$description = isset($body['description']) ? trim((string) $body['description']) : null;
$category = isset($body['category']) ? trim((string) $body['category']) : null;
$price = isset($body['price']) ? round((float) $body['price'], 2) : null;
$available = $body['available'] ?? true;
$variants = $body['variants'] ?? null;

$fields = [];
if ($name === '') {
    $fields['name'] = 'obrigatório';
}
if ($price === null || $price < 0) {
    $fields['price'] = 'obrigatório, em reais';
}
if (!is_bool($available)) {
    $fields['available'] = 'true ou false';
}
if ($variants !== null && !is_array($variants)) {
    $fields['variants'] = 'lista';
}
if ($fields !== []) {
    error_response(422, 'invalid_item', 'Confira os campos do item.', fields: $fields);
}

// Variação sem nome ou com grupo vazio quebraria a tela do cliente (o
// ItemModal agrupa por group_name), então é barrada aqui e não no banco.
$cleanVariants = [];
foreach ($variants ?? [] as $i => $variant) {
    $group = trim((string) ($variant['group_name'] ?? ''));
    $vname = trim((string) ($variant['name'] ?? ''));
    if ($group === '' || $vname === '') {
        error_response(422, 'invalid_variant', 'Toda variação precisa de grupo e nome.', fields: ["variants.{$i}" => 'incompleta']);
    }
    $cleanVariants[] = [
        'group_name' => $group,
        'name' => $vname,
        'price_delta' => round((float) ($variant['price_delta'] ?? 0), 2),
        'required' => ($variant['required'] ?? false) === true,
        'max_selections' => isset($variant['max_selections']) ? (int) $variant['max_selections'] : null,
        'position' => (int) ($variant['position'] ?? $i),
    ];
}

$pdo = db();
$pdo->beginTransaction();
try {
    if ($itemId > 0) {
        $update = $pdo->prepare(
            'UPDATE menu_items
                SET name = :name, description = :description, price = :price,
                    category = :category, available = :available,
                    sold_out_at = CASE WHEN :available2 THEN NULL ELSE sold_out_at END
              WHERE id = :id AND restaurant_id = :rid
              RETURNING *'
        );
        $update->execute([
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'category' => $category,
            'available' => $available ? 'true' : 'false',
            'available2' => $available ? 'true' : 'false',
            'id' => $itemId,
            'rid' => $restaurantId,
        ]);
        $item = $update->fetch();
        if ($item === false) {
            $pdo->rollBack();
            error_response(404, 'item_not_found', 'Item não encontrado no cardápio desta loja.');
        }
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO menu_items (restaurant_id, name, description, price, category, available, position)
             VALUES (:rid, :name, :description, :price, :category, :available,
                     COALESCE((SELECT MAX(position) + 1 FROM menu_items WHERE restaurant_id = :rid2), 0))
             RETURNING *'
        );
        $insert->execute([
            'rid' => $restaurantId,
            'rid2' => $restaurantId,
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'category' => $category,
            'available' => $available ? 'true' : 'false',
        ]);
        $item = $insert->fetch();
        $itemId = (int) $item['id'];
    }

    // Variações são substituídas em bloco, não casadas uma a uma: a tela
    // manda a lista inteira como ela ficou, e apagar+inserir dentro da mesma
    // transação é o que garante que ninguém leia um estado com metade dos
    // tamanhos. `order_items.variants_snapshot` guarda o que foi pedido, então
    // apagar uma variação não reescreve pedido nenhum do passado.
    if ($variants !== null) {
        $pdo->prepare('DELETE FROM item_variants WHERE menu_item_id = :id')->execute(['id' => $itemId]);
        $insertVariant = $pdo->prepare(
            'INSERT INTO item_variants (menu_item_id, group_name, name, price_delta, required, max_selections, position)
             VALUES (:item, :group_name, :name, :price_delta, :required, :max_selections, :position)'
        );
        foreach ($cleanVariants as $variant) {
            $insertVariant->execute([
                'item' => $itemId,
                'group_name' => $variant['group_name'],
                'name' => $variant['name'],
                'price_delta' => $variant['price_delta'],
                'required' => $variant['required'] ? 'true' : 'false',
                'max_selections' => $variant['max_selections'],
                'position' => $variant['position'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$variantStmt = $pdo->prepare('SELECT * FROM item_variants WHERE menu_item_id = :id ORDER BY position, id');
$variantStmt->execute(['id' => $itemId]);

json_response(200, [
    'item' => $item,
    'variants' => $variantStmt->fetchAll(),
    // "Publicar invalida o cache do cardápio no edge" -- o purge do
    // Cloudflare não acontece aqui (não há token de API configurado, e
    // inventar um endpoint que finge purgar seria pior). O service worker
    // do PWA busca cardápio pela rede primeiro (Fase 7.1), então o cliente
    // online já vê o preço novo; o cache de borda fica registrado no README.
    'edge_purged' => false,
]);
