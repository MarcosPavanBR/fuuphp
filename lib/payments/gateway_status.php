<?php
declare(strict_types=1);

// O status de uma cobrança do Mercado Pago que chega DEPOIS da tela: pelo
// webhook, ou pela resposta de um cancelamento (orders/status.php). As duas
// portas aplicam a mesma regra, que mora aqui (decisão 48).
//
// O caso que motivou: o dinheiro pode cair depois de o pedido ter acabado.
//   - cartão que o antifraude segurou em análise e aprovou mais tarde;
//   - Pix automático cujo QR o cliente pagou depois de cancelar;
//   - cobrança que o MP fez e nós não gravamos (a requisição caiu depois da
//     cobrança), seguida de uma segunda tentativa que pagou o pedido.
// Antes, o webhook só marcava o pagamento como aprovado: o cliente pagava
// por um pedido cancelado, ou pagava duas vezes, e nenhum estorno nascia.

// Pra onde o status de uma cobrança pode andar por aviso do gateway. Aviso
// repetido, fora de ordem ou velho (o MP reenvia até receber 200, e não
// garante a ordem) não desfaz o que já andou: 'approved' não volta pra
// 'in_process', e 'refunded' não volta pra 'approved' porque um aviso
// antigo chegou atrasado.
const PAYMENT_GATEWAY_TRANSITIONS = [
    'created' => ['in_process', 'approved', 'rejected'],
    'in_process' => ['approved', 'rejected'],
    // Recusado não volta... exceto pelo Mercado Pago dizendo que aprovou. Ele
    // é a fonte da verdade sobre o dinheiro: se o cliente pagou o QR no mesmo
    // segundo em que o cancelamos, o dinheiro está lá e precisa voltar.
    'rejected' => ['approved'],
    'approved' => ['refunded', 'charged_back'],
    'refunded' => [],
    'charged_back' => [],
];

/**
 * Aplica o status que o gateway informou pra uma cobrança. Chame DENTRO de
 * uma transação.
 *
 * Trava o pedido e depois a cobrança, nessa ordem (a mesma do pay.php e do
 * cancelamento, pra duas portas nunca travarem uma à outra), e relê. Dois
 * avisos do mesmo pagamento ao mesmo tempo (o MP manda payment.created e
 * payment.updated quase juntos) não avançam o pedido duas vezes: o segundo
 * vê o status já gravado e não faz nada.
 *
 * - Pedido esperando pagamento: aprovado vira 'paid'; recusado vira
 *   'rejected', com o motivo.
 * - Aprovado, mas o pedido já acabou (cancelado ou recusado) ou já foi pago
 *   por outra cobrança: pagamento tardio. Nasce o estorno integral
 *   (`record_late_payment_refund`) e a cobrança fica 'refunded'.
 *
 * @return array{order_id:int,status:string,changed:bool,late_refund:?array}
 */
function payment_apply_gateway_status(PDO $pdo, int $paymentId, string $status, ?string $detail, string $source): array
{
    $orderIdStmt = $pdo->prepare('SELECT order_id FROM payments WHERE id = :id');
    $orderIdStmt->execute(['id' => $paymentId]);
    $orderId = (int) $orderIdStmt->fetchColumn();

    $orderStmt = $pdo->prepare('SELECT id, status, payment_method FROM orders WHERE id = :id FOR UPDATE');
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderStmt->fetch();

    $paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
    $paymentStmt->execute(['id' => $paymentId]);
    $payment = $paymentStmt->fetch();

    $current = (string) $payment['status'];
    $result = ['order_id' => $orderId, 'status' => $current, 'changed' => false, 'late_refund' => null];
    if ($current === $status || !in_array($status, PAYMENT_GATEWAY_TRANSITIONS[$current] ?? [], true)) {
        return $result;
    }

    $setStatus = $pdo->prepare('UPDATE payments SET status = :status, status_detail = COALESCE(:detail, status_detail) WHERE id = :id');

    if ($status === 'approved') {
        $otherApproved = $pdo->prepare(
            "SELECT 1 FROM payments WHERE order_id = :order AND status = 'approved' AND id <> :id"
        );
        $otherApproved->execute(['order' => $orderId, 'id' => $paymentId]);
        $hasOtherApproved = $otherApproved->fetchColumn() !== false;
        $paysTheOrder = $order['status'] === 'pending_payment' && !$hasOtherApproved;

        if ($paysTheOrder) {
            $setStatus->execute(['status' => 'approved', 'detail' => $detail, 'id' => $paymentId]);
            call_advance_order($pdo, $orderId, 'paid', null, 'system', ['payment_id' => $paymentId, 'source' => $source]);

            return ['order_id' => $orderId, 'status' => 'approved', 'changed' => true, 'late_refund' => null];
        }

        // O dinheiro chegou, mas não paga pedido nenhum: volta inteiro. A
        // cobrança vira 'refunded' já na decisão, como em record_refund() --
        // e não 'approved', que bateria no índice de "um aprovado por pedido"
        // quando o pedido já tem outro.
        $why = match (true) {
            $hasOtherApproved => 'pagamento em dobro: o pedido já tinha outro pagamento aprovado',
            $order['status'] === 'cancelled' => 'pagamento aprovado depois de o pedido ser cancelado',
            $order['status'] === 'rejected' => 'pagamento aprovado depois de o pedido ser recusado',
            default => "pagamento aprovado com o pedido já pago de outra forma ({$order['status']})",
        };
        $setStatus->execute(['status' => 'refunded', 'detail' => 'late_payment', 'id' => $paymentId]);
        $refund = record_late_payment_refund($pdo, $order, $payment, $why);

        return ['order_id' => $orderId, 'status' => 'refunded', 'changed' => true, 'late_refund' => $refund];
    }

    $setStatus->execute(['status' => $status, 'detail' => $detail, 'id' => $paymentId]);

    // Cartão recusado depois da análise, ou Pix que expirou: o pedido que
    // esperava por esta cobrança não vai ser pago.
    if ($status === 'rejected' && $order['status'] === 'pending_payment') {
        $pdo->prepare('UPDATE orders SET reject_reason = :r WHERE id = :id')
            ->execute(['r' => $detail ?? 'pagamento recusado pelo Mercado Pago', 'id' => $orderId]);
        call_advance_order($pdo, $orderId, 'rejected', null, 'system', ['payment_id' => $paymentId, 'source' => $source]);
    }

    return ['order_id' => $orderId, 'status' => $status, 'changed' => true, 'late_refund' => null];
}

/**
 * Estorno de um pagamento tardio: o valor inteiro da cobrança, sem taxa, com
 * a causa 'late_payment' (migração 045). Idempotente por cobrança (não por
 * pedido, como record_refund): o pedido pode já ter o estorno de outra
 * cobrança.
 *
 * Nasce 'pending', na fila do admin (tela 13.4), como todo estorno; lá ele é
 * enviado e o executor devolve pelo Mercado Pago. Quem "paga" é a
 * plataforma só no rótulo: o dinheiro nunca foi de ninguém, então nada vai
 * pro livro (refund_ledger ignora esta causa) e o pedido não muda de status.
 */
function record_late_payment_refund(PDO $pdo, array $order, array $payment, string $why): array
{
    $existing = $pdo->prepare('SELECT * FROM refunds WHERE payment_id = :id ORDER BY id LIMIT 1');
    $existing->execute(['id' => $payment['id']]);
    $found = $existing->fetch();
    if ($found !== false) {
        return $found;
    }

    $raw = json_decode((string) ($payment['raw_response'] ?? ''), true);
    $isPix = $order['payment_method'] === 'pix_auto'
        || (is_array($raw) && ($raw['payment_method_id'] ?? null) === 'pix');

    $insert = $pdo->prepare(
        "INSERT INTO refunds (order_id, payment_id, refund_key, amount, fee, channel, payer, cause, note)
         VALUES (:order_id, :payment_id, :refund_key, :amount, 0, :channel, 'platform', 'late_payment', :note)
         RETURNING *"
    );
    $insert->execute([
        'order_id' => $order['id'],
        'payment_id' => $payment['id'],
        'refund_key' => uuid_v4(),
        'amount' => $payment['amount'],
        'channel' => $isPix ? 'pix_return' : 'gateway',
        'note' => mb_substr('Estorno automático: ' . $why . '.', 0, 500),
    ]);

    return $insert->fetch();
}

/**
 * Antes de desfazer um pedido que ainda espera pagamento: cancela no
 * Mercado Pago toda cobrança dele que ainda está aberta (Pix emitido,
 * cartão em análise). Chame DENTRO da transação que desfaz o pedido, com o
 * pedido já travado: o aviso de cancelamento que o MP manda em seguida
 * espera a transação acabar e encontra a cobrança já fechada.
 *
 * Devolve o id da cobrança que o MP disse estar APROVADA (o cliente pagou no
 * meio do caminho), ou null quando todas morreram -- essas ficam
 * 'rejected' ('cancelled_with_order'). Rede fora do ar vira exceção de
 * mp_cancel_payment(): sem a certeza de que a cobrança morreu, quem chamou
 * não desfaz o pedido.
 */
function cancel_open_gateway_charges(PDO $pdo, int $orderId): ?int
{
    $open = $pdo->prepare(
        "SELECT id, provider_ref FROM payments
          WHERE order_id = :id AND provider = 'mercadopago' AND status IN ('created','in_process')
          ORDER BY id
          FOR UPDATE"
    );
    $open->execute(['id' => $orderId]);
    $close = $pdo->prepare(
        "UPDATE payments SET status = 'rejected', status_detail = 'cancelled_with_order' WHERE id = :id"
    );
    foreach ($open->fetchAll() as $charge) {
        if (mp_cancel_payment((string) $charge['provider_ref']) === 'approved') {
            return (int) $charge['id'];
        }
        $close->execute(['id' => $charge['id']]);
    }

    return null;
}
