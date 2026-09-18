<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.1 — as saídas concretas. Uma rota, três ações, porque são três
// respostas à MESMA pergunta ("ninguém aceitou a corrida, e agora?") e
// compartilham as mesmas pré-condições.
//
//   boost   — turbina o frete (só onde o dinheiro ainda não andou)
//   pickup  — cliente retira na loja; devolvemos o frete
//   (cancelar continua em orders/status.php, a porta única de status)

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só o cliente escolhe a saída do pedido dele.');
}
$body = read_json_body();

$orderId = (int) ($body['order_id'] ?? 0);
$action = $body['action'] ?? null;
if ($orderId <= 0 || !in_array($action, ['boost', 'pickup'], true)) {
    error_response(422, 'invalid_request', 'Informe order_id e action (boost ou pickup).');
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

if ($order['courier_id'] !== null) {
    error_response(409, 'courier_assigned', 'Um entregador acabou de aceitar — a corrida já tem dono.');
}
if ($order['status'] !== 'ready') {
    error_response(409, 'not_waiting_courier', 'Esse pedido não está esperando entregador.');
}

if ($action === 'boost') {
    if (!in_array((string) $order['payment_method'], ['cash', 'pos_machine'], true)) {
        error_response(409, 'already_paid', 'Esse pedido já foi pago — turbinar exigiria cobrar de novo, e isso não existe aqui.');
    }

    $amount = round((float) ($body['amount'] ?? 4.00), 2);
    if ($amount <= 0 || $amount > 50) {
        error_response(422, 'invalid_amount', 'Turbo precisa ser entre R$ 0,01 e R$ 50,00.', fields: ['amount' => 'inválido']);
    }

    $pdo->beginTransaction();
    try {
        // surge_fee entra no total (coluna gerada) porque é o cliente que
        // paga o turbo -- e ele paga na entrega, já que só pedido em espécie
        // ou maquininha chega aqui.
        $pdo->prepare('UPDATE orders SET surge_fee = surge_fee + :amount WHERE id = :id')
            ->execute(['amount' => $amount, 'id' => $orderId]);

        // O entregador precisa VER o dinheiro a mais, senão turbinar não
        // muda nada: o bônus vai pra oferta aberta, que é o que o app mostra.
        $pdo->prepare(
            "UPDATE offers SET bonus = bonus + :amount, expires_at = now() + interval '5 minutes'
             WHERE order_id = :id AND state = 'open'"
        )->execute(['amount' => $amount, 'id' => $orderId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_response(200, ['order' => fetch_order($pdo, $orderId), 'boosted' => $amount]);
}

// pickup: "Retirar na loja — devolvemos os R$ 6,90 da entrega".
$refundAmount = round((float) $order['delivery_fee'] + (float) $order['surge_fee'], 2);

$pdo->beginTransaction();
try {
    // Zerar frete e turbo muda o total (coluna gerada) -- e é o certo: o
    // cliente não vai pagar entrega que não vai acontecer.
    $pdo->prepare(
        'UPDATE orders
            SET pickup_by_customer = true, delivery_fee = 0, surge_fee = 0, no_courier_since = NULL
          WHERE id = :id'
    )->execute(['id' => $orderId]);

    // A oferta some: não há mais corrida.
    $pdo->prepare("UPDATE offers SET state = 'expired' WHERE order_id = :id AND state = 'open'")
        ->execute(['id' => $orderId]);

    // Pedido já pago devolve o frete pelo mesmo caminho da cobrança. Pedido
    // que paga na entrega não devolve nada -- simplesmente não cobra o
    // frete, porque o total já mudou.
    $refund = null;
    $prepaid = !in_array((string) $order['payment_method'], ['cash', 'pos_machine'], true);
    if ($prepaid && $refundAmount > 0) {
        $plan = refund_plan($order, policy_for_order($pdo, $order), 'no_courier');
        $plan['amount'] = $refundAmount;
        $plan['fee'] = 0.0;
        $refund = record_refund($pdo, $order, $plan, (string) $claims['sub'], partial: true);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'order' => fetch_order($pdo, $orderId),
    'refund' => $refund,
    'refunded_amount' => $refundAmount,
]);
