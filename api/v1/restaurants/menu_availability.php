<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.3 — "Esgotar é um toque na lista".
//
// Rota própria, separada de menu_item.php, porque é a ação mais frequente do
// dia e a única que não passa por rascunho: esgotou, esgotou agora. Mandar
// isso pelo mesmo endpoint de edição faria um toque na lista carregar o
// risco de reenviar preço e descrição junto.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$itemId = positive_id($body['menu_item_id'] ?? null) ?? 0;
$available = $body['available'] ?? null;
if ($itemId <= 0 || !is_bool($available)) {
    error_response(422, 'invalid_request', 'Informe menu_item_id e available (true/false).');
}

$pdo = db();

// O WHERE com restaurant_id é a autorização: sem ele, um id de item da loja
// vizinha esgotaria o produto dela.
$stmt = $pdo->prepare(
    'UPDATE menu_items
        SET available = :available,
            -- "Esgotada hoje · volta amanhã às 11:00": o carimbo é o que
            -- permite dizer "hoje" em vez de "esgotada, sem saber desde
            -- quando". Voltar a ficar disponível apaga o carimbo.
            sold_out_at = CASE WHEN :available2 THEN NULL ELSE now() END
      WHERE id = :id AND restaurant_id = :rid
      RETURNING id, name, available, sold_out_at'
);
$stmt->execute([
    'available' => $available ? 'true' : 'false',
    'available2' => $available ? 'true' : 'false',
    'id' => $itemId,
    'rid' => $restaurantId,
]);
$item = $stmt->fetch();

if ($item === false) {
    error_response(404, 'item_not_found', 'Item não encontrado no cardápio desta loja.');
}

json_response(200, ['item' => $item]);
