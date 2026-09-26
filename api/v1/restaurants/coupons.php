<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.3, lado da loja — "cupom de loja ela cria sozinha no painel,
// dentro do teto que você liberar aqui".
//
// GET  o teto liberado pela plataforma, quanto está comprometido, o que
//      sobra, e os cupons desta loja (os dela e os que a plataforma criou
//      pagos por ela), com usos e gasto.
// POST {action:'create', code, kind, value, min_order, audience, budget_cap,
//       days, dry_run?}  cria um cupom pago pela loja, só nesta loja.
//      dry_run devolve a projeção (ticket médio, resgates, comissão) sem
//      gravar -- a mesma "projeção antes de criar" do admin.
// POST {action:'deactivate', coupon_id}  desliga um cupom que a loja criou
//      e devolve ao teto o que ele não gastou.
//
// Regras que a loja não escolhe:
//   - quem paga é sempre a loja (sai do repasse; livro em record_coupon_ledger);
//   - o teto do cupom cabe no que sobra do teto da loja
//     (store_coupon_budget, com a loja travada pra dois cupons simultâneos
//     não passarem juntos);
//   - prazo obrigatório, de 1 a 90 dias: cupom de loja sem fim ficaria
//     comprometendo o teto pra sempre;
//   - cupom criado pela plataforma não é desligado por aqui.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

const STORE_COUPON_MAX_DAYS = 90;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.code, c.kind, c.value, c.min_order, c.audience, c.budget_cap, c.spent,
                c.starts_at, c.ends_at, c.active, c.created_by_store,
                (c.active AND (c.ends_at IS NULL OR c.ends_at > now())) AS live,
                (SELECT count(*) FROM coupon_redemptions cr WHERE cr.coupon_id = c.id) AS uses
           FROM coupons c
          WHERE c.restaurant_id = :id AND c.payer = 'store' AND c.owner_user_id IS NULL
          ORDER BY live DESC, c.id DESC
          LIMIT 50"
    );
    $stmt->execute(['id' => $restaurantId]);

    json_response(200, [
        'budget' => store_coupon_budget($pdo, $restaurantId),
        'coupons' => $stmt->fetchAll(),
        'max_days' => STORE_COUPON_MAX_DAYS,
        'audiences' => [
            ['code' => 'all', 'label' => 'Todo mundo'],
            ['code' => 'first_order', 'label' => 'Nunca pediu aqui'],
            ['code' => 'inactive_15d', 'label' => 'Sem pedir aqui há 15 dias'],
            ['code' => 'inactive_30d', 'label' => 'Sem pedir aqui há 30 dias'],
        ],
    ]);
}

require_method('POST');
$body = read_json_body();
$action = $body['action'] ?? '';

if ($action === 'deactivate') {
    $couponId = positive_id($body['coupon_id'] ?? null) ?? 0;
    $stmt = $pdo->prepare(
        "UPDATE coupons SET active = false
          WHERE id = :id AND restaurant_id = :rid AND created_by_store AND active
          RETURNING id, code"
    );
    $stmt->execute(['id' => $couponId, 'rid' => $restaurantId]);
    $row = $stmt->fetch();
    if ($row === false) {
        error_response(404, 'coupon_not_found', 'Cupom não encontrado entre os que esta loja criou e ainda estão ativos.');
    }
    json_response(200, ['deactivated' => $row, 'budget' => store_coupon_budget($pdo, $restaurantId)]);
}

if ($action !== 'create') {
    error_response(422, 'invalid_action', 'Informe action: create ou deactivate.');
}

$code = is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
$kind = $body['kind'] ?? '';
// Números de verdade e com teto (o (float) de antes aceitava "x" como 0 e
// 1e30 estourava a coluna): valor até R$ 10.000, pedido mínimo até R$ 100.000.
$value = money_input($body['value'] ?? null, 0, 10000) ?? 0.0;
$minOrder = money_input($body['min_order'] ?? 0, 0, 100000) ?? -1.0;
$audience = $body['audience'] ?? '';
$budgetCap = money_input($body['budget_cap'] ?? null, 0, 10000000) ?? 0.0;
$days = is_int_between($body['days'] ?? null, 0, 3650) ? (int) $body['days'] : 0;
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
if ($value <= 0) {
    $fields['value'] = 'maior que zero (até 10.000)';
}
if ($kind === 'percent' && $value > 100) {
    $fields['value'] = 'percentual acima de 100';
}
if ($minOrder < 0) {
    $fields['min_order'] = 'de R$ 0 a R$ 100.000';
}
if ($budgetCap <= 0) {
    $fields['budget_cap'] = 'obrigatório e maior que zero';
}
if ($days < 1 || $days > STORE_COUPON_MAX_DAYS) {
    $fields['days'] = 'de 1 a ' . STORE_COUPON_MAX_DAYS . ' dias';
}
if ($fields !== []) {
    error_response(422, 'invalid_coupon', 'Confira os campos do cupom.', fields: $fields);
}

if ($dryRun) {
    json_response(200, [
        'dry_run' => true,
        'budget' => store_coupon_budget($pdo, $restaurantId),
        'audience_size' => coupon_audience_size($pdo, (string) $audience, $restaurantId),
        'projection' => coupon_projection($pdo, (string) $kind, $value, $budgetCap, $restaurantId),
    ]);
}

$pdo->beginTransaction();
try {
    // Trava a loja: o "cabe no teto?" e o INSERT viram uma coisa só.
    $pdo->prepare('SELECT id FROM restaurants WHERE id = :id FOR UPDATE')->execute(['id' => $restaurantId]);
    $budget = store_coupon_budget($pdo, $restaurantId);
    if ($budget['limit'] <= 0) {
        $pdo->rollBack();
        error_response(403, 'store_coupons_disabled',
            'A plataforma ainda não liberou teto de cupom pra esta loja. Fale com o suporte.');
    }
    if ($budgetCap > $budget['available']) {
        $pdo->rollBack();
        error_response(409, 'over_store_limit',
            sprintf('Esse teto passa do que sobra pra cupons: R$ %s disponíveis de R$ %s.',
                number_format($budget['available'], 2, ',', '.'), number_format($budget['limit'], 2, ',', '.')),
            fields: ['budget_cap' => 'acima do disponível']);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer, budget_cap,
                              starts_at, ends_at, created_by, created_by_store)
         VALUES (:code, :kind, :value, :min_order, :rid, :audience, 'store', :budget_cap,
                 now(), now() + make_interval(days => :days), :by, true)
         RETURNING id, code, kind, value, min_order, audience, budget_cap, starts_at, ends_at"
    );
    $stmt->execute([
        'code' => $code,
        'kind' => $kind,
        'value' => $value,
        'min_order' => $minOrder,
        'rid' => $restaurantId,
        'audience' => $audience,
        'budget_cap' => $budgetCap,
        'days' => $days,
        'by' => $claims['sub'],
    ]);
    $coupon = $stmt->fetch();
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23505') {
        error_response(409, 'code_taken', 'Já existe um cupom com esse código.', fields: ['code' => 'em uso']);
    }
    throw $e;
}

json_response(201, ['coupon' => $coupon, 'budget' => store_coupon_budget($pdo, $restaurantId)]);
