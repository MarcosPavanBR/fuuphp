<?php
declare(strict_types=1);

// Executor de reembolsos — a última ponta da tela 13.4.
//
// O console do admin DECIDE (valor, taxa, quem paga) e deixa a linha em
// 'sent'. Este arquivo é quem faz o dinheiro andar, e cada canal de
// `refunds.channel` anda de um jeito:
//
//   gateway       → cartão pelo Mercado Pago: chamada automática da API.
//   pix_return    → Pix AUTOMÁTICO (cobrado pelo MP): mesma API de estorno.
//                   Pix MANUAL caiu na chave da loja, não na nossa conta --
//                   ninguém aqui consegue devolver dinheiro que não está
//                   aqui. Esse fica pra confirmação humana, com o
//                   identificador do Pix de volta.
//   acquirer_void → maquininha: o cancelamento é na adquirente DA LOJA.
//                   Também confirmação humana.
//   wallet_credit / none → não há dinheiro a mover.
//
// Estados: 'sent' → 'done' (o gateway confirmou) ou 'failed' (esgotou as
// tentativas). Falha não é silenciosa: vira `last_error` e aparece na fila.

// Quantas vezes o executor tenta antes de desistir e marcar 'failed'. Três
// cobre a instabilidade normal de API; mais que isso é problema que precisa
// de gente olhando, não de retry.
const REFUND_MAX_ATTEMPTS = 3;

// Canais que o executor resolve sozinho. Os outros precisam de confirmação.
const REFUND_AUTOMATIC_METHODS = ['mp_card', 'pix_auto'];

/**
 * O reembolso pode ser executado automaticamente?
 *
 * Depende da forma de pagamento do pedido, não só do canal: `pix_return` é
 * automático quando o Pix foi cobrado pelo Mercado Pago (pix_auto) e manual
 * quando foi pra chave da loja (pix_manual).
 */
function refund_is_automatic(array $refund): bool
{
    return in_array((string) ($refund['payment_method'] ?? ''), REFUND_AUTOMATIC_METHODS, true)
        && in_array((string) $refund['channel'], ['gateway', 'pix_return'], true);
}

/**
 * Executa UM reembolso já decidido. Idempotente: só age em 'sent', e o
 * `refund_key` vai como chave de idempotência pro gateway.
 *
 * Devolve o estado final da linha. Nunca lança por falha do gateway -- a
 * falha é um resultado, gravado em `last_error`.
 */
function refund_execute(PDO $pdo, int $refundId): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT r.*, o.payment_method, p.provider, p.provider_ref AS payment_ref
               FROM refunds r
               JOIN orders o ON o.id = r.order_id
               LEFT JOIN payments p ON p.id = r.payment_id
              WHERE r.id = :id
              FOR UPDATE OF r SKIP LOCKED"
        );
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();

        // SKIP LOCKED: outra instância do executor já está nesta linha.
        // Não é erro -- é a trava funcionando.
        if ($refund === false || (string) $refund['state'] !== 'sent' || !refund_is_automatic($refund)) {
            $pdo->commit();

            return ['id' => $refundId, 'state' => $refund['state'] ?? 'unknown', 'skipped' => true];
        }

        try {
            if ($refund['payment_ref'] === null || $refund['payment_ref'] === '') {
                throw new RuntimeException('pagamento sem referência no gateway');
            }
            $result = mp_refund_payment(
                (string) $refund['payment_ref'],
                (float) $refund['amount'],
                (string) $refund['refund_key']
            );

            $pdo->prepare(
                "UPDATE refunds SET state = 'done', provider_ref = :ref, executed_at = now(),
                                    attempts = attempts + 1, last_error = NULL
                  WHERE id = :id"
            )->execute(['ref' => $result['provider_ref'], 'id' => $refundId]);
            $state = 'done';
        } catch (RuntimeException $e) {
            $attempts = (int) $refund['attempts'] + 1;
            $state = $attempts >= REFUND_MAX_ATTEMPTS ? 'failed' : 'sent';
            $pdo->prepare(
                'UPDATE refunds SET attempts = :n, last_error = :err, state = :state WHERE id = :id'
            )->execute([
                'n' => $attempts,
                'err' => mb_substr($e->getMessage(), 0, 500),
                'state' => $state,
                'id' => $refundId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['id' => $refundId, 'state' => $state, 'skipped' => false];
}

/**
 * Confirmação humana de um reembolso que não passa pela nossa API: o Pix
 * que a loja devolveu da chave dela, ou o cancelamento que ela fez na
 * adquirente. Exige a referência -- "devolvi" sem identificador é a palavra
 * de alguém, e reembolso se discute depois.
 */
function refund_confirm_manual(PDO $pdo, int $refundId, string $providerRef, string $actorId): array
{
    $stmt = $pdo->prepare(
        "UPDATE refunds
            SET state = 'done', provider_ref = :ref, executed_at = now(), decided_by = COALESCE(decided_by, :by)
          WHERE id = :id AND state IN ('sent','failed')
          RETURNING *"
    );
    $stmt->execute(['ref' => $providerRef, 'by' => $actorId, 'id' => $refundId]);
    $row = $stmt->fetch();

    return $row === false ? [] : $row;
}
