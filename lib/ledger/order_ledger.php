<?php
declare(strict_types=1);

// O lançamento que faltava no livro: o acerto do PEDIDO com a loja.
//
// Até aqui `store_receivable` só se mexia em cupom, estorno, ocorrência e
// baixa de espécie -- nenhum pedido entregue dizia quanto a loja tinha a
// receber ou a pagar. A tela 9.7 ("a cobrar") somava um livro sem a linha
// principal. Este arquivo é essa linha.
//
// O modelo, numa frase só: **na entrega, a plataforma passa a dever à loja a
// parte dela; quem entregar o dinheiro à loja abate essa dívida.**
//
//   parte da loja (G) = subtotal − comissão
//   total cobrado (T) = subtotal + frete + turbo + gorjeta − desconto
//
// Quem ficou com o dinheiro do cliente decide o resto:
//
//   cartão / Pix automático → está na conta do Mercado Pago da plataforma.
//        store_receivable −G   (devemos G à loja; vai no repasse semanal)
//
//   Pix manual / maquininha da loja → caiu direto na conta da LOJA.
//        store_receivable −G +T = +(T − G)
//        (ela já recebeu tudo; nos deve comissão, frete, turbo e gorjeta)
//
//   dinheiro / maquininha do entregador → está com o entregador.
//        store_receivable −G agora; a baixa na loja (tela 9.3) lança +T.
//        "O dinheiro do pedido em espécie é seu — o entregador é apenas
//         portador." (tela 9.3)
//
// E, em todo pedido, o entregador recebe o que é dele: o frete já era
// lançado em couriers/deliver.php; a GORJETA passa a ser (courier_payable).
//
// Convenção de sinal (a mesma do resto do livro): `store_receivable`
// positivo é a loja nos devendo; negativo, nós devendo à loja.

// Formas em que o dinheiro caiu direto na conta da loja.
const ORDER_MONEY_AT_STORE = ['pix_manual', 'pos_machine'];

/**
 * A maquininha usada neste pedido era do entregador (e não da loja)?
 * Nesse caso o dinheiro está com ele, como espécie -- não na conta da loja.
 */
function order_paid_on_courier_device(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM card_transactions t
           JOIN pos_devices d ON d.id = t.device_id
          WHERE t.order_id = :id AND d.courier_id IS NOT NULL
          LIMIT 1'
    );
    $stmt->execute(['id' => $orderId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Lança o acerto do pedido entregue. Idempotente pela origem
 * ('order', 'delivered:<id>'): chamar duas vezes não lança duas vezes.
 *
 * Chamado na MESMA transação que avança o pedido pra 'delivered' -- em
 * `couriers/deliver.php` (entrega) e em `orders/status.php` (retirada no
 * balcão).
 */
function ledger_order_delivered(PDO $pdo, array $order, ?string $actorId): void
{
    $orderId = (int) $order['id'];
    $originId = 'delivered:' . $orderId;

    $done = $pdo->prepare(
        "SELECT 1 FROM ledger_entries WHERE origin = 'order' AND origin_id = :oid LIMIT 1"
    );
    $done->execute(['oid' => $originId]);
    if ($done->fetchColumn() !== false) {
        return;
    }

    // Pontos de fidelidade nascem na entrega, junto do acerto (2.3) --
    // lib/account/loyalty.php. Idempotente como o resto desta função.
    loyalty_earn_for_order($pdo, $order);

    $total = (float) $order['total'];
    $storeShare = round((float) $order['subtotal'] - (float) $order['commission'], 2);
    $method = (string) $order['payment_method'];

    $atStore = in_array($method, ORDER_MONEY_AT_STORE, true)
        && !($method === 'pos_machine' && order_paid_on_courier_device($pdo, $orderId));

    $amount = $atStore ? round($total - $storeShare, 2) : -$storeShare;
    $memo = match (true) {
        $atStore => 'pedido pago direto à loja: comissão, frete e extras a nos devolver',
        in_array($method, ['cash', 'pos_machine'], true) => 'parte da loja em mãos do entregador, até a baixa',
        default => 'parte da loja recebida pela plataforma, a repassar',
    };

    if (abs($amount) >= 0.005) {
        ledger_add(
            $pdo,
            'store_receivable',
            (string) $order['restaurant_id'],
            $amount,
            'order',
            $originId,
            $orderId,
            $actorId,
            $memo
        );
    }

    // O bônus da oferta é do entregador: o turbo que o cliente pagou (tela
    // 15.1, já dentro do total) mais o surge que a plataforma pôs nas rodadas
    // do despacho. A parte que o cliente não pagou é despesa nossa.
    $offerStmt = $pdo->prepare(
        "SELECT bonus FROM offers WHERE order_id = :id AND state = 'accepted' ORDER BY id DESC LIMIT 1"
    );
    $offerStmt->execute(['id' => $orderId]);
    $bonus = (float) ($offerStmt->fetchColumn() ?: 0);
    if ($bonus > 0 && $order['courier_id'] !== null) {
        ledger_add($pdo, 'courier_payable', (string) $order['courier_id'], $bonus, 'order', 'bonus:' . $orderId, $orderId, $actorId, 'bônus da corrida (turbo do cliente + surge do despacho)');
        $platformSurge = round($bonus - (float) ($order['surge_fee'] ?? 0), 2);
        if ($platformSurge > 0) {
            ledger_add($pdo, 'platform_expense', (string) $order['restaurant_id'], $platformSurge, 'order', 'surge:' . $orderId, $orderId, $actorId, 'surge do despacho pago pela plataforma');
        }
    }

    // A gorjeta é do entregador (tela 5.5). Até aqui ela entrava no total do
    // pedido e não saía pra ninguém.
    $tip = (float) ($order['tip'] ?? 0);
    if ($tip > 0 && $order['courier_id'] !== null) {
        ledger_add(
            $pdo,
            'courier_payable',
            (string) $order['courier_id'],
            $tip,
            'order',
            'tip:' . $orderId,
            $orderId,
            $actorId,
            'gorjeta do cliente'
        );
    }
}

/**
 * O pedido chegou a ser entregue? Decide como um estorno pesa pra loja:
 * depois da entrega, ela já teve a parte dela lançada; antes, não teve nada.
 */
function order_was_delivered(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM order_events WHERE order_id = :id AND to_status = 'delivered' LIMIT 1"
    );
    $stmt->execute(['id' => $orderId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Baixa de espécie aceita pela loja (tela 9.3 no balcão, 9.5 por Pix): o
 * dinheiro sai da mão do entregador e paga a parte da loja que devíamos desde
 * a entrega. Os dois lançamentos nascem juntos -- quem chama já está numa
 * transação e já trancou a intenção (FOR UPDATE).
 */
function ledger_cash_settled(PDO $pdo, array $intent, string $actorId, string $how): void
{
    $amount = (float) $intent['amount'];
    ledger_add($pdo, 'courier_cash', (string) $intent['courier_id'], -$amount, 'cash_settlement',
        (string) $intent['id'], null, $actorId, "baixa de espécie {$how}");
    ledger_add($pdo, 'store_receivable', (string) $intent['restaurant_id'], $amount, 'cash_settlement',
        (string) $intent['id'], null, $actorId, "recebimento de espécie do entregador {$how}");
}

