<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 14.1 — a central de ajuda inteira numa chamada.
//
// "Ajuda começa no pedido em andamento, não numa lista de perguntas."
// Por isso o primeiro bloco da resposta é o pedido de agora, e não uma
// FAQ: quem abre a ajuda quase sempre está falando dele.

require_method('GET');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'A central de ajuda é do cliente.');
}

$pdo = db();
$userId = (string) $claims['sub'];

$order = support_subject_order($pdo, $userId);
$inProgress = $order !== null && support_in_progress($order);

$subject = null;
if ($order !== null) {
    $subject = [
        'id' => $order['id'],
        'public_code' => $order['public_code'],
        'status' => $order['status'],
        'restaurant_name' => $order['restaurant_name'],
        'total' => $order['total'],
        'created_at' => $order['created_at'],
        'in_progress' => $inProgress,
    ];
}

// "Seus atendimentos": os chamados desta pessoa, com o resultado quando já
// houve um.
$ticketStmt = $pdo->prepare(
    'SELECT t.id, t.code, t.category, t.state, t.sla_due_at, t.created_at,
            o.public_code AS order_code
       FROM tickets t LEFT JOIN orders o ON o.id = t.order_id
      WHERE t.user_id = :uid
      ORDER BY t.created_at DESC LIMIT 10'
);
$ticketStmt->execute(['uid' => $userId]);

// "Tempo médio de resposta agora". Sai das mensagens reais: quanto tempo,
// em média, a loja (ou o suporte) levou pra responder a primeira mensagem
// do cliente nos últimos sete dias -- e é da plataforma inteira, porque é
// isso que a frase promete a quem ainda não escreveu. Sem conversa nenhuma
// no período não há média, e aí a tela não mostra número inventado.
//
// Em segundos, não em minutos: arredondar pra minuto transformava resposta
// rápida em "0 min", que se lê como "ninguém responde".
$replyStmt = $pdo->query(
    "SELECT round(avg(EXTRACT(epoch FROM (reply.created_at - ask.created_at))))::int
       FROM order_messages ask
       JOIN LATERAL (
         SELECT m.created_at FROM order_messages m
          WHERE m.order_id = ask.order_id
            AND m.created_at > ask.created_at
            AND m.sender_role IN ('store','support','courier')
          ORDER BY m.created_at LIMIT 1
       ) reply ON true
      WHERE ask.sender_role = 'customer'
        AND ask.created_at >= now() - interval '7 days'"
);
$avgReply = $replyStmt->fetchColumn();

$topics = [];
foreach (SUPPORT_TOPICS as $topic) {
    $topics[] = $topic + ['sla_minutes' => SUPPORT_SLA_MINUTES[$topic['code']]];
}

json_response(200, [
    'subject_order' => $subject,
    'topics' => $topics,
    'tickets' => $ticketStmt->fetchAll(),
    'avg_reply_seconds' => $avgReply === false || $avgReply === null ? null : (int) $avgReply,
]);
