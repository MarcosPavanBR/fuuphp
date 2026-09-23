<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 15.2, lado de dentro: a fila de análise das candidaturas.
//
// Aprovar aqui é o que CRIA o entregador: `couriers` (id = id da
// candidatura, como a migração 007 manda) e o login do app do entregador
// (partner_accounts com CPF + código de acesso). Antes disso, ninguém
// entrava na plataforma sem um INSERT manual no banco.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT a.*,
                EXTRACT(epoch FROM (now() - a.created_at)) / 3600 AS hours_waiting,
                (SELECT json_agg(json_build_object(
                          'kind', d.kind, 'state', d.state, 'expires_on', d.expires_on)
                        ORDER BY d.kind)
                   FROM courier_documents d WHERE d.application_id = a.id) AS documents,
                (SELECT count(*) FROM courier_documents d WHERE d.application_id = a.id) AS documents_count
           FROM courier_applications a
          WHERE a.state IN ('review','needs_fix')
          ORDER BY a.created_at"
    );

    $recent = $pdo->query(
        "SELECT id, full_name, cpf, state, reviewed_by, created_at
           FROM courier_applications
          WHERE state IN ('approved','rejected')
          ORDER BY created_at DESC LIMIT 10"
    );

    json_response(200, [
        'queue' => $stmt->fetchAll(),
        'recent' => $recent->fetchAll(),
        // "Análise em até 48 h" é o compromisso da tela do candidato; aqui
        // ele vira o número que o time olha.
        'sla_hours' => 48,
    ]);
}

require_method('POST');
$body = read_json_body();

$applicationId = (string) ($body['application_id'] ?? '');
$decision = $body['decision'] ?? '';
$note = isset($body['note']) ? trim((string) $body['note']) : null;

if ($applicationId === '' || !in_array($decision, ['approve', 'reject', 'needs_fix'], true)) {
    error_response(422, 'invalid_request', 'Informe application_id e decision (approve, reject ou needs_fix).');
}
if ($decision !== 'approve' && ($note === null || $note === '')) {
    error_response(422, 'note_required', 'Recusa e pedido de correção precisam de motivo — é o que a pessoa vai ler.', fields: ['note' => 'obrigatório']);
}

$appStmt = $pdo->prepare('SELECT * FROM courier_applications WHERE id = :id FOR UPDATE');
$pdo->beginTransaction();
try {
    $appStmt->execute(['id' => $applicationId]);
    $application = $appStmt->fetch();
    if ($application === false) {
        $pdo->rollBack();
        error_response(404, 'application_not_found', 'Candidatura não encontrada.');
    }
    if ((string) $application['state'] === 'approved') {
        $pdo->rollBack();
        error_response(409, 'already_approved', 'Essa candidatura já foi aprovada.');
    }

    if ($decision !== 'approve') {
        $stmt = $pdo->prepare(
            'UPDATE courier_applications SET state = :state, reviewed_by = :by WHERE id = :id RETURNING *'
        );
        $stmt->execute([
            'state' => $decision === 'reject' ? 'rejected' : 'needs_fix',
            'by' => $adminId,
            'id' => $applicationId,
        ]);
        $decided = $stmt->fetch();
        $pdo->commit();

        json_response(200, ['application' => $decided, 'note' => $note]);
    }

    // Aprovar exige a pessoa: couriers.user_id é NOT NULL, e o vínculo é
    // pelo CPF -- o mesmo CPF que fez a candidatura logado.
    $userStmt = $pdo->prepare('SELECT id, phone FROM users WHERE cpf = :cpf');
    $userStmt->execute(['cpf' => $application['cpf']]);
    $user = $userStmt->fetch();
    if ($user === false) {
        $pdo->rollBack();
        error_response(409, 'user_not_found', 'Não há conta com esse CPF — a pessoa precisa terminar o cadastro antes.');
    }

    // A cidade vem do corpo: a candidatura não tem coluna de cidade (Parte
    // II), e é o revisor que sabe onde essa pessoa vai rodar.
    $city = only_digits((string) ($body['city_ibge_code'] ?? ''));
    if (strlen($city) !== 7) {
        $pdo->rollBack();
        error_response(422, 'city_required', 'Informe a praça (city_ibge_code) onde essa pessoa vai rodar.', fields: ['city_ibge_code' => 'obrigatório']);
    }

    $pdo->prepare("UPDATE users SET role = 'courier' WHERE id = :id AND role = 'customer'")
        ->execute(['id' => $user['id']]);

    $pdo->prepare(
        'INSERT INTO couriers (id, user_id, city_ibge_code, active)
         VALUES (:id, :user_id, :city, true)
         ON CONFLICT (id) DO NOTHING'
    )->execute(['id' => $applicationId, 'user_id' => $user['id'], 'city' => $city]);

    // O código de acesso do app do entregador (Fase 8): seis dígitos,
    // guardado só como hash bcrypt. O texto aparece UMA vez, pra quem aprovou
    // passar adiante -- não há integração de WhatsApp pra mandar sozinho, e
    // fingir que mandamos seria pior que dizer isso.
    $accessCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash)
         VALUES (:user_id, :kind, :courier_id, :login_code, :hash)
         ON CONFLICT (kind, login_code) DO UPDATE
           SET access_code_hash = EXCLUDED.access_code_hash, courier_id = EXCLUDED.courier_id'
    )->execute([
        'user_id' => $user['id'],
        'kind' => 'courier',
        'courier_id' => $applicationId,
        'login_code' => $application['cpf'],
        // bcrypt, como a senha da loja (migração 034): 6 dígitos em SHA-256
        // puro cairiam em segundos se o banco vazasse.
        'hash' => password_hash($accessCode, PASSWORD_DEFAULT),
    ]);

    $stmt = $pdo->prepare(
        "UPDATE courier_applications SET state = 'approved', reviewed_by = :by WHERE id = :id RETURNING *"
    );
    $stmt->execute(['by' => $adminId, 'id' => $applicationId]);
    $decided = $stmt->fetch();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'application' => $decided,
    'courier_id' => $applicationId,
    'access_code' => $accessCode,
    'access_code_note' => 'Passe esse código pra pessoa: ele não aparece de novo, e é o que ela usa com o CPF pra entrar no app do entregador.',
]);
