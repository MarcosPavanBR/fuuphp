<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 12.1 — "Cadastro e aprovação de lojas".
//
// GET lista as que esperam decisão (e as decididas recentemente); POST
// aprova ou recusa.
//
// "Loja nova nasce só-online por 30 dias — a liberação de dinheiro e
// maquininha é consequência do histórico, não de negociação": aprovar grava
// `online_only_until = now() + N dias`, com N vindo da política
// (`new_store_online_only_days`), e limita `restaurant_payment_settings.methods`
// aos métodos online. Não é conselho na tela: é o que o checkout vai ler.

$claims = require_auth();
require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->query(
        "SELECT r.id, r.name, r.cnpj, r.city_ibge_code, r.category, r.created_at,
                r.approved_at, r.rejected_at, r.rejection_reason, r.online_only_until,
                (SELECT count(*) FROM orders o WHERE o.restaurant_id = r.id
                  AND o.status NOT IN ('cart','pending_payment')) AS orders_count,
                (SELECT count(*) FROM restaurants other
                  WHERE substr(other.cnpj, 1, 8) = substr(r.cnpj, 1, 8)
                    AND other.id <> r.id) AS same_root_cnpj
         FROM restaurants r
         WHERE r.approved_at IS NULL AND r.rejected_at IS NULL
         ORDER BY r.created_at"
    );
    $pending = $stmt->fetchAll();

    $recent = $pdo->query(
        "SELECT id, name, cnpj, approved_at, rejected_at, rejection_reason
         FROM restaurants
         WHERE approved_at >= now() - interval '7 days' OR rejected_at >= now() - interval '7 days'
         ORDER BY COALESCE(approved_at, rejected_at) DESC LIMIT 20"
    )->fetchAll();

    json_response(200, ['pending' => $pending, 'recent' => $recent]);
}

require_method('POST');
$body = read_json_body();

$restaurantId = (string) ($body['restaurant_id'] ?? '');
$decision = $body['decision'] ?? null;
if ($restaurantId === '' || !in_array($decision, ['approve', 'reject'], true)) {
    error_response(422, 'invalid_request', 'Informe restaurant_id e decision (approve ou reject).');
}

$stmt = $pdo->prepare('SELECT * FROM restaurants WHERE id = :id');
$stmt->execute(['id' => $restaurantId]);
$restaurant = $stmt->fetch();
if ($restaurant === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}
if ($restaurant['approved_at'] !== null || $restaurant['rejected_at'] !== null) {
    error_response(409, 'already_decided', 'Essa loja já foi analisada.');
}

if ($decision === 'reject') {
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') {
        error_response(422, 'reason_required', 'Recusa exige motivo — a loja precisa saber o que corrigir.', fields: ['reason' => 'obrigatório']);
    }
    $pdo->prepare('UPDATE restaurants SET rejected_at = now(), rejection_reason = :r WHERE id = :id')
        ->execute(['r' => $reason, 'id' => $restaurantId]);
    json_response(200, ['restaurant' => fetch_restaurant($pdo, $restaurantId), 'decision' => 'reject']);
}

$policy = $pdo->query('SELECT new_store_online_only_days FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
$days = (int) ($policy['new_store_online_only_days'] ?? 30);

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE restaurants
            SET approved_at = now(),
                online_only_until = now() + (:days || ' days')::interval
          WHERE id = :id"
    )->execute(['days' => $days, 'id' => $restaurantId]);

    // Só-online não é recomendação: é o que a loja pode LIGAR enquanto o
    // prazo corre. Dinheiro e maquininha entram depois, pelo histórico.
    $pdo->prepare(
        "INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order)
         VALUES (:id, ARRAY['mp_card','pix_auto','pix_manual']::payment_method[], 0)
         ON CONFLICT (restaurant_id) DO UPDATE
           SET methods = ARRAY['mp_card','pix_auto','pix_manual']::payment_method[]"
    )->execute(['id' => $restaurantId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'restaurant' => fetch_restaurant($pdo, $restaurantId),
    'decision' => 'approve',
    'online_only_days' => $days,
]);

function fetch_restaurant(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, cnpj, approved_at, rejected_at, rejection_reason, online_only_until
         FROM restaurants WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);

    return $stmt->fetch();
}
