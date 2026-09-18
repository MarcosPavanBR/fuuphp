<?php
declare(strict_types=1);

// Operação da loja (Fase 11.2 a 11.4).

// "Aumentar sozinho quando a fila passar de 8 pedidos" (tela 11.2). O número
// 8 é do mock; o acréscimo não está escrito em lugar nenhum, então é o menor
// valor que muda alguma coisa pra quem lê: dez minutos.
const PREP_QUEUE_THRESHOLD = 8;
const PREP_BUMP_MINUTES = 10;

/**
 * Tempo de preparo que o cliente vê agora.
 *
 * O acréscimo automático é calculado na hora de ler, nunca gravado por cima
 * de `restaurants.prep_minutes`: se fosse gravado, a fila esvaziaria e o
 * número combinado pela loja teria sumido -- e ninguém saberia qual era o
 * valor "normal" pra voltar.
 */
function effective_prep_minutes(PDO $pdo, string $restaurantId, ?array $store = null): array
{
    if ($store === null) {
        $stmt = $pdo->prepare('SELECT prep_minutes, prep_auto_bump FROM restaurants WHERE id = :id');
        $stmt->execute(['id' => $restaurantId]);
        $store = $stmt->fetch();
        if ($store === false) {
            return ['base' => 30, 'effective' => 30, 'bumped' => false, 'queue' => 0];
        }
    }

    $queueStmt = $pdo->prepare(
        "SELECT count(*) FROM orders
          WHERE restaurant_id = :id AND status IN ('paid','preparing')"
    );
    $queueStmt->execute(['id' => $restaurantId]);
    $queue = (int) $queueStmt->fetchColumn();

    $base = (int) $store['prep_minutes'];
    $bumped = $store['prep_auto_bump'] === true && $queue > PREP_QUEUE_THRESHOLD;

    return [
        'base' => $base,
        'effective' => $bumped ? $base + PREP_BUMP_MINUTES : $base,
        'bumped' => $bumped,
        'queue' => $queue,
    ];
}
