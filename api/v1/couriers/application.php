<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 15.2 — a lista de documentos, item por item, com o que falta.
//
// "Documento por documento com verificação automática visível." A parte
// "automática" tem limite honesto, e ele está escrito abaixo: o que dá pra
// conferir sem provedor é o que a gente confere.

require_method('GET');
$claims = require_auth();

$pdo = db();
$userStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
$userStmt->execute(['id' => $claims['sub']]);
$cpf = $userStmt->fetchColumn();

$application = false;
if (is_string($cpf) && $cpf !== '') {
    $stmt = $pdo->prepare('SELECT * FROM courier_applications WHERE cpf = :cpf');
    $stmt->execute(['cpf' => $cpf]);
    $application = $stmt->fetch();
}

// Os quatro documentos do mock. `address_proof` só é exigido de quem tem
// veículo com placa? Não: é exigido de todo mundo -- é o documento que amarra
// a pessoa a um lugar, e é o mesmo pedido em qualquer modal.
$required = [
    ['kind' => 'cnh', 'label' => 'CNH', 'note' => 'categoria A para moto'],
    ['kind' => 'selfie', 'label' => 'Selfie com o documento', 'note' => 'a mesma pessoa da CNH'],
    ['kind' => 'crlv', 'label' => 'CRLV do veículo', 'note' => 'só para moto e carro'],
    ['kind' => 'address_proof', 'label' => 'Comprovante de residência', 'note' => 'dos últimos 3 meses'],
];

if ($application === false) {
    json_response(200, [
        'application' => null,
        'documents' => [],
        'required' => $required,
        'missing' => array_column($required, 'kind'),
        'can_submit' => false,
    ]);
}

$docStmt = $pdo->prepare(
    'SELECT id, kind, state, expires_on, sha256 FROM courier_documents WHERE application_id = :id ORDER BY kind'
);
$docStmt->execute(['id' => $application['id']]);
$documents = $docStmt->fetchAll();
$sent = array_column($documents, 'kind');

// Veículo sem placa não tem CRLV pra mandar: exigir seria travar bicicleta e
// a pé num documento que não existe.
$needed = array_values(array_filter(
    $required,
    fn (array $doc) => $doc['kind'] !== 'crlv' || in_array((string) $application['vehicle'], ['moto', 'car'], true)
));
$missing = array_values(array_diff(array_column($needed, 'kind'), $sent));

json_response(200, [
    'application' => $application,
    'documents' => $documents,
    'required' => $needed,
    'missing' => $missing,
    'can_submit' => $missing === [] && (string) $application['state'] !== 'review',
    // O mock mostra "rosto confere · 96%". Match facial e liveness precisam
    // de um provedor de visão, que não está na cláusula zero: `face_match`
    // fica nulo e a conferência da selfie é humana, na fila do admin. A tela
    // diz isso em vez de estampar uma porcentagem inventada.
    'face_match_note' => 'A conferência da selfie é feita por uma pessoa do nosso time — não temos match facial automático.',
    'contract_note' => 'Na última etapa você lê e aceita o contrato de prestação de serviço. Sem vínculo empregatício — você escolhe quando ficar online.',
]);
