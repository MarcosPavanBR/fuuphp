<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Trocar a forma de pagamento de um pedido que ainda aguarda pagamento.
//
// O caso que motivou: o cliente escolhe Pix manual, o checkout acontece
// (o carrinho vira pedido em 'pending_payment'), e aí a loja não tem chave
// Pix, ou o cliente desiste do Pix e volta pra escolher cartão. Antes deste
// endpoint o pedido ficava preso ao método antigo: `orders/checkout.php` só
// roda uma vez por carrinho e `payments/pay.php` lê o método do pedido.
//
// Por que TROCAR o método em vez de "abandonar e abrir carrinho novo": o
// pedido já tem tudo decidido que não depende do método -- itens, frete,
// cupom resgatado, crédito de carteira consumido, vaga de agendamento
// reservada. Abandonar exigiria desfazer cada uma dessas coisas (devolver
// orçamento de campanha, recriar crédito, liberar vaga) só pra refazê-las
// em seguida. Trocar só mexe no que é do método. `orders.status` não muda:
// continua 'pending_payment', e `advance_order()` segue sendo o único
// caminho de mudança de status.
//
// Quando a troca é RECUSADA (409 payment_method_locked), e por quê:
//   - pagamento aprovado: não há o que trocar;
//   - Pix manual com comprovante enviado: a loja pode estar conferindo;
//   - Pix automático ou cartão já enviados ao Mercado Pago: o dinheiro pode
//     ainda cair (Pix pago depois, antifraude assíncrono), e um pedido com
//     dois métodos vivos é exatamente o que a regra "um pagamento aprovado
//     por pedido" existe pra impedir.
// O único pagamento que se descarta é o Pix manual SEM comprovante: é só um
// QR que ninguém pagou (ou pagou e vai reclamar -- o suporte vê o registro
// 'rejected' com status_detail 'method_changed').

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente troca a forma de pagamento.');
}
$body = read_json_body();

$orderId = (int) ($body['order_id'] ?? 0);
$newMethod = $body['payment_method'] ?? null;
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}
if (!in_array($newMethod, ['mp_card', 'pix_auto', 'pix_manual', 'cash', 'pos_machine'], true)) {
    error_response(422, 'invalid_payment_method', 'payment_method inválido.', fields: ['payment_method' => 'inválido']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

if ($order['status'] !== 'pending_payment') {
    error_response(409, 'order_not_awaiting_payment', 'Esse pedido não está aguardando pagamento.');
}

// Mesmas regras do checkout pro método novo: a loja precisa aceitar, e o
// troco / tipo de maquininha precisam fazer sentido.
$policy = resolve_policy($pdo, (string) $order['restaurant_id']);
if (!in_array($newMethod, $policy['enabled_methods'], true)) {
    error_response(422, 'payment_method_not_allowed', 'Essa loja não aceita esse método de pagamento.', fields: ['payment_method' => 'não habilitado por esta loja']);
}
$changeFor = isset($body['change_for']) ? money_input($body['change_for'], 0, 100000) : null;
if (isset($body['change_for']) && $changeFor === null) {
    error_response(422, 'invalid_change_for', 'Troco em reais.', fields: ['change_for' => 'inválido']);
}
if ($newMethod === 'cash' && $changeFor !== null && $changeFor < (float) $order['total']) {
    error_response(422, 'invalid_change_for', 'Troco precisa ser maior ou igual ao total.', fields: ['change_for' => 'inválido']);
}
$machineKind = $body['machine_kind'] ?? null;
if ($newMethod === 'pos_machine' && !in_array($machineKind, ['debit', 'credit'], true)) {
    error_response(422, 'machine_kind_required', 'Informe machine_kind: debit ou credit.', fields: ['machine_kind' => 'obrigatório']);
}

$pdo->beginTransaction();
try {
    // Trava o pedido: dois cliques em métodos diferentes não podem se cruzar.
    $pdo->prepare('SELECT id FROM orders WHERE id = :id FOR UPDATE')->execute(['id' => $orderId]);

    $paymentsStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = :id FOR UPDATE');
    $paymentsStmt->execute(['id' => $orderId]);
    $toDiscard = [];
    foreach ($paymentsStmt->fetchAll() as $payment) {
        if (in_array($payment['status'], ['rejected', 'refunded', 'charged_back'], true)) {
            continue; // tentativa morta, não segura nada
        }
        $isManualPix = $payment['provider'] === 'offline' && str_starts_with((string) $payment['provider_ref'], 'manual_');
        if (!$isManualPix || $payment['status'] !== 'in_process') {
            $pdo->rollBack();
            $message = $order['payment_method'] === 'pix_auto'
                ? 'O Pix automático já foi emitido — pague o QR, ou espere ele expirar pra escolher outra forma.'
                : 'Esse pagamento já foi iniciado e não dá mais pra trocar a forma.';
            error_response(409, 'payment_method_locked', $message);
        }
        $proofStmt = $pdo->prepare('SELECT 1 FROM payment_proofs WHERE payment_id = :id');
        $proofStmt->execute(['id' => $payment['id']]);
        if ($proofStmt->fetchColumn() !== false) {
            $pdo->rollBack();
            error_response(409, 'payment_method_locked', 'O comprovante já foi enviado — a loja está conferindo.');
        }
        $toDiscard[] = (int) $payment['id'];
    }

    foreach ($toDiscard as $paymentId) {
        $pdo->prepare("UPDATE payments SET status = 'rejected', status_detail = 'method_changed' WHERE id = :id")
            ->execute(['id' => $paymentId]);
    }

    // O prazo de 15 min é do Pix manual; sai junto com ele. `pay.php` põe
    // um novo se o método novo também for Pix manual.
    $pdo->prepare(
        'UPDATE orders SET payment_method = :method, change_for = :change_for, machine_kind = :machine_kind,
                           verification_deadline = NULL
          WHERE id = :id'
    )->execute([
        'method' => $newMethod,
        'change_for' => $newMethod === 'cash' ? $changeFor : null,
        'machine_kind' => $newMethod === 'pos_machine' ? $machineKind : null,
        'id' => $orderId,
    ]);

    $pdo->prepare(
        "INSERT INTO order_events (order_id, from_status, to_status, actor_id, actor_kind, meta)
         VALUES (:id, 'pending_payment', 'pending_payment', :actor, 'customer', :meta)"
    )->execute([
        'id' => $orderId,
        'actor' => $claims['sub'],
        'meta' => json_encode([
            'event' => 'payment_method_changed',
            'from' => $order['payment_method'],
            'to' => $newMethod,
            'discarded_payments' => $toDiscard,
        ]),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, ['order' => fetch_order($pdo, $orderId), 'discarded_payments' => $toDiscard]);
