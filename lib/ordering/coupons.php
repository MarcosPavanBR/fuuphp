<?php
declare(strict_types=1);

// Tela 15.3 — "A coluna que falta em quase todo painel: quem paga o
// desconto."
//
// `coupons.payer` já existia na migração 008 com os três valores
// ('store','platform','shared'), mas o resgate não mexia no livro: o
// desconto saía do total do pedido e ninguém ficava devendo nada a ninguém.
// Isto aqui é a linha que faltava.

/**
 * Lança no livro quem pagou o desconto deste resgate.
 *
 * - `store`: sai do repasse da loja -- ela nos deve mais (store_receivable).
 * - `platform`: é despesa nossa (platform_expense), com a loja do pedido
 *   como contraparte pra dar pra saber ONDE o dinheiro foi gasto.
 * - `shared`: metade de cada, com o centavo ímpar ficando com a plataforma
 *   (dividir R$ 12,01 dá 6,005 -- alguém tem que ficar com o centavo, e
 *   deixar com quem criou a campanha é mais fácil de defender).
 */
function record_coupon_ledger(PDO $pdo, array $coupon, array $order, float $amount, ?string $actorId): void
{
    $restaurantId = (string) $order['restaurant_id'];
    $orderId = (int) $order['id'];
    $originId = 'coupon:' . $coupon['id'];
    $code = (string) $coupon['code'];

    $storeShare = match ((string) $coupon['payer']) {
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
            'coupon',
            $originId,
            $orderId,
            $actorId,
            "desconto do cupom {$code} bancado pela loja"
        );
    }

    if ($platformShare > 0) {
        ledger_add(
            $pdo,
            'platform_expense',
            $restaurantId,
            $platformShare,
            'coupon',
            $originId,
            $orderId,
            $actorId,
            "desconto do cupom {$code} bancado pela plataforma"
        );
    }
}

/**
 * Quantas pessoas caem no público de uma campanha, agora.
 *
 * "4.180 pessoas no público" no mock. O número sai de `orders` -- não há
 * tabela de segmentação, e não precisa haver: "sem pedir há 15 dias" é uma
 * consulta, não um cadastro.
 */
function coupon_audience_size(PDO $pdo, string $audience, ?string $restaurantId = null): int
{
    $scope = $restaurantId === null ? '' : ' AND o.restaurant_id = :rid';
    $params = $restaurantId === null ? [] : ['rid' => $restaurantId];

    $sql = match ($audience) {
        'all' => "SELECT count(*) FROM users WHERE role = 'customer'",
        'first_order' => "SELECT count(*) FROM users u
                           WHERE u.role = 'customer'
                             AND NOT EXISTS (
                               SELECT 1 FROM orders o
                                WHERE o.user_id = u.id
                                  AND o.status NOT IN ('cart','pending_payment'){$scope}
                             )",
        'inactive_15d', 'inactive_30d' => "SELECT count(*) FROM users u
                           WHERE u.role = 'customer'
                             AND EXISTS (
                               SELECT 1 FROM orders o
                                WHERE o.user_id = u.id
                                  AND o.status NOT IN ('cart','pending_payment'){$scope}
                             )
                             AND NOT EXISTS (
                               SELECT 1 FROM orders o
                                WHERE o.user_id = u.id
                                  AND o.status NOT IN ('cart','pending_payment')
                                  AND o.created_at >= now() - make_interval(days => :days){$scope}
                             )",
        default => 'SELECT 0',
    };

    if (str_contains($sql, ':days')) {
        $params['days'] = $audience === 'inactive_15d' ? 15 : 30;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * "Projeção: ~208 resgates até o teto. Com ticket médio de R$ 53, gera
 * ≈ R$ 11.000 de GMV e R$ 880 de comissão."
 *
 * Tudo aqui é conta de padaria feita com números REAIS da plataforma (ticket
 * médio e comissão vigente), e a resposta diz quando não há ticket médio pra
 * usar. O "se 3 de 10 voltarem a pedir" do mock não entra: é uma previsão de
 * comportamento, e não há dado de recompra pra sustentar.
 */
function coupon_projection(PDO $pdo, string $kind, float $value, float $budgetCap, ?string $restaurantId = null): array
{
    $ticketStmt = $pdo->prepare(
        "SELECT avg(total) FROM orders
          WHERE status NOT IN ('cart','pending_payment','rejected','cancelled')
            AND created_at >= now() - interval '90 days'
            AND (:rid::uuid IS NULL OR restaurant_id = :rid2::uuid)"
    );
    $ticketStmt->execute(['rid' => $restaurantId, 'rid2' => $restaurantId]);
    $ticket = $ticketStmt->fetchColumn();
    $ticket = $ticket === null || $ticket === false ? null : round((float) $ticket, 2);

    $policy = $pdo->query('SELECT commission_bps FROM platform_policies ORDER BY version DESC LIMIT 1')->fetchColumn();
    $commissionBps = $policy === false ? 0 : (int) $policy;

    // Desconto médio por resgate: fixo é o próprio valor; percentual e frete
    // grátis dependem do pedido, então usam o ticket médio como base.
    $perRedemption = match ($kind) {
        'fixed' => $value,
        'percent' => $ticket === null ? null : round($ticket * $value / 100, 2),
        'free_delivery' => $value,
        default => null,
    };

    if ($perRedemption === null || $perRedemption <= 0) {
        return [
            'ticket_avg' => $ticket,
            'redemptions' => null,
            'gmv' => null,
            'commission' => null,
            'reason' => 'Sem pedidos suficientes pra estimar: a projeção precisa de ticket médio real.',
        ];
    }

    $redemptions = (int) floor($budgetCap / $perRedemption);
    $gmv = $ticket === null ? null : round($redemptions * $ticket, 2);

    return [
        'ticket_avg' => $ticket,
        'redemptions' => $redemptions,
        'gmv' => $gmv,
        'commission' => $gmv === null ? null : round($gmv * $commissionBps / 10000, 2),
        'reason' => null,
    ];
}
