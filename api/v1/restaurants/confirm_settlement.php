<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 9.3 — "Esta é a resposta a 'como eu sei que o motoboy entregou': a loja
// conta, digita o código do app dele e confirma."
//
// "Os dois lançamentos nascem na mesma transação, com o nome do atendente.
// Valor diferente do declarado abre ocorrência, nunca edição de saldo."
//
// Os dois lançamentos:
//   courier_cash     -= valor  (sai das mãos do entregador)
//   store_receivable += valor  (a loja recebeu a parte dela, que devíamos
//                               desde a entrega -- lib/ledger/order_ledger.php)
//
// "O dinheiro do pedido em espécie é seu — o entregador é apenas portador."
// Na entrega, a plataforma passa a dever à loja a parte dela (−G); quando o
// entregador entrega o bruto (T) no balcão, essa dívida é paga (+T), e o
// que sobra (T − G) é exatamente comissão + frete: o que a loja nos devolve
// no acerto semanal (tela 9.7). A primeira versão deste arquivo lançava −T
// aqui e nada na entrega -- o livro da loja ficava negativo pra sempre, e a
// coluna "a cobrar" da 9.7 não tinha de onde sair.
//
// Divergência entre o contado e o declarado marca a intenção como 'disputed'
// e NÃO lança nada -- porque o lançamento errado não tem desfazimento
// (ledger_entries é append-only), e "abrir ocorrência" é justamente não
// registrar um número que ninguém sabe se é o certo.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a equipe da loja confirma recebimento.');
}
$restaurantId = $claims['restaurant_id'] ?? null;
if ($restaurantId === null) {
    error_response(403, 'forbidden', 'Esse login não está vinculado a uma loja.');
}

$code = is_string($body['code'] ?? null) || is_int($body['code'] ?? null) ? only_digits((string) $body['code']) : '';
// O que a loja contou na mão: número de R$ 0 a R$ 100.000 ("x" virava 0 e
// 1e30 estourava a coluna).
$counted = money_input($body['counted_amount'] ?? null, 0, 100000);

if (strlen($code) !== 6 || $counted === null) {
    error_response(422, 'invalid_request', 'Informe o código de 6 dígitos e o valor contado.', fields: ['code' => 'obrigatório', 'counted_amount' => 'obrigatório']);
}

$pdo = db();

$pdo->beginTransaction();
try {
    // FOR UPDATE pelo mesmo motivo do comprovante de Pix (tela 7.3): dois
    // atendentes confirmando ao mesmo tempo não podem lançar duas vezes.
    $stmt = $pdo->prepare(
        "SELECT * FROM cash_settlement_intents
         WHERE restaurant_id = :restaurant_id AND code_hash = :code_hash AND state = 'open'
         FOR UPDATE"
    );
    $stmt->execute(['restaurant_id' => $restaurantId, 'code_hash' => hash('sha256', $code)]);
    $intent = $stmt->fetch();

    if ($intent === false) {
        $pdo->rollBack();
        error_response(404, 'intent_not_found', 'Código não confere com nenhuma baixa aberta desta loja.');
    }
    if (strtotime((string) $intent['expires_at']) < time()) {
        $pdo->prepare("UPDATE cash_settlement_intents SET state = 'expired' WHERE id = :id")
            ->execute(['id' => $intent['id']]);
        $pdo->commit();
        error_response(410, 'intent_expired', 'Esse código expirou. Peça um novo no app do entregador.');
    }

    $declared = (float) $intent['amount'];
    $matches = abs($declared - $counted) < 0.001;

    $pdo->prepare(
        'UPDATE cash_settlement_intents
            SET state = :state, counted_amount = :counted, confirmed_by = :by, confirmed_at = now()
          WHERE id = :id'
    )->execute([
        'state' => $matches ? 'settled' : 'disputed',
        'counted' => $counted,
        'by' => $claims['sub'],
        'id' => $intent['id'],
    ]);

    if (!$matches) {
        // Ocorrência precisa existir em algum lugar pra alguém decidir: sem
        // isso, "abre ocorrência" seria só uma palavra na tela. `disputes`
        // já tem o tipo certo desde a migração 008, e o valor gravado é a
        // DIFERENÇA -- é ela que está em disputa, não o total.
        $pdo->prepare(
            "INSERT INTO disputes (order_id, courier_id, restaurant_id, kind, risk, amount)
             VALUES (NULL, :courier_id, :restaurant_id, 'cash_unsettled', :risk, :amount)"
        )->execute([
            'courier_id' => $intent['courier_id'],
            'restaurant_id' => $restaurantId,
            'risk' => abs($declared - $counted) >= 50 ? 'high' : 'medium',
            'amount' => round(abs($declared - $counted), 2),
        ]);
    }

    if ($matches) {
        ledger_cash_settled($pdo, $intent, (string) $claims['sub'], 'na loja');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$refreshed = $pdo->prepare('SELECT * FROM cash_settlement_intents WHERE id = :id');
$refreshed->execute(['id' => $intent['id']]);

json_response(200, [
    'intent' => $refreshed->fetch(),
    'settled' => $matches,
    'courier_cash_balance' => courier_cash_balance($pdo, (string) $intent['courier_id']),
    'message' => $matches
        ? 'Baixa confirmada. O saldo do entregador foi abatido.'
        : 'Valor contado diferente do declarado — ocorrência aberta, nada foi lançado.',
]);
