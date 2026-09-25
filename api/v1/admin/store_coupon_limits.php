<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 15.3 — "cupom de loja ela cria sozinha no painel, dentro do teto que
// você liberar aqui". Este é o "aqui": o teto de cupom de cada loja.
//
// GET ?q=  lojas aprovadas (busca por nome ou CNPJ; sem busca, as que já
//          têm teto), com teto, comprometido e disponível.
// POST {restaurant_id, limit}  define o teto (0 = a loja não cria cupom).
//
// Baixar o teto abaixo do já comprometido não apaga cupom nenhum: os vivos
// seguem até vencer ou gastar, e a loja só cria outro quando sobrar espaço.
// Toda mudança vai pro audit_log com o valor anterior.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $digits = only_digits($q);
    $stmt = $pdo->prepare(
        "SELECT r.id, r.name, r.cnpj, r.coupon_budget_limit
           FROM restaurants r
          WHERE r.approved_at IS NOT NULL
            AND (CASE WHEN :q = '' THEN r.coupon_budget_limit > 0
                      ELSE r.name ILIKE '%' || :q2 || '%' OR (:digits <> '' AND r.cnpj LIKE :digits2 || '%') END)
          ORDER BY r.name
          LIMIT 30"
    );
    $stmt->execute(['q' => $q, 'q2' => $q, 'digits' => strlen($digits) >= 3 ? $digits : '', 'digits2' => $digits]);
    $stores = array_map(
        static fn (array $r): array => $r + ['budget' => store_coupon_budget($pdo, $r['id'])],
        $stmt->fetchAll()
    );

    json_response(200, ['stores' => $stores]);
}

require_method('POST');
$body = read_json_body();
$restaurantId = (string) ($body['restaurant_id'] ?? '');
$limit = $body['limit'] ?? null;
// Teto de sanidade (R$ 1 milhão): 1e30 passava no is_numeric e estourava a
// coluna numeric no banco.
if (!is_valid_uuid($restaurantId) || !is_number_between($limit, 0, 1000000)) {
    error_response(422, 'invalid_request', 'Informe restaurant_id e limit (de 0 a 1.000.000).',
        fields: ['limit' => 'de 0 a 1.000.000']);
}
$limit = round((float) $limit, 2);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT coupon_budget_limit FROM restaurants WHERE id = :id AND approved_at IS NOT NULL FOR UPDATE');
    $stmt->execute(['id' => $restaurantId]);
    $before = $stmt->fetchColumn();
    if ($before === false) {
        $pdo->rollBack();
        error_response(404, 'restaurant_not_found', 'Loja aprovada não encontrada.');
    }
    $pdo->prepare('UPDATE restaurants SET coupon_budget_limit = :l WHERE id = :id')
        ->execute(['l' => $limit, 'id' => $restaurantId]);
    $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, 'restaurant.coupon_limit', :target, :before, :after, :ip)"
    )->execute([
        'actor' => $adminId,
        'target' => 'restaurants:' . $restaurantId,
        'before' => json_encode(['coupon_budget_limit' => (float) $before]),
        'after' => json_encode(['coupon_budget_limit' => $limit]),
        'ip' => client_ip(),
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, ['restaurant_id' => $restaurantId, 'budget' => store_coupon_budget($pdo, $restaurantId)]);
