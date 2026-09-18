<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 14.2 — "Chat do pedido (três pontas)". GET lista, POST manda.
//
// "Eventos do sistema entram na mesma linha do tempo": as mensagens vêm
// misturadas com os `order_events` do pedido, ordenadas por horário. Isso não
// é enfeite -- é o que faz "Saiu para entrega às 20:29" aparecer entre duas
// falas, que é como a conversa realmente aconteceu.
//
// "O chat fecha 2 h após a entrega": regra de API, histórico permanece
// (comentário da própria migração 008). Ler continua valendo pra sempre --
// prova de disputa não pode sumir; o que fecha é escrever.

$claims = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$orderId = (int) ($_GET['id'] ?? 0);
if ($method === 'POST') {
    $body = read_json_body();
    $orderId = (int) ($body['order_id'] ?? 0);
}
if ($orderId <= 0) {
    error_response(422, 'id_required', 'Informe o pedido.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

// Quem está na conversa: as três pontas do pedido e o suporte. A checagem é
// por vínculo com ESTE pedido, não por papel -- uma loja não entra no chat
// do pedido da loja vizinha.
$role = (string) ($claims['role'] ?? '');
$senderRole = match (true) {
    $role === 'customer' && $order['user_id'] === $claims['sub'] => 'customer',
    $role === 'restaurant_staff' && ($claims['restaurant_id'] ?? null) === $order['restaurant_id'] => 'store',
    $role === 'courier' && ($claims['courier_id'] ?? null) === $order['courier_id'] => 'courier',
    $role === 'support' || $role === 'admin' => 'support',
    default => null,
};
if ($senderRole === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

// Duas horas depois da entrega o chat vira somente-leitura. `delivered_at` não
// existe como coluna: a hora da entrega é o evento que registrou a transição.
$closedStmt = $pdo->prepare(
    "SELECT max(created_at) < now() - interval '2 hours'
     FROM order_events WHERE order_id = :id AND to_status = 'delivered'"
);
$closedStmt->execute(['id' => $orderId]);
$closed = $closedStmt->fetchColumn() === true;

if ($method === 'POST') {
    if ($closed) {
        error_response(409, 'chat_closed', 'Este chat fechou 2 h depois da entrega. Abra um chamado no suporte.');
    }
    $text = trim((string) ($body['body'] ?? ''));
    if ($text === '') {
        error_response(422, 'body_required', 'Escreva alguma coisa.', fields: ['body' => 'obrigatório']);
    }
    if (mb_strlen($text) > 1000) {
        error_response(422, 'body_too_long', 'Mensagem longa demais (máx. 1000 caracteres).', fields: ['body' => 'inválido']);
    }

    $insert = $pdo->prepare(
        'INSERT INTO order_messages (order_id, sender_id, sender_role, body)
         VALUES (:order_id, :sender_id, :sender_role, :body) RETURNING *'
    );
    $insert->execute([
        'order_id' => $orderId,
        'sender_id' => $claims['sub'],
        'sender_role' => $senderRole,
        'body' => $text,
    ]);

    json_response(201, ['message' => $insert->fetch()]);
}

require_method('GET');

// Marca como lida o que a outra ponta mandou -- read_at existe na tabela e
// alimenta o "lida" da tela.
$pdo->prepare(
    'UPDATE order_messages SET read_at = now()
     WHERE order_id = :id AND sender_role <> :role AND read_at IS NULL'
)->execute(['id' => $orderId, 'role' => $senderRole]);

$msgStmt = $pdo->prepare(
    'SELECT m.id, m.sender_role, m.body, m.read_at, m.created_at, u.full_name AS sender_name
     FROM order_messages m LEFT JOIN users u ON u.id = m.sender_id
     WHERE m.order_id = :id ORDER BY m.created_at'
);
$msgStmt->execute(['id' => $orderId]);

// Os eventos do pedido entram na mesma linha do tempo, como a tela pede.
$evStmt = $pdo->prepare(
    'SELECT to_status, created_at FROM order_events
     WHERE order_id = :id AND from_status IS DISTINCT FROM to_status
     ORDER BY created_at'
);
$evStmt->execute(['id' => $orderId]);

json_response(200, [
    'messages' => $msgStmt->fetchAll(),
    'events' => $evStmt->fetchAll(),
    'me' => $senderRole,
    'closed' => $closed,
    // Respostas rápidas "evitam digitar de moto": a lista depende de quem
    // está falando, porque as frases úteis não são as mesmas.
    'quick_replies' => match ($senderRole) {
        'customer' => ['Já desço', 'Deixe na portaria', 'Pode subir', 'Obrigado!'],
        'store' => ['Saindo em 5 min', 'Acabou um item, posso trocar?', 'Pedido pronto'],
        'courier' => ['Estou chegando', 'Estou no portão', 'Qual o interfone?'],
        default => [],
    },
]);
