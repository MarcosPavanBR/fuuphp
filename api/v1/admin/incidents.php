<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 13.3, o outro lado: "Passado o prazo, o suporte libera: devolver à
// loja ou descartar."
//
// O entregador registra e fica com a comida na mão; quem decide o destino
// dela é o time. E é aqui que a promessa da tela do entregador vira dinheiro:
// "Você recebe a corrida integral nas duas saídas" -- devolver e descartar
// creditam o frete igual.
//
// O que NÃO se decide aqui é o reembolso do cliente. Fechar ocorrência e
// devolver dinheiro são duas perguntas diferentes, e misturá-las é como se
// perde dias discutindo: a primeira é logística, a segunda é a tela 13.4.
// Quando a ocorrência gera devolução, o reembolso nasce em 'pending' e cai
// na fila de lá -- com quem paga escolhido por gente, porque a lista "quem
// paga a conta, por causa" da 13.4 não cobre cliente ausente.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT i.*,
                o.public_code, o.total, o.payment_method, o.status AS order_status,
                o.delivery_fee, o.restaurant_id,
                r.name AS restaurant_name,
                u.full_name AS courier_name,
                EXTRACT(epoch FROM (now() - i.created_at)) / 60 AS minutes_open,
                (SELECT json_agg(json_build_object('kind', a.kind, 'at', a.created_at)
                        ORDER BY a.created_at)
                   FROM delivery_attempts a WHERE a.order_id = i.order_id) AS attempts
           FROM delivery_incidents i
           JOIN orders o ON o.id = i.order_id
           JOIN restaurants r ON r.id = o.restaurant_id
           JOIN couriers c ON c.id = i.courier_id
           JOIN users u ON u.id = c.user_id
          WHERE i.resolution IS NULL
          ORDER BY i.created_at"
    );

    // `json_agg` volta como texto pelo PDO; decodificar aqui é o que faz a
    // tela receber uma lista de tentativas, não uma string com aspas.
    $queue = $stmt->fetchAll();
    foreach ($queue as &$row) {
        $row['attempts'] = $row['attempts'] === null
            ? []
            : json_decode((string) $row['attempts'], true);
    }
    unset($row);

    json_response(200, [
        'queue' => $queue,
        'resolutions' => INCIDENT_RESOLUTIONS,
        'kinds' => INCIDENT_KINDS,
        'wait_minutes' => INCIDENT_WAIT_MINUTES,
    ]);
}

require_method('POST');
$body = read_json_body();

$incidentId = positive_id($body['incident_id'] ?? null) ?? 0;
$resolution = input_str($body, 'resolution');
if ($incidentId <= 0 || !array_key_exists($resolution, INCIDENT_RESOLUTIONS)) {
    error_response(422, 'invalid_request', 'Informe incident_id e resolution (returned, discarded ou delivered).');
}

$wantsRefund = ($body['refund'] ?? false) === true;
$refundPayer = input_str($body, 'refund_payer');
if ($wantsRefund && !in_array($refundPayer, ['store', 'platform', 'shared'], true)) {
    error_response(422, 'payer_required', 'Quem paga o reembolso desta ocorrência? (store, platform ou shared)', fields: ['refund_payer' => 'obrigatório']);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM delivery_incidents WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $incidentId]);
    $incident = $stmt->fetch();
    if ($incident === false) {
        $pdo->rollBack();
        error_response(404, 'incident_not_found', 'Ocorrência não encontrada.');
    }
    if ($incident['resolution'] !== null) {
        $pdo->rollBack();
        error_response(409, 'already_resolved', 'Essa ocorrência já foi resolvida.');
    }

    $order = fetch_order($pdo, (int) $incident['order_id']);
    if ($order === null) {
        $pdo->rollBack();
        error_response(404, 'order_not_found', 'Pedido não encontrado.');
    }

    $pdo->prepare('UPDATE delivery_incidents SET resolution = :res WHERE id = :id')
        ->execute(['res' => $resolution, 'id' => $incidentId]);

    $pdo->prepare(
        "UPDATE disputes SET state = 'resolved', resolution = :res, decided_by = :by
          WHERE order_id = :order AND kind = 'not_delivered' AND state = 'open'"
    )->execute(['res' => $resolution, 'by' => $adminId, 'order' => $order['id']]);

    $refund = null;
    $compensation = 0.0;

    if ($resolution === 'delivered') {
        // O cliente apareceu. O pedido segue em 'delivering' e a entrega
        // termina pelo caminho normal (couriers/deliver.php), que já credita
        // o frete -- creditar aqui também pagaria a corrida duas vezes.
        $pdo->commit();

        json_response(200, [
            'incident' => array_merge($incident, ['resolution' => $resolution]),
            'order' => fetch_order($pdo, (int) $order['id']),
            'notice' => 'Liberado pra entregar. O frete entra no livro quando a entrega for confirmada, como em qualquer corrida.',
        ]);
    }

    // Devolvida ou descartada: o pedido acabou sem entrega.
    call_advance_order($pdo, (int) $order['id'], 'cancelled', $adminId, 'admin', [
        'reason' => 'delivery_incident',
        'incident_kind' => $incident['kind'],
        'resolution' => $resolution,
    ]);
    $pdo->prepare('UPDATE orders SET cancel_reason = :reason WHERE id = :id')
        ->execute([
            'reason' => incident_summary($incident) . ' · ' . INCIDENT_RESOLUTIONS[$resolution],
            'id' => $order['id'],
        ]);

    // "Você recebe a corrida integral nas duas saídas." A frase da tela do
    // entregador, cumprida: o frete entra no que a plataforma deve a ele,
    // origem 'compensation' -- não é corrida entregue, é corrida garantida.
    $compensation = (float) $order['delivery_fee'];
    if ($compensation > 0) {
        ledger_add(
            $pdo,
            'courier_payable',
            (string) $incident['courier_id'],
            $compensation,
            'compensation',
            'incident:' . $incidentId,
            (int) $order['id'],
            $adminId,
            'corrida garantida em ocorrência (' . INCIDENT_RESOLUTIONS[$resolution] . ')'
        );
    }

    if ($wantsRefund) {
        $policy = policy_for_order($pdo, $order);
        $plan = refund_plan($order, $policy, 'not_delivered');
        // Cancelar já levou o pedido pra 'cancelled': a taxa de cancelamento
        // não se aplica a quem não recebeu comida nenhuma.
        $plan['fee'] = 0.0;
        $plan['amount'] = $plan['channel'] === 'none' ? 0.0 : (float) $order['total'];
        $plan['payer'] = $refundPayer;
        $refund = record_refund($pdo, $order, $plan, $adminId);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'incident' => array_merge($incident, ['resolution' => $resolution]),
    'order' => fetch_order($pdo, (int) $order['id']),
    'courier_compensation' => $compensation,
    'refund' => $refund,
    'notice' => $refund === null
        ? 'Ocorrência encerrada e corrida garantida ao entregador. Sem devolução ao cliente.'
        : 'Ocorrência encerrada. O reembolso entrou na fila de decisão (tela de reembolsos).',
]);
