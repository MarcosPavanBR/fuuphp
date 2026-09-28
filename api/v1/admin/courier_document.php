<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 15.2, lado de dentro: o revisor VÊ o documento da candidatura (CNH,
// selfie com o documento, CRLV, comprovante de residência). Antes não havia
// rota nenhuma pra isso: a fila dizia "4 documentos" e ninguém conseguia
// abrir -- a conferência da selfie com a CNH, que é o antifraude, não tinha
// como ser feita (decisão 51).
//
// GET ?id=<courier_documents.id>
//
// - Só admin (com o segundo fator, em produção: guard.php).
// - O arquivo é decifrado aqui (lib/core/file_crypto.php) e sai direto pra
//   tela, sem cache em lugar nenhum (no-store): é documento de identidade.
// - Cada abertura vai pro audit_log: LGPD pede saber quem viu o dado de
//   quem.

require_method('GET');
$claims = require_auth();
$adminId = require_admin($claims);

$docId = positive_id($_GET['id'] ?? null);
if ($docId === null) {
    error_response(422, 'id_required', 'Informe ?id= do documento.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, application_id, kind, storage_key FROM courier_documents WHERE id = :id');
$stmt->execute(['id' => $docId]);
$doc = $stmt->fetch();
if ($doc === false) {
    error_response(404, 'document_not_found', 'Documento não encontrado.');
}

// A chave é gerada pelo servidor (apply_document.php): mesmo assim, nada de
// caminho com barra ou "..".
$key = (string) $doc['storage_key'];
if (preg_match('/^[a-f0-9-]{36}-[a-z_]+-[a-f0-9]{12}\.(jpg|png|webp|pdf)$/', $key) !== 1) {
    error_response(404, 'document_not_found', 'Documento não encontrado.');
}
$path = app_path(rtrim((string) env('COURIER_DOC_DIR', 'storage/courier_docs'), '/')) . '/' . $key;
$stored = is_file($path) ? file_get_contents($path) : false;
if ($stored === false) {
    error_response(404, 'document_file_missing', 'O arquivo desse documento não está no servidor.');
}
$bytes = file_decrypt($stored);

$pdo->prepare(
    'INSERT INTO audit_log (actor_id, action, target, before, after, ip)
     VALUES (:actor, :action, :target, NULL, :after, :ip)'
)->execute([
    'actor' => $adminId,
    'action' => 'courier_document.viewed',
    'target' => 'courier_applications:' . $doc['application_id'],
    'after' => json_encode(['document_id' => (int) $doc['id'], 'kind' => $doc['kind']]),
    'ip' => client_ip(),
]);

$type = match (pathinfo($key, PATHINFO_EXTENSION)) {
    'png' => 'image/png',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    default => 'image/jpeg',
};
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $doc['kind'] . '.' . pathinfo($key, PATHINFO_EXTENSION) . '"');
echo $bytes;
