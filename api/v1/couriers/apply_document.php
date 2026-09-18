<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.2 — envio de um documento da candidatura.
//
// Mesmo cuidado do comprovante de Pix (Fase 4.4): MIME real por finfo, não o
// Content-Type que o navegador mandou, e sha256 do conteúdo. Sem marca
// d'água aqui -- comprovante de Pix é reenviado em fraude de pagamento;
// documento de identidade a gente não carimba.
//
// Guardado em disco local (COURIER_DOC_DIR); em produção é bucket privado
// com URL assinada, igual aos comprovantes. Documento de identidade tem
// retenção própria (Parte I §7) e NÃO fica em pasta pública.

require_method('POST');
$claims = require_auth();

$kind = (string) ($_POST['kind'] ?? '');
if (!in_array($kind, ['cnh', 'selfie', 'crlv', 'address_proof'], true)) {
    error_response(422, 'invalid_kind', 'Tipo de documento inválido.', fields: ['kind' => 'inválido']);
}
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    error_response(422, 'document_required', 'Envie o arquivo no campo "document".', fields: ['document' => 'obrigatório']);
}

$pdo = db();
$userStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$cpf = $userStmt->fetchColumn();
if (!is_string($cpf) || $cpf === '') {
    error_response(409, 'cpf_required', 'Complete seu cadastro com CPF antes de enviar documento.');
}

$appStmt = $pdo->prepare('SELECT * FROM courier_applications WHERE cpf = :cpf');
$appStmt->execute(['cpf' => $cpf]);
$application = $appStmt->fetch();
if ($application === false) {
    error_response(404, 'application_not_found', 'Comece a candidatura antes de mandar documento.');
}
if (in_array((string) $application['state'], ['approved', 'review'], true)) {
    error_response(409, 'application_locked', 'Essa candidatura não aceita mais alteração agora.');
}

$tmpPath = $_FILES['document']['tmp_name'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath) ?: '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    error_response(422, 'invalid_file_type', 'Envie foto (JPEG, PNG, WEBP) ou PDF.', fields: ['document' => 'tipo não aceito']);
}
$bytes = file_get_contents($tmpPath);
if ($bytes === false || $bytes === '') {
    error_response(422, 'empty_file', 'Arquivo vazio.');
}
if (strlen($bytes) > 10 * 1024 * 1024) {
    error_response(422, 'file_too_large', 'Documento maior que 10 MB.');
}

$sha256 = hash('sha256', $bytes);

// Mesmo arquivo em duas candidaturas diferentes é sinal de fraude (a mesma
// CNH tentando virar dois entregadores). Barrar aqui é barato.
$dupStmt = $pdo->prepare(
    'SELECT 1 FROM courier_documents WHERE sha256 = :sha AND application_id <> :id'
);
$dupStmt->execute(['sha' => $sha256, 'id' => $application['id']]);
if ($dupStmt->fetchColumn() !== false) {
    error_response(409, 'document_reused', 'Esse arquivo já foi enviado em outra candidatura.');
}

$storageDir = rtrim((string) env('COURIER_DOC_DIR', 'storage/courier_docs'), '/');
$absoluteDir = str_starts_with($storageDir, '/') ? $storageDir : __DIR__ . '/../../../' . $storageDir;
if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0770, true) && !is_dir($absoluteDir)) {
    error_response(500, 'storage_unavailable', 'Não deu pra guardar o documento agora.');
}

$extension = match ($mime) {
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    default => 'jpg',
};
$storageKey = $application['id'] . '-' . $kind . '-' . substr($sha256, 0, 12) . '.' . $extension;
if (file_put_contents($absoluteDir . '/' . $storageKey, $bytes) === false) {
    error_response(500, 'storage_unavailable', 'Não deu pra guardar o documento agora.');
}

// Validade só existe pra documento que vence (CNH). Vem do candidato porque
// ler a data do documento exigiria OCR -- e o revisor confere na imagem.
$expiresOn = null;
if ($kind === 'cnh' && isset($_POST['expires_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['expires_on']) === 1) {
    $expiresOn = (string) $_POST['expires_on'];
    if (strtotime($expiresOn) < time()) {
        error_response(422, 'document_expired', 'Essa CNH está vencida.', fields: ['expires_on' => 'vencida']);
    }
}

$stmt = $pdo->prepare(
    "INSERT INTO courier_documents (application_id, kind, storage_key, sha256, expires_on, state)
     VALUES (:app, :kind, :key, :sha, :expires, 'pending')
     ON CONFLICT (application_id, kind) DO UPDATE
       SET storage_key = EXCLUDED.storage_key, sha256 = EXCLUDED.sha256,
           expires_on = EXCLUDED.expires_on, state = 'pending'
     RETURNING id, kind, state, expires_on"
);
$stmt->execute([
    'app' => $application['id'],
    'kind' => $kind,
    'key' => $storageKey,
    'sha' => $sha256,
    'expires' => $expiresOn,
]);

json_response(201, ['document' => $stmt->fetch()]);
