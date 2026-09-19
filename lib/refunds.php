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
        'INSERT INTO refunds (order_id, payment_id, refund_key, amount, fee, channel, payer, cause, decided_by)
         VALUES (:order_id, :payment_id, :refund_key, :amount, :fee, :channel, :payer, :cause, :decided_by)
         RETURNING *'
    );
    $insert->execute([
        'order_id' => $order['id'],
        'payment_id' => $paymentId === false ? null : $paymentId,
        'refund_key' => uuid_v4(),
        'amount' => $plan['amount'],
        // A taxa fica gravada ao lado do estorno porque a tela 13.4 deixa o
        // admin mexer nela depois ("Perdoar / Metade / Manter"), e sem o
        // valor original não dá pra recalcular nem pra explicar a mudança.
        'fee' => $plan['fee'],
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

// --------------------------------------------------------------------------
// Tela 13.4 — o console do admin. Tudo daqui pra baixo é a DECISÃO sobre um
// reembolso que já existe em 'pending', não a criação dele.
// --------------------------------------------------------------------------

// "AJUSTE DA TAXA — Perdoar · Metade · Manter". Três botões, três fatores.
const REFUND_FEE_ADJUSTMENTS = [
    'forgive' => ['label' => 'Perdoar', 'factor' => 0.0],
    'half' => ['label' => 'Metade', 'factor' => 0.5],
    'keep' => ['label' => 'Manter', 'factor' => 1.0],
];

// "Quem paga a conta, por causa" — a lista da tela 13.4, pra o admin ler a
// regra ao lado da decisão em vez de lembrar dela.
const REFUND_CAUSE_RULES = [
    'store_reject' => 'Loja recusou depois de aceitar ou errou o pedido → reembolso sai do repasse dela; a plataforma compensa o entregador.',
    'wrong_item' => 'Loja recusou depois de aceitar ou errou o pedido → reembolso sai do repasse dela; a plataforma compensa o entregador.',
    'customer_cancel' => 'Cliente cancelou antes do preparo → devolução integral, ninguém perde. Depois do preparo → taxa fica com a loja, resto volta.',
    'platform_failure' => 'Falha nossa (dispatch sem entregador, bug, fora do ar) → FUUDelivery paga tudo, inclusive a comida produzida.',
    'no_courier' => 'Falha nossa (dispatch sem entregador, bug, fora do ar) → FUUDelivery paga tudo, inclusive a comida produzida.',
    'fraud' => 'Fraude confirmada do cliente → sem reembolso, conta bloqueada, loja ressarcida pela plataforma.',
    'not_delivered' => 'Não entregue: o entregador recebe a corrida integral de qualquer forma; quem paga a comida depende da ocorrência.',
];

const REFUND_PAYER_LABELS = [
    'store' => 'LOJA',
    'platform' => 'FUUDELIVERY',
    'shared' => 'DIVIDIDO',
];

// A coluna "COMO DEVOLVER / PRAZO" da fila, por canal já gravado. REFUND_ROUTES
// lá em cima responde a mesma pergunta pela forma de PAGAMENTO, antes de
// existir reembolso; esta responde depois, pelo canal que ficou na linha.
const REFUND_CHANNEL_LABELS = [
    'gateway' => ['how' => 'Estorno automático na API', 'eta' => 'até 2 faturas'],
    'pix_return' => ['how' => 'Pix de volta para a chave do pagador', 'eta' => '1 dia útil'],
    'acquirer_void' => ['how' => 'Cancelamento na adquirente da loja', 'eta' => 'D+1'],
    'wallet_credit' => ['how' => 'Saldo no app · cliente aceitou no lugar do estorno', 'eta' => 'imediato'],
    'none' => ['how' => 'Nada cobrado · só compensar o entregador', 'eta' => 'imediato'],
];

/**
 * Recalcula o estorno quando o admin mexe na taxa.
 *
 * O total pago não muda; o que muda é quanto da taxa a loja fica. "A loja
 * anunciou 25 min e estava com 41 — perdoar a taxa é o padrão nesse caso, e
 * o custo fica com ela": perdoar aumenta o estorno, e o aumento sai do
 * repasse da loja -- é o `payer` da linha que carrega isso.
 */
function refund_with_fee(array $refund, string $adjustment): array
{
    $factor = REFUND_FEE_ADJUSTMENTS[$adjustment]['factor'] ?? 1.0;
    $paid = round((float) $refund['amount'] + (float) $refund['fee'], 2);
    $fee = round((float) $refund['fee'] * $factor, 2);

    return [
        'fee' => $fee,
        'amount' => round($paid - $fee, 2),
        'paid' => $paid,
    ];
}

/**
 * Lança no livro o custo de um reembolso decidido.
 *
 * Este é o "+ ledger_entries" do chip da tela. Até aqui, `refunds.payer` era
 * um rótulo: a linha existia, o dinheiro não andava. As contas são as mesmas
 * do cupom (lib/coupons.php), pelo mesmo motivo -- é o mesmo tipo de custo:
 * dinheiro que ia pra loja e voltou pro cliente.
 *
 * `origin_id` é o refund_key, e é isso que faz "idempotente por refund_key"
 * ser verdade no livro, não só na tabela de reembolsos.
 */
function refund_ledger(PDO $pdo, array $order, array $refund, float $amount, ?string $actorId): void
{
    if ($amount <= 0) {
        return;
    }

    $restaurantId = (string) $order['restaurant_id'];
    $orderId = (int) $order['id'];
    $originId = (string) $refund['refund_key'];

    $storeShare = match ((string) $refund['payer']) {
        'store' => $amount,
        'platform' => 0.0,
        'shared' => round($amount / 2, 2),
        default => 0.0,
    };
    $platformShare = round($amount - $storeShare, 2);

    if ($storeShare > 0) {
        ledger_add(
            $pdo,
            'store_receivable',
            $restaurantId,
            $storeShare,
            'refund',
            $originId,
            $orderId,
            $actorId,
            "estorno do pedido {$order['public_code']} debitado do repasse da loja"
        );
    }
    if ($platformShare > 0) {
        ledger_add(
            $pdo,
            'platform_expense',
            $restaurantId,
            $platformShare,
            'refund',
            $originId,
            $orderId,
            $actorId,
            "estorno do pedido {$order['public_code']} bancado pela plataforma"
        );
    }
}

/**
 * Já existe lançamento no livro pra esta chave? A trava de idempotência que
 * a tela promete: dois cliques no mesmo botão, um lançamento só.
 */
function refund_already_booked(PDO $pdo, string $refundKey): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM ledger_entries WHERE origin = 'refund' AND origin_id = :key LIMIT 1"
    );
    $stmt->execute(['key' => $refundKey]);

    return $stmt->fetchColumn() !== false;
}
