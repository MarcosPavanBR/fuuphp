<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 7.3 — "VISÃO GERAL DE HOJE": faturado, pedidos, Pix validados,
// recusados. "Hoje" é o dia corrente no fuso do banco (now()::date), não
// as últimas 24h -- é o que um dono de loja entende por "hoje".
//
// Faturado conta só pedido que passou de 'paid' (ou seja: pago ou adiante),
// nunca carrinho nem pendente -- o número que ele compara com o caixa.

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja vê o resumo.');
}
$restaurantId = $claims['restaurant_id'] ?? null;
if ($restaurantId === null) {
    error_response(403, 'forbidden', 'Esse login não está vinculado a uma loja.');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT
       COALESCE(SUM(total) FILTER (
         WHERE status IN ('paid','preparing','ready','delivering','delivered')), 0) AS revenue,
       count(*) FILTER (WHERE status NOT IN ('cart','pending_payment'))            AS orders_count,
       count(*) FILTER (WHERE status = 'rejected')                                  AS rejected_count
     FROM orders
     WHERE restaurant_id = :id AND created_at >= now()::date"
);
$stmt->execute(['id' => $restaurantId]);
$row = $stmt->fetch();

$proofStmt = $pdo->prepare(
    "SELECT count(*) FILTER (WHERE state = 'approved') AS pix_approved,
            count(*) FILTER (WHERE state = 'pending')  AS pix_pending
     FROM payment_proofs
     WHERE restaurant_id = :id AND created_at >= now()::date"
);
$proofStmt->execute(['id' => $restaurantId]);
$proofRow = $proofStmt->fetch();

json_response(200, [
    'stats' => [
        'revenue' => $row['revenue'],
        'orders_count' => (int) $row['orders_count'],
        'rejected_count' => (int) $row['rejected_count'],
        'pix_approved' => (int) $proofRow['pix_approved'],
        'pix_pending' => (int) $proofRow['pix_pending'],
    ],
]);
