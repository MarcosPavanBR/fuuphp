<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.2, última etapa: "você lê e aceita o contrato de prestação de
// serviço e a política de dados".
//
// O aceite não é um checkbox que some: fica em `consents` (migração 001,
// com IP e user-agent) e a VERSÃO do contrato fica na candidatura -- é isso
// que faz "contrato versionado" ser verdade, e é o que se apresenta quando
// alguém discute o que foi aceito.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

// A versão vigente do contrato é a da política da plataforma: o contrato
// muda junto com as regras que ele descreve.
$pdo = db();
$contractVersion = (int) $pdo->query(
    'SELECT MAX(version) FROM platform_policies'
)->fetchColumn();

if (($body['accept_contract'] ?? false) !== true) {
    error_response(422, 'contract_required', 'Pra enviar a candidatura é preciso aceitar o contrato.', fields: ['accept_contract' => 'obrigatório']);
}

$userStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$cpf = $userStmt->fetchColumn();
if (!is_string($cpf) || $cpf === '') {
    error_response(409, 'cpf_required', 'Complete seu cadastro com CPF.');
}

$appStmt = $pdo->prepare('SELECT * FROM courier_applications WHERE cpf = :cpf');
$appStmt->execute(['cpf' => $cpf]);
$application = $appStmt->fetch();
if ($application === false) {
    error_response(404, 'application_not_found', 'Você ainda não começou uma candidatura.');
}
if ((string) $application['state'] === 'approved') {
    error_response(409, 'application_locked', 'Sua candidatura já foi aprovada.');
}
if ((string) $application['state'] === 'review') {
    error_response(409, 'already_in_review', 'Sua candidatura já está em análise.');
}

// Faltando documento, não vai pra fila: o revisor abrir e fechar candidatura
// incompleta é o que faz a fila de análise virar 48 h de verdade.
$docStmt = $pdo->prepare('SELECT kind FROM courier_documents WHERE application_id = :id');
$docStmt->execute(['id' => $application['id']]);
$sent = array_column($docStmt->fetchAll(), 'kind');
$needed = ['cnh', 'selfie', 'address_proof'];
if (in_array((string) $application['vehicle'], ['moto', 'car'], true)) {
    $needed[] = 'crlv';
}
$missing = array_values(array_diff($needed, $sent));
if ($missing !== []) {
    error_response(422, 'documents_missing', 'Faltam documentos pra mandar pra análise.', fields: ['missing' => implode(', ', $missing)]);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "UPDATE courier_applications
            SET state = 'review', contract_version = :version
          WHERE id = :id RETURNING *"
    );
    $stmt->execute(['version' => $contractVersion, 'id' => $application['id']]);

    $pdo->prepare(
        'INSERT INTO consents (user_id, kind, version, accepted_at, ip)
         VALUES (:user_id, :kind, :version, now(), :ip)'
    )->execute([
        'user_id' => $claims['sub'],
        // `consents.kind` não tem valor de "contrato de entregador" (a lista
        // da migração 001 é terms/privacy_policy/marketing/location). O
        // contrato de prestação de serviço é o "terms" desta pessoa nesta
        // versão; inventar um valor novo pediria migração só pra rótulo.
        'kind' => 'terms',
        // A versão carrega o prefixo pra não se confundir com o aceite dos
        // termos do app do cliente, que usa a mesma tabela.
        'version' => 'courier-contract-v' . $contractVersion,
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
    'application' => $stmt->fetch(),
    'contract_version' => $contractVersion,
    // "Análise em até 48 h · avisamos por WhatsApp": o prazo é o compromisso
    // que a fila do admin mostra; o aviso por WhatsApp não existe (não há
    // integração), e por isso a resposta diz como a pessoa fica sabendo.
    'notice' => 'Análise em até 48 h. O resultado aparece aqui mesmo nesta tela — ainda não mandamos WhatsApp.',
]);
