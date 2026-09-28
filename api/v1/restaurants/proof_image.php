<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Serve a imagem do comprovante pro painel da loja (tela 7.3) -- o arquivo
// fica fora da raiz servida (PROOF_STORAGE_DIR), então não existe URL
// pública pra ele: a única porta é esta, e ela confere se o comprovante é
// mesmo da loja que está pedindo antes de mandar um byte.
//
// O painel busca esta rota com fetch + Authorization e converte em blob
// URL pra por no <img> -- de propósito, pra NÃO precisar aceitar token por
// query string aqui (a exceção documentada em lib/core/auth_guard.php vale só
// pro SSE, onde o EventSource não deixa mandar header).

require_method('GET');
$claims = require_auth();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja vê comprovante.');
}

$proofId = positive_id($_GET['id'] ?? null) ?? 0;
if ($proofId <= 0) {
    error_response(422, 'id_required', 'Informe ?id= do comprovante.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT storage_key, restaurant_id FROM payment_proofs WHERE id = :id');
$stmt->execute(['id' => $proofId]);
$proof = $stmt->fetch();

if ($proof === false || $proof['restaurant_id'] !== ($claims['restaurant_id'] ?? null)) {
    error_response(404, 'proof_not_found', 'Comprovante não encontrado.');
}

$storageDir = rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/');
$absoluteDir = app_path($storageDir);
// basename() blinda contra storage_key com "../" -- hoje ele é sempre
// "<sha256>.<ext>" gerado pelo servidor, mas o arquivo é lido do disco:
// não custa não confiar.
$path = $absoluteDir . '/' . basename((string) $proof['storage_key']);

if (!is_file($path)) {
    error_response(404, 'proof_file_missing', 'Arquivo do comprovante não está mais no armazenamento.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
