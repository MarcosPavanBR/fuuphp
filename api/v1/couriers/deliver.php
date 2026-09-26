<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Telas 8.5 e 8.6 — entrega, cobrança e prova.
//
// "A cobrança em espécie entra no livro do entregador na mesma transação."
// É a frase que define este endpoint: avançar pra 'delivered', gravar a prova
// e lançar no livro acontecem juntos ou não acontecem.
//
// Contabilidade, na ordem em que o dinheiro anda (telas 8.7 e 9.3):
//   - pedido em dinheiro: o entregador recebe o BRUTO do cliente, então
//     courier_cash += total. Ele devolve esse bruto pra loja na baixa, "sem
//     descontar o frete dele -- quem paga o frete somos nós".
//   - toda corrida entregue: courier_payable += frete. É o que a plataforma
//     deve a ele, independente da forma de pagamento.
//
// Idempotente por pedido: `X-Idempotency-Key` cobre o retry de rede, e a
// própria função do banco barra um segundo 'delivered'.

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);
$key = require_idempotency_key();
$body = read_json_body();

$orderId = positive_id($body['order_id'] ?? null) ?? 0;
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}

$pdo = db();

idempotent_response($pdo, 'POST /v1/couriers/deliver', $key, $body, function () use ($pdo, $orderId, $courierId, $claims, $body): array {
    $order = fetch_order($pdo, $orderId);
    if ($order === null || $order['courier_id'] !== $courierId) {
        return [404, ['code' => 'order_not_found', 'message' => 'Pedido não encontrado.']];
    }
    if ($order['status'] !== 'delivering') {
        return [409, [
            'code' => 'order_not_in_delivery',
            'message' => 'Esse pedido não está em rota de entrega.',
            'order' => $order,
        ]];
    }

    // Prova de entrega: código do cliente OU foto. Sem uma das duas não
    // fecha -- é o que sustenta disputa depois (Fase 14).
    $code = isset($body['delivery_code']) && is_scalar($body['delivery_code']) ? only_digits(input_str($body, 'delivery_code')) : '';
    $hasPhoto = isset($body['photo_storage_key']) && $body['photo_storage_key'] !== '';

    if ($code === '' && !$hasPhoto) {
        return [422, [
            'code' => 'proof_required',
            'message' => 'Informe o código do cliente ou envie a foto da entrega.',
        ]];
    }
    if ($code !== '' && !hash_equals((string) $order['delivery_code'], $code)) {
        return [422, ['code' => 'wrong_delivery_code', 'message' => 'Código não confere. Confira com o cliente ou use a foto.']];
    }
    // A foto tem que ser DESTE pedido e ter chegado no servidor
    // (lib/dispatch/delivery_photos.php). Texto qualquer fechava a entrega.
    $photo = $hasPhoto ? verified_delivery_photo($orderId, $body['photo_storage_key']) : null;
    if ($code === '' && $photo === null) {
        return [422, ['code' => 'photo_not_found', 'message' => 'A foto da entrega não chegou. Tire e envie de novo.']];
    }

    // Coordenada fora da faixa é lixo do GPS: fica sem, em vez de estourar
    // a coluna numeric (1e30 dava 500 no meio da entrega).
    $lat = coord_input($body['lat'] ?? null, 90);
    $lng = coord_input($body['lng'] ?? null, 180);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO delivery_proofs (order_id, courier_id, kind, storage_key, sha256, lat, lng)
             VALUES (:order_id, :courier_id, :kind, :storage_key, :sha256, :lat, :lng)
             ON CONFLICT (order_id) DO NOTHING'
        )->execute([
            'order_id' => $orderId,
            'courier_id' => $courierId,
            'kind' => $code !== '' ? 'code' : 'photo',
            'storage_key' => $photo['key'] ?? null,
            // O hash é recalculado aqui, do arquivo gravado pelo upload --
            // não o que o aparelho diz (photo_sha256 é ignorado). É ele que
            // faz a mesma foto em duas corridas virar sinal de fraude.
            'sha256' => $photo['sha256'] ?? null,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        call_advance_order($pdo, $orderId, 'delivered', (string) $claims['sub'], 'courier', [
            'proof' => $code !== '' ? 'code' : 'photo',
        ]);

        if ($order['payment_method'] === 'cash') {
            ledger_add(
                $pdo,
                'courier_cash',
                $courierId,
                (float) $order['total'],
                'order',
                (string) $orderId,
                $orderId,
                (string) $claims['sub'],
                'espécie recebida do cliente (bruto)'
            );
        }

        ledger_add(
            $pdo,
            'courier_payable',
            $courierId,
            (float) $order['delivery_fee'],
            'order',
            (string) $orderId,
            $orderId,
            (string) $claims['sub'],
            'frete da corrida'
        );

        // O acerto do pedido com a loja (lib/ledger/order_ledger.php): a parte dela
        // passa a ser devida, e a gorjeta vai pro entregador. Mesma
        // transação -- pedido entregue sem o acerto lançado é o livro que a
        // tela 9.7 somava sem a linha principal.
        ledger_order_delivered($pdo, fetch_order($pdo, $orderId), (string) $claims['sub']);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [200, [
        'order' => fetch_order($pdo, $orderId),
        'balances' => [
            'cash' => courier_cash_balance($pdo, $courierId),
            'payable' => courier_payable_balance($pdo, $courierId),
        ],
    ]];
});
