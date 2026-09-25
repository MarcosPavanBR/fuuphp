<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.2 — Onboarding do entregador.
//
// "A regra 'chave Pix tem que ser sua' é antifraude, e dizer isso na tela
// evita 90% das tentativas." Aqui ela é regra de verdade, não texto: chave
// que é CPF tem que ser o CPF do candidato, e chave que é telefone tem que
// ser o telefone dele.
//
// A candidatura exige login (OTP do cliente, o mesmo da Fase 10): couriers
// .user_id é NOT NULL, então na hora de aprovar precisa existir uma pessoa
// com conta -- e exigir isso na entrada evita candidatura órfã e spam.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$pdo = db();
$userStmt = $pdo->prepare('SELECT id, full_name, cpf, phone, email FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$user = $userStmt->fetch();
if ($user === false) {
    error_response(404, 'user_not_found', 'Conta não encontrada.');
}

$fullName = (body_text($body, 'full_name', 120) ?? trim((string) ($user['full_name'] ?? '')));
$cpf = only_digits((string) ($body['cpf'] ?? $user['cpf'] ?? ''));
$phone = only_digits((string) ($body['phone'] ?? $user['phone'] ?? ''));
$vehicle = $body['vehicle'] ?? '';
$plate = isset($body['plate']) ? strtoupper(body_text($body, 'plate', 10) ?? '') : null;
$pixKey = body_text($body, 'pix_key', 140) ?? '';

$fields = [];
if ($fullName === '') {
    $fields['full_name'] = 'obrigatório';
}
if (!is_valid_cpf($cpf)) {
    $fields['cpf'] = 'CPF inválido';
}
if (strlen($phone) < 10) {
    $fields['phone'] = 'telefone inválido';
}
if (!in_array($vehicle, ['moto', 'bike', 'car', 'foot'], true)) {
    $fields['vehicle'] = 'moto, bike, car ou foot';
}
// Veículo que tem placa precisa dela: é o que liga o CRLV à pessoa.
if (in_array($vehicle, ['moto', 'car'], true) && ($plate === null || $plate === '')) {
    $fields['plate'] = 'obrigatória para moto e carro';
}
if ($pixKey === '') {
    $fields['pix_key'] = 'obrigatória';
}
if ($fields !== []) {
    error_response(422, 'invalid_application', 'Confira os dados da candidatura.', fields: $fields);
}

// A regra antifraude, escrita como código. Chave aleatória ou e-mail não dá
// pra conferir aqui (a checagem de titularidade é do banco, no momento da
// transferência) -- e a tela diz isso em vez de fingir que conferiu.
$keyDigits = only_digits($pixKey);
$pixOwnershipChecked = false;
if (strlen($keyDigits) === 11 && is_valid_cpf($keyDigits)) {
    if ($keyDigits !== $cpf) {
        error_response(
            422,
            'pix_key_not_yours',
            'A chave Pix precisa ser sua: esse CPF não é o mesmo da sua candidatura.',
            fields: ['pix_key' => 'CPF diferente do seu']
        );
    }
    $pixOwnershipChecked = true;
} elseif (strlen($keyDigits) >= 10 && strlen($keyDigits) <= 13) {
    $normalized = substr($keyDigits, -11);
    if (substr($phone, -11) !== $normalized) {
        error_response(
            422,
            'pix_key_not_yours',
            'A chave Pix precisa ser sua: esse telefone não é o da sua candidatura.',
            fields: ['pix_key' => 'telefone diferente do seu']
        );
    }
    $pixOwnershipChecked = true;
} elseif (filter_var($pixKey, FILTER_VALIDATE_EMAIL) !== false && $user['email'] !== null) {
    $pixOwnershipChecked = strcasecmp($pixKey, (string) $user['email']) === 0;
    if (!$pixOwnershipChecked) {
        error_response(
            422,
            'pix_key_not_yours',
            'A chave Pix precisa ser sua: esse e-mail não é o da sua conta.',
            fields: ['pix_key' => 'e-mail diferente do seu']
        );
    }
}

// Candidatura é por CPF (UNIQUE): reenviar é corrigir a mesma, não criar
// outra. Só volta a ser editável enquanto não foi aprovada.
$existing = $pdo->prepare('SELECT * FROM courier_applications WHERE cpf = :cpf');
$existing->execute(['cpf' => $cpf]);
$application = $existing->fetch();

if ($application !== false && in_array((string) $application['state'], ['approved', 'review'], true)) {
    error_response(409, 'application_locked', match ((string) $application['state']) {
        'approved' => 'Sua candidatura já foi aprovada.',
        default => 'Sua candidatura já está em análise — a gente avisa quando sair.',
    });
}

if ($application === false) {
    $stmt = $pdo->prepare(
        "INSERT INTO courier_applications (full_name, cpf, phone, email, vehicle, plate, pix_key, state)
         VALUES (:full_name, :cpf, :phone, :email, :vehicle, :plate, :pix_key, 'draft')
         RETURNING *"
    );
    $stmt->execute([
        'full_name' => $fullName,
        'cpf' => $cpf,
        'phone' => $phone,
        'email' => $user['email'],
        'vehicle' => $vehicle,
        'plate' => $plate,
        'pix_key' => $pixKey,
    ]);
} else {
    $stmt = $pdo->prepare(
        "UPDATE courier_applications
            SET full_name = :full_name, phone = :phone, vehicle = :vehicle,
                plate = :plate, pix_key = :pix_key, state = 'draft'
          WHERE id = :id RETURNING *"
    );
    $stmt->execute([
        'full_name' => $fullName,
        'phone' => $phone,
        'vehicle' => $vehicle,
        'plate' => $plate,
        'pix_key' => $pixKey,
        'id' => $application['id'],
    ]);
}

json_response(201, [
    'application' => $stmt->fetch(),
    'pix_ownership_checked' => $pixOwnershipChecked,
    // Chave aleatória e e-mail sem conta: a titularidade só é conferida de
    // verdade na transferência, pelo banco. Dizer o contrário aqui seria
    // prometer uma checagem que não acontece.
    'pix_note' => $pixOwnershipChecked
        ? 'Conferimos: a chave é sua.'
        : 'Não dá pra conferir essa chave por aqui — a titularidade é checada pelo banco no primeiro pagamento, e chave de outra pessoa é recusada lá.',
]);
