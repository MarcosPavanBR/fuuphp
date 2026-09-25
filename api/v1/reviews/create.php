<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 5.5 — Avaliação. "Só habilitada para pedido com status = 'delivered'
// -- uma nota por pedido, garantida por índice único." A UNIQUE (order_id)
// da migração 011 faz o "uma nota por pedido"; aqui só confere o dono e o
// status antes de deixar gravar.
//
// Gorjeta (migração 025): "Vai 100% para o entregador, no repasse da terça.
// Cobrada no mesmo cartão do pedido." Por isso só existe gorjeta em pedido
// pago com CARTÃO pelo app e entregue por entregador -- em Pix, dinheiro ou
// maquininha não há cartão guardado pra cobrar, e na retirada no balcão não
// há entregador pra receber.
//
// Ordem das coisas: a avaliação é gravada primeiro (a nota vale mesmo se o
// cartão recusar), depois a gorjeta é cobrada fora da transação -- chamada
// de rede não segura lock de banco -- e, se aprovada, vira
// `courier_payable` no livro. Recusa não desfaz a avaliação: fica
// `tip_state = 'failed'` e a tela avisa. A chave de idempotência é por
// pedido, então um retry nunca cobra duas vezes.

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
// Etiquetas: lista curta de textos curtos ("chegou quente"); comentário até
// 1000 letras. Lista dentro de lista virava "Array" e texto de 10 mil
// letras ia inteiro pro banco.
$tags = $body['tags'] ?? [];
if (!is_array($tags) || count($tags) > 10) {
    error_response(422, 'invalid_tags', 'Até 10 etiquetas.', fields: ['tags' => 'lista de até 10']);
}
foreach ($tags as $tag) {
    if (!is_string($tag) || mb_strlen($tag) > 40) {
        error_response(422, 'invalid_tags', 'Etiqueta é um texto de até 40 letras.', fields: ['tags' => 'inválida']);
    }
}
$tags = array_values(array_unique(array_map('trim', $tags)));
$comment = is_string($body['comment'] ?? null) ? trim($body['comment']) : null;
if ($comment !== null && mb_strlen($comment) > 1000) {
    error_response(422, 'comment_too_long', 'O comentário vai até 1000 caracteres.', fields: ['comment' => 'até 1000 caracteres']);
}
$courierTip = isset($body['courier_tip']) ? (money_input($body['courier_tip'], 0, 1000000) ?? -1.0) : 0.0;
// Mesmo teto da gorjeta no pedido (TIP_MAX, lib/ordering/orders.php).
if (!is_valid_tip($courierTip)) {
    error_response(422, 'invalid_courier_tip', 'Gorjeta inválida (de R$ 0 a R$ 200).', fields: ['courier_tip' => 'de 0 a 200']);
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

$cardPayment = null;
if ($courierTip > 0) {
    if ($order['courier_id'] === null) {
        error_response(422, 'tip_no_courier', 'Pedido retirado no balcão não tem entregador pra receber gorjeta.');
    }
    $paymentStmt = $pdo->prepare(
        "SELECT * FROM payments WHERE order_id = :id AND provider = 'mercadopago' AND status = 'approved' LIMIT 1"
    );
    $paymentStmt->execute(['id' => $orderId]);
    $cardPayment = $paymentStmt->fetch();
    if ($order['payment_method'] !== 'mp_card' || $cardPayment === false) {
        error_response(422, 'tip_requires_card', 'A gorjeta é cobrada no cartão do pedido — este pedido não foi pago com cartão pelo app.');
    }
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

if ($courierTip > 0) {
    $review = review_charge_tip($pdo, $review, $order, $cardPayment, (string) $claims['sub']);
}

json_response(201, ['review' => $review]);

/**
 * Cobra a gorjeta e registra o resultado na avaliação. Aprovada, lança o
 * valor em `courier_payable` (origem 'order', 'review_tip:<pedido>') -- é o
 * mesmo lugar onde a gorjeta do checkout e o frete do entregador já moram,
 * então o repasse da terça paga sem saber de onde veio.
 */
function review_charge_tip(PDO $pdo, array $review, array $order, array $cardPayment, string $actorId): array
{
    $orderId = (int) $order['id'];
    $amount = (float) $review['courier_tip'];
    try {
        $charge = mp_charge_tip((string) $cardPayment['provider_ref'], $amount, 'tip-order-' . $orderId);
    } catch (RuntimeException $e) {
        $stmt = $pdo->prepare(
            "UPDATE reviews SET tip_state = 'failed', tip_error = :err WHERE id = :id RETURNING *"
        );
        $stmt->execute(['err' => $e->getMessage(), 'id' => $review['id']]);

        return $stmt->fetch();
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE reviews SET tip_state = 'charged', tip_provider_ref = :ref, tip_charged_at = now(), tip_error = NULL
              WHERE id = :id RETURNING *"
        );
        $stmt->execute(['ref' => $charge['provider_ref'], 'id' => $review['id']]);
        $updated = $stmt->fetch();
        ledger_add($pdo, 'courier_payable', (string) $order['courier_id'], $amount, 'order',
            'review_tip:' . $orderId, $orderId, $actorId, 'gorjeta da avaliação, cobrada no cartão do pedido');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $updated;
}
