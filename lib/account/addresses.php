<?php

declare(strict_types=1);

// Endereço do cliente (tela 6.1): a validação única de criar e editar, e a
// regra da migração 043 -- endereço já usado em pedido não muda por baixo do
// pedido. Quem usa: api/v1/addresses/{create,update,delete}.php.

/** Campos de texto e o tamanho máximo de cada um (o que cabe numa etiqueta). */
const ADDRESS_TEXT_LIMITS = [
    'label' => 40,
    'street' => 200,
    'number' => 20,
    'complement' => 100,
    'reference' => 200,
    'neighborhood' => 100,
    'city' => 100,
];

/** Sem estes o frete não se calcula e o entregador não chega. */
const ADDRESS_REQUIRED = ['street', 'city', 'city_ibge_code', 'state', 'postal_code', 'lat', 'lng'];

/**
 * Confere e normaliza os campos de endereço que vieram no corpo.
 *
 * Criar ($partial = false): os obrigatórios têm que vir. Editar ($partial =
 * true): só o que veio muda, mas obrigatório não pode virar vazio.
 *
 * @return array{0: array<string,mixed>, 1: array<string,string>} [campos limpos, erros por campo]
 */
function address_input(array $body, bool $partial): array
{
    $clean = [];
    $errors = [];

    foreach (ADDRESS_TEXT_LIMITS as $field => $max) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $value = $body[$field];
        if ($value === null || $value === '') {
            $clean[$field] = null;
            continue;
        }
        // Número da casa chega como 42 às vezes; lista e objeto nunca.
        if (!is_string($value) && !is_int($value)) {
            $errors[$field] = 'precisa ser texto';
            continue;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            $errors[$field] = "até {$max} caracteres";
            continue;
        }
        $clean[$field] = $value === '' ? null : $value;
    }

    if (array_key_exists('city_ibge_code', $body)) {
        $ibge = is_string($body['city_ibge_code']) || is_int($body['city_ibge_code']) ? (string) $body['city_ibge_code'] : '';
        if (is_valid_ibge($ibge)) {
            $clean['city_ibge_code'] = $ibge;
        } else {
            $errors['city_ibge_code'] = 'código IBGE de 7 dígitos';
        }
    }
    if (array_key_exists('state', $body)) {
        $state = is_string($body['state']) ? strtoupper(trim($body['state'])) : '';
        if (preg_match('/^[A-Z]{2}$/', $state) === 1) {
            $clean['state'] = $state;
        } else {
            $errors['state'] = 'UF de 2 letras';
        }
    }
    if (array_key_exists('postal_code', $body)) {
        $cep = is_string($body['postal_code']) || is_int($body['postal_code']) ? only_digits((string) $body['postal_code']) : '';
        if (strlen($cep) === 8) {
            $clean['postal_code'] = $cep;
        } else {
            $errors['postal_code'] = 'CEP de 8 dígitos';
        }
    }
    foreach (['lat' => 90.0, 'lng' => 180.0] as $field => $limit) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        if (is_number_between($body[$field], -$limit, $limit)) {
            $clean[$field] = round((float) $body[$field], 6);
        } else {
            $errors[$field] = "coordenada entre -{$limit} e {$limit}";
        }
    }

    foreach (ADDRESS_REQUIRED as $field) {
        if (isset($errors[$field])) {
            continue;
        }
        $missing = $partial
            ? array_key_exists($field, $clean) && $clean[$field] === null
            : !isset($clean[$field]);
        if ($missing) {
            $errors[$field] = 'obrigatório';
        }
    }

    return [$clean, $errors];
}

/** O endereço já foi destino de algum pedido (inclusive carrinho)? */
function address_in_use(PDO $pdo, int $addressId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE address_id = :id LIMIT 1');
    $stmt->execute(['id' => $addressId]);
    return $stmt->fetchColumn() !== false;
}

/** As mudanças pedidas mudam de fato alguma coisa no endereço guardado? */
function address_differs(array $existing, array $updates): bool
{
    foreach ($updates as $field => $value) {
        $old = $existing[$field] ?? null;
        if ($field === 'lat' || $field === 'lng') {
            if ($old === null || abs((float) $old - (float) $value) > 0.0000005) {
                return true;
            }
        } elseif (($old === null ? null : trim((string) $old)) !== $value) {
            return true;
        }
    }
    return false;
}
