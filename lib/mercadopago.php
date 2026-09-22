<?php
declare(strict_types=1);

// Cliente HTTP para a Payments API do Mercado Pago (cláusula zero: único
// gateway de cartão/Pix automático deste projeto -- nunca AbacatePay, nunca
// outro gateway). O cartão é tokenizado no navegador pelo MercadoPago.js;
// só o token chega até aqui, nunca o número do cartão (Fase 4.2 do mock).
//
// Limite honesto deste ambiente: não há conta sandbox real do Mercado Pago
// disponível para testar contra a API de verdade. MERCADOPAGO_MODE=fake
// (o padrão quando MERCADOPAGO_ACCESS_TOKEN não está configurado) simula
// respostas no mesmo formato da API real, usando a mesma convenção de
// prefixo que os cartões de teste do próprio Mercado Pago usam (token
// começando com "OTHE"/"CONT" recusa, qualquer outro aprova) -- serve para
// os smoke tests e o front funcionarem ponta a ponta neste repositório.
// Em produção, configure MERCADOPAGO_ACCESS_TOKEN (e deixe MERCADOPAGO_MODE
// vazio ou "live"): o código passa a chamar api.mercadopago.com de verdade,
// sem precisar mudar uma linha de chamada.

function mp_mode(): string
{
    $mode = env('MERCADOPAGO_MODE');
    if ($mode !== null && $mode !== '') {
        return $mode;
    }
    return env('MERCADOPAGO_ACCESS_TOKEN', '') === '' ? 'fake' : 'live';
}

function mp_request(string $method, string $path, array $body, ?string $idempotencyKey = null): array
{
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . env('MERCADOPAGO_ACCESS_TOKEN', '')];
    if ($idempotencyKey !== null) {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init('https://api.mercadopago.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Mercado Pago indisponível: {$err}");
    }
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    return ['http_status' => $httpStatus, 'body' => is_array($decoded) ? $decoded : []];
}

/**
 * @return array{provider_ref:string,status:string,status_detail:?string,card_brand:?string,card_last4:?string,raw:array}
 */
function mp_create_card_payment(string $idempotencyKey, float $amount, string $cardToken, int $installments, string $payerEmail, ?string $payerCpf): array
{
    if (mp_mode() === 'fake') {
        return mp_fake_card_payment($cardToken, $amount, $installments);
    }

    $payer = ['email' => $payerEmail];
    if ($payerCpf !== null) {
        $payer['identification'] = ['type' => 'CPF', 'number' => $payerCpf];
    }
    $resp = mp_request('POST', '/v1/payments', [
        'transaction_amount' => $amount,
        'token' => $cardToken,
        'installments' => $installments,
        'payer' => $payer,
    ], $idempotencyKey);

    return mp_parse_payment_response($resp);
}

/**
 * @return array{provider_ref:string,status:string,qr_code:?string,qr_code_base64:?string,raw:array}
 */
function mp_create_pix_payment(float $amount, string $payerEmail): array
{
    if (mp_mode() === 'fake') {
        return mp_fake_pix_payment($amount);
    }

    $resp = mp_request('POST', '/v1/payments', [
        'transaction_amount' => $amount,
        'payment_method_id' => 'pix',
        'payer' => ['email' => $payerEmail],
    ]);
    $body = $resp['body'];
    $poi = $body['point_of_interaction']['transaction_data'] ?? [];

    return [
        'provider_ref' => (string) ($body['id'] ?? ''),
        'status' => (string) ($body['status'] ?? 'in_process'),
        'qr_code' => $poi['qr_code'] ?? null,
        'qr_code_base64' => $poi['qr_code_base64'] ?? null,
        'raw' => $body,
    ];
}

function mp_parse_payment_response(array $resp): array
{
    $body = $resp['body'];
    if ($resp['http_status'] >= 400 && !isset($body['id'])) {
        throw new RuntimeException('Mercado Pago recusou a requisição: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $card = $body['card'] ?? [];
    return [
        'provider_ref' => (string) ($body['id'] ?? ''),
        'status' => (string) ($body['status'] ?? 'in_process'),
        'status_detail' => $body['status_detail'] ?? null,
        'card_brand' => $body['payment_method_id'] ?? null,
        'card_last4' => $card['last_four_digits'] ?? null,
        'raw' => $body,
    ];
}

function mp_fake_card_payment(string $cardToken, float $amount, int $installments): array
{
    $upperToken = strtoupper($cardToken);
    $approved = !str_starts_with($upperToken, 'OTHE') && !str_starts_with($upperToken, 'CONT') && !str_starts_with($upperToken, 'FUND');
    return [
        'provider_ref' => 'fake_' . bin2hex(random_bytes(8)),
        'status' => $approved ? 'approved' : 'rejected',
        'status_detail' => $approved ? 'accredited' : 'cc_rejected_other_reason',
        'card_brand' => 'visa',
        'card_last4' => substr(str_pad($cardToken, 4, '0', STR_PAD_LEFT), -4),
        'raw' => ['mode' => 'fake', 'amount' => $amount, 'installments' => $installments],
    ];
}

function mp_fake_pix_payment(float $amount): array
{
    $ref = 'fake_' . bin2hex(random_bytes(8));
    return [
        'provider_ref' => $ref,
        'status' => 'in_process',
        'qr_code' => 'fake-pix-copia-e-cola-' . $ref,
        'qr_code_base64' => base64_encode('fake-qr:' . $ref),
        'raw' => ['mode' => 'fake', 'amount' => $amount],
    ];
}

/**
 * Fase 6.2 — cartão salvo. Modelo do Mercado Pago: um Customer por
 * usuário, N Cards por Customer. Cria o Customer só na primeira vez
 * (users.mp_customer_id fica null até então); toda chamada seguinte
 * reaproveita o id salvo.
 *
 * @return array{mp_customer_id:string}
 */
function mp_create_customer(string $email): array
{
    if (mp_mode() === 'fake') {
        return ['mp_customer_id' => 'fake_customer_' . bin2hex(random_bytes(8))];
    }
    $resp = mp_request('POST', '/v1/customers', ['email' => $email]);
    return ['mp_customer_id' => (string) ($resp['body']['id'] ?? '')];
}

/**
 * @return array{mp_card_id:string,brand:string,last4:string,exp_month:int,exp_year:int}
 */
function mp_create_card(string $mpCustomerId, string $cardToken): array
{
    if (mp_mode() === 'fake') {
        return mp_fake_create_card($cardToken);
    }
    $resp = mp_request('POST', "/v1/customers/{$mpCustomerId}/cards", ['token' => $cardToken]);
    $body = $resp['body'];
    if ($resp['http_status'] >= 400 && !isset($body['id'])) {
        throw new RuntimeException('Mercado Pago recusou salvar o cartão: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    return [
        'mp_card_id' => (string) ($body['id'] ?? ''),
        'brand' => (string) ($body['payment_method']['id'] ?? 'desconhecida'),
        'last4' => (string) ($body['last_four_digits'] ?? '0000'),
        'exp_month' => (int) ($body['expiration_month'] ?? 1),
        'exp_year' => (int) ($body['expiration_year'] ?? 2000),
    ];
}

function mp_delete_card(string $mpCustomerId, string $mpCardId): void
{
    if (mp_mode() === 'fake') {
        return;
    }
    mp_request('DELETE', "/v1/customers/{$mpCustomerId}/cards/{$mpCardId}", []);
}

/**
 * Sem BIN de verdade pra consultar (não há base de bandeiras neste
 * ambiente), a bandeira em modo fake é um palpite simples pelo primeiro
 * dígito do token-placeholder (que, no CardForm.svelte, são os próprios
 * dígitos do cartão) -- só cosmético, nunca usado pra decidir cobrança.
 */
function mp_fake_create_card(string $cardToken): array
{
    $digits = preg_replace('/\D/', '', $cardToken) ?? '';
    $digits = $digits !== '' ? $digits : '4000000000000000';
    $brand = str_starts_with($digits, '5') ? 'mastercard' : (str_starts_with($digits, '4') ? 'visa' : 'elo');
    return [
        'mp_card_id' => 'fake_card_' . bin2hex(random_bytes(8)),
        'brand' => $brand,
        'last4' => substr(str_pad($digits, 4, '0', STR_PAD_LEFT), -4),
        'exp_month' => 11,
        'exp_year' => (int) date('Y') + 3,
    ];
}

/**
 * Confere a assinatura do webhook do Mercado Pago (header x-signature,
 * formato "ts=...,v1=..."), seguindo o manifesto documentado:
 * "id:{data.id};request-id:{x-request-id};ts:{ts};". Sem
 * MERCADOPAGO_WEBHOOK_SECRET configurado, a verificação é pulada (mesmo
 * padrão de ALLOWED_ORIGIN vazio) -- aceitável só em dev, nunca em produção.
 */
function mp_verify_webhook_signature(string $xSignature, string $xRequestId, string $dataId): bool
{
    $secret = env('MERCADOPAGO_WEBHOOK_SECRET', '');
    if ($secret === '') {
        return true;
    }

    $parts = [];
    foreach (explode(',', $xSignature) as $chunk) {
        [$k, $v] = array_pad(explode('=', trim($chunk), 2), 2, '');
        $parts[$k] = $v;
    }
    if (!isset($parts['ts'], $parts['v1'])) {
        return false;
    }

    $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$parts['ts']};";
    $expected = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($expected, $parts['v1']);
}

/**
 * Estorno de um pagamento no Mercado Pago (tela 13.4, chip "MP refund API").
 *
 * `POST /v1/payments/{id}/refunds` com `amount` pra estorno parcial; sem
 * `amount`, o gateway estorna tudo. O `X-Idempotency-Key` é o `refund_key`
 * da linha em `refunds`: se o executor cair entre mandar e gravar a
 * resposta, a segunda tentativa devolve o MESMO estorno em vez de criar
 * outro -- "idempotente por refund_key" de ponta a ponta, inclusive fora
 * do nosso banco.
 *
 * Modo fake: aprova sempre, com um id sintético, no mesmo formato da
 * resposta real. Pagamento cujo provider_ref começa com "FAIL" simula a
 * recusa do gateway -- é o que deixa o teste exercitar o caminho de falha.
 *
 * @return array{provider_ref:string,status:string,raw:array}
 */
function mp_refund_payment(string $paymentProviderRef, float $amount, string $idempotencyKey): array
{
    if (mp_mode() === 'fake') {
        if (str_starts_with(strtoupper($paymentProviderRef), 'FAIL')) {
            throw new RuntimeException('Mercado Pago recusou o estorno: payment not refundable (simulado)');
        }

        return [
            'provider_ref' => 'fake-refund-' . substr(hash('sha256', $idempotencyKey), 0, 12),
            'status' => 'approved',
            'raw' => ['simulated' => true, 'amount' => $amount, 'payment_id' => $paymentProviderRef],
        ];
    }

    $resp = mp_request(
        'POST',
        '/v1/payments/' . rawurlencode($paymentProviderRef) . '/refunds',
        ['amount' => round($amount, 2)],
        $idempotencyKey
    );
    if ($resp['http_status'] >= 400 || !isset($resp['body']['id'])) {
        throw new RuntimeException('Mercado Pago recusou o estorno: ' . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
    }

    return [
        'provider_ref' => (string) $resp['body']['id'],
        'status' => (string) ($resp['body']['status'] ?? 'approved'),
        'raw' => $resp['body'],
    ];
}
