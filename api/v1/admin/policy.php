<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 10.5 — "Painel admin: políticas (teto, prazo, comissão, métodos)".
//
// "tudo versionado em auditoria": por isso alterar NÃO é UPDATE. Cada
// mudança insere uma versão nova em `platform_policies` (a PK é a versão),
// com quem fez. A versão antiga continua existindo, e os pedidos que a
// congelaram em `policy_snapshot` continuam valendo pelo que foi combinado
// no dia deles.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $current = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
    $history = $pdo->query(
        'SELECT p.version, p.created_at, u.full_name AS created_by_name,
                p.cash_ceiling, p.commission_bps, p.cancel_fee
         FROM platform_policies p LEFT JOIN users u ON u.id = p.created_by
         ORDER BY p.version DESC LIMIT 10'
    )->fetchAll();

    json_response(200, ['current' => $current, 'history' => $history]);
}

require_method('POST');
$body = read_json_body();

$current = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
if ($current === false) {
    error_response(409, 'no_policy', 'Não existe política base pra versionar.');
}

// Campos editáveis pela tela. O que não vier no corpo continua igual à
// versão anterior -- é uma versão nova da MESMA política, não um formulário
// em branco.
$editable = [
    'cash_ceiling' => 'numeric',
    'commission_bps' => 'int',
    'cancel_fee' => 'numeric',
    'allow_partial_settle' => 'bool',
    'withhold_unsettled' => 'bool',
    'require_cash_photo' => 'bool',
    'new_store_online_only_days' => 'int',
];

$values = [];
foreach ($editable as $field => $type) {
    if (!array_key_exists($field, $body)) {
        $values[$field] = $current[$field];
        continue;
    }
    $raw = $body[$field];
    $values[$field] = match ($type) {
        'numeric' => round((float) $raw, 2),
        'int' => (int) $raw,
        'bool' => (bool) $raw,
    };
}

if ($values['commission_bps'] < 0 || $values['commission_bps'] > 3000) {
    error_response(422, 'invalid_commission', 'Comissão precisa estar entre 0 e 3000 bps (0% a 30%).', fields: ['commission_bps' => 'inválido']);
}
if ($values['cash_ceiling'] < 0 || $values['cancel_fee'] < 0) {
    error_response(422, 'invalid_amount', 'Valores não podem ser negativos.');
}

$methods = $body['enabled_methods'] ?? null;
$methodsSql = '(SELECT enabled_methods FROM platform_policies WHERE version = ' . (int) $current['version'] . ')';
$params = [
    'cash_ceiling' => $values['cash_ceiling'],
    'commission_bps' => $values['commission_bps'],
    'cancel_fee' => $values['cancel_fee'],
    'allow_partial_settle' => pg_bool((bool) $values['allow_partial_settle']),
    'withhold_unsettled' => pg_bool((bool) $values['withhold_unsettled']),
    'require_cash_photo' => pg_bool((bool) $values['require_cash_photo']),
    'new_store_online_only_days' => $values['new_store_online_only_days'],
    'created_by' => $adminId,
];

if (is_array($methods)) {
    $valid = ['mp_card', 'pix_auto', 'pix_manual', 'cash', 'pos_machine'];
    $clean = array_values(array_intersect($valid, $methods));
    if ($clean === []) {
        error_response(422, 'no_methods', 'Pelo menos uma forma de pagamento precisa continuar ligada.', fields: ['enabled_methods' => 'inválido']);
    }
    $methodsSql = ':methods::payment_method[]';
    $params['methods'] = '{' . implode(',', $clean) . '}';
}

$stmt = $pdo->prepare(
    "INSERT INTO platform_policies
       (version, cash_ceiling, cash_settle_deadline, allow_partial_settle, withhold_unsettled,
        require_cash_photo, commission_bps, courier_payout_dow, store_debit_dow,
        pos_return_deadline, allow_courier_own_pos, enabled_methods,
        new_store_online_only_days, no_courier_timeout, cancel_fee, created_by)
     SELECT (SELECT MAX(version) + 1 FROM platform_policies),
            :cash_ceiling, cash_settle_deadline, :allow_partial_settle, :withhold_unsettled,
            :require_cash_photo, :commission_bps, courier_payout_dow, store_debit_dow,
            pos_return_deadline, allow_courier_own_pos, {$methodsSql},
            :new_store_online_only_days, no_courier_timeout, :cancel_fee, :created_by
     FROM platform_policies WHERE version = (SELECT MAX(version) FROM platform_policies)
     RETURNING *"
);
$stmt->execute($params);

json_response(201, ['policy' => $stmt->fetch()]);
