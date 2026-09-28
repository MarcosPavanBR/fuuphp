<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Chave Pix da loja (tela 10.4, aba Pagamentos): onde cai o "Pix direto pra
// loja" do cliente (4.3) e a baixa de espécie por Pix do entregador (9.5).
//
// GET  a chave atual, o tipo e quando mudou.
// POST {pix_key}  troca a chave. As mesmas regras do cadastro
//      (store_pix_key_check): CNPJ só o da própria loja, CPF nunca.
//
// Trocar a chave é o jeito mais direto de desviar dinheiro de uma loja com a
// senha vazada. Por isso cada troca vai pro audit_log com a chave anterior, a
// hora e o IP -- o suporte vê e reverte -- e `rotated_at` fica visível pra
// plataforma.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

$current = $pdo->prepare('SELECT pix_key, rotated_at FROM restaurant_credentials WHERE restaurant_id = :r');
$current->execute(['r' => $restaurantId]);
$row = $current->fetch();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_response(200, [
        'pix_key' => $row === false ? null : $row['pix_key'],
        'rotated_at' => $row === false ? null : $row['rotated_at'],
    ]);
}

require_method('POST');
$body = read_json_body();

$cnpj = $pdo->prepare('SELECT cnpj FROM restaurants WHERE id = :r');
$cnpj->execute(['r' => $restaurantId]);
$check = store_pix_key_check(input_str($body, 'pix_key'), (string) $cnpj->fetchColumn());
if (!$check['ok']) {
    error_response(422, 'invalid_pix_key', $check['error'], fields: ['pix_key' => $check['error']]);
}

$before = $row === false ? null : $row['pix_key'];
if ($before === $check['key']) {
    json_response(200, ['pix_key' => $before, 'changed' => false]);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO restaurant_credentials (restaurant_id, pix_key, rotated_at) VALUES (:r, :k, now())
         ON CONFLICT (restaurant_id) DO UPDATE SET pix_key = EXCLUDED.pix_key, rotated_at = now()'
    )->execute(['r' => $restaurantId, 'k' => $check['key']]);
    $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, 'restaurant.pix_key_changed', :target, :before, :after, :ip)"
    )->execute([
        'actor' => $claims['sub'],
        'target' => 'restaurants:' . $restaurantId,
        'before' => json_encode(['pix_key' => $before]),
        'after' => json_encode(['pix_key' => $check['key'], 'kind' => $check['kind'], 'ownership_checked' => $check['ownership_checked']]),
        'ip' => client_ip(),
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'pix_key' => $check['key'],
    'kind' => $check['kind'],
    'ownership_checked' => $check['ownership_checked'],
    'changed' => true,
]);
