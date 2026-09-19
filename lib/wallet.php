<?php
declare(strict_types=1);

// Tela 13.4 — "Oferecer crédito + R$ 10".
//
// "Crédito na carteira é aceito por 6 de cada 10 clientes e custa menos que
// o estorno — mas nunca pode ser imposto."
//
// As duas metades dessa frase viram código:
//   - "custa menos": o crédito vale o estorno MAIS um bônus, e o dinheiro
//     fica na casa;
//   - "nunca imposto": a oferta nasce em 'offered' e só vira saldo quando a
//     pessoa aceita. Recusar devolve o estorno original ao caminho normal.

// O "+ R$ 10" do mock. É o padrão que o console sugere, não um limite: o
// admin manda o bônus que decidiu.
const WALLET_BONUS_DEFAULT = 10.00;
// Crédito sem prazo é passivo eterno no balanço; 90 dias é o prazo declarado
// na oferta, e a pessoa lê isso antes de aceitar.
const WALLET_CREDIT_DAYS = 90;

/**
 * Saldo que a pessoa pode gastar agora: só crédito aceito e não vencido.
 * Oferta pendente não é saldo -- é proposta.
 */
function wallet_balance(PDO $pdo, string $userId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount + bonus), 0) FROM wallet_credits
          WHERE user_id = :id AND state = 'accepted'
            AND (expires_at IS NULL OR expires_at > now())"
    );
    $stmt->execute(['id' => $userId]);

    return (float) $stmt->fetchColumn();
}

/**
 * Os créditos aceitos disponíveis, do mais antigo pro mais novo: gasta-se o
 * que vence primeiro.
 */
function wallet_available(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM wallet_credits
          WHERE user_id = :id AND state = 'accepted'
            AND (expires_at IS NULL OR expires_at > now())
          ORDER BY expires_at NULLS LAST, id
          FOR UPDATE"
    );
    $stmt->execute(['id' => $userId]);

    return $stmt->fetchAll();
}

/**
 * Ofertas abertas -- o que a pessoa precisa aceitar ou recusar.
 */
function wallet_offers(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT w.*, o.public_code
           FROM wallet_credits w
           LEFT JOIN orders o ON o.id = w.order_id
          WHERE w.user_id = :id AND w.state = 'offered'
          ORDER BY w.created_at DESC"
    );
    $stmt->execute(['id' => $userId]);

    return $stmt->fetchAll();
}

/**
 * Gasta crédito num pedido, até o teto pedido.
 *
 * Decisão de contabilidade registrada, porque é o ponto que costuma ficar
 * errado: o custo do crédito JÁ FOI lançado no livro quando a oferta foi
 * aceita (refund_ledger, em lib/refunds.php) -- a plataforma ficou com o
 * dinheiro do cliente e com a dívida no passivo. Gastar, aqui, só consome
 * esse passivo. Lançar de novo agora contaria a mesma despesa duas vezes.
 *
 * Crédito maior que o pedido não se perde: a linha é consumida e o troco
 * vira uma linha nova, aceita, com a mesma validade. É o jeito de ter saldo
 * parcial sem uma coluna de saldo parcial -- a tabela continua sendo um
 * histórico de fatos ("este crédito foi gasto neste pedido") em vez de um
 * número que alguém edita.
 */
function wallet_spend(PDO $pdo, string $userId, int $orderId, float $limit): float
{
    if ($limit <= 0) {
        return 0.0;
    }

    $spent = 0.0;
    foreach (wallet_available($pdo, $userId) as $credit) {
        $room = round($limit - $spent, 2);
        if ($room <= 0) {
            break;
        }

        $value = round((float) $credit['amount'] + (float) $credit['bonus'], 2);
        $use = min($value, $room);

        $pdo->prepare(
            "UPDATE wallet_credits SET state = 'spent', order_id_spent = :order, amount = :amount, bonus = 0
              WHERE id = :id AND state = 'accepted'"
        )->execute(['order' => $orderId, 'amount' => $use, 'id' => $credit['id']]);

        $change = round($value - $use, 2);
        if ($change > 0) {
            $pdo->prepare(
                "INSERT INTO wallet_credits (user_id, refund_id, order_id, amount, bonus, state, expires_at, decided_by)
                 VALUES (:user_id, :refund_id, :order_id, :amount, 0, 'accepted', :expires, :by)"
            )->execute([
                'user_id' => $userId,
                'refund_id' => $credit['refund_id'],
                'order_id' => $credit['order_id'],
                'amount' => $change,
                'expires' => $credit['expires_at'],
                'by' => $credit['decided_by'],
            ]);
        }

        $spent = round($spent + $use, 2);
    }

    return $spent;
}

/**
 * Vence o que passou do prazo. Chamado na leitura da carteira -- não há job
 * pra isso, e crédito vencido que ainda aparece como saldo é pior que um
 * cron a menos.
 */
function wallet_expire_stale(PDO $pdo, string $userId): void
{
    $pdo->prepare(
        "UPDATE wallet_credits SET state = 'expired'
          WHERE user_id = :id AND state IN ('offered','accepted')
            AND expires_at IS NOT NULL AND expires_at <= now()"
    )->execute(['id' => $userId]);
}
