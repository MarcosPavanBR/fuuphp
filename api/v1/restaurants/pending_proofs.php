<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 7.3 — fila de validação do painel da loja. Devolve, de uma vez, tudo
// que o modal de decisão precisa mostrar: o comprovante (referência), os
// dados do pedido e a "conferência automática" (hash, phash, horário,
// marca d'água) que o mock descreve -- "reduz o trabalho do atendente a uma
// decisão: o valor bate?".
//
// Uma consulta só, com os itens agregados em JSON e a contagem de pedidos
// anteriores do cliente ("3º pedido" no mock) -- nada de N+1 numa tela que
// fica aberta o dia inteiro atualizando.

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja acessa a fila de validação.');
}
$restaurantId = $claims['restaurant_id'] ?? null;
if ($restaurantId === null) {
    error_response(403, 'forbidden', 'Esse login não está vinculado a uma loja.');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT pr.id, pr.sha256, pr.phash, pr.created_at AS uploaded_at, pr.storage_key,
            o.id AS order_id, o.public_code, o.total, o.verification_deadline, o.status,
            u.full_name AS customer_name,
            (SELECT count(*) FROM orders o2
              WHERE o2.user_id = o.user_id AND o2.restaurant_id = o.restaurant_id
                AND o2.status NOT IN ('cart','pending_payment')) AS customer_order_count,
            (SELECT count(*) FROM payment_proofs dup
              WHERE dup.sha256 = pr.sha256 AND dup.id <> pr.id) AS same_image_count,
            (SELECT json_agg(json_build_object('name', oi.name_snapshot, 'quantity', oi.quantity)
                             ORDER BY oi.id)
               FROM order_items oi WHERE oi.order_id = o.id) AS items,
            a.street, a.number, a.complement, a.neighborhood
     FROM payment_proofs pr
     JOIN orders o ON o.id = pr.order_id
     JOIN users u ON u.id = o.user_id
     LEFT JOIN addresses a ON a.id = o.address_id
     WHERE pr.restaurant_id = :id AND pr.state = 'pending'
     ORDER BY o.verification_deadline ASC NULLS LAST, pr.created_at ASC"
);
$stmt->execute(['id' => $restaurantId]);

json_response(200, ['proofs' => $stmt->fetchAll()]);
