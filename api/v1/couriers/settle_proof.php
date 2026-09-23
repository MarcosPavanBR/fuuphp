<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 9.5 — o comprovante da baixa por Pix, lado do entregador.
//
//   GET   a baixa por Pix aberta dele (se houver), com o estado do último
//         comprovante: nada enviado, esperando a loja, ou recusado (e por quê).
//   POST  multipart {intent_id, proof} + X-Idempotency-Key (UUID, opcional):
//         manda o comprovante da transferência pra fila da loja.
//
// "O saldo só zera quando a loja validar o comprovante." Aqui nada é lançado
// no livro: quem baixa é a loja (restaurants/settlement_proofs.php). A foto
// passa pelo mesmo tratamento do comprovante do cliente -- sha256, aHash e
// marca d'água (lib/payments/proof_images.php) -- e a loja vê se o arquivo
// já apareceu antes. "Enviar comprovante falso bloqueia a conta e gera
// ocorrência": isso acontece quando a loja recusa como falso.

$claims = require_auth();
$courierId = require_courier($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.amount, i.expires_at, i.restaurant_id, r.name AS restaurant_name, r.cnpj AS restaurant_cnpj,
                rc.pix_key, p.state AS proof_state, p.reject_reason, p.created_at AS proof_sent_at
           FROM cash_settlement_intents i
           JOIN restaurants r ON r.id = i.restaurant_id
           LEFT JOIN restaurant_credentials rc ON rc.restaurant_id = r.id
           LEFT JOIN LATERAL (
                SELECT * FROM settlement_proofs sp WHERE sp.intent_id = i.id ORDER BY sp.id DESC LIMIT 1
           ) p ON true
          WHERE i.courier_id = :c AND i.method = 'pix' AND i.state = 'open'"
    );
    $stmt->execute(['c' => $courierId]);
    $open = $stmt->fetch();
    if ($open !== false) {
        // O copia-e-cola não é guardado: é determinístico (chave, valor, BX-id)
        // e é recalculado igual, pra tela poder voltar depois de recarregar.
        $open['pix_copy_paste'] = pix_copy_paste((string) $open['pix_key'], (float) $open['amount'], 'BX' . $open['id'], (string) $open['restaurant_name'], 'BRASIL');
        unset($open['pix_key']);
    }
    json_response(200, ['intent' => $open === false ? null : $open]);
}

require_method('POST');

$uploadKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
if ($uploadKey !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadKey) !== 1) {
    error_response(422, 'invalid_idempotency_key', 'X-Idempotency-Key precisa ser um UUID.');
}
if ($uploadKey !== null) {
    $again = $pdo->prepare('SELECT * FROM settlement_proofs WHERE upload_key = :k AND courier_id = :c');
    $again->execute(['k' => $uploadKey, 'c' => $courierId]);
    $previous = $again->fetch();
    if ($previous !== false) {
        json_response(200, ['proof' => $previous, 'replayed' => true]);
    }
}

$intentId = (int) ($_POST['intent_id'] ?? 0);
$intentStmt = $pdo->prepare(
    "SELECT * FROM cash_settlement_intents WHERE id = :id AND courier_id = :c AND method = 'pix'"
);
$intentStmt->execute(['id' => $intentId, 'c' => $courierId]);
$intent = $intentStmt->fetch();
if ($intent === false) {
    error_response(404, 'intent_not_found', 'Baixa por Pix não encontrada.');
}
if ($intent['state'] !== 'open') {
    error_response(409, 'intent_not_open', 'Essa baixa não está mais aberta.');
}
if (strtotime((string) $intent['expires_at']) < time()) {
    error_response(410, 'intent_expired', 'O prazo dessa baixa acabou. As corridas em dinheiro ficam bloqueadas até regularizar.');
}

[$bytes, $mime] = proof_read_upload('proof');
$stored = proof_store($bytes, $mime, 'BAIXA #BX' . $intent['id'], 'settlements');

try {
    $insert = $pdo->prepare(
        'INSERT INTO settlement_proofs (intent_id, courier_id, restaurant_id, storage_key, sha256, phash, upload_key)
         VALUES (:intent, :courier, :restaurant, :key, :sha, :phash, :upload_key) RETURNING *'
    );
    $insert->execute([
        'intent' => $intent['id'],
        'courier' => $courierId,
        'restaurant' => $intent['restaurant_id'],
        'key' => $stored['storage_key'],
        'sha' => $stored['sha256'],
        'phash' => $stored['phash'],
        'upload_key' => $uploadKey,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        error_response(409, 'proof_pending', 'Já tem um comprovante esperando a loja conferir.');
    }
    throw $e;
}

json_response(201, ['proof' => $insert->fetch()]);
