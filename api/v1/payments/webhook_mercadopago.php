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
//
// Decisão 48: o aviso pode chegar repetido, fora de ordem, ao mesmo tempo
// que outro, ou depois de o pedido ter acabado. Nenhum desses casos pode
// avançar o pedido duas vezes nem deixar dinheiro sem destino.

require_method('POST');

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
$body = read_json_body();

$dataId = input_str($body['data'] ?? null, 'id');
$topic = input_str($body, 'type', input_str($body, 'topic'));

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
    // O corpo do webhook, neste modo, já carrega o status simulado direto
    // (e, pra testar a cobrança órfã, a referência e o valor).
    $gateway = [
        'status' => input_str($body, 'status', 'approved'),
        'status_detail' => is_string($body['status_detail'] ?? null) ? $body['status_detail'] : null,
        'external_reference' => input_str($body, 'external_reference'),
        'amount' => isset($body['transaction_amount']) ? money_input($body['transaction_amount'], 0.01, 1000000) : null,
    ];
} else {
    $gateway = mp_fetch_payment($dataId);
}

if ($gateway['status'] === '') {
    error_response(422, 'payment_not_found_upstream', 'Não deu pra confirmar esse pagamento no Mercado Pago.');
}
// 'pending', 'cancelled' etc. viram o vocabulário de payments.status.
$status = mp_normalize_status($gateway['status']);

$pdo->beginTransaction();
try {
    $paymentId = webhook_payment_id($pdo, $dataId, $gateway);
    if ($paymentId === null) {
        // Webhook de um pagamento que este backend não criou nem reconhece
        // (ex.: reenvio de teste do painel do Mercado Pago) -- responde 200
        // pra ele parar de reentregar, sem inventar um pedido pra associar.
        $pdo->rollBack();
        json_response(200, ['known' => false]);
    }

    // A regra de verdade (trava, transições permitidas, pagamento tardio)
    // é a mesma do cancelamento: lib/payments/gateway_status.php.
    $result = payment_apply_gateway_status($pdo, $paymentId, $status, $gateway['status_detail'], 'mp_webhook');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'order_id' => $result['order_id'],
    'status' => $result['status'],
    'changed' => $result['changed'],
    'late_refund' => $result['late_refund'] === null ? null : (int) $result['late_refund']['id'],
]);

/**
 * A cobrança deste aviso no nosso banco.
 *
 * Quando não existe, pode ser uma cobrança ÓRFÃ: o Mercado Pago cobrou, mas
 * a nossa requisição caiu antes de gravar (timeout de rede no pay.php). O
 * `external_reference` que mandamos na criação é o id do pedido: com ele, a
 * cobrança é gravada agora e segue a regra de sempre -- paga o pedido se ele
 * ainda espera pagamento, ou volta pro cliente se não (decisão 48). Antes,
 * esse dinheiro ficava na conta sem pedido e sem estorno.
 *
 * O pedido é travado ANTES de gravar a cobrança, na mesma ordem do pay.php:
 * se o pay.php ainda estiver no meio da cobrança, este aviso espera ele
 * terminar e encontra a linha que ele gravou.
 */
function webhook_payment_id(PDO $pdo, string $providerRef, array $gateway): ?int
{
    $find = $pdo->prepare("SELECT id FROM payments WHERE provider = 'mercadopago' AND provider_ref = :ref");
    $find->execute(['ref' => $providerRef]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $orderId = ctype_digit($gateway['external_reference']) ? (int) $gateway['external_reference'] : 0;
    if ($orderId <= 0 || $gateway['amount'] === null) {
        return null;
    }
    // A gorjeta cobrada no cartão (reviews/create.php) é outra cobrança do
    // mesmo pedido, gravada em reviews, não em payments. Não é órfã nem
    // pagamento em dobro: estornar seria tirar a gorjeta do entregador.
    $tip = $pdo->prepare('SELECT 1 FROM reviews WHERE tip_provider_ref = :ref');
    $tip->execute(['ref' => $providerRef]);
    if ($tip->fetchColumn() !== false) {
        return null;
    }
    $lock = $pdo->prepare('SELECT id FROM orders WHERE id = :id FOR UPDATE');
    $lock->execute(['id' => $orderId]);
    if ($lock->fetchColumn() === false) {
        return null;
    }

    $pdo->prepare(
        "INSERT INTO payments (order_id, provider, provider_ref, amount, status, raw_response)
         VALUES (:order_id, 'mercadopago', :ref, :amount, 'created', :raw)
         ON CONFLICT (provider, provider_ref) DO NOTHING"
    )->execute([
        'order_id' => $orderId,
        'ref' => $providerRef,
        'amount' => $gateway['amount'],
        'raw' => json_encode(['source' => 'mp_webhook_orphan'] + $gateway, JSON_UNESCAPED_UNICODE),
    ]);
    $find->execute(['ref' => $providerRef]);
    $id = $find->fetchColumn();

    return $id === false ? null : (int) $id;
}
