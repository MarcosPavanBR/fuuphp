<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.1 — Endereços salvos. "CRUD com endereço padrão." Edição parcial
// (só os campos mandados mudam) e troca de padrão: marcar um endereço
// como is_default=true desmarca os outros do mesmo usuário na mesma
// transação -- nunca dois padrões ao mesmo tempo, sem depender de UNIQUE
// parcial no banco (a tabela não tem essa constraint, é regra de aplicação).
//
// Endereço já usado em pedido não é reescrito (migração 043): a edição vira
// um endereço NOVO, com as mudanças, e o antigo é arquivado -- o pedido a
// caminho continua indo pra onde foi pedido, e o recibo antigo não muda. A
// resposta traz o endereço que vale daqui pra frente (id novo nesse caso,
// com `replaced_id` = o antigo). Só trocar o padrão não cria versão nova.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$addressId = positive_id($body['id'] ?? null);
if ($addressId === null) {
    error_response(422, 'id_required', 'Informe id.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT * FROM addresses WHERE id = :id AND user_id = :user_id AND archived_at IS NULL');
$existingStmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
$existing = $existingStmt->fetch();
if ($existing === false) {
    error_response(404, 'address_not_found', 'Endereço não encontrado.');
}

[$updates, $fields] = address_input($body, true);
if (array_key_exists('is_default', $body) && !is_bool($body['is_default'])) {
    $fields['is_default'] = 'true ou false';
}
if ($fields !== []) {
    error_response(422, 'invalid_address', 'Confira os campos do endereço.', fields: $fields);
}
$makeDefault = ($body['is_default'] ?? false) === true;

$currentId = $addressId;
$replacedId = null;
$pdo->beginTransaction();
try {
    if ($updates !== [] && address_differs($existing, $updates)) {
        if (address_in_use($pdo, $addressId)) {
            // Versão nova com tudo do antigo + as mudanças; o antigo sai da
            // lista, mas continua sendo o destino dos pedidos que já o usam.
            $keep = [...array_keys(ADDRESS_TEXT_LIMITS), 'city_ibge_code', 'state', 'postal_code', 'lat', 'lng'];
            $row = array_merge(array_intersect_key($existing, array_flip($keep)), $updates);
            $row['is_default'] = pg_bool((bool) $existing['is_default']);
            $insert = $pdo->prepare(
                'INSERT INTO addresses (user_id, label, street, number, complement, reference, neighborhood, city, city_ibge_code, state, postal_code, lat, lng, is_default)
                 VALUES (:user_id, :label, :street, :number, :complement, :reference, :neighborhood, :city, :city_ibge_code, :state, :postal_code, :lat, :lng, :is_default)
                 RETURNING id'
            );
            $insert->execute(['user_id' => $claims['sub']] + $row);
            $currentId = (int) $insert->fetchColumn();
            $replacedId = $addressId;
            $pdo->prepare('UPDATE addresses SET archived_at = now(), is_default = false WHERE id = :id')
                ->execute(['id' => $addressId]);
        } else {
            $setClauses = implode(', ', array_map(static fn (string $f) => "{$f} = :{$f}", array_keys($updates)));
            $pdo->prepare("UPDATE addresses SET {$setClauses} WHERE id = :id")
                ->execute([...$updates, 'id' => $addressId]);
        }
    }
    if ($makeDefault) {
        $pdo->prepare('UPDATE addresses SET is_default = false WHERE user_id = :user_id AND id <> :id')
            ->execute(['user_id' => $claims['sub'], 'id' => $currentId]);
        $pdo->prepare('UPDATE addresses SET is_default = true WHERE id = :id')->execute(['id' => $currentId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$freshStmt = $pdo->prepare('SELECT * FROM addresses WHERE id = :id');
$freshStmt->execute(['id' => $currentId]);

json_response(200, ['address' => $freshStmt->fetch(), 'replaced_id' => $replacedId]);
