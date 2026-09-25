<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.3 — "Cadastro: mínimo necessário + LGPD por campo". O cadastro
// começa no OTP (que já cria o usuário com nome e telefone verificados) e
// termina aqui: CPF pra nota fiscal, e-mail, e os opcionais que só são
// gravados se a pessoa consentir.
//
// Edição parcial: só os campos presentes no corpo mudam. Mandar `null` é
// diferente de omitir -- apagar a data de nascimento é o que acontece quando
// alguém revoga o consentimento de aniversário, e precisa ser possível.
//
// O que esta rota NÃO faz: trocar telefone. O telefone é o que o OTP
// verificou; mudar exigiria verificar de novo (purpose=phone_verify, que o
// módulo identity já tem) e é outro fluxo, não um campo de formulário.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$pdo = db();
$userId = (string) $claims['sub'];

$updates = [];

if (array_key_exists('full_name', $body)) {
    $fullName = body_text($body, 'full_name', 120) ?? '';
    if ($fullName === '') {
        error_response(422, 'full_name_required', 'Nome completo é obrigatório.', fields: ['full_name' => 'obrigatório']);
    }
    $updates['full_name'] = $fullName;
}

if (array_key_exists('email', $body)) {
    $email = $body['email'] === null ? null : body_text($body, 'email', 254) ?? '';
    if ($email !== null && !is_valid_email($email)) {
        error_response(422, 'invalid_email', 'E-mail inválido.', fields: ['email' => 'inválido']);
    }
    $updates['email'] = $email;
}

if (array_key_exists('cpf', $body)) {
    $cpf = $body['cpf'] === null ? null : only_digits((string) $body['cpf']);
    if ($cpf !== null && !is_valid_cpf($cpf)) {
        error_response(422, 'invalid_cpf', 'CPF inválido.', fields: ['cpf' => 'inválido']);
    }
    $updates['cpf'] = $cpf;
}

if (array_key_exists('birth_date', $body)) {
    $birthDate = $body['birth_date'] === null || $body['birth_date'] === '' ? null : (string) $body['birth_date'];
    if ($birthDate !== null) {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        if ($parsed === false || $parsed->format('Y-m-d') !== $birthDate) {
            error_response(422, 'invalid_birth_date', 'Data de nascimento inválida (use AAAA-MM-DD).', fields: ['birth_date' => 'inválido']);
        }
        if ($parsed > new \DateTimeImmutable('today')) {
            error_response(422, 'invalid_birth_date', 'Data de nascimento no futuro.', fields: ['birth_date' => 'inválido']);
        }
    }
    $updates['birth_date'] = $birthDate;
}

if ($updates === []) {
    error_response(422, 'nothing_to_update', 'Nenhum campo pra atualizar.');
}

$assignments = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", array_keys($updates)));

try {
    $pdo->prepare("UPDATE users SET {$assignments} WHERE id = :id")
        ->execute([...$updates, 'id' => $userId]);
} catch (\PDOException $e) {
    // CPF e e-mail são UNIQUE em users: outra conta já usa esse documento.
    // 409 com a saída possível, não 500 cru (Especificação I.6).
    if ($e->getCode() === '23505') {
        $isCpf = str_contains((string) $e->getMessage(), 'cpf');
        error_response(
            409,
            $isCpf ? 'cpf_in_use' : 'email_in_use',
            $isCpf
                ? 'Esse CPF já está em outra conta. Entre com ela ou fale com o suporte.'
                : 'Esse e-mail já está em outra conta. Entre com ela para continuar.'
        );
    }
    throw $e;
}

$stmt = $pdo->prepare('SELECT id, full_name, email, phone, birth_date, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);

json_response(200, ['user' => $stmt->fetch()]);
