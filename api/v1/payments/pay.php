<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Fase 4 — dispara a cobrança do método já escolhido no checkout
// (orders/checkout.php). O método é lido do próprio pedido, não do corpo
// da requisição: o cliente não pode pagar um pedido de cartão como se
// fosse dinheiro só trocando o body.
//
// Idempotência (Especificação, Parte I §3): a especificação exige
// X-Idempotency-Key só para cartão ("sempre com X-Idempotency-Key"); este
// endpoint exige para os cinco métodos, de propósito -- nenhum deles pode
// rodar duas vezes por um retry de rede, nem os de validação humana.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente paga pedido.');
}
$idempotencyKey = require_idempotency_key();
$body = read_json_body();

$orderId = (int) ($body['order_id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

// A checagem de status/método do pedido fica DENTRO do handler, não aqui
// fora -- rodar antes de idempotent_response() faria um replay (chave já
// usada, pedido já 'paid' pela primeira chamada) cair nesse erro antes de
// nunca chegar no cache da resposta original.
idempotent_response($pdo, 'POST /v1/payments/pay', $idempotencyKey, $body, function () use ($pdo, $order, $body, $claims, $idempotencyKey): array {
    if ($order['status'] !== 'pending_payment') {
        error_response(409, 'order_not_awaiting_payment', 'Esse pedido não está aguardando pagamento.');
    }
    $method = $order['payment_method'];
    if ($method === null) {
        error_response(422, 'payment_method_missing', 'Esse pedido ainda não tem forma de pagamento definida. Chame orders/checkout.php primeiro.');
    }

    return match ($method) {
        'mp_card' => pay_with_card($pdo, $order, $body, $claims, $idempotencyKey),
        'pix_auto' => pay_with_pix($pdo, $order, $claims, true),
        'pix_manual' => pay_with_pix($pdo, $order, $claims, false),
        'cash', 'pos_machine' => pay_offline($pdo, $order, $claims),
        default => throw new RuntimeException("payment_method desconhecido: {$method}"),
    };
});

/**
 * @return array{0:int,1:array}
 */
function pay_with_card(PDO $pdo, array $order, array $body, array $claims, string $idempotencyKey): array
{
    $cardToken = $body['card_token'] ?? null;
    $installments = (int) ($body['installments'] ?? 1);
    if (!is_string($cardToken) || $cardToken === '') {
        error_response(422, 'card_token_required', 'Token do cartão ausente — a tokenização acontece no navegador, via MercadoPago.js.', fields: ['card_token' => 'obrigatório']);
    }
    if ($installments < 1 || $installments > 12) {
        error_response(422, 'invalid_installments', 'Parcelas inválidas.', fields: ['installments' => 'inválido']);
    }
    $payerCpf = isset($body['payer_cpf']) ? only_digits((string) $body['payer_cpf']) : null;
    if ($payerCpf !== null && !is_valid_cpf($payerCpf)) {
        error_response(422, 'invalid_payer_cpf', 'CPF do titular inválido.', fields: ['payer_cpf' => 'inválido']);
    }

    $userStmt = $pdo->prepare('SELECT email, mp_customer_id FROM users WHERE id = :id');
    $userStmt->execute(['id' => $claims['sub']]);
    $payer = $userStmt->fetch() ?: [];
    $payerEmail = (string) (($payer['email'] ?? null) ?: 'cliente@fuudelivery.com.br');

    // Tela 6.2 → 4.2 — cartão salvo: "pagar com ele ainda exige CVV e gera
    // novo token de uso único". O CVV nunca chega aqui: o navegador gera o
    // token com MercadoPago.js a partir do card_id + CVV, e manda só o token
    // e QUAL cartão salvo foi usado. O servidor confere que o cartão é desta
    // pessoa e cobra como cliente do Mercado Pago (payer.type = customer),
    // que é o que o MP exige pra token de cartão salvo -- e o que permite,
    // depois, cobrar a gorjeta "no mesmo cartão do pedido" (reviews/create.php).
    $savedCard = null;
    if (isset($body['saved_card_id'])) {
        $cardStmt = $pdo->prepare('SELECT * FROM saved_cards WHERE id = :id AND user_id = :user');
        $cardStmt->execute(['id' => (int) $body['saved_card_id'], 'user' => $claims['sub']]);
        $savedCard = $cardStmt->fetch();
        if ($savedCard === false) {
            error_response(404, 'card_not_found', 'Cartão salvo não encontrado.');
        }
        if (($payer['mp_customer_id'] ?? null) === null) {
            error_response(409, 'card_not_linked', 'Esse cartão não está mais vinculado à sua conta no Mercado Pago. Cadastre de novo.');
        }
    }

    $mp = mp_create_card_payment(
        $idempotencyKey,
        (float) $order['total'],
        $cardToken,
        $installments,
        $payerEmail,
        $payerCpf,
        $savedCard === null ? null : (string) $payer['mp_customer_id']
    );
    if ($savedCard !== null) {
        // O cartão usado fica no registro do pagamento: é o que o recibo e o
        // suporte mostram ("Visa •••• 6351"), mesmo no modo de teste.
        $mp['card_brand'] = $savedCard['brand'];
        $mp['card_last4'] = $savedCard['last4'];
        $mp['raw']['saved_card_id'] = (int) $savedCard['id'];
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO payments (order_id, provider, provider_ref, amount, status, status_detail, raw_response)
             VALUES (:order_id, :provider, :provider_ref, :amount, :status, :status_detail, :raw) RETURNING *'
        );
        $insert->execute([
            'order_id' => $order['id'],
            'provider' => 'mercadopago',
            'provider_ref' => $mp['provider_ref'],
            'amount' => $order['total'],
            'status' => $mp['status'],
            'status_detail' => $mp['status_detail'],
            'raw' => json_encode($mp['raw'], JSON_UNESCAPED_UNICODE),
        ]);
        $payment = $insert->fetch();

        if ($mp['status'] === 'approved') {
            call_advance_order($pdo, (int) $order['id'], 'paid', (string) $claims['sub'], 'customer', [
                'payment_id' => $payment['id'],
                'card_brand' => $mp['card_brand'],
                'card_last4' => $mp['card_last4'],
            ]);
        } else {
            $pdo->prepare('UPDATE orders SET reject_reason = :r WHERE id = :id')
                ->execute(['r' => $mp['status_detail'] ?? 'pagamento recusado pelo emissor', 'id' => $order['id']]);
            call_advance_order($pdo, (int) $order['id'], 'rejected', (string) $claims['sub'], 'customer', ['payment_id' => $payment['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [$mp['status'] === 'approved' ? 200 : 402, [
        'order' => fetch_order($pdo, (int) $order['id']),
        'payment' => $payment,
    ]];
}

/**
 * @return array{0:int,1:array}
 */
function pay_with_pix(PDO $pdo, array $order, array $claims, bool $auto): array
{
    // Cobrança Pix já emitida e ainda viva? Devolve a MESMA em vez de emitir
    // outra. Sem isto, voltar da tela do QR e escolher Pix de novo criava um
    // segundo QR pro mesmo pedido -- e o cliente podia pagar os dois.
    $existingStmt = $pdo->prepare(
        "SELECT * FROM payments WHERE order_id = :id AND status = 'in_process' AND provider = :provider
          ORDER BY id DESC LIMIT 1"
    );
    $existingStmt->execute(['id' => $order['id'], 'provider' => $auto ? 'mercadopago' : 'offline']);
    $existing = $existingStmt->fetch();

    if ($auto) {
        if ($existing !== false) {
            $raw = json_decode((string) $existing['raw_response'], true) ?: [];
            $poi = $raw['point_of_interaction']['transaction_data'] ?? [];
            if (isset($poi['qr_code'])) {
                return [200, [
                    'order' => fetch_order($pdo, (int) $order['id']),
                    'payment' => $existing,
                    'pix_copy_paste' => $poi['qr_code'],
                    'pix_qr_base64' => $poi['qr_code_base64'] ?? null,
                    'reused' => true,
                ]];
            }
        }
        $userStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $claims['sub']]);
        $payerEmail = (string) ($userStmt->fetchColumn() ?: 'cliente@fuudelivery.com.br');

        $mp = mp_create_pix_payment((float) $order['total'], $payerEmail);
        $provider = 'mercadopago';
        $providerRef = $mp['provider_ref'];
        $status = $mp['status'];
        $raw = $mp['raw'];
        $copyPaste = $mp['qr_code'];
        $qrBase64 = $mp['qr_code_base64'];
    } else {
        // pix_manual: o dinheiro cai direto na chave Pix da própria loja
        // (white-label) -- não passa pelo Mercado Pago, por isso exige
        // comprovante do cliente e validação humana da loja (Fase 7.3).
        $credStmt = $pdo->prepare('SELECT pix_key FROM restaurant_credentials WHERE restaurant_id = :id');
        $credStmt->execute(['id' => $order['restaurant_id']]);
        $pixKey = $credStmt->fetchColumn();
        if ($pixKey === false || $pixKey === null || $pixKey === '') {
            error_response(422, 'pix_key_missing', 'Essa loja ainda não cadastrou uma chave Pix.');
        }

        $restaurantStmt = $pdo->prepare('SELECT name FROM restaurants WHERE id = :id');
        $restaurantStmt->execute(['id' => $order['restaurant_id']]);
        $restaurantName = (string) ($restaurantStmt->fetchColumn() ?: 'FUUdelivery');

        if ($existing !== false) {
            // O copia-e-cola do Pix manual é determinístico (chave, valor,
            // código do pedido): recalcular dá o mesmo texto que o cliente já viu.
            return [200, [
                'order' => fetch_order($pdo, (int) $order['id']),
                'payment' => $existing,
                'pix_copy_paste' => pix_copy_paste((string) $pixKey, (float) $order['total'], (string) $order['public_code'], $restaurantName, 'BRASIL'),
                'pix_qr_base64' => null,
                'reused' => true,
            ]];
        }

        $providerRef = 'manual_' . bin2hex(random_bytes(8));
        $provider = 'offline';
        $status = 'in_process';
        $copyPaste = pix_copy_paste((string) $pixKey, (float) $order['total'], (string) $order['public_code'], $restaurantName, 'BRASIL');
        $qrBase64 = null;
        $raw = ['pix_key' => $pixKey];
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO payments (order_id, provider, provider_ref, amount, status, raw_response)
             VALUES (:order_id, :provider, :provider_ref, :amount, :status, :raw) RETURNING *'
        );
        $insert->execute([
            'order_id' => $order['id'],
            'provider' => $provider,
            'provider_ref' => $providerRef,
            'amount' => $order['total'],
            'status' => $status,
            'raw' => json_encode($raw, JSON_UNESCAPED_UNICODE),
        ]);
        $payment = $insert->fetch();

        // Prazo único (Fase 4.3 + 5.2 do mock): 15 minutos que cobrem pagar,
        // enviar comprovante (se manual) e a loja validar -- não é reiniciado
        // no upload, só lido de novo pela tela de acompanhamento.
        $pdo->prepare("UPDATE orders SET verification_deadline = now() + interval '15 minutes' WHERE id = :id")
            ->execute(['id' => $order['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [201, [
        'order' => fetch_order($pdo, (int) $order['id']),
        'payment' => $payment,
        'pix_copy_paste' => $copyPaste,
        'pix_qr_base64' => $qrBase64,
    ]];
}

/**
 * Dinheiro e maquininha: pagos fisicamente na entrega, não agora. Não há
 * captura de valor aqui -- só o registro da intenção. A cozinha pode
 * começar de imediato (paid) porque nenhum dinheiro trocou de mãos ainda
 * para exigir conferência; quem confere é o entregador na entrega (Fase 8/9,
 * courier_cash_ledger e card_transactions, ainda não construídos).
 *
 * @return array{0:int,1:array}
 */
function pay_offline(PDO $pdo, array $order, array $claims): array
{
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            "INSERT INTO payments (order_id, provider, amount, status)
             VALUES (:order_id, 'offline', :amount, 'created') RETURNING *"
        );
        $insert->execute(['order_id' => $order['id'], 'amount' => $order['total']]);
        $payment = $insert->fetch();

        call_advance_order($pdo, (int) $order['id'], 'paid', (string) $claims['sub'], 'customer', ['payment_id' => $payment['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [200, [
        'order' => fetch_order($pdo, (int) $order['id']),
        'payment' => $payment,
    ]];
}
