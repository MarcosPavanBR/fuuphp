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
