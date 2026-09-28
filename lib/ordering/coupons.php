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

/**
 * Parâmetros da condição de público (coupon_audience_condition): a loja, só
 * quando a condição consulta pedidos, e a janela em dias dos "sem pedir há".
 */
function coupon_audience_params(string $audience, ?string $restaurantId): array
{
    // 'all' não consulta pedido nenhum (condição `true`): mandar :rid mesmo
    // assim é parâmetro sobrando, e o PDO recusa (HY093). Era o que derrubava
    // a projeção de campanha de loja pra "Todo mundo".
    $params = $restaurantId === null || $audience === 'all' ? [] : ['rid' => $restaurantId];
    if (str_starts_with($audience, 'inactive_')) {
        $params['days'] = $audience === 'inactive_15d' ? 15 : 30;
    }

    return $params;
}

/**
 * Quantas pessoas caem no público de uma campanha, agora (projeção da tela
 * 15.3).
 *
 * "4.180 pessoas no público" no mock. O número sai de `orders` -- não há
 * tabela de segmentação, e não precisa haver: "sem pedir há 15 dias" é uma
 * consulta, não um cadastro.
 */
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

/**
 * O cupom já foi usado por este CPF OU por esta conta?
 *
 * O índice único de coupon_redemptions é por CPF ("um uso por CPF, não por
 * conta"). Mas o CPF se troca no perfil: sem olhar a conta também, usar o
 * cupom, trocar o CPF e usar de novo passava. Um uso por CPF E por conta.
 */
function coupon_used_by(PDO $pdo, int $couponId, string $cpf, string $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT EXISTS (
           SELECT 1 FROM coupon_redemptions cr JOIN orders o ON o.id = cr.order_id
            WHERE cr.coupon_id = :id AND (cr.cpf = :cpf OR o.user_id = :uid))'
    );
    $stmt->execute(['id' => $couponId, 'cpf' => $cpf, 'uid' => $userId]);
    return $stmt->fetchColumn() === true;
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

    // Cupom de uma loja: a comissão dela, com exceção negociada (aba
    // Políticas) se houver. Cupom da plataforma: a comissão da plataforma.
    if ($restaurantId !== null) {
        try {
            $commissionBps = (int) resolve_policy($pdo, $restaurantId)['commission_bps'];
        } catch (RuntimeException) {
            $commissionBps = 0;
        }
    } else {
        $policy = $pdo->query('SELECT commission_bps FROM platform_policies ORDER BY version DESC LIMIT 1')->fetchColumn();
        $commissionBps = $policy === false ? 0 : (int) $policy;
    }

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

/**
 * Teto de cupom da loja (migração 031): o que a plataforma liberou, o que
 * já está comprometido em cupons vivos criados pela própria loja e o que
 * sobra pra criar outro.
 *
 * "Comprometido" é a soma dos tetos (`budget_cap`) dos cupons ativos e no
 * prazo -- não o que já foi gasto: um cupom vivo ainda pode gastar até o teto
 * dele. Desativar ou vencer libera o orçamento de volta.
 *
 * Chame com a linha da loja travada (FOR UPDATE) quando for criar cupom,
 * senão dois cupons criados ao mesmo tempo passam do teto juntos.
 *
 * @return array{limit: float, committed: float, available: float}
 */
function store_coupon_budget(PDO $pdo, string $restaurantId): array
{
    $stmt = $pdo->prepare(
        "SELECT r.coupon_budget_limit AS limit,
                COALESCE((SELECT SUM(c.budget_cap) FROM coupons c
                           WHERE c.restaurant_id = r.id AND c.created_by_store AND c.active
                             AND (c.ends_at IS NULL OR c.ends_at > now())), 0) AS committed
           FROM restaurants r WHERE r.id = :id"
    );
    $stmt->execute(['id' => $restaurantId]);
    $row = $stmt->fetch();
    $limit = $row === false ? 0.0 : (float) $row['limit'];
    $committed = $row === false ? 0.0 : (float) $row['committed'];

    return [
        'limit' => $limit,
        'committed' => $committed,
        'available' => max(0.0, round($limit - $committed, 2)),
    ];
}

/**
 * O cupom de primeiro pedido já foi usado NESTE endereço por outra conta?
 * (auditoria NEG-01). Mesmo endereço = mesmo CEP e número, ou a menos de
 * ~50 m (quando os dois têm coordenada). Pedido cancelado ou recusado não
 * conta: o cupom não chegou a ser aproveitado.
 *
 * O CPF só passa pelo dígito verificador e um telefone novo custa um chip,
 * então conta nova com CPF gerado repetiria o "primeiro pedido" sem fim. O
 * endereço de entrega é o que não se inventa.
 */
function first_order_used_at_address(PDO $pdo, int $addressId, string $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
           FROM addresses here
           JOIN coupon_redemptions cr ON true
           JOIN coupons c ON c.id = cr.coupon_id AND c.audience = 'first_order'
           JOIN orders o ON o.id = cr.order_id AND o.user_id <> :uid
                        AND o.status NOT IN ('cancelled', 'rejected')
           JOIN addresses there ON there.id = o.address_id
          WHERE here.id = :aid
            AND (
                  (regexp_replace(here.postal_code, '\\D', '', 'g') = regexp_replace(there.postal_code, '\\D', '', 'g')
                   AND lower(trim(coalesce(here.number, ''))) <> ''
                   AND lower(trim(here.number)) = lower(trim(coalesce(there.number, ''))))
               OR (here.lat IS NOT NULL AND there.lat IS NOT NULL
                   AND abs(here.lat - there.lat) < 0.00045 AND abs(here.lng - there.lng) < 0.00045)
            )
          LIMIT 1"
    );
    $stmt->execute(['aid' => $addressId, 'uid' => $userId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * O cupom, com `live` = ativo e dentro das datas AGORA (relógio do banco).
 * Por código (aplicar no carrinho) ou por id (o cupom que o carrinho guarda
 * desde a migração 044); $lock trava a linha pro checkout, que vai mexer no
 * orçamento dela.
 */
function fetch_coupon(PDO $pdo, string|int $codeOrId, bool $lock = false): ?array
{
    $where = is_int($codeOrId) ? 'c.id = :k' : 'c.code = :k';
    $stmt = $pdo->prepare(
        "SELECT c.*, (c.active AND c.starts_at <= now() AND (c.ends_at IS NULL OR c.ends_at > now())) AS live
           FROM coupons c WHERE {$where}" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['k' => $codeOrId]);
    $coupon = $stmt->fetch();
    return $coupon === false ? null : $coupon;
}

/**
 * O desconto que o cupom dá sobre este subtotal -- e sobre este frete, no
 * fechamento (no carrinho ainda não há endereço, então frete grátis vale 0).
 * Nunca passa do subtotal (fixo e percentual) nem do frete (frete grátis):
 * cupom não vira crédito.
 */
function coupon_discount(array $coupon, float $subtotal, float $deliveryFee = 0.0): float
{
    $discount = match ($coupon['kind']) {
        'fixed' => (float) $coupon['value'],
        'percent' => round($subtotal * (float) $coupon['value'] / 100, 2),
        'free_delivery' => $deliveryFee,
        default => 0.0,
    };
    $ceiling = $coupon['kind'] === 'free_delivery' ? $deliveryFee : $subtotal;
    return round(max(0.0, min($discount, $ceiling)), 2);
}

/**
 * Por que este cupom NÃO vale neste carrinho agora -- ou null, se vale.
 *
 * A mesma lista ao aplicar (cart/apply_coupon.php) e ao fechar
 * (orders/checkout.php): entre um e outro a campanha pode vencer ou
 * esgotar, a pessoa pode ter usado o cupom em outro pedido, e o carrinho
 * pode ter encolhido abaixo do pedido mínimo. $amount é quanto este pedido
 * vai consumir do orçamento (0 ao aplicar, quando nada é consumido ainda).
 *
 * @return array{0: int, 1: string, 2: string}|null [status HTTP, code, mensagem]
 */
function coupon_rejection(PDO $pdo, array $coupon, array $cart, string $cpf, string $userId, float $amount = 0.0): ?array
{
    if (!$coupon['live']) {
        return [404, 'coupon_not_found', 'Cupom inválido ou fora do prazo.'];
    }
    if ($coupon['restaurant_id'] !== null && $coupon['restaurant_id'] !== $cart['restaurant_id']) {
        return [409, 'coupon_other_store', 'Esse cupom é de outra loja.'];
    }
    if ((float) $cart['subtotal'] < (float) $coupon['min_order']) {
        return [409, 'coupon_min_order', sprintf(
            'Esse cupom vale a partir de R$ %s em itens.',
            number_format((float) $coupon['min_order'], 2, ',', '.')
        )];
    }
    if (coupon_used_by($pdo, (int) $coupon['id'], $cpf, $userId)) {
        return [409, 'coupon_already_used', 'Você já usou esse cupom.'];
    }
    // O público da campanha (tela 15.3: primeiro pedido, inativos há 15/30
    // dias) é a mesma condição que conta o público na projeção.
    if (!coupon_audience_includes($pdo, $coupon, $userId)) {
        return [409, 'coupon_audience', coupon_audience_message((string) $coupon['audience'], ($coupon['owner_user_id'] ?? null) !== null)];
    }
    // Passar do teto seria recusado pelo CHECK within_budget -- com 500.
    if ((float) $coupon['spent'] >= (float) $coupon['budget_cap']
        || money_cents((string) $coupon['spent']) + money_cents($amount) > money_cents((string) $coupon['budget_cap'])) {
        return [409, 'coupon_exhausted', 'Esse cupom acabou (orçamento da campanha esgotou).'];
    }
    return null;
}

/**
 * Refaz o desconto do cupom do carrinho depois que os itens mudaram
 * (chamada por recompute_cart_subtotal). Percentual acompanha o subtotal;
 * abaixo do pedido mínimo o desconto vira zero -- o cupom fica no carrinho
 * e o checkout explica por que não vale. Carrinho sem cupom não tem
 * desconto: o crédito de carteira só entra no fechamento.
 */
function refresh_cart_coupon(PDO $pdo, int $cartId): void
{
    $cart = fetch_order($pdo, $cartId);
    if ($cart === null || $cart['status'] !== 'cart') {
        return;
    }
    $discount = 0.0;
    if ($cart['coupon_id'] !== null) {
        $coupon = fetch_coupon($pdo, (int) $cart['coupon_id']);
        if ($coupon !== null && (float) $cart['subtotal'] >= (float) $coupon['min_order']) {
            $discount = coupon_discount($coupon, (float) $cart['subtotal']);
        }
    }
    if (money_cents((string) $cart['discount']) !== money_cents($discount)) {
        $pdo->prepare('UPDATE orders SET discount = :d WHERE id = :id')->execute(['d' => $discount, 'id' => $cartId]);
    }
}
