<?php

declare(strict_types=1);

// Tela 15.1 — a promessa escrita no rodapé da tela:
//
//   "Passados 15 min sem entregador, cancelamos sozinhos e devolvemos o
//    valor integral — inclusive a comida, que a loja recebe por nossa conta."
//
// Esta é a única parte da promessa que o cliente não pode executar: ele fecha
// o app e ela tem que acontecer mesmo assim. Por isso é um processo de varredura,
// não um botão.
//
// POR QUE NÃO É UM JOB DO pg_cron, como o timeout do Pix (migração 009):
// porque aqui não é só mudar status -- é decidir dinheiro. Quem decide por
// onde o estorno volta, quanto volta e de que bolso sai é lib/refunds.php, em
// PHP, com a rota de cada forma de pagamento escrita num lugar só. Reescrever
// essa tabela em PL/pgSQL criaria uma segunda fonte de verdade sobre o
// dinheiro, e as duas iam divergir no primeiro ajuste. O expire_pending_
// verifications() pode viver no banco justamente porque lá nada foi cobrado.
//
// COMO RODAR (uma linha no cron do cPanel, a cada minuto):
//
//   * * * * * /usr/local/bin/php /home/USUARIO/app/bin/auto_cancel_no_courier.php >> /home/USUARIO/logs/no_courier.log 2>&1
//
// Rodar duas instâncias ao mesmo tempo é seguro, mas não pelo motivo óbvio:
// o SELECT dos candidatos não trava nada (em autocommit o lock morreria junto
// com a consulta). Quem garante é o SELECT ... FOR UPDATE de novo dentro da
// transação de cada pedido: a segunda varredura espera a primeira, relê o
// status já 'cancelled' e segue adiante.

// bootstrap.php foi escrito para requisição HTTP e lê REQUEST_METHOD. No CLI
// não existe requisição; declarar isto evita o warning e deixa claro que
// nenhuma rota está sendo servida aqui.
$_SERVER['REQUEST_METHOD'] = 'CLI';

require_once __DIR__ . '/../lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// bootstrap instala um handler que responde 500 em JSON -- inútil aqui.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, sprintf("[%s] %s: %s\n", date('c'), get_class($e), $e->getMessage()));
    exit(1);
});

$pdo = db();

// Candidatos: pronto, sem entregador, sem ser retirada, com o relógio
// correndo. O prazo NÃO entra neste WHERE porque cada pedido carrega a
// política congelada no seu próprio checkout -- mudar o prazo hoje não pode
// encurtar o de um pedido feito ontem (mesma regra da taxa de cancelamento).
$candidates = $pdo->query(
    "SELECT * FROM orders
      WHERE status = 'ready'
        AND courier_id IS NULL
        AND pickup_by_customer = false
        AND no_courier_since IS NOT NULL
      ORDER BY no_courier_since"
)->fetchAll();

$cancelled = 0;

foreach ($candidates as $order) {
    $policy = policy_for_order($pdo, $order);
    $timeout = (int) ($policy['no_courier_timeout_seconds'] ?? 900);
    $waiting = time() - strtotime((string) $order['no_courier_since']);
    if ($waiting < $timeout) {
        continue;
    }

    $orderId = (int) $order['id'];
    $reason = sprintf('Nenhum entregador aceitou em %d min', (int) round($timeout / 60));

    // 'no_courier' é a causa que zera a taxa e manda a conta pra plataforma
    // (refund_payer, lib/refunds.php): a comida já feita é paga por nós, não
    // pela loja. É o que o rodapé da tela promete, palavra por palavra.
    $plan = refund_plan($order, $policy, 'no_courier');

    $pdo->beginTransaction();
    try {
        // Reconfere DENTRO da transação: entre o SELECT e aqui um entregador
        // pode ter aceitado. advance_order já trava a linha, mas quem pagou o
        // preço de descobrir tarde demais seria o cliente, com um pedido
        // cancelado depois de alguém estar a caminho.
        $fresh = $pdo->prepare(
            "SELECT status, courier_id, pickup_by_customer FROM orders WHERE id = :id FOR UPDATE"
        );
        $fresh->execute(['id' => $orderId]);
        $now = $fresh->fetch();
        if (
            $now === false
            || $now['status'] !== 'ready'
            || $now['courier_id'] !== null
            || $now['pickup_by_customer'] === true
        ) {
            $pdo->rollBack();
            continue;
        }

        $pdo->prepare('UPDATE orders SET cancel_reason = :r, no_courier_since = NULL WHERE id = :id')
            ->execute(['r' => $reason, 'id' => $orderId]);

        call_advance_order($pdo, $orderId, 'cancelled', null, 'system', [
            'reason' => $reason,
            'cause' => 'no_courier',
            'waited_seconds' => $waiting,
        ]);

        // A corrida deixa de existir junto: oferta aberta de pedido cancelado
        // é entregador indo buscar comida que ninguém vai pagar.
        $pdo->prepare("UPDATE offers SET state = 'expired' WHERE order_id = :id AND state = 'open'")
            ->execute(['id' => $orderId]);

        $refund = record_refund($pdo, $order, $plan, null);

        $pdo->commit();
        $cancelled++;

        printf(
            "[%s] pedido #%s cancelado apos %d min sem entregador; estorno %s (%s, pago por %s)\n",
            date('c'),
            $order['public_code'],
            (int) round($waiting / 60),
            $refund === null ? 'nenhum' : number_format((float) $refund['amount'], 2, ',', '.'),
            $plan['channel'],
            $plan['payer']
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, sprintf(
            "[%s] falha ao cancelar pedido #%s: %s\n",
            date('c'),
            $order['public_code'],
            $e->getMessage()
        ));
    }
}

// Silêncio quando não há nada a fazer: rodando a cada minuto, um log por
// varredura vazia seria 1.440 linhas por dia escondendo as que importam.
if ($cancelled > 0) {
    printf("[%s] %d pedido(s) cancelado(s) por falta de entregador\n", date('c'), $cancelled);
}
