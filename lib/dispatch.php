<?php
declare(strict_types=1);

// Despacho: quando um pedido fica pronto, vira uma oferta aberta; enquanto
// ninguém aceita, a oferta sobe de RODADA -- o raio de busca cresce e, a
// partir da terceira, a plataforma põe um bônus (surge) em cima do frete.
//
// Cada rodada vira uma linha em `dispatch_attempts` (migração 007): "por que
// não achou entregador" -- raio, quantos candidatos havia, quanto de surge.
// É o registro que responde, depois, se faltou gente ou faltou dinheiro.
//
// Quem vê a oferta é decidido pela posição do entregador
// (`courier_positions`, tabela UNLOGGED escrita a cada 15 s pelo app, ver
// couriers/position.php) contra o raio da rodada atual. Entregador sem
// posição recente só enxerga a oferta na última rodada, que vale pra praça
// inteira -- sem GPS ele não some do mapa, só entra por último.

// As rodadas. Os números são parâmetros de operação, não regra do mock (a
// especificação pede rodada, raio e surge, e não fixa os valores): começam
// perto e baratos e abrem aos poucos. Tempo acumulado desde que o pedido
// ficou pronto sem entregador (`orders.no_courier_since`).
const DISPATCH_ROUNDS = [
    ['round' => 1, 'after_seconds' => 0,   'radius_km' => 2.0,  'surge' => 0.00],
    ['round' => 2, 'after_seconds' => 60,  'radius_km' => 4.0,  'surge' => 0.00],
    ['round' => 3, 'after_seconds' => 150, 'radius_km' => 7.0,  'surge' => 2.00],
    ['round' => 4, 'after_seconds' => 270, 'radius_km' => 50.0, 'surge' => 4.00],
];

// Posição mais velha que isto não conta: é gente que fechou o app.
const COURIER_POSITION_FRESH_SECONDS = 300;

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
    $offer = $insert->fetch();

    // Primeira rodada gravada já na criação: o raio inicial vale desde o
    // primeiro segundo, não só depois que alguém perguntar.
    $fresh = fetch_order($pdo, (int) $order['id']);
    if ($fresh !== null) {
        dispatch_advance($pdo, $fresh);
    }

    return $offer;
}

/**
 * A rodada em que o pedido deveria estar agora, pelo tempo sem entregador.
 */
function dispatch_round_for(int $secondsWaiting): array
{
    $current = DISPATCH_ROUNDS[0];
    foreach (DISPATCH_ROUNDS as $round) {
        if ($secondsWaiting >= $round['after_seconds']) {
            $current = $round;
        }
    }

    return $current;
}

/**
 * Quantos entregadores poderiam pegar esta corrida dentro do raio: em turno,
 * ativos, na mesma praça, sem corrida em andamento, com posição recente -- e
 * sem caixa bloqueado, se o pedido é em dinheiro.
 */
function dispatch_candidates(PDO $pdo, array $order, float $radiusKm): int
{
    $stmt = $pdo->prepare(
        "SELECT count(*)
           FROM couriers c
           JOIN courier_shifts s ON s.courier_id = c.id AND s.ended_at IS NULL
           JOIN courier_positions p ON p.courier_id = c.id
                                   AND p.updated_at > now() - make_interval(secs => :fresh)
           JOIN restaurants r ON r.id = :rid
          WHERE c.active AND c.city_ibge_code = r.city_ibge_code
            AND NOT (c.cash_blocked AND :is_cash)
            AND NOT EXISTS (SELECT 1 FROM orders busy
                             WHERE busy.courier_id = c.id AND busy.status IN ('ready','delivering'))
            AND (2 * 6371 * asin(sqrt(
                  power(sin(radians(p.lat - r.lat) / 2), 2) +
                  cos(radians(r.lat)) * cos(radians(p.lat)) *
                  power(sin(radians(p.lng - r.lng) / 2), 2)))) <= :radius"
    );
    $stmt->execute([
        'fresh' => COURIER_POSITION_FRESH_SECONDS,
        'rid' => $order['restaurant_id'],
        'is_cash' => $order['payment_method'] === 'cash' ? 'true' : 'false',
        'radius' => $radiusKm,
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * Avança o despacho de UM pedido, se o tempo pede uma rodada nova.
 *
 * Idempotente e barato: sem mudança de rodada, não escreve nada. Com
 * mudança, grava a tentativa e soma ao bônus da oferta a DIFERENÇA de surge
 * -- o turbo que o cliente pagou (tela 15.1) já está no bônus e não é
 * tocado. Devolve a rodada vigente.
 */
function dispatch_advance(PDO $pdo, array $order): ?array
{
    if ($order['courier_id'] !== null || $order['status'] !== 'ready' || $order['no_courier_since'] === null) {
        return null;
    }

    $waited = max(0, time() - (int) strtotime((string) $order['no_courier_since']));
    $round = dispatch_round_for($waited);

    $last = $pdo->prepare(
        'SELECT round, surge FROM dispatch_attempts WHERE order_id = :id ORDER BY round DESC LIMIT 1'
    );
    $last->execute(['id' => $order['id']]);
    $previous = $last->fetch();

    if ($previous !== false && (int) $previous['round'] >= $round['round']) {
        return $round;
    }

    // Rodadas puladas (ninguém perguntou por mais de uma janela) entram
    // também, cada uma com seu raio: o registro é "por que não achou", e
    // pular linha apagaria justamente o raio intermediário que também falhou.
    $fromRound = $previous === false ? 0 : (int) $previous['round'];
    $insert = $pdo->prepare(
        'INSERT INTO dispatch_attempts (order_id, round, radius_km, candidates, surge)
         VALUES (:id, :round, :radius, :candidates, :surge)'
    );
    foreach (DISPATCH_ROUNDS as $step) {
        if ($step['round'] <= $fromRound || $step['round'] > $round['round']) {
            continue;
        }
        $insert->execute([
            'id' => $order['id'],
            'round' => $step['round'],
            'radius' => $step['radius_km'],
            'candidates' => dispatch_candidates($pdo, $order, $step['radius_km']),
            'surge' => $step['surge'],
        ]);
    }

    $delta = round($round['surge'] - ($previous === false ? 0.0 : (float) $previous['surge']), 2);
    if ($delta > 0) {
        $pdo->prepare(
            "UPDATE offers SET bonus = bonus + :delta
              WHERE order_id = :id AND state = 'open'"
        )->execute(['delta' => $delta, 'id' => $order['id']]);
    }

    return $round;
}

/**
 * Avança o despacho de todos os pedidos esperando entregador. É o que o
 * cron (bin/dispatch_rounds.php) roda, e o que a vitrine de ofertas chama
 * de passagem -- o app do entregador pergunta a cada 4 s, e isso deixa as
 * rodadas andando no tempo certo mesmo entre um minuto de cron e outro.
 */
function dispatch_tick(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT * FROM orders
          WHERE status = 'ready' AND courier_id IS NULL AND no_courier_since IS NOT NULL
            AND NOT pickup_by_customer"
    );
    $advanced = 0;
    foreach ($stmt->fetchAll() as $order) {
        $before = $pdo->prepare('SELECT count(*) FROM dispatch_attempts WHERE order_id = :id');
        $before->execute(['id' => $order['id']]);
        $count = (int) $before->fetchColumn();
        dispatch_advance($pdo, $order);
        $before->execute(['id' => $order['id']]);
        if ((int) $before->fetchColumn() > $count) {
            $advanced++;
        }
    }

    return $advanced;
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
