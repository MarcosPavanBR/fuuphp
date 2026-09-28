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
// lib/ordering/cart.php): esta rota muda a fonte da verdade, não o cálculo.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$itemId = isset($body['id']) ? (positive_id($body['id']) ?? -1) : 0;
$text = static fn (string $key): ?string => isset($body[$key]) && is_string($body[$key]) ? trim($body[$key]) : null;
$name = $text('name') ?? '';
$description = $text('description');
$category = $text('category');
// Preço é número de verdade: (float) "x" dava 0 e publicava o item de graça.
$price = is_number_between($body['price'] ?? null, 0, MENU_PRICE_MAX) ? round((float) $body['price'], 2) : null;
$available = $body['available'] ?? true;
$variants = $body['variants'] ?? null;

$fields = [];
if ($itemId < 0) {
    $fields['id'] = 'id do item';
}
if ($name === '' || mb_strlen($name) > 80) {
    $fields['name'] = 'obrigatório, até 80 caracteres';
}
if ($description !== null && mb_strlen($description) > 500) {
    $fields['description'] = 'até 500 caracteres';
}
if ($category !== null && mb_strlen($category) > 40) {
    $fields['category'] = 'até 40 caracteres';
}
if ($price === null) {
    $fields['price'] = 'obrigatório, em reais (de 0 a ' . MENU_PRICE_MAX . ')';
}
if (!is_bool($available)) {
    $fields['available'] = 'true ou false';
}
if ($variants !== null && (!is_array($variants) || !array_is_list($variants) || count($variants) > 100)) {
    $fields['variants'] = 'lista (até 100)';
}
if ($fields !== []) {
    error_response(422, 'invalid_item', 'Confira os campos do item.', fields: $fields);
}

// Variação sem nome ou com grupo vazio quebraria a tela do cliente (o
// ItemModal agrupa por group_name), então é barrada aqui e não no banco.
$cleanVariants = [];
foreach ($variants ?? [] as $i => $variant) {
    $variant = is_array($variant) ? $variant : [];
    $group = is_string($variant['group_name'] ?? null) ? trim($variant['group_name']) : '';
    $vname = is_string($variant['name'] ?? null) ? trim($variant['name']) : '';
    if ($group === '' || $vname === '' || mb_strlen($group) > 40 || mb_strlen($vname) > 60) {
        error_response(422, 'invalid_variant', 'Toda variação precisa de grupo (até 40 letras) e nome (até 60).', fields: ["variants.{$i}" => 'incompleta']);
    }
    // Acréscimo pode ser negativo (tamanho menor), mas é número e cabe na
    // coluna: 1e30 dava 500.
    $delta = $variant['price_delta'] ?? 0;
    if (!is_number_between($delta, -MENU_PRICE_MAX, MENU_PRICE_MAX)) {
        error_response(422, 'invalid_variant', 'Acréscimo da variação em reais.', fields: ["variants.{$i}.price_delta" => 'número em reais']);
    }
    $maxSel = $variant['max_selections'] ?? null;
    if ($maxSel !== null && !is_int_between($maxSel, 1, 50)) {
        error_response(422, 'invalid_variant', 'Máximo de escolhas de 1 a 50.', fields: ["variants.{$i}.max_selections" => 'de 1 a 50']);
    }
    $cleanVariants[] = [
        'group_name' => $group,
        'name' => $vname,
        'price_delta' => round((float) $delta, 2),
        'required' => ($variant['required'] ?? false) === true,
        'max_selections' => $maxSel === null ? null : (int) $maxSel,
        'position' => is_int_between($variant['position'] ?? null, 0, 10000) ? (int) $variant['position'] : $i,
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
