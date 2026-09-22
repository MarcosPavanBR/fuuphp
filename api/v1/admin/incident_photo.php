<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// A foto da ocorrência (tela 13.3) pra quem decide o destino da sacola.
//
// Mesmo desenho de `restaurants/proof_image.php`: o arquivo mora fora da
// raiz servida, não existe URL pública, e a única porta é esta rota
// autenticada. O painel busca com fetch + Authorization e converte em blob
// -- token nunca vai em query string.

require_method('GET');
$claims = require_auth();
require_admin($claims);

$incidentId = (int) ($_GET['id'] ?? 0);
if ($incidentId <= 0) {
    error_response(422, 'id_required', 'Informe ?id= da ocorrência.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT photo_key FROM delivery_incidents WHERE id = :id');
$stmt->execute(['id' => $incidentId]);
$key = $stmt->fetchColumn();
if (!is_string($key) || $key === '') {
    // Foto apagada pela retenção de 180 dias (purge_retention) cai aqui
    // também: não é erro, é a LGPD funcionando.
    error_response(404, 'photo_not_found', 'Essa ocorrência não tem foto (ou ela já passou do prazo de retenção).');
}

$storageDir = rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/') . '/delivery';
$absoluteDir = str_starts_with($storageDir, '/') ? $storageDir : __DIR__ . '/../../../' . $storageDir;
// basename(): a chave é gerada pelo servidor, mas o arquivo é lido do disco.
$path = $absoluteDir . '/' . basename($key);
if (!is_file($path)) {
    error_response(404, 'photo_file_missing', 'O arquivo da foto não está mais no armazenamento.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
header('Content-Type: ' . ($finfo->file($path) ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
