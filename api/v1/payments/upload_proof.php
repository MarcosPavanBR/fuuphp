<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Fase 4.4 — upload do comprovante de Pix manual. MIME real por finfo (não
// o Content-Type que o navegador mandou), hash sha256 (repetição exata) e
// um "average hash" (aHash) de 64 bits como phash simplificado (repetição
// de print reeditado/recortado) -- documentado como simplificação: um pHash
// de verdade usa DCT, este usa a média de luminância de um grid 8x8, mais
// barato e sem dependência nova, e já pega o caso comum descrito no mock
// ("mesma imagem, reenviada"). Watermark aplicado com a fonte embutida do
// GD (imagestring), sem depender de um arquivo .ttf que este ambiente não
// tem. Guardado em disco local (PROOF_STORAGE_DIR): em produção isto é um
// bucket privado com URL assinada (Cloudflare R2/S3), documentado no README.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente envia comprovante.');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}
if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    error_response(422, 'proof_required', 'Envie o arquivo do comprovante no campo "proof".', fields: ['proof' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

// Tela 7.1 — "só o upload do comprovante [é enfileirado offline], que é
// idempotente por UUID". A fila do app manda o mesmo `X-Idempotency-Key`
// em cada tentativa; se esse envio já entrou, devolve-se o MESMO
// comprovante em vez de tentar criar outro (o pedido já saiu de
// 'pending_payment', e a segunda tentativa bateria no 409 abaixo).
$uploadKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
if ($uploadKey !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadKey) !== 1) {
    error_response(422, 'invalid_idempotency_key', 'X-Idempotency-Key precisa ser um UUID.');
}
if ($uploadKey !== null) {
    $already = $pdo->prepare('SELECT * FROM payment_proofs WHERE upload_key = :key AND order_id = :order');
    $already->execute(['key' => $uploadKey, 'order' => $orderId]);
    $previous = $already->fetch();
    if ($previous !== false) {
        json_response(200, ['order' => fetch_order($pdo, $orderId), 'proof' => $previous, 'replayed' => true]);
    }
}

if ($order['status'] !== 'pending_payment' || $order['payment_method'] !== 'pix_manual') {
    error_response(409, 'proof_not_applicable', 'Esse pedido não está aguardando comprovante de Pix.');
}

[$bytes, $mime] = proof_read_upload('proof');

$paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = :id ORDER BY created_at DESC LIMIT 1");
$paymentStmt->execute(['id' => $orderId]);
$payment = $paymentStmt->fetch();
if ($payment === false) {
    error_response(409, 'payment_not_started', 'Chame payments/pay.php antes de enviar o comprovante.');
}
// O último pagamento pode ser um Pix descartado por payments/change_method.php
// (troca pra outro método e volta pro Pix antes de gerar QR novo): comprovante
// de QR morto não entra.
if ($payment['status'] !== 'in_process') {
    error_response(409, 'payment_not_started', 'Gere o QR do Pix de novo antes de enviar o comprovante.');
}

// Marca d'água, hash e gravação: lib/payments/proof_images.php.
$stored = proof_store($bytes, $mime, 'PEDIDO #' . $order['public_code']);
$storageKey = $stored['storage_key'];
$sha256 = $stored['sha256'];
$phash = $stored['phash'];

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        'INSERT INTO payment_proofs (payment_id, order_id, restaurant_id, storage_key, sha256, phash, uploaded_by, upload_key)
         VALUES (:payment_id, :order_id, :restaurant_id, :storage_key, :sha256, :phash, :uploaded_by, :upload_key) RETURNING *'
    );
    $insert->execute([
        'payment_id' => $payment['id'],
        'order_id' => $orderId,
        'restaurant_id' => $order['restaurant_id'],
        'storage_key' => $storageKey,
        'sha256' => $sha256,
        'phash' => $phash,
        'uploaded_by' => $claims['sub'],
        'upload_key' => $uploadKey,
    ]);
    $proof = $insert->fetch();

    call_advance_order($pdo, $orderId, 'pending_verification', (string) $claims['sub'], 'customer', ['proof_id' => $proof['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$dupeStmt = $pdo->prepare('SELECT count(*) FROM payment_proofs WHERE sha256 = :sha AND id <> :id');
$dupeStmt->execute(['sha' => $sha256, 'id' => $proof['id']]);
$proof['seen_before'] = (int) $dupeStmt->fetchColumn() > 0;

json_response(201, [
    'order' => fetch_order($pdo, $orderId),
    'proof' => $proof,
]);
