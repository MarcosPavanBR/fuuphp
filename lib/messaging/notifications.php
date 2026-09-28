<?php
declare(strict_types=1);

// Tela 7.2 — de onde as notificações nascem.
//
// "Três tipos que importam: aprovação, saiu para entrega e prazo acabando.
// Origem é a outbox, então nada se perde."
//
// Os textos são os do mock, com o dado real no lugar do exemplo:
//   "Pix confirmado 🎉 — A cozinha já começou o pedido #C71A04."
//   "Jonas saiu para entrega — Chega em torno de 20:35."
//   "Faltam 5 min para expirar — Envie o comprovante para não perder o pedido."
//
// A aprovação e a saída vêm da `outbox` (todo avanço de pedido grava lá,
// migração 004). O prazo não é um evento -- é o relógio andando --, então
// sai de uma varredura dos pedidos em Pix manual perto de vencer.

// "Faltam 5 min": a janela em que o aviso é útil. Mais cedo é ruído; mais
// tarde não dá tempo de achar o print.
const PROOF_DEADLINE_WARN_MINUTES = 5;

// A velocidade média da estimativa (DELIVERY_AVG_KMH) mora em lib/ordering/delivery.php.

/**
 * Lê a outbox pendente, gera as notificações dos tópicos que viram aviso e
 * marca cada linha como publicada. Este worker é O publicador da outbox:
 * tópico sem aviso ao cliente também é marcado, senão a fila crescia pra
 * sempre com eventos que ninguém mais lê.
 *
 * Devolve quantas notificações novas nasceram.
 */
function notifications_from_outbox(PDO $pdo, int $limit = 200): int
{
    $rows = $pdo->prepare(
        'SELECT * FROM outbox WHERE published_at IS NULL ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED'
    );

    $created = 0;
    $pdo->beginTransaction();
    try {
        $rows->bindValue('limit', $limit, PDO::PARAM_INT);
        $rows->execute();
        foreach ($rows->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true) ?: [];
            $orderId = (int) ($payload['order_id'] ?? 0);

            if ($orderId > 0 && in_array($row['topic'], ['order.paid', 'order.delivering'], true)) {
                $created += notification_for_order_event($pdo, (string) $row['topic'], $orderId, (int) $row['id']);
            }

            $pdo->prepare('UPDATE outbox SET published_at = now() WHERE id = :id')->execute(['id' => $row['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $created;
}

/** A notificação de um evento de pedido da outbox. */
function notification_for_order_event(PDO $pdo, string $topic, int $orderId, int $outboxId): int
{
    $stmt = $pdo->prepare(
        "SELECT o.*, cu.full_name AS courier_name,
                (2 * 6371 * asin(sqrt(
                  power(sin(radians(a.lat - r.lat) / 2), 2) +
                  cos(radians(r.lat)) * cos(radians(a.lat)) *
                  power(sin(radians(a.lng - r.lng) / 2), 2)))) AS distance_km
           FROM orders o
           JOIN restaurants r ON r.id = o.restaurant_id
           LEFT JOIN addresses a ON a.id = o.address_id
           LEFT JOIN couriers c ON c.id = o.courier_id
           LEFT JOIN users cu ON cu.id = c.user_id
          WHERE o.id = :id"
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    if ($order === false) {
        return 0;
    }
    $code = '#' . $order['public_code'];

    if ($topic === 'order.paid') {
        // "Pix confirmado 🎉" é o aviso da APROVAÇÃO: faz sentido quando
        // alguém teve de esperar (Pix). Cartão aprovado na hora, com a
        // pessoa olhando a tela, não precisa de push.
        $title = match ((string) $order['payment_method']) {
            'pix_manual', 'pix_auto' => 'Pix confirmado 🎉',
            default => null,
        };
        if ($title === null) {
            return 0;
        }

        return push_notify($pdo, (string) $order['user_id'], 'payment_approved', $title,
            "A cozinha já começou o pedido {$code}.", $orderId, $outboxId) === null ? 0 : 1;
    }

    // order.delivering. Retirada no balcão também passa por 'delivering', e
    // lá ninguém "saiu para entrega".
    if ($order['pickup_by_customer'] === true) {
        return 0;
    }
    $who = $order['courier_name'] !== null ? strtok((string) $order['courier_name'], ' ') : 'O entregador';
    $body = 'Seu pedido está a caminho.';
    if ($order['distance_km'] !== null) {
        $minutes = (int) ceil((float) $order['distance_km'] / DELIVERY_AVG_KMH * 60) + 3;
        // Hora de parede de quem lê: o fuso da cidade da loja (quem pede está
        // na mesma cidade), não o do servidor.
        $eta = (new DateTimeImmutable('now', store_timezone($pdo, (string) $order['restaurant_id'])))->modify("+{$minutes} minutes");
        $body = 'Chega em torno de ' . $eta->format('H:i') . '.';
    }

    return push_notify($pdo, (string) $order['user_id'], 'out_for_delivery', "{$who} saiu para entrega", $body, $orderId, $outboxId) === null ? 0 : 1;
}

/**
 * "Faltam 5 min para expirar": pedidos em Pix manual ainda sem comprovante,
 * com o prazo (orders.verification_deadline, 15 min desde o pagamento)
 * vencendo nos próximos minutos. Um aviso por pedido -- o índice único
 * `notifications_deadline_idx` garante.
 */
function notifications_proof_deadlines(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "SELECT id, user_id, public_code FROM orders
          WHERE status = 'pending_payment' AND payment_method = 'pix_manual'
            AND verification_deadline > now()
            AND verification_deadline <= now() + make_interval(mins => :warn)"
    );
    $stmt->execute(['warn' => PROOF_DEADLINE_WARN_MINUTES]);

    $created = 0;
    foreach ($stmt->fetchAll() as $order) {
        $result = push_notify($pdo, (string) $order['user_id'], 'proof_deadline',
            'Faltam ' . PROOF_DEADLINE_WARN_MINUTES . ' min para expirar',
            'Envie o comprovante para não perder o pedido #' . $order['public_code'] . '.',
            (int) $order['id'], null);
        if ($result !== null) {
            $created++;
        }
    }

    return $created;
}
