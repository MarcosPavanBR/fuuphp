<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Webhook do Mercado Pago (Fase 5.1: "O webhook do Mercado Pago é a fonte
// da verdade, não a resposta da tela"). Cobre dois casos que a resposta
// síncrona de payments/pay.php não fecha sozinha: cartão que muda de status
// depois de "in_process" (ex.: antifraude assíncrono) e Pix automático
// (pix_auto), que é sempre assíncrono -- a resposta de pay.php só devolve
// o QR, quem confirma o pagamento é este webhook.
//
// Sem MERCADOPAGO_WEBHOOK_SECRET configurado, a assinatura não é conferida
// (mp_verify_webhook_signature() já documenta isso) -- aceitável só em dev.

require_method('POST');

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
$body = read_json_body();

$dataId = (string) ($body['data']['id'] ?? '');
$topic = (string) ($body['type'] ?? $body['topic'] ?? '');

if ($dataId === '' || $topic !== 'payment') {
    // Outros tópicos (merchant_order, etc.) não interessam a este projeto.
    json_response(200, ['ignored' => true]);
}

if (!mp_verify_webhook_signature((string) $xSignature, (string) $xRequestId, $dataId)) {
    error_response(401, 'invalid_signature', 'Assinatura do webhook inválida.');
}

$pdo = db();

if (mp_mode() === 'fake') {
    // Sem conta real, não há um /v1/payments/{id} de verdade pra consultar.
    // O corpo do webhook, neste modo, já carrega o status simulado direto.
    $status = (string) ($body['status'] ?? 'approved');
    $statusDetail = $body['status_detail'] ?? null;
} else {
    $resp = mp_request('GET', "/v1/payments/{$dataId}", []);
    $status = (string) ($resp['body']['status'] ?? '');
    $statusDetail = $resp['body']['status_detail'] ?? null;
}

if ($status === '') {
    error_response(422, 'payment_not_found_upstream', 'Não deu pra confirmar esse pagamento no Mercado Pago.');
}

$paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE provider = 'mercadopago' AND provider_ref = :ref");
$paymentStmt->execute(['ref' => $dataId]);
$payment = $paymentStmt->fetch();
if ($payment === false) {
    // Webhook de um pagamento que este backend não criou (ex.: reenvio de
    // teste do painel do Mercado Pago) -- responde 200 pra ele parar de
    // reentregar, sem inventar um pedido pra associar.
    json_response(200, ['known' => false]);
}

if ($payment['status'] === $status) {
    json_response(200, ['order_id' => $payment['order_id'], 'status' => $status, 'changed' => false]);
}

$orderId = (int) $payment['order_id'];
$order = fetch_order($pdo, $orderId);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE payments SET status = :status, status_detail = :detail WHERE id = :id')
        ->execute(['status' => $status, 'detail' => $statusDetail, 'id' => $payment['id']]);

    if ($order !== null && $order['status'] === 'pending_payment') {
        if ($status === 'approved') {
            call_advance_order($pdo, $orderId, 'paid', null, 'system', ['payment_id' => $payment['id'], 'source' => 'mp_webhook']);
        } elseif (in_array($status, ['rejected', 'cancelled'], true)) {
            $pdo->prepare('UPDATE orders SET reject_reason = :r WHERE id = :id')
                ->execute(['r' => $statusDetail ?? 'pagamento recusado pelo Mercado Pago', 'id' => $orderId]);
            call_advance_order($pdo, $orderId, 'rejected', null, 'system', ['payment_id' => $payment['id'], 'source' => 'mp_webhook']);
        }
        // outros status (in_process, pending) não avançam pedido -- só atualiza o registro do pagamento.
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, ['order_id' => $orderId, 'status' => $status, 'changed' => true]);
