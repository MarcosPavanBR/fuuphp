<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 13.3 — "PROVA (OBRIGATÓRIA): foto do local · GPS e hora gravados".
//
// Até aqui o app do entregador sabia falar em `photo_storage_key` (a prova de
// entrega da 8.6 aceita uma), mas não havia por onde subir a foto: o campo
// existia e ninguém conseguia preencher. Isto é o upload que faltava, e ele
// serve às duas telas -- a foto da ocorrência e a foto da entrega sem código.
//
// Mesmo cuidado do comprovante de Pix: MIME real por finfo (não o
// Content-Type que o aparelho mandou) e sha256 do conteúdo. A foto não é
// pública: fica em disco privado aqui, bucket privado com URL assinada em
// produção -- é foto da porta da casa de alguém.

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    error_response(422, 'photo_required', 'Envie a foto no campo "photo".', fields: ['photo' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null || $order['courier_id'] !== $courierId) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

$tmpPath = $_FILES['photo']['tmp_name'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath) ?: '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    error_response(422, 'invalid_file_type', 'A prova é uma foto (JPEG, PNG ou WEBP).', fields: ['photo' => 'tipo não aceito']);
}
$bytes = file_get_contents($tmpPath);
if ($bytes === false || $bytes === '') {
    error_response(422, 'empty_file', 'Arquivo vazio.');
}
if (strlen($bytes) > 10 * 1024 * 1024) {
    error_response(422, 'file_too_large', 'Foto maior que 10 MB.');
}

$sha256 = hash('sha256', $bytes);

// Antifraude da Fase 14: a mesma foto reaproveitada em duas corridas é o
// truque mais velho de prova falsa. Aqui não se barra (pode ser o mesmo
// prédio duas vezes na mesma noite) -- registra-se o sinal, e a fila de
// disputas do admin decide com o resto do contexto.
$dupStmt = $pdo->prepare(
    'SELECT order_id FROM delivery_proofs WHERE sha256 = :sha AND order_id <> :id LIMIT 1'
);
$dupStmt->execute(['sha' => $sha256, 'id' => $orderId]);
$reused = $dupStmt->fetchColumn() !== false;
if ($reused) {
    $pdo->prepare(
        "INSERT INTO fraud_signals (kind, subject, courier_id, order_id, score)
         VALUES ('proof_reuse', :subject, :courier, :order, 70)"
    )->execute(['subject' => $sha256, 'courier' => $courierId, 'order' => $orderId]);
}

$storageDir = rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/') . '/delivery';
$absoluteDir = app_path($storageDir);
if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0770, true) && !is_dir($absoluteDir)) {
    error_response(500, 'storage_unavailable', 'Não deu pra guardar a foto agora.');
}

$extension = match ($mime) {
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'jpg',
};
$storageKey = 'delivery/' . $orderId . '-' . substr($sha256, 0, 12) . '.' . $extension;
$path = $absoluteDir . '/' . basename($storageKey);
if (file_put_contents($path, $bytes) === false) {
    error_response(500, 'storage_unavailable', 'Não deu pra guardar a foto agora.');
}

json_response(201, [
    'photo_storage_key' => $storageKey,
    'sha256' => $sha256,
    'reused' => $reused,
]);
