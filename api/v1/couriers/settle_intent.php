<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Telas 9.1 e 9.2 — "Intenção de baixa com validade de 10 min. Guardamos só o
// hash do código; o valor é imutável depois de gerado."
//
// O entregador declara quanto vai entregar e pra qual loja; o servidor gera um
// código de 6 dígitos e guarda só o hash -- mesma disciplina do OTP do módulo
// identity. O valor fica congelado na linha: a loja confirma contra ele, e
// divergência abre ocorrência em vez de virar edição de saldo (tela 9.3).
//
// Tela 9.5 — `method: "pix"` ("baixa por Pix, loja fechada"): não há código
// (ninguém digita nada no balcão). A resposta traz o copia-e-cola da chave
// Pix da LOJA com o valor exato, e o prazo é o do mock -- "até amanhã,
// 23:59" (fuso de São Paulo). O entregador transfere e manda o comprovante
// em couriers/settle_proof.php; quem baixa é a loja, ao aprovar.
//
// O índice `one_open_intent` (migração 006) garante UMA baixa em voo por
// entregador. Duas intenções abertas seria o caminho mais curto pra pagar
// duas vezes a mesma espécie.

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);
$body = read_json_body();

$restaurantId = (string) ($body['restaurant_id'] ?? '');
$method = $body['method'] ?? 'in_person';
$amount = isset($body['amount']) ? round((float) $body['amount'], 2) : null;

if ($restaurantId === '' || !in_array($method, ['in_person', 'pix'], true)) {
    error_response(422, 'invalid_request', 'Informe restaurant_id e method (in_person ou pix).');
}

$pdo = db();

$balance = courier_cash_balance($pdo, $courierId);
if ($balance <= 0) {
    error_response(409, 'nothing_to_settle', 'Você não tem espécie pra baixar.');
}

$policy = $pdo->query('SELECT allow_partial_settle FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
$allowPartial = $policy === false ? true : (bool) $policy['allow_partial_settle'];

// Baixa parcial ("entregar parte do dinheiro sem zerar") é opção de política,
// não escolha livre do entregador.
if ($amount === null) {
    $amount = $balance;
}
if ($amount <= 0 || $amount > $balance) {
    error_response(422, 'invalid_amount', 'Valor precisa ser maior que zero e no máximo o seu saldo em espécie.', fields: ['amount' => 'inválido']);
}
if (!$allowPartial && abs($amount - $balance) > 0.001) {
    error_response(422, 'partial_not_allowed', 'A política atual exige baixar o saldo inteiro.', fields: ['amount' => 'inválido']);
}

$storeStmt = $pdo->prepare(
    'SELECT r.id, r.name, r.cnpj, rc.pix_key FROM restaurants r
       LEFT JOIN restaurant_credentials rc ON rc.restaurant_id = r.id
      WHERE r.id = :id'
);
$storeStmt->execute(['id' => $restaurantId]);
$store = $storeStmt->fetch();
if ($store === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}
if ($method === 'pix' && ($store['pix_key'] ?? '') === '') {
    error_response(409, 'store_has_no_pix_key', 'Essa loja não cadastrou chave Pix — dê baixa no balcão.');
}

// Balcão: código de 6 dígitos, 10 minutos. Pix: sem código, até o fim de amanhã.
$code = $method === 'in_person' ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : null;
$expiresAt = $method === 'in_person'
    ? (new DateTimeImmutable('+10 minutes'))->format(DATE_ATOM)
    // "Fim de amanhã" no relógio da loja que recebe a baixa.
    : (new DateTimeImmutable('tomorrow 23:59:59', store_timezone($pdo, $restaurantId)))->format(DATE_ATOM);

try {
    $stmt = $pdo->prepare(
        "INSERT INTO cash_settlement_intents
           (courier_id, restaurant_id, amount, method, code_hash, expires_at)
         VALUES (:courier_id, :restaurant_id, :amount, :method, :code_hash, :expires_at)
         RETURNING id, courier_id, restaurant_id, amount, method, expires_at, state, created_at"
    );
    $stmt->execute([
        'courier_id' => $courierId,
        'restaurant_id' => $restaurantId,
        'amount' => $amount,
        'method' => $method,
        'code_hash' => $code === null ? null : hash('sha256', $code),
        'expires_at' => $expiresAt,
    ]);
    $intent = $stmt->fetch();
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        error_response(409, 'intent_already_open', 'Você já tem uma baixa em andamento. Finalize ou espere expirar.');
    }
    throw $e;
}

$response = [
    'intent' => $intent,
    // Única vez que o código aparece: depois daqui só existe o hash.
    'code' => $code,
];
if ($method === 'pix') {
    $response['store'] = ['name' => $store['name'], 'cnpj' => $store['cnpj']];
    // O identificador da transação é o da baixa ("BX-<id>"): é o que a loja
    // procura no extrato e o que vai na marca d'água do comprovante.
    $response['pix_copy_paste'] = pix_copy_paste((string) $store['pix_key'], (float) $intent['amount'], 'BX' . $intent['id'], (string) $store['name'], 'BRASIL');
}
json_response(201, $response);
