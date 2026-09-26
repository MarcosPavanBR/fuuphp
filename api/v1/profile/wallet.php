<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 13.4, o lado do cliente: "crédito em carteira é oferta, nunca
// imposição".
//
// O admin propõe; quem decide é quem levou o prejuízo. Aceitar vira saldo
// (e só então o custo entra no livro); recusar devolve o estorno ao caminho
// normal, e ele volta pra fila do admin como se a oferta nunca tivesse
// existido.

$claims = require_auth();
$pdo = db();
$userId = (string) $claims['sub'];

// Crédito vencido que ainda aparece como saldo é pior que um cron a menos.
wallet_expire_stale($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $history = $pdo->prepare(
        "SELECT w.id, w.amount, w.bonus, w.state, w.expires_at, w.created_at, o.public_code
           FROM wallet_credits w
           LEFT JOIN orders o ON o.id = w.order_id
          WHERE w.user_id = :id AND w.state <> 'offered'
          ORDER BY w.created_at DESC LIMIT 20"
    );
    $history->execute(['id' => $userId]);

    json_response(200, [
        'balance' => wallet_balance($pdo, $userId),
        'offers' => wallet_offers($pdo, $userId),
        'history' => $history->fetchAll(),
        'credit_days' => WALLET_CREDIT_DAYS,
    ]);
}

require_method('POST');
$body = read_json_body();

$creditId = positive_id($body['credit_id'] ?? null) ?? 0;
$decision = input_str($body, 'decision');
if ($creditId <= 0 || !in_array($decision, ['accept', 'decline'], true)) {
    error_response(422, 'invalid_request', 'Informe credit_id e decision (accept ou decline).');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM wallet_credits WHERE id = :id AND user_id = :user FOR UPDATE');
    $stmt->execute(['id' => $creditId, 'user' => $userId]);
    $credit = $stmt->fetch();
    if ($credit === false) {
        $pdo->rollBack();
        error_response(404, 'credit_not_found', 'Oferta não encontrada.');
    }
    if ((string) $credit['state'] !== 'offered') {
        $pdo->rollBack();
        error_response(409, 'already_decided', 'Essa oferta já foi respondida.');
    }

    if ($decision === 'decline') {
        $pdo->prepare("UPDATE wallet_credits SET state = 'declined' WHERE id = :id")
            ->execute(['id' => $creditId]);

        // O estorno volta ao canal do método de pagamento e à fila do admin.
        // Recusar não pode custar o reembolso: é o "nunca imposto" valendo
        // nos dois sentidos.
        $restored = null;
        if ($credit['refund_id'] !== null) {
            $orderStmt = $pdo->prepare(
                'SELECT o.payment_method FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.id = :id'
            );
            $orderStmt->execute(['id' => $credit['refund_id']]);
            $method = (string) $orderStmt->fetchColumn();
            $restored = REFUND_ROUTES[$method]['channel'] ?? 'none';

            $pdo->prepare(
                "UPDATE refunds SET channel = :channel WHERE id = :id AND state = 'pending'"
            )->execute(['channel' => $restored, 'id' => $credit['refund_id']]);
        }

        $pdo->commit();

        json_response(200, [
            'balance' => wallet_balance($pdo, $userId),
            'restored_channel' => $restored,
            'notice' => 'Oferta recusada. O estorno volta pelo mesmo caminho do pagamento.',
        ]);
    }

    $pdo->prepare("UPDATE wallet_credits SET state = 'accepted' WHERE id = :id")
        ->execute(['id' => $creditId]);

    // Aceitou: agora sim o custo é real e entra no livro. O estorno, pela
    // conta de quem paga (igual a qualquer reembolso); o bônus, sempre nossa
    // despesa -- ninguém mais concordou em pagar o "+ R$ 10".
    if ($credit['refund_id'] !== null) {
        $refundStmt = $pdo->prepare('SELECT * FROM refunds WHERE id = :id FOR UPDATE');
        $refundStmt->execute(['id' => $credit['refund_id']]);
        $refund = $refundStmt->fetch();

        if ($refund !== false && !refund_already_booked($pdo, (string) $refund['refund_key'])) {
            $order = fetch_order($pdo, (int) $refund['order_id']);
            refund_ledger($pdo, $order, $refund, (float) $credit['amount'], $userId);

            if ((float) $credit['bonus'] > 0) {
                ledger_add(
                    $pdo,
                    'platform_expense',
                    (string) $order['restaurant_id'],
                    (float) $credit['bonus'],
                    'refund',
                    $refund['refund_key'] . ':bonus',
                    (int) $order['id'],
                    $userId,
                    'bônus do crédito em carteira aceito no lugar do estorno'
                );
            }
        }

        // O reembolso fecha como 'done': o cliente já tem o valor, em saldo.
        $pdo->prepare(
            "UPDATE refunds SET state = 'done', channel = 'wallet_credit', decided_at = now()
              WHERE id = :id AND state = 'pending'"
        )->execute(['id' => $credit['refund_id']]);

        // E a cobrança original volta a valer. `record_refund` marca o
        // pagamento como 'refunded' quando o reembolso é CRIADO -- é a
        // promessa feita ao cliente no cancelamento. Aceitar crédito é
        // desfazer essa promessa por outra: o dinheiro não volta pro cartão,
        // vira saldo. Deixar 'refunded' aqui diria que a fatura mudou, e ela
        // não muda -- é exatamente por isso que crédito custa menos que
        // estorno.
        $pdo->prepare(
            "UPDATE payments SET status = 'approved'
              WHERE order_id = :order AND status = 'refunded'"
        )->execute(['order' => $credit['order_id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$total = round((float) $credit['amount'] + (float) $credit['bonus'], 2);

json_response(200, [
    'balance' => wallet_balance($pdo, $userId),
    'credit' => array_merge($credit, ['state' => 'accepted']),
    'notice' => sprintf(
        'R$ %s de saldo na sua carteira. Entra sozinho no próximo pedido e vale por %d dias.',
        number_format($total, 2, ',', '.'),
        WALLET_CREDIT_DAYS
    ),
]);
