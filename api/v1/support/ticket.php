<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 14.1 — abrir chamado, depois do fluxo automático.
//
// O chamado nasce com prazo (`sla_due_at`), porque "SLA por categoria" é o
// que separa um chamado de uma mensagem perdida: pedido atrasado tem 15 min,
// estorno tem um dia útil. O índice `tickets_sla_idx` (migração 008) existe
// justamente pra varrer o que venceu.
//
// O chamado também deixa a primeira mensagem na conversa DO PEDIDO (14.2)
// quando há pedido: suporte que não enxerga a conversa vira o ping-pong de
// "qual o número do pedido?" que a Fase 14 existe pra matar.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'A central de ajuda é do cliente.');
}
$body = read_json_body();

$category = $body['category'] ?? '';
if (!is_string($category) || !array_key_exists($category, SUPPORT_SLA_MINUTES)) {
    error_response(422, 'invalid_category', 'Categoria de chamado desconhecida.', fields: ['category' => 'inválida']);
}

$message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
if ($message === '') {
    error_response(422, 'message_required', 'Conta o que aconteceu — é o que o atendente lê primeiro.', fields: ['message' => 'obrigatório']);
}
if (mb_strlen($message) > 1000) {
    error_response(422, 'message_too_long', 'Mensagem muito longa (máx. 1000 caracteres).', fields: ['message' => 'longa demais']);
}

$pdo = db();

$order = null;
if (isset($body['order_id'])) {
    $order = fetch_order($pdo, (int) $body['order_id']);
    if ($order === null) {
        error_response(404, 'order_not_found', 'Pedido não encontrado.');
    }
    authorize_order_access($order, $claims);
}

// Um chamado aberto por categoria e pedido: apertar duas vezes o mesmo
// atalho é a mesma pessoa com o mesmo problema, não dois problemas.
$openStmt = $pdo->prepare(
    "SELECT * FROM tickets
      WHERE user_id = :uid AND category = :cat AND state <> 'resolved'
        AND (order_id IS NOT DISTINCT FROM :oid)
      ORDER BY created_at DESC LIMIT 1"
);
$openStmt->execute([
    'uid' => $claims['sub'],
    'cat' => $category,
    'oid' => $order === null ? null : $order['id'],
]);
$existing = $openStmt->fetch();

$pdo->beginTransaction();
try {
    if ($existing !== false) {
        $ticket = $existing;
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO tickets (code, user_id, order_id, category, state, sla_due_at)
             VALUES (:code, :uid, :oid, :cat, 'open', now() + (:sla || ' minutes')::interval)
             RETURNING *"
        );
        $insert->execute([
            'code' => support_ticket_code($pdo),
            'uid' => $claims['sub'],
            'oid' => $order === null ? null : $order['id'],
            'cat' => $category,
            'sla' => SUPPORT_SLA_MINUTES[$category],
        ]);
        $ticket = $insert->fetch();
    }

    if ($order !== null) {
        $pdo->prepare(
            "INSERT INTO order_messages (order_id, sender_id, sender_role, body)
             VALUES (:oid, :uid, 'customer', :body)"
        )->execute([
            'oid' => $order['id'],
            'uid' => $claims['sub'],
            'body' => "[{$ticket['code']}] {$message}",
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response($existing === false ? 201 : 200, [
    'ticket' => $ticket,
    'reopened' => $existing !== false,
    // Sem pedido, a mensagem não tem onde morar: a conversa é POR PEDIDO
    // (14.2) e não existe caixa de entrada avulsa na especificação. A tela
    // diz isso em vez de fingir que alguém já leu.
    'message_delivered' => $order !== null,
]);
