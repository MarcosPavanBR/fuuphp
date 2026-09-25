<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 15.3 — "Cupons e campanhas".
//
// "A coluna que falta em quase todo painel: quem paga o desconto. Teto
// obrigatório com desativação automática, um uso por CPF (não por conta) e
// projeção de retorno antes de criar."
//
// GET  — campanhas com gasto/teto, usos e quem paga.
// POST — cria (ou projeta, com dry_run) uma campanha nova.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Usos e gasto saem de coupon_redemptions, não de um contador à parte:
    // `coupons.spent` existe pro CHECK do teto, mas quem conta quantas
    // pessoas usaram é a tabela de resgates.
    $stmt = $pdo->query(
        "SELECT c.*, r.name AS restaurant_name,
                (SELECT count(*) FROM coupon_redemptions cr WHERE cr.coupon_id = c.id) AS uses,
                (SELECT COALESCE(SUM(cr.amount), 0) FROM coupon_redemptions cr WHERE cr.coupon_id = c.id) AS redeemed
           FROM coupons c
           LEFT JOIN restaurants r ON r.id = c.restaurant_id
          ORDER BY c.active DESC, c.id DESC"
    );
    $coupons = $stmt->fetchAll();

    // Quanto do desconto já saiu de cada bolso, de verdade -- do livro, não
    // do rótulo da campanha.
    $ledger = $pdo->query(
        "SELECT account, COALESCE(SUM(amount), 0) AS total
           FROM ledger_entries WHERE origin = 'coupon'
          GROUP BY account"
    )->fetchAll();
    $paid = ['store' => 0.0, 'platform' => 0.0];
    foreach ($ledger as $row) {
        if ($row['account'] === 'store_receivable') {
            $paid['store'] = (float) $row['total'];
        }
        if ($row['account'] === 'platform_expense') {
            $paid['platform'] = (float) $row['total'];
        }
    }

    json_response(200, [
        'coupons' => $coupons,
        'paid_so_far' => $paid,
        'audiences' => [
            ['code' => 'all', 'label' => 'Todo mundo'],
            ['code' => 'first_order', 'label' => 'Nunca pediu'],
            ['code' => 'inactive_15d', 'label' => 'Sem pedir há 15 dias'],
            ['code' => 'inactive_30d', 'label' => 'Sem pedir há 30 dias'],
        ],
    ]);
}

require_method('POST');
$body = read_json_body();

$code = is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
$kind = $body['kind'] ?? '';
// Números de verdade e com teto (o (float) de antes aceitava "x" como 0 e
// 1e30 estourava a coluna): valor até R$ 10.000, pedido mínimo até R$ 100.000.
$value = money_input($body['value'] ?? null, 0, 10000) ?? 0.0;
$minOrder = money_input($body['min_order'] ?? 0, 0, 100000) ?? -1.0;
$audience = $body['audience'] ?? '';
$payer = $body['payer'] ?? '';
$budgetCap = money_input($body['budget_cap'] ?? null, 0, 10000000) ?? 0.0;
$restaurantId = $body['restaurant_id'] ?? null;
$dryRun = ($body['dry_run'] ?? false) === true;

$fields = [];
if (!preg_match('/^[A-Z0-9]{4,20}$/', $code)) {
    $fields['code'] = 'letras e números, 4 a 20';
}
if (!in_array($kind, ['fixed', 'percent', 'free_delivery'], true)) {
    $fields['kind'] = 'fixed, percent ou free_delivery';
}
if (!in_array($audience, ['all', 'first_order', 'inactive_15d', 'inactive_30d'], true)) {
    $fields['audience'] = 'público inválido';
}
if (!in_array($payer, ['store', 'platform', 'shared'], true)) {
    $fields['payer'] = 'store, platform ou shared';
}
if ($value <= 0) {
    $fields['value'] = 'maior que zero (até 10.000)';
}
if ($kind === 'percent' && $value > 100) {
    $fields['value'] = 'percentual acima de 100';
}
// "Teto de gasto é obrigatório." Não é validação de formulário: é a regra
// que o mock põe em primeiro lugar, e o CHECK (budget_cap > 0) da migração
// 008 já não deixaria passar -- aqui ela vira mensagem em vez de 500.
if ($minOrder < 0) {
    $fields['min_order'] = 'de R$ 0 a R$ 100.000';
}
if ($budgetCap <= 0) {
    $fields['budget_cap'] = 'obrigatório e maior que zero';
}
if ($payer === 'store' && ($restaurantId === null || $restaurantId === '')) {
    $fields['restaurant_id'] = 'cupom pago pela loja precisa dizer qual loja';
}
if ($fields !== []) {
    error_response(422, 'invalid_campaign', 'Confira os campos da campanha.', fields: $fields);
}

if ($restaurantId !== null && $restaurantId !== '') {
    if (!is_string($restaurantId) || !is_valid_uuid($restaurantId)) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }
    $check = $pdo->prepare('SELECT 1 FROM restaurants WHERE id = :id');
    $check->execute(['id' => $restaurantId]);
    if ($check->fetchColumn() === false) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }
} else {
    $restaurantId = null;
}

$projection = coupon_projection($pdo, (string) $kind, $value, $budgetCap, $restaurantId);
$audienceSize = coupon_audience_size($pdo, (string) $audience, $restaurantId);

// "Projeção de retorno antes de criar": a tela pergunta antes de gravar.
if ($dryRun) {
    json_response(200, [
        'dry_run' => true,
        'audience_size' => $audienceSize,
        'projection' => $projection,
    ]);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer,
                              budget_cap, starts_at, ends_at, created_by)
         VALUES (:code, :kind, :value, :min_order, :restaurant_id, :audience, :payer,
                 :budget_cap, now(), :ends_at, :created_by)
         RETURNING *'
    );
    $stmt->execute([
        'code' => $code,
        'kind' => $kind,
        'value' => $value,
        'min_order' => $minOrder,
        'restaurant_id' => $restaurantId,
        'audience' => $audience,
        'payer' => $payer,
        'budget_cap' => $budgetCap,
        'ends_at' => $body['ends_at'] ?? null,
        'created_by' => $adminId,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        error_response(409, 'code_taken', 'Já existe uma campanha com esse código.', fields: ['code' => 'em uso']);
    }
    throw $e;
}

json_response(201, [
    'coupon' => $stmt->fetch(),
    'audience_size' => $audienceSize,
    'projection' => $projection,
]);
