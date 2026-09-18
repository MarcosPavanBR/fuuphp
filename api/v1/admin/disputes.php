<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 12.2 — "Disputas e galeria antifraude".
//
// "Fila por risco e valor" e, embaixo, a galeria de comprovantes reusados --
// "o padrão aparece visualmente antes de virar prejuízo". A galeria aqui é a
// consulta que encontra o padrão: sha256 de comprovante que aparece em mais
// de um pedido.
//
// "Toda decisão é lançamento no livro e notificação às duas partes, nunca
// edição de saldo": resolver uma disputa com valor gera contrapartida em
// `ledger_entries` -- nunca um UPDATE em saldo, que nem existe como campo.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $open = $pdo->query(
        "SELECT d.id, d.order_id, d.kind, d.risk, d.amount, d.state, d.created_at,
                o.public_code, r.name AS restaurant_name, u.full_name AS courier_name
         FROM disputes d
         LEFT JOIN orders o ON o.id = d.order_id
         LEFT JOIN restaurants r ON r.id = COALESCE(d.restaurant_id, o.restaurant_id)
         LEFT JOIN couriers c ON c.id = d.courier_id
         LEFT JOIN users u ON u.id = c.user_id
         WHERE d.state = 'open'
         ORDER BY CASE d.risk WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                  d.amount DESC NULLS LAST, d.created_at"
    )->fetchAll();

    // Galeria antifraude: mesma imagem em pedidos diferentes. É o sinal que
    // a tela 7.3 já mostra por comprovante -- aqui ele aparece agrupado, que
    // é como o padrão fica visível.
    $gallery = $pdo->query(
        "SELECT pr.sha256, count(*) AS uses, min(pr.created_at) AS first_seen,
                max(pr.created_at) AS last_seen,
                array_agg(DISTINCT o.public_code) AS orders
         FROM payment_proofs pr JOIN orders o ON o.id = pr.order_id
         GROUP BY pr.sha256 HAVING count(*) > 1
         ORDER BY count(*) DESC, max(pr.created_at) DESC
         LIMIT 12"
    )->fetchAll();

    json_response(200, ['open' => $open, 'gallery' => $gallery]);
}

require_method('POST');
$body = read_json_body();

$disputeId = (int) ($body['dispute_id'] ?? 0);
$resolution = trim((string) ($body['resolution'] ?? ''));
$charge = $body['charge'] ?? null; // 'courier' | 'store' | 'platform' | null

if ($disputeId <= 0 || $resolution === '') {
    error_response(422, 'invalid_request', 'Informe dispute_id e resolution.', fields: ['resolution' => 'obrigatório']);
}
if ($charge !== null && !in_array($charge, ['courier', 'store', 'platform'], true)) {
    error_response(422, 'invalid_charge', 'charge precisa ser courier, store ou platform.');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT * FROM disputes WHERE id = :id AND state = 'open' FOR UPDATE");
    $stmt->execute(['id' => $disputeId]);
    $dispute = $stmt->fetch();
    if ($dispute === false) {
        $pdo->rollBack();
        error_response(404, 'dispute_not_found', 'Ocorrência não encontrada ou já resolvida.');
    }

    $pdo->prepare(
        "UPDATE disputes SET state = 'resolved', resolution = :res, decided_by = :by WHERE id = :id"
    )->execute(['res' => $resolution, 'by' => $adminId, 'id' => $disputeId]);

    // A contrapartida só existe quando alguém arca com um valor. Resolver
    // "sem cobrança" é decisão legítima e não mexe em dinheiro nenhum.
    $amount = (float) ($dispute['amount'] ?? 0);
    if ($charge !== null && $amount > 0) {
        [$account, $partyId] = match ($charge) {
            'courier' => ['courier_cash', $dispute['courier_id']],
            'store' => ['store_receivable', $dispute['restaurant_id']],
            'platform' => ['platform_expense', $adminId],
        };
        if ($partyId === null) {
            $pdo->rollBack();
            error_response(422, 'no_party', 'Essa ocorrência não tem esse lado pra cobrar.');
        }
        ledger_add(
            $pdo,
            $account,
            (string) $partyId,
            $charge === 'platform' ? $amount : -$amount,
            'dispute',
            (string) $disputeId,
            $dispute['order_id'] === null ? null : (int) $dispute['order_id'],
            $adminId,
            'resolução de ocorrência #' . $disputeId
        );
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$refreshed = $pdo->prepare('SELECT * FROM disputes WHERE id = :id');
$refreshed->execute(['id' => $disputeId]);

json_response(200, ['dispute' => $refreshed->fetch()]);
