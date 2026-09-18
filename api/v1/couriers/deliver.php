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

$orderId = (int) ($body['order_id'] ?? 0);
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
    $code = isset($body['delivery_code']) ? only_digits((string) $body['delivery_code']) : '';
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

    $lat = isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : null;
    $lng = isset($body['lng']) && is_numeric($body['lng']) ? (float) $body['lng'] : null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO delivery_proofs (order_id, courier_id, kind, storage_key, lat, lng)
             VALUES (:order_id, :courier_id, :kind, :storage_key, :lat, :lng)
             ON CONFLICT (order_id) DO NOTHING'
        )->execute([
            'order_id' => $orderId,
            'courier_id' => $courierId,
            'kind' => $code !== '' ? 'code' : 'photo',
            'storage_key' => $hasPhoto ? (string) $body['photo_storage_key'] : null,
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
