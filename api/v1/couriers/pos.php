<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.6 — "Entregador: devolução da maquininha" — e a alimentação da
// conciliação da 9.6.
//
// "O dinheiro da maquininha cai direto na conta da loja — você não deve nada
// por essas vendas. Só o equipamento e os NSUs." Essa frase é a regra de
// contabilidade deste arquivo inteiro: venda na maquininha NÃO lança
// `courier_cash`. Espécie vira dívida; maquininha vira conferência.
//
// Quatro ações:
//   take    → registra a posse (tela 10.4: "retira registrando a posse no app")
//   sale    → informa NSU e valor da venda que acabou de passar (9.6)
//   return  → "Devolvi a maquininha"; a loja confirma depois (10.4)
//   (GET)   → o equipamento em mãos, o prazo e as vendas do turno

$claims = require_auth();
$courierId = require_courier($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $custody = pos_custody_open($pdo, $courierId);

    // As máquinas que ele PODE retirar agora: as livres da loja da corrida
    // de maquininha em andamento. Sem corrida de maquininha, não há por que
    // sair com equipamento -- e a lista vem vazia de propósito.
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
        'custody' => $custody,
        'deadline_label' => $custody === null
            ? null
            : pos_deadline_label((float) $custody['seconds_left']),
        'sales' => $custody === null ? [] : pos_custody_sales($pdo, $custody),
        'late_alert_minutes' => POS_LATE_ALERT_MINUTES,
        // A frase que a tela 10.6 mostra em corpo de texto, vinda de quem
        // manda nela -- é uma regra de dinheiro, não um lembrete.
        'notice' => 'O dinheiro da maquininha cai direto na conta da loja — você não deve nada por essas vendas. Só o equipamento e os NSUs.',
    ]);
}

require_method('POST');
$body = read_json_body();
$action = (string) ($body['action'] ?? '');
$open = pos_custody_open($pdo, $courierId);

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
    $policy = $pdo->query('SELECT pos_return_deadline FROM platform_policies ORDER BY version DESC LIMIT 1')
        ->fetchColumn();
    $deadline = is_string($policy) && $policy !== '' ? $policy : '06:00:00';

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO pos_custody (device_id, courier_id, due_at)
             VALUES (:device, :courier, now() + :deadline::interval)
             RETURNING *"
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

if ($open === null || ($open['returned_at'] !== null && $action === 'return')) {
    error_response(409, 'no_custody', 'Você não está com nenhuma maquininha agora.');
}

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

    // O valor informado é conferido contra o pedido na hora: digitar errado
    // na máquina é o erro que a tela 9.6 mostra como divergência, e barrar o
    // que dá pra barrar aqui é mais barato que abrir ocorrência depois.
    if (abs($amount - (float) $order['total']) > 0.001) {
        error_response(422, 'amount_mismatch', 'O valor do pedido é R$ ' . number_format((float) $order['total'], 2, ',', '.') . '.', fields: ['amount' => 'diferente do pedido']);
    }

    // Completar o NSU de uma venda que entrou sem ele ("informe para fechar o
    // dia") é a MESMA venda, não uma segunda: atualiza a linha pendente deste
    // pedido nesta máquina em vez de inserir outra.
    $existing = $pdo->prepare(
        'SELECT id FROM card_transactions
          WHERE order_id = :order AND device_id = :device AND nsu IS NULL
          ORDER BY id LIMIT 1'
    );
    $existing->execute(['order' => $orderId, 'device' => $open['device_id']]);
    $pendingId = $existing->fetchColumn();

    $params = [
        'nsu' => $nsu === '' ? null : $nsu,
        'amount' => $amount,
        'brand' => isset($body['brand']) ? trim((string) $body['brand']) : null,
    ];

    try {
        if ($pendingId !== false) {
            $stmt = $pdo->prepare(
                'UPDATE card_transactions
                    SET nsu = :nsu, amount_app = :amount, brand = COALESCE(:brand, brand)
                  WHERE id = :id RETURNING *'
            );
            $stmt->execute($params + ['id' => $pendingId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO card_transactions
                    (order_id, device_id, acquirer, nsu, amount_app, brand, kind, state)
                 VALUES (:order, :device, :acq, :nsu, :amount, :brand, :kind, 'pending')
                 RETURNING *"
            );
            $stmt->execute($params + [
                'order' => $orderId,
                'device' => $open['device_id'],
                'acq' => $open['acquirer'],
                'kind' => ($order['machine_kind'] ?? 'credit') === 'debit' ? 'debit' : 'credit',
            ]);
        }
    } catch (PDOException $e) {
        // UNIQUE (acquirer, nsu): o mesmo NSU não se repete na adquirente.
        // Digitar o NSU de outra venda é erro de digitação, não venda nova.
        if (str_contains($e->getMessage(), 'card_transactions_acquirer_nsu_key')) {
            error_response(409, 'nsu_already_used', 'Esse NSU já foi informado em outra venda. Confira o comprovante.');
        }
        throw $e;
    }

    json_response(201, [
        'transaction' => $stmt->fetch(),
        // "#A38F63 · NSU faltando · informe para fechar o dia": a venda sem
        // NSU entra mesmo assim, porque ela existiu -- e é justamente ela que
        // trava o fechamento até ser completada.
        'notice' => $nsu === ''
            ? 'Venda registrada SEM NSU. Informe o número antes do fim do turno — sem ele o dia da loja não fecha.'
            : 'Venda registrada. A loja confere com o extrato da adquirente.',
    ]);
}

if ($action !== 'return') {
    error_response(422, 'invalid_action', 'Ação inválida: take, sale ou return.', fields: ['action' => 'inválida']);
}

$stmt = $pdo->prepare(
    'UPDATE pos_custody SET returned_at = now()
      WHERE id = :id AND returned_at IS NULL RETURNING *'
);
$stmt->execute(['id' => $open['id']]);
$custody = $stmt->fetch();

$pending = $pdo->prepare(
    "SELECT count(*) FROM card_transactions
      WHERE device_id = :device AND created_at >= :since AND nsu IS NULL"
);
$pending->execute(['device' => $open['device_id'], 'since' => $open['taken_at']]);
$missing = (int) $pending->fetchColumn();

json_response(200, [
    'custody' => $custody,
    'missing_nsu' => $missing,
    // A devolução não fecha sozinha: a loja confirma. Dizer isso evita a
    // pessoa achar que acabou e o equipamento ficar em aberto no painel.
    'notice' => $missing > 0
        ? "Devolução registrada. Faltam {$missing} NSU(s) — sem eles o dia da loja não fecha."
        : 'Devolução registrada. A loja confirma no painel dela.',
]);
