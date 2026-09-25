<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.1/14.3 — cadastra um endereço do cliente logado. CEP de 8 dígitos,
// UF de 2 letras, código IBGE de 7 dígitos e coordenada obrigatórios: é a
// coordenada que o frete e a área de entrega usam (lib/ordering/delivery.php).
// A validação é a mesma da edição (address_input, lib/account/addresses.php):
// tipo, tamanho e faixa de cada campo, antes de chegar no banco.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

[$address, $fields] = address_input($body, false);
if ($fields !== []) {
    error_response(422, 'invalid_address', 'Confira os campos do endereço.', fields: $fields);
}

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO addresses (user_id, label, street, number, complement, reference, neighborhood, city, city_ibge_code, state, postal_code, lat, lng, is_default)
     VALUES (:user_id, :label, :street, :number, :complement, :reference, :neighborhood, :city, :city_ibge_code, :state, :postal_code, :lat, :lng, :is_default)
     RETURNING id'
);
$stmt->execute([
    'user_id' => $claims['sub'],
    'label' => $address['label'] ?? null,
    'street' => $address['street'],
    'number' => $address['number'] ?? null,
    'complement' => $address['complement'] ?? null,
    // 14.3 — "ponto de referência (ajuda o entregador)". Não é complemento:
    // complemento identifica a unidade e vai no cupom; referência é
    // instrução pra quem entrega.
    'reference' => $address['reference'] ?? null,
    'neighborhood' => $address['neighborhood'] ?? null,
    'city' => $address['city'],
    'city_ibge_code' => $address['city_ibge_code'],
    'state' => $address['state'],
    'postal_code' => $address['postal_code'],
    'lat' => $address['lat'],
    'lng' => $address['lng'],
    'is_default' => pg_bool(($body['is_default'] ?? false) === true),
]);

json_response(201, ['id' => $stmt->fetchColumn()]);
