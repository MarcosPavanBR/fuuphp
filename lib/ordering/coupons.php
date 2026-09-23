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
/**
 * A condição SQL (sobre `users u`) de quem pertence ao público de uma
 * campanha. UMA definição só, usada pra contar o público na projeção (tela
 * 15.3) e pra decidir se a pessoa pode usar o cupom (resgate e checkout):
 * a campanha que promete "primeiro pedido" não pode contar um público e
 * aceitar outro.
 *
 * "Pedido" aqui é pedido que andou (nem carrinho nem pagamento pendente).
 * Com loja (`:rid`), vale só o histórico naquela loja.
 */
function coupon_audience_condition(string $audience, bool $scoped): string
{
    $scope = $scoped ? ' AND o.restaurant_id = :rid' : '';
    $placed = "SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cart','pending_payment'){$scope}";

    return match ($audience) {
        'all' => 'true',
        'first_order' => "NOT EXISTS ({$placed})",
        'inactive_15d', 'inactive_30d' => "EXISTS ({$placed})
            AND NOT EXISTS ({$placed} AND o.created_at >= now() - make_interval(days => :days))",
        default => 'false',
    };
}

function coupon_audience_params(string $audience, ?string $restaurantId): array
{
    $params = $restaurantId === null ? [] : ['rid' => $restaurantId];
    if (str_starts_with($audience, 'inactive_')) {
        $params['days'] = $audience === 'inactive_15d' ? 15 : 30;
    }

    return $params;
}

/** Tamanho do público (projeção da tela 15.3). */
function coupon_audience_size(PDO $pdo, string $audience, ?string $restaurantId = null): int
{
    $stmt = $pdo->prepare(
        "SELECT count(*) FROM users u WHERE u.role = 'customer' AND "
        . coupon_audience_condition($audience, $restaurantId !== null)
    );
    $stmt->execute(coupon_audience_params($audience, $restaurantId));

    return (int) $stmt->fetchColumn();
}

/** Esta pessoa está no público da campanha? (resgate e checkout) */
function coupon_audience_includes(PDO $pdo, array $coupon, string $userId): bool
{
    // Cupom pessoal (troca de pontos, 2.3): só o dono usa, qualquer que seja o público.
    if (($coupon['owner_user_id'] ?? null) !== null) {
        return $coupon['owner_user_id'] === $userId;
    }
    $audience = (string) $coupon['audience'];
    $restaurantId = $coupon['restaurant_id'] ?? null;
    $stmt = $pdo->prepare(
        'SELECT 1 FROM users u WHERE u.id = :uid AND ' . coupon_audience_condition($audience, $restaurantId !== null)
    );
    $stmt->execute(['uid' => $userId] + coupon_audience_params($audience, $restaurantId));

    return $stmt->fetchColumn() !== false;
}

/** A frase de recusa, no idioma da campanha. */
function coupon_audience_message(string $audience, bool $personal = false): string
{
    if ($personal) {
        return 'Esse cupom é pessoal: foi trocado por pontos de outra conta.';
    }
    return match ($audience) {
        'first_order' => 'Esse cupom é só pra quem ainda não fez pedido.',
        'inactive_15d' => 'Esse cupom é pra quem não pede há mais de 15 dias.',
        'inactive_30d' => 'Esse cupom é pra quem não pede há mais de 30 dias.',
        default => 'Esse cupom não vale pra sua conta.',
    };
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
