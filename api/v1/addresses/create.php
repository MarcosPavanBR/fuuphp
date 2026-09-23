<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.1/14.3 — cadastra um endereço do cliente logado. CEP de 8 dígitos,
// UF de 2 letras e coordenada obrigatórias: é a coordenada que o frete e a
// área de entrega usam (lib/ordering/delivery.php).

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$required = ['street', 'city', 'city_ibge_code', 'state', 'postal_code', 'lat', 'lng'];
$fields = [];
foreach ($required as $field) {
    if (!isset($body[$field]) || $body[$field] === '') {
        $fields[$field] = 'obrigatório';
    }
}
if ($fields !== []) {
    error_response(422, 'invalid_address', 'Faltam campos obrigatórios do endereço.', fields: $fields);
}

$postalCode = only_digits((string) $body['postal_code']);
if (strlen($postalCode) !== 8) {
    error_response(422, 'invalid_postal_code', 'CEP inválido.', fields: ['postal_code' => 'inválido']);
}

$state = strtoupper(trim((string) $body['state']));
if (strlen($state) !== 2) {
    error_response(422, 'invalid_state', 'UF inválida.', fields: ['state' => 'inválido']);
}

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO addresses (user_id, label, street, number, complement, reference, neighborhood, city, city_ibge_code, state, postal_code, lat, lng, is_default)
     VALUES (:user_id, :label, :street, :number, :complement, :reference, :neighborhood, :city, :city_ibge_code, :state, :postal_code, :lat, :lng, :is_default)
     RETURNING id'
);
$stmt->execute([
    'user_id' => $claims['sub'],
    'label' => $body['label'] ?? null,
    'street' => $body['street'],
    'number' => $body['number'] ?? null,
    'complement' => $body['complement'] ?? null,
    // 14.3 — "ponto de referência (ajuda o entregador)". Não é complemento:
    // complemento identifica a unidade e vai no cupom; referência é
    // instrução pra quem entrega.
    'reference' => $body['reference'] ?? null,
    'neighborhood' => $body['neighborhood'] ?? null,
    'city' => $body['city'],
    'city_ibge_code' => $body['city_ibge_code'],
    'state' => $state,
    'postal_code' => $postalCode,
    'lat' => $body['lat'],
    'lng' => $body['lng'],
    'is_default' => pg_bool(!empty($body['is_default'])),
]);

json_response(201, ['id' => $stmt->fetchColumn()]);
