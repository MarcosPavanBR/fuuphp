<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Pede uma mudança de status do pedido. Aqui só se decide QUEM pode pedir
// cada transição (loja: preparo, pronto, saiu, recusar, cancelar e -- só
// na retirada no balcão -- entregue; cliente: cancelar); se a transição é válida, quem decide é `advance_order()` no
// banco -- o único caminho que muda orders.status.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$orderId = positive_id($body['order_id'] ?? null) ?? 0;
$to = $body['to'] ?? null;
// O motivo aparece pro cliente e vai pro histórico do pedido: texto curto.
$reason = body_text($body, 'reason', 300);

if ($orderId <= 0 || !is_string($to)) {
    error_response(422, 'invalid_request', 'Informe order_id e to.');
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

authorize_order_access($order, $claims);

$role = $claims['role'];
// Quem pode pedir qual transição -- a legalidade da transição em si (de/para)
// continua só na função do banco; isto aqui é só "quem tem permissão de pedir".
$allowedTargetsByRole = [
    'customer' => ['cancelled'],
    // 'delivering' está aqui porque quem entrega a sacola em mãos é a loja:
    // o botão "Entregue ao motoboy" da tela 11.1 é dela, não do entregador.
    // Quando o app do entregador existir, ele ganha o mesmo alvo -- são duas
    // pessoas que podem registrar a mesma passagem de bastão.
    'restaurant_staff' => ['preparing', 'ready', 'delivering', 'cancelled', 'rejected'],
];
$allowed = $allowedTargetsByRole[$role] ?? [];

// 15.1 — pedido que virou retirada não tem entregador pra fechar a corrida.
// Quem entrega a sacola na mão do cliente é a loja, no balcão, então é ela
// que pode registrar 'delivered' -- e SÓ nesse caso: em pedido com entrega,
// quem confirma que chegou é quem chegou.
if ($role === 'restaurant_staff' && $order['pickup_by_customer'] === true) {
    $allowed[] = 'delivered';
}

if (!in_array($to, $allowed, true)) {
    error_response(403, 'forbidden', "Esse papel não pode pedir a transição para \"{$to}\".");
}

// Fase 13 — desfazer um pedido não é só mudar o status: alguém pagou, e esse
// dinheiro precisa de destino. Cancelamento e recusa exigem motivo porque é
// ele que decide a causa do reembolso (e, no caso do cliente, alimenta o
// ranking da loja).
$undoing = in_array($to, ['cancelled', 'rejected'], true);
if ($undoing && ($reason === null || trim($reason) === '')) {
    error_response(422, 'reason_required', 'Diga o motivo — ele decide quem arca com o estorno.', fields: ['reason' => 'obrigatório']);
}

$meta = $reason !== null ? ['reason' => $reason] : [];

// order_events.actor_kind não usa os mesmos rótulos de users.role
// (Especificação, Parte II §9: 'customer','store','courier','admin','system').
$actorKind = $role === 'restaurant_staff' ? 'store' : 'customer';

// A causa do reembolso vem de QUEM desfez, não do texto do motivo: cliente
// cancelando é 'customer_cancel', loja recusando pedido já aceito é
// 'store_reject' -- e é isso que decide de qual bolso sai o estorno.
$cause = match (true) {
    $to === 'rejected' || $role === 'restaurant_staff' => 'store_reject',
    // Tela 15.1: cancelar um pedido pronto que ninguém aceitou não é
    // desistência do cliente, é falha nossa de despacho -- e por isso a
    // devolução é integral e quem paga a comida já feita somos nós.
    $order['status'] === 'ready' && $order['courier_id'] === null && $order['no_courier_since'] !== null => 'no_courier',
    default => 'customer_cancel',
};

// O plano tem que ser calculado ANTES da transição: depois o pedido já está
// 'cancelled' e a taxa (que depende de a cozinha ter começado) seria sempre
// zero. Um pedido que nunca foi pago não gera reembolso nenhum.
$plan = null;
if ($undoing && (string) $order['status'] !== 'pending_payment') {
    $plan = refund_plan($order, policy_for_order($pdo, $order), $cause);
}

$refund = null;
$pdo->beginTransaction();
try {
    if ($to === 'cancelled' && $reason !== null) {
        $pdo->prepare('UPDATE orders SET cancel_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $orderId]);
    }
    if ($to === 'rejected' && $reason !== null) {
        $pdo->prepare('UPDATE orders SET reject_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $orderId]);
    }

    call_advance_order($pdo, $orderId, $to, (string) $claims['sub'], $actorKind, $meta);

    // Mesma transação de propósito: um pedido desfeito sem a decisão do
    // dinheiro registrada junto é exatamente o estado que trava reembolso
    // por dias (tela 13.4).
    if ($plan !== null) {
        $refund = record_refund($pdo, $order, $plan, (string) $claims['sub']);
    }

    // Retirada no balcão fechada pela loja: o mesmo acerto da entrega.
    if ($to === 'delivered') {
        ledger_order_delivered($pdo, fetch_order($pdo, $orderId), (string) $claims['sub']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// Pedido pronto vira corrida oferecida (Fase 8). É o despacho mínimo do
// lib/dispatch/dispatch.php -- uma rodada, sem raio e sem surge, até a Fase 15 existir.
if ($to === 'ready') {
    ensure_offer($pdo, fetch_order($pdo, $orderId));
}

$response = ['order' => fetch_order($pdo, $orderId)];
if ($plan !== null) {
    $response['refund_plan'] = $plan;
    $response['refund'] = $refund;
}

json_response(200, $response);
