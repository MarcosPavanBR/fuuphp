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

$tmpPath = $_FILES['proof']['tmp_name'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath) ?: '';
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    error_response(422, 'invalid_file_type', 'Envie uma foto (JPEG, PNG ou WEBP) do comprovante.', fields: ['proof' => 'tipo de arquivo não aceito']);
}
$bytes = file_get_contents($tmpPath);
if ($bytes === false || $bytes === '') {
    error_response(422, 'empty_file', 'Arquivo vazio.');
}
if (strlen($bytes) > 10 * 1024 * 1024) {
    error_response(422, 'file_too_large', 'Comprovante maior que 10 MB.');
}

$paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = :id ORDER BY created_at DESC LIMIT 1");
$paymentStmt->execute(['id' => $orderId]);
$payment = $paymentStmt->fetch();
if ($payment === false) {
    error_response(409, 'payment_not_started', 'Chame payments/pay.php antes de enviar o comprovante.');
}

$sha256 = hash('sha256', $bytes);
$phash = pix_proof_average_hash($bytes, $mime);
$watermarked = pix_proof_watermark($bytes, $mime, (string) $order['public_code']);

$storageDir = rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/');
$absoluteDir = str_starts_with($storageDir, '/') ? $storageDir : __DIR__ . '/../../../' . $storageDir;
if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0770, true) && !is_dir($absoluteDir)) {
    throw new RuntimeException("não deu pra criar {$absoluteDir}");
}
$extension = match ($mime) {
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'jpg',
};
$storageKey = "{$sha256}.{$extension}";
file_put_contents("{$absoluteDir}/{$storageKey}", $watermarked ?? $bytes);

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

function pix_proof_average_hash(string $bytes, string $mime): ?string
{
    $image = pix_proof_load_image($bytes, $mime);
    if ($image === false) {
        return null;
    }
    $small = imagescale($image, 8, 8);
    imagedestroy($image);
    if ($small === false) {
        return null;
    }

    $values = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $values[] = (int) round(($r + $g + $b) / 3);
        }
    }
    imagedestroy($small);

    $avg = array_sum($values) / count($values);
    $bits = '';
    foreach ($values as $v) {
        $bits .= $v >= $avg ? '1' : '0';
    }

    $hex = '';
    foreach (str_split($bits, 4) as $nibble) {
        $hex .= dechex(bindec(str_pad($nibble, 4, '0')));
    }
    return $hex;
}

function pix_proof_watermark(string $bytes, string $mime, string $orderCode): ?string
{
    $image = pix_proof_load_image($bytes, $mime);
    if ($image === false) {
        return null;
    }
    $width = imagesx($image);
    $height = imagesy($image);

    $text = "FUUDELIVERY · PEDIDO #{$orderCode} · " . date('d/m/Y H:i');
    $white = imagecolorallocatealpha($image, 255, 255, 255, 40);
    $black = imagecolorallocatealpha($image, 0, 0, 0, 60);
    $y = max(0, $height - 18);
    imagestring($image, 3, 9, $y + 1, $text, $black);
    imagestring($image, 3, 8, $y, $text, $white);

    ob_start();
    match ($mime) {
        'image/png' => imagepng($image),
        'image/webp' => imagewebp($image),
        default => imagejpeg($image, null, 85),
    };
    $out = ob_get_clean();
    imagedestroy($image);
    return $out === false ? null : $out;
}

/**
 * @return \GdImage|false
 */
function pix_proof_load_image(string $bytes, string $mime)
{
    return match ($mime) {
        'image/png' => @imagecreatefromstring($bytes),
        'image/webp' => @imagecreatefromstring($bytes),
        'image/jpeg' => @imagecreatefromstring($bytes),
        default => false,
    };
}
