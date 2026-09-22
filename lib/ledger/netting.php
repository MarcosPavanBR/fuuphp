<?php
declare(strict_types=1);

// Tela 9.7 — "netting semanal e repasse".
//
// A tela responde uma pergunta de dono, e a resposta inteira está em quatro
// linhas dela:
//
//   1. Pedido pago no app: nossa taxa sai na hora, por split do Mercado Pago.
//      Nada a cobrar depois.
//   2. Pedido em espécie: o entregador devolve o BRUTO à loja. Nossa taxa e
//      o frete ficam como crédito nosso contra ela.
//   3. Quem paga o entregador é o FUUdelivery, sempre. Por isso todo frete
//      entra na coluna "frete a nós", qualquer que seja a forma de pagamento.
//   4. Terça: um único débito por loja. Atraso bloqueia novas corridas em
//      espécie naquela loja -- não o pagamento do entregador.
//
// Nada aqui calcula saldo por fora do livro: "saldo é soma de lançamentos,
// correção é contrapartida -- nunca edição". Toda consulta deste arquivo é
// SUM em `ledger_entries`.

// "Atraso 3D" na tela: dias corridos desde o vencimento do débito. O dia da
// semana do débito é política (`platform_policies.store_debit_dow`).
const NETTING_GRACE_DAYS = 0;

/**
 * A semana fechada mais recente: segunda a domingo anteriores a hoje.
 *
 * A tela diz "Semana 09–15 set · fechamento terça 17/09": fecha-se a semana
 * cheia, e o acerto acontece no dia seguinte útil. Semana em curso não entra
 * -- cobrar por semana que ainda está correndo é a receita do acerto que não
 * bate.
 */
function netting_last_week(?string $today = null): array
{
    $base = strtotime($today ?? 'today');
    $lastMonday = strtotime('monday this week', $base) - 7 * 86400;

    return [
        'start' => date('Y-m-d', $lastMonday),
        'end' => date('Y-m-d', $lastMonday + 6 * 86400),
    ];
}

/**
 * O cabeçalho da tela: GMV, nossa taxa, frete dos entregadores e espécie não
 * baixada. Os quatro números saem de fontes diferentes de propósito -- GMV é
 * pedido, o resto é livro.
 */
function netting_summary(PDO $pdo, string $start, string $end): array
{
    $gmv = $pdo->prepare(
        "SELECT COALESCE(SUM(total), 0), COALESCE(SUM(commission), 0)
           FROM orders
          WHERE status IN ('delivered','refunded')
            AND created_at >= :start::date AND created_at < :end::date + 1"
    );
    $gmv->execute(['start' => $start, 'end' => $end]);
    [$gmvTotal, $commission] = $gmv->fetch(PDO::FETCH_NUM);

    $freight = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries
          WHERE account = 'courier_payable'
            AND created_at >= :start::date AND created_at < :end::date + 1"
    );
    $freight->execute(['start' => $start, 'end' => $end]);

    // Espécie não baixada é saldo ACUMULADO, não da semana: é dinheiro que
    // está na mão de alguém agora, e é isso que se retém do repasse.
    $cash = $pdo->query(
        "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'courier_cash'"
    );

    return [
        'period' => ['start' => $start, 'end' => $end],
        'gmv' => round((float) $gmvTotal, 2),
        'commission' => round((float) $commission, 2),
        'courier_freight' => round((float) $freight->fetchColumn(), 2),
        'cash_unsettled' => round((float) $cash->fetchColumn(), 2),
    ];
}

/**
 * "ACERTO POR RESTAURANTE — UM VALOR ÚNICO (NETTING)".
 *
 * A distinção que a tela faz e o código tem que fazer junto: **taxa já
 * split** x **taxa em aberto**. Pedido pago no app teve a comissão retida na
 * hora pelo gateway; pedido em espécie e maquininha não teve retenção
 * nenhuma, e é ele que vira crédito nosso. Somar os dois numa coluna só é
 * cobrar duas vezes da loja -- o erro que essa tela existe pra evitar.
 */
function netting_by_store(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        "SELECT r.id, r.name, r.online_only_until,
                COALESCE(o.gmv, 0) AS gmv,
                COALESCE(o.commission_split, 0) AS commission_split,
                COALESCE(l.owed, 0) AS owed,
                p.id AS payout_id, p.state AS payout_state, p.net AS payout_net,
                p.period_start, p.period_end
           FROM restaurants r
           LEFT JOIN (
                SELECT restaurant_id,
                       SUM(total) AS gmv,
                       SUM(commission) FILTER (
                         WHERE payment_method IN ('mp_card','pix_auto')) AS commission_split
                  FROM orders
                 WHERE status IN ('delivered','refunded')
                   AND created_at >= :start::date AND created_at < :end::date + 1
                 GROUP BY restaurant_id
           ) o ON o.restaurant_id = r.id
           LEFT JOIN (
                SELECT party_id, SUM(amount) AS owed
                  FROM ledger_entries
                 WHERE account = 'store_receivable'
                   AND created_at >= :start::date AND created_at < :end::date + 1
                 GROUP BY party_id
           ) l ON l.party_id = r.id
           LEFT JOIN payouts p ON p.party_kind = 'restaurant' AND p.party_id = r.id
                              AND p.period_start = :start::date AND p.period_end = :end::date
          WHERE r.approved_at IS NOT NULL
            AND (o.gmv IS NOT NULL OR l.owed IS NOT NULL OR p.id IS NOT NULL)
          ORDER BY COALESCE(o.gmv, 0) DESC"
    );
    $stmt->execute(['start' => $start, 'end' => $end]);

    return $stmt->fetchAll();
}

/**
 * Quando este débito vence, e há quantos dias está em atraso.
 *
 * "Terça-feira: um único débito por loja" — o dia é `store_debit_dow` da
 * política (1 = segunda ... 7 = domingo, ISO), contado a partir do fim do
 * período.
 */
function netting_due(string $periodEnd, int $debitDow, ?string $today = null): array
{
    $end = strtotime($periodEnd);
    $due = $end;
    // Anda até o primeiro dia da semana pedido DEPOIS do fim do período.
    do {
        $due += 86400;
    } while ((int) date('N', $due) !== $debitDow);

    $now = strtotime($today ?? 'today');
    $lateDays = (int) floor(($now - $due) / 86400) - NETTING_GRACE_DAYS;

    return [
        'due_on' => date('Y-m-d', $due),
        'late_days' => max(0, $lateDays),
    ];
}

/**
 * O lote de repasse aos entregadores: bruto da semana menos a espécie que
 * ainda está com cada um.
 *
 * "Bruto R$ 22.740 (pago por nós) − espécie não baixada R$ 1.284 (retida até
 * a baixa) = R$ 21.456 a transferir." A retenção nunca deixa o líquido
 * negativo: dívida maior que o repasse continua sendo dívida, não vira
 * cobrança embutida no pagamento de quem trabalhou.
 */
function netting_courier_batch(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, u.full_name, c.cash_blocked
           FROM payouts p
           JOIN couriers c ON c.id = p.party_id
           JOIN users u ON u.id = c.user_id
          WHERE p.party_kind = 'courier'
            AND p.period_start = :start::date AND p.period_end = :end::date
          ORDER BY p.net DESC"
    );
    $stmt->execute(['start' => $start, 'end' => $end]);
    $rows = $stmt->fetchAll();

    $gross = 0.0;
    $withheld = 0.0;
    $net = 0.0;
    foreach ($rows as $row) {
        $gross += (float) $row['gross'];
        $withheld += (float) $row['withheld'];
        $net += (float) $row['net'];
    }

    return [
        'rows' => $rows,
        'count' => count($rows),
        'gross' => round($gross, 2),
        'withheld' => round($withheld, 2),
        'net' => round($net, 2),
    ];
}

/**
 * "BLOQUEIOS ATIVOS": quem está travado agora e por quê.
 *
 * Dois bloqueios diferentes, de propósito -- o da tela: atraso da loja
 * suspende pedido em espécie NAQUELA loja, e não atrasa o pagamento de
 * ninguém; espécie fora do prazo tira o entregador das corridas em dinheiro
 * até ele baixar.
 */
function netting_blocks(PDO $pdo): array
{
    $couriers = $pdo->query(
        "SELECT c.id, u.full_name,
                COALESCE((SELECT SUM(amount) FROM ledger_entries
                           WHERE account = 'courier_cash' AND party_id = c.id), 0) AS cash
           FROM couriers c JOIN users u ON u.id = c.user_id
          WHERE c.cash_blocked
          ORDER BY u.full_name"
    );

    $stores = $pdo->query(
        "SELECT id, name, online_only_until FROM restaurants
          WHERE online_only_until IS NOT NULL AND online_only_until > now()
          ORDER BY name"
    );

    return [
        'couriers' => $couriers->fetchAll(),
        'stores' => $stores->fetchAll(),
    ];
}
