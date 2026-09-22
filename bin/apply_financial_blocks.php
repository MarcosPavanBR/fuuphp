<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Tela 9.7, bloco "BLOQUEIOS ATIVOS":
//
//   "2 entregadores · Espécie acima do prazo — sem corridas em dinheiro até
//    a baixa."
//   "1 loja em atraso · Mercadinho São José · pedidos em espécie suspensos."
//
// `couriers.cash_blocked` existe desde a migração 007 e já é respeitado em
// `couriers/offers.php` e `accept_offer.php`; `restaurants.online_only_until`
// existe desde a 002 e é respeitado no checkout (via resolve_policy, desde a migração 025). O que faltava era alguém
// LIGAR os dois: os campos eram lidos por todo mundo e escritos por ninguém.
//
// POR QUE EM PHP, E NÃO NO pg_cron (mesma razão do auto_cancel da 15.1):
// os dois bloqueios saem de política (`cash_ceiling`, `cash_settle_deadline`,
// `store_debit_dow`) e a leitura dessa política já mora em PHP. Reescrever a
// interpretação dela em PL/pgSQL criaria uma segunda fonte de verdade sobre
// quando alguém está em atraso -- e as duas divergiriam no primeiro ajuste.
//
// COMO RODAR (uma linha no cron, de hora em hora basta):
//
//   0 * * * * /usr/local/bin/php /home/USUARIO/app/bin/apply_financial_blocks.php >> /home/USUARIO/logs/blocks.log 2>&1
//
// Rodar duas vezes seguidas é inofensivo: tudo aqui é idempotente -- calcula
// o estado que DEVERIA valer e escreve só quando ele difere do gravado.

// Quanto tempo a loja fica limitada ao online depois de entrar em atraso.
// Não é prazo fixo: é "até regularizar". A data serve de validade pra trava
// não ficar eterna se o débito for baixado por fora; quem realmente destrava
// é a baixa em `admin/netting.php`.
const STORE_BLOCK_DAYS = 7;

$pdo = db();
$policy = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
if ($policy === false) {
    fwrite(STDERR, "nenhuma platform_policies cadastrada\n");
    exit(1);
}

$ceiling = (float) ($policy['cash_ceiling'] ?? 0);
$deadline = (string) ($policy['cash_settle_deadline'] ?? '24:00:00');
$debitDow = (int) ($policy['store_debit_dow'] ?? 2);

$changed = ['couriers' => 0, 'stores' => 0];

// ── Entregadores ───────────────────────────────────────────────────────
//
// Dois motivos pra travar, e a tela nomeia o segundo: acima do teto de
// espécie, ou com dinheiro na mão há mais tempo que o prazo de baixa. O
// "há mais tempo" é medido pelo lançamento de espécie MAIS ANTIGO ainda não
// coberto por baixa -- e como o livro é append-only, isso é a data do
// primeiro crédito depois do último saldo zerado.
$stmt = $pdo->prepare(
    "SELECT c.id,
            COALESCE(SUM(l.amount), 0) AS balance,
            MIN(l.created_at) FILTER (WHERE l.amount > 0) AS oldest_cash,
            c.cash_blocked
       FROM couriers c
       LEFT JOIN ledger_entries l
              ON l.party_id = c.id AND l.account = 'courier_cash'
      GROUP BY c.id, c.cash_blocked"
);
$stmt->execute();

$update = $pdo->prepare('UPDATE couriers SET cash_blocked = :blocked WHERE id = :id');

foreach ($stmt->fetchAll() as $courier) {
    $balance = round((float) $courier['balance'], 2);
    $overCeiling = $ceiling > 0 && $balance > $ceiling;

    $overdue = false;
    if ($balance > 0 && $courier['oldest_cash'] !== null) {
        $limit = strtotime($courier['oldest_cash']) + interval_to_seconds($deadline);
        $overdue = $limit < time();
    }

    $shouldBlock = $balance > 0 && ($overCeiling || $overdue);
    if ($shouldBlock === (bool) $courier['cash_blocked']) {
        continue;
    }

    $update->execute(['blocked' => $shouldBlock ? 'true' : 'false', 'id' => $courier['id']]);
    $changed['couriers']++;
    printf(
        "%s entregador %s (saldo R$ %s%s%s)\n",
        $shouldBlock ? 'BLOQUEIA' : 'LIBERA',
        $courier['id'],
        number_format($balance, 2, ',', '.'),
        $overCeiling ? ', acima do teto' : '',
        $overdue ? ', fora do prazo' : ''
    );
}

// ── Lojas ──────────────────────────────────────────────────────────────
//
// "Atraso bloqueia novas corridas em espécie naquela loja, não o pagamento
// do entregador." A trava é `online_only_until`, que o checkout já respeita
// -- pedido em dinheiro e maquininha simplesmente somem das opções.
$stores = $pdo->query(
    "SELECT p.id, p.party_id, p.period_end, p.net, p.state, r.name, r.online_only_until
       FROM payouts p
       JOIN restaurants r ON r.id = p.party_id
      WHERE p.party_kind = 'restaurant' AND p.state <> 'paid' AND p.net > 0"
);

$block = $pdo->prepare(
    'UPDATE restaurants SET online_only_until = :until WHERE id = :id'
);

foreach ($stores->fetchAll() as $store) {
    $due = netting_due((string) $store['period_end'], $debitDow);
    if ($due['late_days'] <= 0) {
        continue;
    }

    $already = $store['online_only_until'] !== null
        && strtotime((string) $store['online_only_until']) > time();
    if ($already) {
        continue;
    }

    $block->execute([
        'until' => date('c', time() + STORE_BLOCK_DAYS * 86400),
        'id' => $store['party_id'],
    ]);
    $changed['stores']++;
    printf(
        "SOMENTE ONLINE loja %s (débito de %s vencido há %dd)\n",
        $store['name'],
        number_format((float) $store['net'], 2, ',', '.'),
        $due['late_days']
    );
}

printf(
    "[%s] bloqueios aplicados: %d entregador(es), %d loja(s)\n",
    date('c'),
    $changed['couriers'],
    $changed['stores']
);

/**
 * Converte um `interval` do Postgres em segundos. Vem como "24:00:00" ou
 * "1 day 12:00:00" conforme o valor, e o PHP sabe ler os dois com strtotime
 * relativo -- o que evita reescrever aritmética de intervalo à mão.
 */
function interval_to_seconds(string $interval): int
{
    $base = strtotime('2000-01-01 00:00:00 UTC');
    $with = strtotime('+' . $interval, $base);

    return $with === false ? 86400 : $with - $base;
}
