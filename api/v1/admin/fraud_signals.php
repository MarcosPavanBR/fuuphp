<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Sinais de fraude pro admin (aba Ocorrências). O sistema grava sinais em
// fraud_signals (comprovante de entrega repetido, cupom de primeiro pedido
// repetido no mesmo endereço -- auditoria NEG-01), mas nenhuma tela lia:
// sinal que ninguém vê não protege nada.
//
// GET ?days=30  os sinais dos últimos N dias (até 90), mais novos primeiro,
//               no máximo 200, e a contagem por tipo. Só leitura: a decisão
//               (bloquear conta, abrir disputa) segue pelas telas de sempre.

$claims = require_auth();
require_admin($claims);
require_method('GET');

$days = min(90, max(1, (int) ($_GET['days'] ?? 30)));
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT f.id, f.kind, f.subject, f.score, f.created_at,
            u.full_name AS user_name, u.phone AS user_phone, u.blocked AS user_blocked,
            cu.full_name AS courier_name,
            o.public_code AS order_code, o.status AS order_status
       FROM fraud_signals f
       LEFT JOIN users u ON u.id = f.user_id
       LEFT JOIN couriers c ON c.id = f.courier_id
       LEFT JOIN users cu ON cu.id = c.user_id
       LEFT JOIN orders o ON o.id = f.order_id
      WHERE f.created_at > now() - make_interval(days => :days)
      ORDER BY f.created_at DESC, f.id DESC
      LIMIT 200"
);
$stmt->execute(['days' => $days]);
$signals = $stmt->fetchAll();
foreach ($signals as &$s) {
    // Telefone mascarado: o admin identifica a conta, a tela não expõe o número.
    $s['user_phone'] = $s['user_phone'] === null ? null : otp_mask((string) $s['user_phone']);
}
unset($s);

$count = $pdo->prepare(
    'SELECT kind, count(*) AS n FROM fraud_signals
      WHERE created_at > now() - make_interval(days => :days) GROUP BY kind'
);
$count->execute(['days' => $days]);

json_response(200, [
    'days' => $days,
    'signals' => $signals,
    'by_kind' => array_column($count->fetchAll(), 'n', 'kind'),
]);
