<?php
declare(strict_types=1);

// Despacho mínimo: quando um pedido fica pronto, vira uma oferta aberta pros
// entregadores da cidade.
//
// A Fase 15 do mock desenha o despacho completo -- rodadas, raio crescente,
// surge, `dispatch_attempts` gravando por que não achou ninguém. Nada disso
// está aqui: esta é UMA rodada, sem raio e sem surge, o suficiente pro app do
// entregador (Fase 8) ter corrida pra aceitar. A tabela `offers` já é a da
// especificação, então a Fase 15 substitui esta função sem migrar nada.

// Quanto tempo a oferta continua valendo no servidor. O mock mostra um timer
// de 15 s, mas aquilo é o tempo de DECISÃO de quem está vendo a oferta na
// tela, não o tempo de vida dela: num app que busca ofertas por polling, 15 s
// de validade significaria oferta sempre vencida. O app conta 15 s e recusa
// sozinho; o servidor segura a oferta por 5 min pro caso de ninguém abrir.
const OFFER_TTL_SECONDS = 300;

/**
 * Cria a oferta do pedido, se ainda não existir uma aberta ou aceita.
 * Idempotente: chamada de novo no mesmo pedido não duplica.
 *
 * A remuneração é `orders.delivery_fee` -- o frete que o cliente pagou é o
 * que o entregador recebe. Bônus fica zero: quem calcula bônus é o despacho
 * da Fase 15, com surge, e inventar um valor aqui seria prometer dinheiro que
 * ninguém decidiu pagar.
 */
function ensure_offer(PDO $pdo, array $order): ?array
{
    if ($order['courier_id'] !== null) {
        return null;
    }

    $existing = $pdo->prepare(
        "SELECT * FROM offers WHERE order_id = :id AND state IN ('open','accepted') ORDER BY id DESC LIMIT 1"
    );
    $existing->execute(['id' => $order['id']]);
    $found = $existing->fetch();
    if ($found !== false) {
        return $found;
    }

    $insert = $pdo->prepare(
        "INSERT INTO offers (order_id, fee, bonus, expires_at)
         VALUES (:order_id, :fee, 0, now() + (:ttl || ' seconds')::interval)
         RETURNING *"
    );
    $insert->execute([
        'order_id' => $order['id'],
        'fee' => $order['delivery_fee'],
        'ttl' => OFFER_TTL_SECONDS,
    ]);

    // A partir daqui o pedido está pronto e sem ninguém pra levar -- é o
    // relógio da tela 15.1 que começa a correr.
    mark_no_courier($pdo, (int) $order['id'], true);

    return $insert->fetch();
}

/**
 * Marca (ou limpa) o relógio de "pronto e sem ninguém pra levar".
 *
 * Tela 15.1 — "o momento que mais gera ticket e ninguém desenha". O carimbo
 * começa quando o pedido fica pronto sem entregador designado, e some no
 * instante em que alguém aceita: é ele que decide quando a tela muda de
 * "procurando" pra "está mais difícil que o normal", e quando o cancelamento
 * automático entra.
 */
function mark_no_courier(PDO $pdo, int $orderId, bool $waiting): void
{
    $pdo->prepare(
        'UPDATE orders SET no_courier_since = :value WHERE id = :id'
    )->execute(['value' => $waiting ? date('c') : null, 'id' => $orderId]);
}

/**
 * Saldo em espécie do entregador: soma do livro, não campo guardado.
 *
 * "Livro de lançamentos, não um campo de saldo: cada corrida, bônus, gorjeta
 * e repasse é uma linha -- é o que permite fechar o caixa sem discussão"
 * (tela 8.7). A view courier_cash_balance existe desde a migração 006.
 */
function courier_cash_balance(PDO $pdo, string $courierId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries
         WHERE account = 'courier_cash' AND party_id = :id"
    );
    $stmt->execute(['id' => $courierId]);

    return (float) $stmt->fetchColumn();
}

/**
 * O que a plataforma deve ao entregador (frete das corridas já entregues).
 */
function courier_payable_balance(PDO $pdo, string $courierId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries
         WHERE account = 'courier_payable' AND party_id = :id"
    );
    $stmt->execute(['id' => $courierId]);

    return (float) $stmt->fetchColumn();
}

/**
 * Lança no livro. Append-only: o role app_rw não tem UPDATE nem DELETE em
 * ledger_entries (migração 006), então correção é lançamento de
 * contrapartida, nunca edição -- a regra está no banco, não na boa vontade
 * de quem escreve o PHP.
 */
function ledger_add(
    PDO $pdo,
    string $account,
    string $partyId,
    float $amount,
    string $origin,
    string $originId,
    ?int $orderId,
    ?string $actorId,
    ?string $memo = null
): void {
    $pdo->prepare(
        'INSERT INTO ledger_entries (account, party_id, amount, origin, origin_id, order_id, actor_id, memo)
         VALUES (:account, :party_id, :amount, :origin, :origin_id, :order_id, :actor_id, :memo)'
    )->execute([
        'account' => $account,
        'party_id' => $partyId,
        'amount' => $amount,
        'origin' => $origin,
        'origin_id' => $originId,
        'order_id' => $orderId,
        'actor_id' => $actorId,
        'memo' => $memo,
    ]);
}

/**
 * Exige que quem está chamando seja um entregador logado, e devolve o
 * courier_id do token -- o mesmo padrão do restaurant_id no painel da loja.
 */
function require_courier(array $claims): string
{
    if (($claims['role'] ?? null) !== 'courier') {
        error_response(403, 'forbidden', 'Só entregador acessa esta parte.');
    }
    $courierId = $claims['courier_id'] ?? null;
    if ($courierId === null) {
        error_response(403, 'forbidden', 'Esse login não está vinculado a um entregador.');
    }

    return (string) $courierId;
}
