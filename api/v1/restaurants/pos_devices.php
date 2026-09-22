<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.4, bloco "MAQUININHAS CADASTRADAS" — e a outra ponta da 10.6.
//
// "A máquina é do estabelecimento": cadastrar, ver com quem está e
// CONFIRMAR a devolução são as três coisas que a loja faz com ela. A
// confirmação é o que fecha a custódia -- "ele marca 'devolvi', a loja
// confirma no painel" (tela 10.6). Uma ponta só seria a palavra de um
// contra a do outro sobre um equipamento que custa dinheiro.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

require_method('POST');
$body = read_json_body();
$action = (string) ($body['action'] ?? '');

if ($action === 'register') {
    $label = trim((string) ($body['label'] ?? ''));
    $acquirer = strtolower(trim((string) ($body['acquirer'] ?? '')));
    $serial = isset($body['serial']) ? trim((string) $body['serial']) : null;

    if ($label === '' || $acquirer === '') {
        error_response(422, 'invalid_request', 'Informe o apelido da máquina e a adquirente.', fields: ['label' => 'obrigatório', 'acquirer' => 'obrigatório']);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO pos_devices (restaurant_id, label, acquirer, serial)
         VALUES (:rid, :label, :acq, :serial)
         ON CONFLICT (restaurant_id, label) DO UPDATE
           SET acquirer = EXCLUDED.acquirer, serial = EXCLUDED.serial, active = true
         RETURNING *'
    );
    $stmt->execute(['rid' => $restaurantId, 'label' => $label, 'acq' => $acquirer, 'serial' => $serial]);

    json_response(201, ['device' => $stmt->fetch()]);
}

if ($action === 'deactivate') {
    $deviceId = (string) ($body['device_id'] ?? '');

    // Máquina na rua não some do cadastro: enquanto a custódia estiver
    // aberta, desativar esconderia justamente o equipamento que falta voltar.
    $open = $pdo->prepare(
        'SELECT 1 FROM pos_custody c JOIN pos_devices d ON d.id = c.device_id
          WHERE c.device_id = :id AND d.restaurant_id = :rid AND c.returned_at IS NULL'
    );
    $open->execute(['id' => $deviceId, 'rid' => $restaurantId]);
    if ($open->fetchColumn() !== false) {
        error_response(409, 'device_out', 'Essa máquina está com um entregador — confirme a devolução antes.');
    }

    $stmt = $pdo->prepare(
        'UPDATE pos_devices SET active = false
          WHERE id = :id AND restaurant_id = :rid RETURNING *'
    );
    $stmt->execute(['id' => $deviceId, 'rid' => $restaurantId]);
    $device = $stmt->fetch();
    if ($device === false) {
        error_response(404, 'device_not_found', 'Máquina não encontrada nesta loja.');
    }

    json_response(200, ['device' => $device]);
}

if ($action !== 'confirm_return') {
    error_response(422, 'invalid_action', 'Ação inválida: register, deactivate ou confirm_return.', fields: ['action' => 'inválida']);
}

$custodyId = (int) ($body['custody_id'] ?? 0);
if ($custodyId <= 0) {
    error_response(422, 'custody_required', 'Informe custody_id.', fields: ['custody_id' => 'obrigatório']);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'SELECT c.*, d.restaurant_id, d.label
           FROM pos_custody c JOIN pos_devices d ON d.id = c.device_id
          WHERE c.id = :id FOR UPDATE OF c'
    );
    $stmt->execute(['id' => $custodyId]);
    $custody = $stmt->fetch();
    if ($custody === false || (string) $custody['restaurant_id'] !== $restaurantId) {
        $pdo->rollBack();
        error_response(404, 'custody_not_found', 'Retirada não encontrada nesta loja.');
    }
    if ($custody['confirmed_by'] !== null) {
        $pdo->rollBack();
        error_response(409, 'already_confirmed', 'Essa devolução já foi confirmada.');
    }

    // A loja pode confirmar mesmo que o entregador não tenha marcado
    // "devolvi" (ele entregou no balcão e foi embora, que é o caso comum):
    // aí a confirmação fecha as duas pontas de uma vez.
    $stmt = $pdo->prepare(
        'UPDATE pos_custody
            SET returned_at = COALESCE(returned_at, now()), confirmed_by = :by
          WHERE id = :id RETURNING *'
    );
    $stmt->execute(['by' => $claims['sub'], 'id' => $custodyId]);
    $closed = $stmt->fetch();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// "os NSUs pendentes travam o fechamento do dia": a máquina voltou, o dia
// não fechou. Dizer isso aqui é o que evita a loja achar que acabou.
$pending = $pdo->prepare(
    "SELECT count(*) FROM card_transactions
      WHERE device_id = :device AND created_at >= :since AND state <> 'reconciled'"
);
$pending->execute(['device' => $custody['device_id'], 'since' => $custody['taken_at']]);

json_response(200, [
    'custody' => $closed,
    'pending_transactions' => (int) $pending->fetchColumn(),
]);
