<?php
declare(strict_types=1);

// Volta única para mudar status (Especificação, Parte II §9): nenhum
// "UPDATE orders SET status" fora daqui, nem no PHP. Toda transição passa
// pela função do banco, que é quem decide o que é legal.
/**
 * Teto de sanidade da gorjeta, no pedido e na avaliação: R$ 5.000 por engano
 * de digitação é estorno e suporte. As telas oferecem R$ 2, 5 e 10.
 */
const TIP_MAX = 200.0;

/** Gorjeta válida: número finito entre zero e TIP_MAX. */
function is_valid_tip(float $tip): bool
{
    return is_finite($tip) && $tip >= 0 && $tip <= TIP_MAX;
}

function call_advance_order(PDO $pdo, int $orderId, string $to, ?string $actorId, string $actorKind, array $meta = []): void
{
    try {
        $stmt = $pdo->prepare('SELECT advance_order(:id, :to, :actor, :kind, :meta::jsonb)');
        $stmt->execute([
            'id' => $orderId,
            'to' => $to,
            'actor' => $actorId,
            'kind' => $actorKind,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'transicao ilegal')) {
            error_response(409, 'illegal_transition', 'Esse pedido não pode mudar para esse status agora.', detail: $e->getMessage());
        }
        if (str_contains($e->getMessage(), 'inexistente')) {
            error_response(404, 'order_not_found', 'Pedido não encontrado.');
        }
        throw $e;
    }
}

/**
 * O pedido pelo id, ou null. Não confere dono: quem chama usa
 * authorize_order_access() antes de devolver qualquer coisa.
 */
function fetch_order(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    return $order === false ? null : $order;
}

/**
 * As linhas do pedido, na ordem em que entraram no carrinho.
 */
function fetch_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute(['id' => $orderId]);
    return $stmt->fetchAll();
}

/**
 * Linha do tempo do pedido (Fase 5.3, "cada etapa é uma linha de
 * order_events, então o histórico é auditável"). Vem de advance_order() --
 * nenhum evento é inventado aqui, só lido.
 */
function fetch_order_events(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM order_events WHERE order_id = :id ORDER BY created_at, id');
    $stmt->execute(['id' => $orderId]);
    return $stmt->fetchAll();
}

/**
 * Garante que quem está pedindo pode ver/mexer nesse pedido: cliente só o
 * próprio, loja só o seu restaurante. Encerra a requisição com 403/404
 * quando não pode.
 */
function authorize_order_access(array $order, array $claims): void
{
    $role = $claims['role'] ?? null;
    if ($role === 'customer') {
        if ($order['user_id'] !== $claims['sub']) {
            error_response(404, 'order_not_found', 'Pedido não encontrado.');
        }
        return;
    }
    if ($role === 'restaurant_staff') {
        if (($claims['restaurant_id'] ?? null) !== $order['restaurant_id']) {
            error_response(404, 'order_not_found', 'Pedido não encontrado.');
        }
        return;
    }
    error_response(403, 'forbidden', 'Esse papel não acessa pedidos por aqui.');
}
