<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 5.5 — Avaliação. "Só habilitada para pedido com status = 'delivered'
// -- uma nota por pedido, garantida por índice único." A UNIQUE (order_id)
// da migração 011 faz o "uma nota por pedido"; aqui só confere o dono e o
// status antes de deixar gravar.
//
// courier_tip é REGISTRADA aqui, não cobrada de novo no cartão -- "Cobrada
// no mesmo cartão do pedido" (o texto do mock) pediria uma segunda
// transação no Mercado Pago associada ao pagamento original, que este
// módulo não implementa. Documentado no README como simplificação, mesmo
// padrão de dinheiro/maquininha no módulo de pagamentos (intenção
// registrada, captura de valor de verdade fica pra outro módulo).

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente avalia pedido.');
}
$body = read_json_body();

$orderId = (int) ($body['order_id'] ?? 0);
$rating = (int) ($body['rating'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}
if ($rating < 1 || $rating > 5) {
    error_response(422, 'invalid_rating', 'Nota precisa ser de 1 a 5.', fields: ['rating' => 'inválida']);
}
$tags = is_array($body['tags'] ?? null) ? array_values(array_map('strval', $body['tags'])) : [];
$comment = isset($body['comment']) ? trim((string) $body['comment']) : null;
$courierTip = isset($body['courier_tip']) ? (float) $body['courier_tip'] : 0.0;
if ($courierTip < 0) {
    error_response(422, 'invalid_courier_tip', 'Gorjeta inválida.', fields: ['courier_tip' => 'inválida']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);
if ($order['status'] !== 'delivered') {
    error_response(409, 'order_not_delivered', 'Só dá pra avaliar um pedido já entregue.');
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO reviews (order_id, user_id, rating, tags, comment, courier_tip)
         VALUES (:order_id, :user_id, :rating, :tags, :comment, :courier_tip) RETURNING *'
    );
    $stmt->execute([
        'order_id' => $orderId,
        'user_id' => $claims['sub'],
        'rating' => $rating,
        'tags' => '{' . implode(',', array_map(static fn (string $t) => '"' . str_replace('"', '\\"', $t) . '"', $tags)) . '}',
        'comment' => $comment !== '' ? $comment : null,
        'courier_tip' => $courierTip,
    ]);
    $review = $stmt->fetch();
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'reviews_order_id_key')) {
        error_response(409, 'already_reviewed', 'Esse pedido já foi avaliado.');
    }
    throw $e;
}

json_response(201, ['review' => $review]);
