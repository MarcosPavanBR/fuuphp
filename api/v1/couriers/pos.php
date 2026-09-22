<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

/*
 * POST|GET /v1/couriers/pos.php — maquininha no app do entregador.
 *
 * Telas 10.6 ("devolução da maquininha") e 9.6 (a venda que alimenta a
 * conciliação), com as duas donas possíveis da máquina:
 *
 *   MÁQUINA DA LOJA (o padrão). "O dinheiro da maquininha cai direto na
 *   conta da loja — você não deve nada por essas vendas. Só o equipamento e
 *   os NSUs." Venda NÃO lança `courier_cash`: é conferência, não dívida.
 *
 *   MÁQUINA DO PRÓPRIO ENTREGADOR (só se `allow_courier_own_pos` na
 *   política). "O valor cai na conta dele, então vira dívida com a loja e
 *   segue o mesmo fluxo da espécie." Venda LANÇA `courier_cash` -- e daí em
 *   diante é baixa de caixa como dinheiro vivo (tela 9.3).
 *
 * Ações:
 *   GET                 → máquina em mãos, prazo, vendas, máquinas livres
 *   POST take           → retira uma máquina da loja (registra posse)
 *   POST register_own   → cadastra a própria máquina
 *   POST sale           → informa NSU e valor de uma venda (ou completa o NSU)
 *   POST return         → "Devolvi a maquininha"; a loja confirma depois
 */

$claims = require_auth();
$courierId = require_courier($claims);
$pdo = db();

$policy = $pdo->query(
    'SELECT pos_return_deadline, allow_courier_own_pos FROM platform_policies ORDER BY version DESC LIMIT 1'
)->fetch() ?: [];
$allowOwn = ($policy['allow_courier_own_pos'] ?? false) === true;

/** As máquinas próprias ativas deste entregador. */
function courier_own_devices(PDO $pdo, string $courierId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, label, acquirer FROM pos_devices WHERE courier_id = :id AND active ORDER BY label'
    );
    $stmt->execute(['id' => $courierId]);

    return $stmt->fetchAll();
}

// ── GET ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $custody = pos_custody_open($pdo, $courierId);

    // As máquinas que ele PODE retirar agora: as livres (conferidas no
    // balcão) da loja da corrida de maquininha em andamento. Sem corrida de
    // maquininha, não há por que sair com equipamento.
    $available = [];
    if ($custody === null || $custody['returned_at'] !== null) {
        $stmt = $pdo->prepare(
            "SELECT d.id, d.label, d.acquirer, r.name AS restaurant_name
               FROM orders o
               JOIN pos_devices d ON d.restaurant_id = o.restaurant_id AND d.active
               JOIN restaurants r ON r.id = o.restaurant_id
              WHERE o.courier_id = :courier
                AND o.payment_method = 'pos_machine'
                AND o.status IN ('ready','delivering')
                AND NOT EXISTS (SELECT 1 FROM pos_custody c
                                 WHERE c.device_id = d.id AND c.confirmed_by IS NULL)
              ORDER BY d.label"
        );
        $stmt->execute(['courier' => $courierId]);
        $available = $stmt->fetchAll();
    }

    json_response(200, [
        'available_devices' => $available,
        'own_devices' => courier_own_devices($pdo, $courierId),
        'allow_own_pos' => $allowOwn,
        'custody' => $custody,
        'deadline_label' => $custody === null ? null : pos_deadline_label((float) $custody['seconds_left']),
        'sales' => $custody === null ? [] : pos_custody_sales($pdo, $custody),
        'late_alert_minutes' => POS_LATE_ALERT_MINUTES,
        'notice' => 'O dinheiro da maquininha cai direto na conta da loja — você não deve nada por essas vendas. Só o equipamento e os NSUs.',
    ]);
}

require_method('POST');
$body = read_json_body();
$action = (string) ($body['action'] ?? '');
$open = pos_custody_open($pdo, $courierId);

// ── take: retirar a máquina da loja ────────────────────────────────────
if ($action === 'take') {
    $deviceId = (string) ($body['device_id'] ?? '');
    if ($deviceId === '') {
        error_response(422, 'device_required', 'Informe device_id.', fields: ['device_id' => 'obrigatório']);
    }
    // "Atraso [...] bloqueia nova retirada": sair com a segunda máquina antes
    // de devolver a primeira é como equipamento some.
    if ($open !== null && $open['returned_at'] === null) {
        error_response(409, 'already_holding', 'Você já está com a ' . $open['label'] . '. Devolva antes de pegar outra.');
    }

    $deviceStmt = $pdo->prepare(
        'SELECT d.*, r.name AS restaurant_name FROM pos_devices d
           JOIN restaurants r ON r.id = d.restaurant_id
          WHERE d.id = :id AND d.active'
    );
    $deviceStmt->execute(['id' => $deviceId]);
    $device = $deviceStmt->fetch();
    if ($device === false) {
        error_response(404, 'device_not_found', 'Máquina não encontrada.');
    }
    if (!pos_device_free($pdo, $deviceId)) {
        error_response(409, 'device_taken', 'Essa máquina ainda não foi conferida no balcão — a loja confirma a devolução antes de ela sair de novo.');
    }

    // O prazo é política da plataforma, não combinação de balcão.
    $deadline = is_string($policy['pos_return_deadline'] ?? null) ? $policy['pos_return_deadline'] : '06:00:00';

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO pos_custody (device_id, courier_id, due_at)
             VALUES (:device, :courier, now() + :deadline::interval)
             RETURNING *'
        );
        $stmt->execute(['device' => $deviceId, 'courier' => $courierId, 'deadline' => $deadline]);
    } catch (PDOException $e) {
        // `pos_one_holder` (migração 006): a máquina está com UMA pessoa.
        if (str_contains($e->getMessage(), 'pos_one_holder')) {
            error_response(409, 'device_taken', 'Essa máquina já está com outra pessoa.');
        }
        throw $e;
    }
    $custody = $stmt->fetch();

    json_response(201, [
        'custody' => $custody,
        'device' => $device,
        'deadline_label' => pos_deadline_label((float) (strtotime((string) $custody['due_at']) - time())),
    ]);
}

// ── register_own: cadastrar a própria máquina ──────────────────────────
if ($action === 'register_own') {
    if (!$allowOwn) {
        error_response(409, 'own_pos_not_allowed', 'A plataforma não libera maquininha própria na sua praça.');
    }
    $label = trim((string) ($body['label'] ?? ''));
    $acquirer = strtolower(trim((string) ($body['acquirer'] ?? '')));
    if ($label === '' || $acquirer === '') {
        error_response(422, 'invalid_request', 'Informe o apelido e a adquirente da sua máquina.', fields: ['label' => 'obrigatório']);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO pos_devices (courier_id, label, acquirer, serial)
         VALUES (:courier, :label, :acq, :serial)
         ON CONFLICT (courier_id, label) WHERE courier_id IS NOT NULL
           DO UPDATE SET acquirer = EXCLUDED.acquirer, active = true
         RETURNING *'
    );
    $stmt->execute([
        'courier' => $courierId,
        'label' => $label,
        'acq' => $acquirer,
        'serial' => isset($body['serial']) ? trim((string) $body['serial']) : null,
    ]);

    json_response(201, [
        'device' => $stmt->fetch(),
        'notice' => 'Máquina própria cadastrada. O que você cobrar nela vira valor a repassar à loja, como dinheiro.',
    ]);
}

// ── sale: informar NSU e valor ─────────────────────────────────────────
if ($action === 'sale') {
    $orderId = (int) ($body['order_id'] ?? 0);
    $nsu = isset($body['nsu']) ? only_digits((string) $body['nsu']) : '';
    $amount = isset($body['amount']) && is_numeric($body['amount']) ? round((float) $body['amount'], 2) : null;
    if ($orderId <= 0 || $amount === null || $amount <= 0) {
        error_response(422, 'invalid_request', 'Informe order_id e o valor cobrado.', fields: ['amount' => 'obrigatório']);
    }

    $order = fetch_order($pdo, $orderId);
    if ($order === null || $order['courier_id'] !== $courierId) {
        error_response(404, 'order_not_found', 'Pedido não encontrado.');
    }
    if ($order['payment_method'] !== 'pos_machine') {
        error_response(409, 'not_a_machine_order', 'Esse pedido não é de maquininha.');
    }
    // Digitar errado na máquina é o erro que a 9.6 mostra como divergência;
    // barrar aqui o que dá pra barrar é mais barato que abrir ocorrência.
    if (abs($amount - (float) $order['total']) > 0.001) {
        error_response(422, 'amount_mismatch', 'O valor do pedido é R$ ' . number_format((float) $order['total'], 2, ',', '.') . '.', fields: ['amount' => 'diferente do pedido']);
    }

    // Qual máquina passou a venda: a da loja em custódia, ou a própria.
    $ownDevices = courier_own_devices($pdo, $courierId);
    $useOwn = ($body['own'] ?? false) === true || ($open === null || $open['returned_at'] !== null);
    if ($useOwn) {
        if (!$allowOwn || $ownDevices === []) {
            error_response(409, 'no_custody', 'Você não está com nenhuma maquininha agora.');
        }
        $device = ['id' => $ownDevices[0]['id'], 'acquirer' => $ownDevices[0]['acquirer']];
    } else {
        $device = ['id' => $open['device_id'], 'acquirer' => $open['acquirer']];
    }

    // Completar o NSU de uma venda que entrou sem ele é a MESMA venda.
    $existing = $pdo->prepare(
        'SELECT id FROM card_transactions
          WHERE order_id = :order AND device_id = :device AND nsu IS NULL
          ORDER BY id LIMIT 1'
    );
    $existing->execute(['order' => $orderId, 'device' => $device['id']]);
    $pendingId = $existing->fetchColumn();

    $params = [
        'nsu' => $nsu === '' ? null : $nsu,
        'amount' => $amount,
        'brand' => isset($body['brand']) ? trim((string) $body['brand']) : null,
    ];

    $pdo->beginTransaction();
    try {
        if ($pendingId !== false) {
            $stmt = $pdo->prepare(
                'UPDATE card_transactions
                    SET nsu = :nsu, amount_app = :amount, brand = COALESCE(:brand, brand)
                  WHERE id = :id RETURNING *'
            );
            $stmt->execute($params + ['id' => $pendingId]);
            $transaction = $stmt->fetch();
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO card_transactions
                    (order_id, device_id, acquirer, nsu, amount_app, brand, kind, state)
                 VALUES (:order, :device, :acq, :nsu, :amount, :brand, :kind, 'pending')
                 RETURNING *"
            );
            $stmt->execute($params + [
                'order' => $orderId,
                'device' => $device['id'],
                'acq' => $device['acquirer'],
                'kind' => ($order['machine_kind'] ?? 'credit') === 'debit' ? 'debit' : 'credit',
            ]);
            $transaction = $stmt->fetch();

            // Máquina própria: o dinheiro está na conta DELE. Vira dívida com
            // a loja no mesmo livro da espécie, e daí segue a baixa de caixa.
            // Só no INSERT -- completar o NSU depois não cobra duas vezes.
            if ($useOwn) {
                ledger_add(
                    $pdo,
                    'courier_cash',
                    $courierId,
                    $amount,
                    'order',
                    (string) $orderId,
                    $orderId,
                    (string) $claims['sub'],
                    'venda na maquininha própria (valor na conta do entregador)'
                );
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // UNIQUE (acquirer, nsu): digitar o NSU de outra venda é engano.
        if (str_contains($e->getMessage(), 'card_transactions_acquirer_nsu_key')) {
            error_response(409, 'nsu_already_used', 'Esse NSU já foi informado em outra venda. Confira o comprovante.');
        }
        throw $e;
    }

    json_response(201, [
        'transaction' => $transaction,
        'own_device' => $useOwn,
        'notice' => match (true) {
            $useOwn => 'Venda na sua máquina registrada: o valor entra no seu caixa, a repassar à loja como dinheiro.',
            $nsu === '' => 'Venda registrada SEM NSU. Informe o número antes do fim do turno — sem ele o dia da loja não fecha.',
            default => 'Venda registrada. A loja confere com o extrato da adquirente.',
        },
    ]);
}

// ── return: "Devolvi a maquininha" ─────────────────────────────────────
if ($action !== 'return') {
    error_response(422, 'invalid_action', 'Ação inválida: take, register_own, sale ou return.', fields: ['action' => 'inválida']);
}
if ($open === null || $open['returned_at'] !== null) {
    error_response(409, 'no_custody', 'Você não está com nenhuma maquininha agora.');
}

$stmt = $pdo->prepare(
    'UPDATE pos_custody SET returned_at = now()
      WHERE id = :id AND returned_at IS NULL RETURNING *'
);
$stmt->execute(['id' => $open['id']]);
$custody = $stmt->fetch();

$pending = $pdo->prepare(
    'SELECT count(*) FROM card_transactions
      WHERE device_id = :device AND created_at >= :since AND nsu IS NULL'
);
$pending->execute(['device' => $open['device_id'], 'since' => $open['taken_at']]);
$missing = (int) $pending->fetchColumn();

json_response(200, [
    'custody' => $custody,
    'missing_nsu' => $missing,
    // A devolução não fecha sozinha: a loja confirma.
    'notice' => $missing > 0
        ? "Devolução registrada. Faltam {$missing} NSU(s) — sem eles o dia da loja não fecha."
        : 'Devolução registrada. A loja confirma no painel dela.',
]);
