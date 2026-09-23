<?php
declare(strict_types=1);

// Tela 2.3 — Fidelidade (migração 029). "Saldo calculado no banco (soma dos
// lançamentos), nunca no cliente."
//
//   ganhar   na ENTREGA (não no pagamento: pedido cancelado não dá ponto),
//            1 ponto por real de subtotal (política `loyalty_points_per_brl`);
//            frete, gorjeta e desconto não contam -- ponto é pelo que se comprou.
//   perder   no estorno de pedido que já tinha dado ponto, na proporção do
//            valor devolvido.
//   gastar   no resgate: vira um cupom pessoal, pago pela plataforma.
//
// Tudo são linhas em `loyalty_entries` com origem única: chamar duas vezes não
// dá ponto duas vezes.

/** Saldo de pontos da pessoa. */
function loyalty_balance(PDO $pdo, string $userId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM loyalty_entries WHERE user_id = :u');
    $stmt->execute(['u' => $userId]);

    return (int) $stmt->fetchColumn();
}

function loyalty_rate(PDO $pdo): float
{
    $rate = $pdo->query('SELECT loyalty_points_per_brl FROM platform_policies ORDER BY version DESC LIMIT 1')->fetchColumn();

    return $rate === false ? 1.0 : (float) $rate;
}

/** Pontos do pedido entregue. Chamado junto do acerto da entrega (ledger_order_delivered). */
function loyalty_earn_for_order(PDO $pdo, array $order): void
{
    $points = (int) floor((float) $order['subtotal'] * loyalty_rate($pdo));
    if ($points <= 0) {
        return;
    }
    $pdo->prepare(
        "INSERT INTO loyalty_entries (user_id, points, origin, origin_id, order_id, memo)
         VALUES (:u, :p, 'order', :oid, :order, :memo)
         ON CONFLICT (origin, origin_id) DO NOTHING"
    )->execute([
        'u' => $order['user_id'],
        'p' => $points,
        'oid' => 'order:' . $order['id'],
        'order' => $order['id'],
        'memo' => 'Pedido #' . $order['public_code'],
    ]);
}

/**
 * Estorno de pedido que deu ponto: devolve os pontos na proporção do valor
 * estornado (estorno integral tira tudo). O saldo pode ficar negativo se a
 * pessoa já gastou -- o livro registra o fato, e o próximo pedido cobre.
 */
function loyalty_reverse_for_refund(PDO $pdo, int $orderId, int $refundId, float $refundAmount): void
{
    $stmt = $pdo->prepare(
        "SELECT e.user_id, e.points, o.total, o.public_code FROM loyalty_entries e
           JOIN orders o ON o.id = e.order_id
          WHERE e.origin = 'order' AND e.order_id = :id"
    );
    $stmt->execute(['id' => $orderId]);
    $earned = $stmt->fetch();
    if ($earned === false || (float) $earned['total'] <= 0) {
        return;
    }
    $fraction = min(1.0, $refundAmount / (float) $earned['total']);
    $points = (int) round((int) $earned['points'] * $fraction);
    if ($points <= 0) {
        return;
    }
    $pdo->prepare(
        "INSERT INTO loyalty_entries (user_id, points, origin, origin_id, order_id, memo)
         VALUES (:u, :p, 'refund', :oid, :order, :memo)
         ON CONFLICT (origin, origin_id) DO NOTHING"
    )->execute([
        'u' => $earned['user_id'],
        'p' => -$points,
        'oid' => 'refund:' . $refundId,
        'order' => $orderId,
        'memo' => 'Estorno #' . $earned['public_code'],
    ]);
}

/**
 * Troca pontos por um cupom pessoal. Devolve o cupom criado.
 * Encerra com 409 se o saldo não dá.
 */
function loyalty_redeem(PDO $pdo, string $userId, int $rewardId): array
{
    $pdo->beginTransaction();
    try {
        // Trava a pessoa: dois resgates simultâneos não gastam o mesmo saldo.
        $pdo->prepare('SELECT id FROM users WHERE id = :u FOR UPDATE')->execute(['u' => $userId]);

        $rewardStmt = $pdo->prepare('SELECT * FROM loyalty_rewards WHERE id = :id AND active');
        $rewardStmt->execute(['id' => $rewardId]);
        $reward = $rewardStmt->fetch();
        if ($reward === false) {
            $pdo->rollBack();
            error_response(404, 'reward_not_found', 'Essa troca não está disponível.');
        }
        $balance = loyalty_balance($pdo, $userId);
        if ($balance < (int) $reward['cost']) {
            $pdo->rollBack();
            error_response(409, 'not_enough_points', 'Faltam ' . ((int) $reward['cost'] - $balance) . ' pontos pra essa troca.');
        }

        // Código legível e único; o cupom só vale pra esta pessoa (owner_user_id).
        $code = 'PTS' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        // Frete grátis não tem valor fixo: o teto do cupom cobre um frete
        // normal da praça, e o desconto real é o frete daquele pedido.
        $budget = $reward['kind'] === 'free_delivery' ? 50.00 : (float) $reward['value'];
        $coupon = $pdo->prepare(
            "INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer, budget_cap,
                                  starts_at, ends_at, created_by, owner_user_id)
             VALUES (:code, :kind, :value, 0, NULL, 'all', 'platform', :budget,
                     now(), now() + make_interval(days => :days), :u, :u)
             RETURNING code, kind, value, ends_at"
        );
        $coupon->execute([
            'code' => $code,
            'kind' => $reward['kind'],
            'value' => $reward['value'],
            'budget' => $budget,
            'days' => (int) $reward['valid_days'],
            'u' => $userId,
        ]);
        $created = $coupon->fetch();

        $pdo->prepare(
            "INSERT INTO loyalty_entries (user_id, points, origin, origin_id, memo)
             VALUES (:u, :p, 'redeem', :oid, :memo)"
        )->execute([
            'u' => $userId,
            'p' => -(int) $reward['cost'],
            'oid' => 'redeem:' . $code,
            'memo' => $reward['kind'] === 'free_delivery' ? 'Cupom entrega grátis' : 'Cupom R$ ' . number_format((float) $reward['value'], 0, ',', '.'),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $created;
}

/** O que a tela 2.3 mostra: saldo, a próxima meta, as trocas e o histórico. */
function loyalty_summary(PDO $pdo, string $userId): array
{
    $balance = loyalty_balance($pdo, $userId);
    $rewards = $pdo->query('SELECT id, label, kind, value, cost FROM loyalty_rewards WHERE active ORDER BY cost, value DESC')->fetchAll();

    // "Faltam 260 pontos para o cupom de R$ 20": a troca mais barata que ainda
    // não dá (empate: a de maior valor). Sem nenhuma pendente, a meta é a maior.
    $goal = null;
    foreach ($rewards as $r) {
        if ((int) $r['cost'] > $balance) {
            $goal = $r;
            break;
        }
    }
    $goal ??= $rewards === [] ? null : $rewards[array_key_last($rewards)];

    $history = $pdo->prepare(
        'SELECT points, origin, memo, created_at FROM loyalty_entries WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 30'
    );
    $history->execute(['u' => $userId]);

    $coupons = $pdo->prepare(
        "SELECT code, kind, value, ends_at FROM coupons
          WHERE owner_user_id = :u AND active AND spent = 0 AND (ends_at IS NULL OR ends_at > now())
          ORDER BY ends_at"
    );
    $coupons->execute(['u' => $userId]);

    return [
        'balance' => $balance,
        'goal' => $goal,
        'rewards' => array_map(static fn (array $r): array => $r + ['affordable' => (int) $r['cost'] <= $balance], $rewards),
        'history' => $history->fetchAll(),
        'coupons' => $coupons->fetchAll(),
        'rate' => loyalty_rate($pdo),
    ];
}
