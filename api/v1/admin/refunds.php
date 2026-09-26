<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 13.4 — "Admin: reembolso por método e quem paga".
//
// "A tabela que faltava: cartão estorna na API, Pix precisa de devolução
// para a chave do pagador, dinheiro não devolve nada e maquininha cancela na
// adquirente. A coluna 'quem paga' evita a discussão que trava reembolso por
// dias — e crédito em carteira é oferta, nunca imposição."
//
// Os reembolsos já nasciam em 'pending' (cancelamento, recusa, ocorrência).
// O que não existia era a decisão: ninguém mexia na taxa, ninguém mandava o
// dinheiro embora, e `refunds.payer` era um rótulo que não movia um centavo
// no livro. Este arquivo é essa decisão.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT r.*,
                o.public_code, o.total, o.payment_method, o.status AS order_status,
                o.cancel_reason, o.reject_reason, o.restaurant_id,
                rest.name AS restaurant_name,
                u.id AS user_id, u.full_name AS customer_name,
                (SELECT w.state FROM wallet_credits w
                  WHERE w.refund_id = r.id ORDER BY w.id DESC LIMIT 1) AS wallet_state
           FROM refunds r
           JOIN orders o ON o.id = r.order_id
           JOIN restaurants rest ON rest.id = o.restaurant_id
           JOIN users u ON u.id = o.user_id
          WHERE r.state = 'pending'
          ORDER BY r.created_at"
    );
    $queue = $stmt->fetchAll();

    // "9 na fila · R$ 1.204 em análise" — os dois números do cabeçalho saem
    // da própria fila, não de uma contagem separada que pode divergir dela.
    $inAnalysis = 0.0;
    foreach ($queue as &$row) {
        $inAnalysis += (float) $row['amount'];
        $labels = REFUND_CHANNEL_LABELS[(string) $row['channel']] ?? ['how' => '—', 'eta' => '—'];
        $row['how'] = $labels['how'];
        $row['eta'] = $labels['eta'];
        $row['payer_label'] = REFUND_PAYER_LABELS[(string) $row['payer']] ?? (string) $row['payer'];
        $row['cause_rule'] = REFUND_CAUSE_RULES[(string) $row['cause']] ?? '';
    }
    unset($row);

    // A segunda fila: o que já foi decidido e ainda não chegou. É onde se vê
    // o estorno que o gateway recusou e o Pix que a loja ainda não devolveu
    // -- decidir e esquecer é como reembolso fica parado por semanas.
    $inflight = $pdo->query(
        "SELECT r.*, o.public_code, o.payment_method, rest.name AS restaurant_name
           FROM refunds r
           JOIN orders o ON o.id = r.order_id
           JOIN restaurants rest ON rest.id = o.restaurant_id
          WHERE r.state IN ('sent','failed')
          ORDER BY r.state DESC, r.decided_at"
    )->fetchAll();
    foreach ($inflight as &$row) {
        $row['automatic'] = refund_is_automatic($row);
        $labels = REFUND_CHANNEL_LABELS[(string) $row['channel']] ?? ['how' => '—', 'eta' => '—'];
        $row['how'] = $labels['how'];
    }
    unset($row);

    json_response(200, [
        'inflight' => $inflight,
        'queue' => $queue,
        'count' => count($queue),
        'amount_in_analysis' => round($inAnalysis, 2),
        'fee_adjustments' => REFUND_FEE_ADJUSTMENTS,
        'cause_rules' => REFUND_CAUSE_RULES,
        'wallet_bonus_default' => WALLET_BONUS_DEFAULT,
        'wallet_credit_days' => WALLET_CREDIT_DAYS,
    ]);
}

require_method('POST');
$body = read_json_body();

$refundId = positive_id($body['refund_id'] ?? null) ?? 0;
$action = input_str($body, 'action', 'refund');
$adjustment = input_str($body, 'fee_adjustment', 'keep');
$note = body_text($body, 'note', 500);

if ($refundId <= 0 || !in_array($action, ['refund', 'wallet_offer', 'execute', 'confirm_manual'], true)) {
    error_response(422, 'invalid_request', 'Informe refund_id e action (refund, wallet_offer, execute ou confirm_manual).');
}

// Executar agora: o mesmo caminho do bin/execute_refunds.php, pra quem não
// quer esperar o próximo minuto do cron (ou quer tentar de novo um que
// falhou -- 'failed' volta pra 'sent' e ganha mais uma rodada).
if ($action === 'execute') {
    $pdo->prepare("UPDATE refunds SET state = 'sent', attempts = 0 WHERE id = :id AND state = 'failed'")
        ->execute(['id' => $refundId]);
    $result = refund_execute($pdo, $refundId);
    $row = $pdo->prepare('SELECT * FROM refunds WHERE id = :id');
    $row->execute(['id' => $refundId]);
    $refund = $row->fetch();
    json_response(200, [
        'refund' => $refund,
        'notice' => match ($refund['state'] ?? '') {
            'done' => 'Estorno confirmado pelo gateway.',
            'failed' => 'O gateway recusou de novo: ' . ($refund['last_error'] ?? ''),
            default => $result['skipped']
                ? 'Esse reembolso não é automático — confirme manualmente com a referência.'
                : 'Não deu desta vez; o executor tenta de novo: ' . ($refund['last_error'] ?? ''),
        },
    ]);
}

// Confirmação humana: Pix manual devolvido pela loja, cancelamento na
// adquirente. A referência é obrigatória -- é ela que se apresenta quando o
// cliente disser que não recebeu.
if ($action === 'confirm_manual') {
    $ref = body_text($body, 'provider_ref', 100) ?? '';
    if ($ref === '') {
        error_response(422, 'provider_ref_required', 'Informe o identificador (E2E do Pix, protocolo da adquirente).', fields: ['provider_ref' => 'obrigatório']);
    }
    $refund = refund_confirm_manual($pdo, $refundId, $ref, $adminId);
    if ($refund === []) {
        error_response(409, 'not_in_flight', 'Esse reembolso não está esperando confirmação.');
    }
    json_response(200, ['refund' => $refund, 'notice' => 'Reembolso confirmado com a referência ' . $ref . '.']);
}
if (!array_key_exists($adjustment, REFUND_FEE_ADJUSTMENTS)) {
    error_response(422, 'invalid_adjustment', 'Ajuste da taxa inválido: perdoar, metade ou manter.', fields: ['fee_adjustment' => 'inválido']);
}

// Bônus é crédito dado ao cliente: número de 0 a R$ 1.000 (teto contra
// zero a mais digitado, não regra de negócio; 1e30 dava 500 no banco).
$bonus = isset($body['bonus']) ? money_input($body['bonus'], 0, 1000) : WALLET_BONUS_DEFAULT;
if ($bonus === null) {
    error_response(422, 'invalid_bonus', 'O bônus vai de R$ 0 a R$ 1.000.', fields: ['bonus' => 'inválido']);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $refundId]);
    $refund = $stmt->fetch();
    if ($refund === false) {
        $pdo->rollBack();
        error_response(404, 'refund_not_found', 'Reembolso não encontrado.');
    }
    if ((string) $refund['state'] !== 'pending') {
        $pdo->rollBack();
        error_response(409, 'already_decided', 'Esse reembolso já foi decidido.', detail: 'estado atual: ' . $refund['state']);
    }

    $order = fetch_order($pdo, (int) $refund['order_id']);
    if ($order === null) {
        $pdo->rollBack();
        error_response(404, 'order_not_found', 'Pedido não encontrado.');
    }

    $recalculated = refund_with_fee($refund, $adjustment);

    if ($action === 'wallet_offer') {
        // "Oferecer crédito + R$ 10 [...] nunca pode ser imposto." Então aqui
        // não se paga nada e não se lança nada: cria-se uma OFERTA. O
        // reembolso continua 'pending' de propósito -- se a pessoa recusar,
        // ele volta pra esta fila pelo caminho de sempre.
        $offer = $pdo->prepare(
            "INSERT INTO wallet_credits (user_id, refund_id, order_id, amount, bonus, state, expires_at, decided_by)
             VALUES (:user_id, :refund_id, :order_id, :amount, :bonus, 'offered',
                     now() + make_interval(days => :days), :by)
             RETURNING *"
        );
        try {
            $offer->execute([
                'user_id' => $order['user_id'],
                'refund_id' => $refundId,
                'order_id' => $order['id'],
                'amount' => $recalculated['amount'],
                'bonus' => $bonus,
                'days' => WALLET_CREDIT_DAYS,
                'by' => $adminId,
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'wallet_credits_refund_idx')) {
                $pdo->rollBack();
                error_response(409, 'offer_already_open', 'Já existe uma oferta de crédito em aberto pra este reembolso.');
            }
            throw $e;
        }

        $pdo->prepare(
            "UPDATE refunds SET amount = :amount, fee = :fee, channel = 'wallet_credit', note = :note
              WHERE id = :id"
        )->execute([
            'amount' => $recalculated['amount'],
            'fee' => $recalculated['fee'],
            'note' => $note,
            'id' => $refundId,
        ]);

        $pdo->commit();

        json_response(200, [
            'offer' => $offer->fetch(),
            'refund' => refund_row($pdo, $refundId),
            'notice' => sprintf(
                'Oferta enviada: %s de crédito (%s de estorno + %s de bônus). Só vira saldo se a pessoa aceitar — se recusar, o estorno volta pra esta fila.',
                money_br($recalculated['amount'] + $bonus),
                money_br($recalculated['amount']),
                money_br($bonus)
            ),
        ]);
    }

    // Estorno de verdade: o dinheiro volta pelo canal do pagamento.
    //
    // O canal pode estar como 'wallet_credit' de uma oferta anterior que
    // ninguém respondeu; estornar agora é voltar pro caminho do método.
    $route = REFUND_ROUTES[(string) $order['payment_method']] ?? ['channel' => 'none'];
    $channel = (string) $route['channel'];

    if ($recalculated['amount'] <= 0 || $channel === 'none') {
        // Dinheiro: "nada cobrado · só compensar o entregador". Não há o que
        // devolver, e a linha de reembolso fecha sem mover valor.
        $pdo->prepare(
            "UPDATE refunds SET state = 'done', channel = 'none', amount = amount, fee = :fee,
                                note = :note, decided_by = :by, decided_at = now()
              WHERE id = :id"
        )->execute(['fee' => $recalculated['fee'], 'note' => $note, 'by' => $adminId, 'id' => $refundId]);

        $pdo->commit();

        json_response(200, [
            'refund' => refund_row($pdo, $refundId),
            'notice' => 'Pedido em dinheiro: nada foi cobrado, nada a estornar. A compensação do entregador é lançamento à parte.',
        ]);
    }

    // Cancela qualquer oferta de crédito ainda pendente: decidir pelo estorno
    // é dizer não à oferta.
    $pdo->prepare(
        "UPDATE wallet_credits SET state = 'declined' WHERE refund_id = :id AND state = 'offered'"
    )->execute(['id' => $refundId]);

    $pdo->prepare(
        "UPDATE refunds SET amount = :amount, fee = :fee, channel = :channel, state = 'sent',
                            note = :note, decided_by = :by, decided_at = now()
          WHERE id = :id"
    )->execute([
        'amount' => $recalculated['amount'],
        'fee' => $recalculated['fee'],
        'channel' => $channel,
        'note' => $note,
        'by' => $adminId,
        'id' => $refundId,
    ]);

    $decided = refund_row($pdo, $refundId);

    // "idempotente por refund_key": o livro é a trava. Se já houver
    // lançamento com esta chave, decidir de novo não cria um segundo custo.
    if (!refund_already_booked($pdo, (string) $refund['refund_key'])) {
        refund_ledger($pdo, $order, $decided, (float) $decided['amount'], $adminId);
    }

    // O pagamento só vira 'refunded' quando o dinheiro volta pelo mesmo
    // caminho: crédito em carteira e espécie não desfazem cobrança nenhuma.
    if ($refund['payment_id'] !== null) {
        $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE id = :id")
            ->execute(['id' => $refund['payment_id']]);
    }

    // `orders.status = 'refunded'` só é legal vindo de paid, delivered ou
    // cancelled (advance_order, migração 004). Pedido ainda em preparo com
    // estorno decidido existe -- e nesse caso o status continua sendo o que
    // ele é. Quem manda no status é a função do banco, não este arquivo.
    if (in_array((string) $order['status'], ['paid', 'delivered', 'cancelled'], true)) {
        call_advance_order($pdo, (int) $order['id'], 'refunded', $adminId, 'admin', [
            'refund_key' => $refund['refund_key'],
            'amount' => $decided['amount'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$labels = REFUND_CHANNEL_LABELS[$channel] ?? ['how' => '—', 'eta' => '—'];

json_response(200, [
    'refund' => $decided,
    'order' => fetch_order($pdo, (int) $order['id']),
    'how' => $labels['how'],
    'eta' => $labels['eta'],
    // O estorno fica em 'sent', não em 'done': o dinheiro saiu daqui, mas
    // quem confirma que chegou é o gateway. Marcar 'done' na hora seria
    // dizer que a fatura do cliente já mudou -- e ela não mudou.
    'notice' => sprintf('%s · %s. Estado: enviado — o gateway confirma a baixa.', $labels['how'], $labels['eta']),
]);

/** Relê a linha depois do UPDATE: é ela que vai pra tela, não o que o PHP achou que gravou. */
function refund_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM refunds WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->fetch();
}

function money_br(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}
