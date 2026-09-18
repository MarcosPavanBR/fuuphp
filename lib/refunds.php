<?php
declare(strict_types=1);

// Fase 13 — o caminho do erro. Este arquivo é a tabela da tela 13.4 escrita
// em código: por onde o dinheiro volta em cada forma de pagamento, em quanto
// tempo, e quem paga a conta.
//
// "Cada forma de pagamento desfaz de um jeito diferente: cartão estorna, Pix
// precisa de devolução com chave, dinheiro não cobra nada."
//
// Os canais são os mesmos do CHECK de refunds.channel (migração 005) --
// gateway, pix_return, acquirer_void, wallet_credit, none --, não uma lista
// nova inventada aqui.

const REFUND_ROUTES = [
    'mp_card' => [
        'channel' => 'gateway',
        'how' => 'Estorno automático na API do Mercado Pago',
        'eta' => 'até 2 faturas',
    ],
    'pix_auto' => [
        'channel' => 'pix_return',
        'how' => 'Pix de volta para a chave do pagador',
        'eta' => '1 dia útil',
    ],
    'pix_manual' => [
        'channel' => 'pix_return',
        'how' => 'Pix de volta para a chave do pagador',
        'eta' => '1 dia útil',
    ],
    'pos_machine' => [
        'channel' => 'acquirer_void',
        'how' => 'Cancelamento na adquirente da loja',
        'eta' => 'D+1',
    ],
    'cash' => [
        'channel' => 'none',
        'how' => 'Nada foi cobrado — não há o que estornar',
        'eta' => 'imediato',
    ],
];

// A taxa só existe depois que a cozinha começou: antes disso nada foi
// consumido. Ordem dos status em ordering (migração 004).
const STATUSES_BEFORE_KITCHEN = ['pending_payment', 'pending_verification', 'paid'];

/**
 * A política que vale pra este pedido é a congelada no checkout
 * (orders.policy_snapshot), não a de agora: mudar a taxa hoje não pode
 * encarecer o cancelamento de um pedido feito ontem. Pedido antigo, de antes
 * da migração 014, cai na política corrente -- não havia snapshot com taxa.
 */
function policy_for_order(PDO $pdo, array $order): array
{
    $snapshot = json_decode((string) ($order['policy_snapshot'] ?? '{}'), true);
    if (is_array($snapshot) && array_key_exists('cancel_fee', $snapshot)) {
        return $snapshot;
    }

    return resolve_policy($pdo, (string) $order['restaurant_id']);
}

/**
 * Calcula o que acontece se este pedido for desfeito agora: taxa, valor de
 * volta, por onde volta, em quanto tempo e quem arca.
 *
 * Não escreve nada -- é o que a tela 13.1 mostra ANTES de confirmar
 * ("taxa e valor do estorno na mesma tela, nada de descobrir depois") e o
 * que o endpoint de cancelamento usa depois pra gravar a mesma coisa.
 */
function refund_plan(array $order, array $policy, string $cause): array
{
    $method = (string) ($order['payment_method'] ?? '');
    $route = REFUND_ROUTES[$method] ?? [
        'channel' => 'none',
        'how' => 'Forma de pagamento sem rota de estorno definida',
        'eta' => '—',
    ];

    $total = (float) $order['total'];
    $beforeKitchen = in_array((string) $order['status'], STATUSES_BEFORE_KITCHEN, true);

    // Recusa da loja e falha nossa nunca cobram taxa do cliente: a culpa não
    // é dele. Cliente cancelando depois do preparo é o único caso com taxa.
    $fee = ($cause === 'customer_cancel' && !$beforeKitchen)
        ? min((float) ($policy['cancel_fee'] ?? 0), $total)
        : 0.0;

    // Dinheiro não movimentou valor nenhum: não há estorno, só a compensação
    // do entregador (que é lançamento de ledger, Fase 9, fora daqui).
    $amount = $route['channel'] === 'none' ? 0.0 : round($total - $fee, 2);

    return [
        'cause' => $cause,
        'channel' => $route['channel'],
        'how' => $route['how'],
        'eta' => $route['eta'],
        'paid_amount' => $total,
        'fee' => $fee,
        'amount' => $amount,
        'payer' => refund_payer($cause, $route['channel']),
        'free_cancel' => $beforeKitchen,
    ];
}

/**
 * "Quem paga a conta, por causa" (tela 13.4).
 *
 * Decisão de codificação registrada: `payer` diz de qual bolso sai o custo, e
 * o dinheiro do pedido estava a caminho da loja -- por isso o estorno debita
 * o repasse dela em quase todos os casos. "Ninguém perde" no cancelamento
 * antes do preparo é verdade sobre a TAXA (que é zero), não sobre quem move
 * o dinheiro de volta. Quando não há dinheiro pra devolver (espécie), o único
 * custo é compensar o entregador, e esse é nosso.
 */
function refund_payer(string $cause, string $channel): string
{
    if ($cause === 'platform_failure' || $cause === 'no_courier') {
        return 'platform';
    }
    if ($channel === 'none') {
        return 'platform';
    }

    return 'store';
}

/**
 * Grava o reembolso decidido. Idempotente por pedido: se já existe reembolso
 * pra este pedido, devolve o que existe em vez de criar um segundo -- a
 * coluna refund_key é UNIQUE justamente pra isso ser ponta a ponta.
 *
 * Devolve null quando não há o que estornar (espécie, ou taxa igual ao
 * total): refunds.amount tem CHECK (amount > 0), então "estorno de zero" não
 * é uma linha na tabela, é a ausência dela.
 */
function record_refund(PDO $pdo, array $order, array $plan, ?string $decidedBy, bool $partial = false): ?array
{
    $existing = $pdo->prepare('SELECT * FROM refunds WHERE order_id = :id ORDER BY id LIMIT 1');
    $existing->execute(['id' => $order['id']]);
    $found = $existing->fetch();
    if ($found !== false) {
        return $found;
    }

    if ($plan['amount'] <= 0) {
        return null;
    }

    $paymentStmt = $pdo->prepare(
        'SELECT id FROM payments WHERE order_id = :id ORDER BY id DESC LIMIT 1'
    );
    $paymentStmt->execute(['id' => $order['id']]);
    $paymentId = $paymentStmt->fetchColumn();

    $insert = $pdo->prepare(
        'INSERT INTO refunds (order_id, payment_id, refund_key, amount, channel, payer, cause, decided_by)
         VALUES (:order_id, :payment_id, :refund_key, :amount, :channel, :payer, :cause, :decided_by)
         RETURNING *'
    );
    $insert->execute([
        'order_id' => $order['id'],
        'payment_id' => $paymentId === false ? null : $paymentId,
        'refund_key' => uuid_v4(),
        'amount' => $plan['amount'],
        'channel' => $plan['channel'],
        'payer' => $plan['payer'],
        'cause' => $plan['cause'],
        'decided_by' => $decidedBy,
    ]);
    $refund = $insert->fetch();

    // O pagamento só vira 'refunded' quando o dinheiro realmente volta pelo
    // mesmo caminho E por inteiro. Devolução parcial (o frete de um pedido
    // que virou retirada, por exemplo) não desfaz a cobrança: o cliente
    // continua tendo pago a comida. Crédito em carteira também não mexe,
    // porque não desfaz a cobrança original.
    if (!$partial && $paymentId !== false && in_array($plan['channel'], ['gateway', 'pix_return', 'acquirer_void'], true)) {
        $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE id = :id")
            ->execute(['id' => $paymentId]);
    }

    return $refund;
}
