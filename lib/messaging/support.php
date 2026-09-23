<?php
declare(strict_types=1);

// Tela 14.1 — central de ajuda.
//
// "Os quatro atalhos cobrem a maior parte dos tickets reais de delivery —
// cada um abre um fluxo automático antes de chamar gente."
//
// O fluxo automático é o que está aqui: cada atalho responde com o que o
// banco JÁ sabe sobre os pedidos daquela pessoa. Só depois disso é que
// aparece a opção de abrir ticket -- e quando a resposta automática resolve,
// o ticket nem chega a existir.

// SLA por categoria (chip da tela). A especificação não fixa os prazos;
// estes são a escolha registrada, e o critério é o custo de esperar:
// pedido atrasado e Pix não confirmado são comida esfriando e dinheiro
// parado, então são minutos; estorno depende de terceiro (banco,
// adquirente), então é dia útil.
const SUPPORT_SLA_MINUTES = [
    'late' => 15,
    'pix_pending' => 15,
    'wrong_item' => 30,
    'refund' => 24 * 60,
    'other' => 4 * 60,
];

const SUPPORT_TOPICS = [
    ['code' => 'late', 'label' => 'Meu pedido está atrasado', 'icon' => 'bi-clock-history'],
    ['code' => 'wrong_item', 'label' => 'Veio item errado ou faltando', 'icon' => 'bi-bag-x'],
    ['code' => 'refund', 'label' => 'Onde está meu estorno', 'icon' => 'bi-arrow-counterclockwise'],
    ['code' => 'pix_pending', 'label' => 'Paguei o Pix e não confirmou', 'icon' => 'bi-qr-code'],
];

/**
 * O pedido que a ajuda deve assumir como assunto: o que ainda está em
 * andamento; na falta dele, o último que chegou ao fim.
 *
 * É o que evita o "qual o número do pedido?" que a Fase 14 existe pra
 * matar -- quem abre a ajuda quase sempre está falando do pedido de agora.
 */
function support_subject_order(PDO $pdo, string $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT o.*, r.name AS restaurant_name
           FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
          WHERE o.user_id = :uid AND o.status <> 'cart'
          ORDER BY (o.status IN ('pending_payment','pending_verification','paid','preparing','ready','delivering')) DESC,
                   o.created_at DESC
          LIMIT 1"
    );
    $stmt->execute(['uid' => $userId]);
    $order = $stmt->fetch();

    return $order === false ? null : $order;
}

/**
 * O pedido ainda está andando (não entregue, cancelado nem recusado)?
 */
function support_in_progress(array $order): bool
{
    return in_array(
        (string) $order['status'],
        ['pending_payment', 'pending_verification', 'paid', 'preparing', 'ready', 'delivering'],
        true
    );
}

/**
 * A resposta automática de um atalho, calculada dos dados reais do pedido.
 *
 * Devolve sempre: o texto, se aquilo já resolve (`resolved`) e se ainda faz
 * sentido abrir ticket (`can_open_ticket`). Nenhum dos textos promete prazo
 * que o sistema não cumpre nem inventa estado que não está no banco.
 */
function support_auto_answer(PDO $pdo, string $topic, ?array $order): array
{
    if ($order === null) {
        return [
            'title' => 'Não achei um pedido seu',
            'body' => 'Essa ajuda parte do seu último pedido, e ainda não há nenhum. Se você fez um pedido e ele não aparece aqui, fale com a gente.',
            'resolved' => false,
            'can_open_ticket' => true,
        ];
    }

    $status = (string) $order['status'];
    $code = (string) $order['public_code'];

    return match ($topic) {
        'late' => support_answer_late($pdo, $order, $status, $code),
        'pix_pending' => support_answer_pix($pdo, $order, $status, $code),
        'refund' => support_answer_refund($pdo, $order, $code),
        'wrong_item' => [
            // Item errado é o único dos quatro que o banco não tem como
            // verificar sozinho: só quem abriu a sacola sabe. O fluxo
            // automático aqui é dizer o que vai acontecer, não fingir que
            // conferiu.
            'title' => 'Conta o que faltou',
            'body' => $status === 'delivered'
                ? "O pedido #{$code} consta como entregue. Diga o que veio errado ou faltando e a loja responde na conversa do pedido — se a gente confirmar, o estorno da diferença sai sem você precisar cobrar."
                : "O pedido #{$code} ainda não consta como entregue. Se já chegou e veio algo errado, abra o chamado que a gente confere com a loja.",
            'resolved' => false,
            'can_open_ticket' => true,
        ],
        default => [
            'title' => 'Fala com a gente',
            'body' => 'Conta o que aconteceu que alguém responde.',
            'resolved' => false,
            'can_open_ticket' => true,
        ],
    };
}

/**
 * Resposta automática da ajuda (14.1) pra "meu pedido está atrasado", pelo
 * status real: sem entregador manda pra tela 15.1, em rota manda pro chat,
 * na cozinha mostra o tempo de preparo em vigor.
 */
function support_answer_late(PDO $pdo, array $order, string $status, string $code): array
{
    if ($status === 'ready' && $order['courier_id'] === null && $order['no_courier_since'] !== null) {
        // Tela 15.1 existe exatamente pra isso, e tem saídas concretas --
        // mandar pra lá resolve mais rápido que qualquer atendente.
        return [
            'title' => 'Sua comida está pronta, o entregador é que não apareceu',
            'body' => "O #{$code} está pronto e ninguém aceitou a corrida ainda. Na tela do pedido você pode turbinar o frete, retirar na loja ou cancelar recebendo tudo de volta — e, passado o prazo, a gente cancela sozinho e devolve integral.",
            'resolved' => true,
            'can_open_ticket' => true,
            'go_to_order' => true,
        ];
    }

    if ($status === 'delivering') {
        return [
            'title' => 'Já saiu para entrega',
            'body' => "O #{$code} está com o entregador. Dá pra falar com ele e com a loja na conversa do pedido — é mais rápido que abrir chamado.",
            'resolved' => true,
            'can_open_ticket' => true,
            'go_to_order' => true,
        ];
    }

    if (in_array($status, ['paid', 'preparing'], true)) {
        $prep = effective_prep_minutes($pdo, (string) $order['restaurant_id']);
        $extra = $prep['bumped'] ? ' A cozinha está com fila, e o tempo informado já subiu por causa disso.' : '';

        return [
            'title' => 'A cozinha ainda está com ele',
            'body' => "O #{$code} está em preparo. A loja informa {$prep['effective']} min de preparo agora.{$extra} Se passar muito disso, abre o chamado que a gente cobra.",
            'resolved' => false,
            'can_open_ticket' => true,
            'go_to_order' => true,
        ];
    }

    if ($status === 'delivered') {
        return [
            'title' => 'Esse pedido já foi entregue',
            'body' => "O #{$code} consta como entregue. Se a entrega demorou demais, conta pra gente — atraso entra na avaliação da loja.",
            'resolved' => false,
            'can_open_ticket' => true,
        ];
    }

    return [
        'title' => 'O pedido ainda não começou',
        'body' => "O #{$code} está em \"" . $status . "\". Enquanto o pagamento não fecha, a cozinha não começa.",
        'resolved' => false,
        'can_open_ticket' => true,
    ];
}

/**
 * Resposta automática pra "e o meu Pix?": comprovante na fila da loja (com
 * o prazo que falta), comprovante ainda não enviado, ou pagamento já aprovado.
 */
function support_answer_pix(PDO $pdo, array $order, string $status, string $code): array
{
    $proofStmt = $pdo->prepare(
        'SELECT state, created_at FROM payment_proofs WHERE order_id = :id ORDER BY id DESC LIMIT 1'
    );
    $proofStmt->execute(['id' => $order['id']]);
    $proof = $proofStmt->fetch();

    if ($status === 'pending_verification' && $proof !== false) {
        $deadline = $order['verification_deadline'];
        $left = $deadline === null ? null : max(0, (int) ceil((strtotime((string) $deadline) - time()) / 60));

        return [
            'title' => 'Seu comprovante está na fila da loja',
            'body' => $left === null
                ? "O comprovante do #{$code} chegou e está esperando a loja conferir."
                : "O comprovante do #{$code} chegou e está esperando a loja conferir — faltam {$left} min do prazo. Se ela não confirmar até lá, o pedido é recusado automaticamente e nada é cobrado.",
            'resolved' => true,
            'can_open_ticket' => true,
            'go_to_order' => true,
        ];
    }

    if ($status === 'pending_payment' && (string) $order['payment_method'] === 'pix_manual') {
        return [
            'title' => 'Falta enviar o comprovante',
            'body' => "O #{$code} está esperando o comprovante do Pix. Abra o pedido e envie a imagem — é ela que manda o pedido pra fila da loja.",
            'resolved' => true,
            'can_open_ticket' => true,
            'go_to_order' => true,
        ];
    }

    if (in_array($status, ['paid', 'preparing', 'ready', 'delivering', 'delivered'], true)) {
        return [
            'title' => 'Seu Pix já foi confirmado',
            'body' => "O pagamento do #{$code} está confirmado. Se o valor saiu duas vezes da sua conta, abre o chamado com o comprovante que a gente devolve.",
            'resolved' => true,
            'can_open_ticket' => true,
        ];
    }

    return [
        'title' => 'Não achei Pix esperando confirmação',
        'body' => "O #{$code} não está esperando confirmação de Pix. Se você pagou e o pedido não avançou, conta pra gente.",
        'resolved' => false,
        'can_open_ticket' => true,
    ];
}

/**
 * Resposta automática pra "onde está meu estorno?": o último estorno do
 * CLIENTE (não só do pedido em foco), com valor, caminho e prazo do meio de
 * pagamento.
 */
function support_answer_refund(PDO $pdo, array $order, string $code): array
{
    // Estorno é do CLIENTE, não do pedido em foco: quem pergunta "onde está
    // meu estorno" pode estar falando de um pedido de semana passada.
    $stmt = $pdo->prepare(
        'SELECT r.*, o.public_code
           FROM refunds r JOIN orders o ON o.id = r.order_id
          WHERE o.user_id = :uid
          ORDER BY r.created_at DESC LIMIT 1'
    );
    $stmt->execute(['uid' => $order['user_id']]);
    $refund = $stmt->fetch();

    if ($refund === false) {
        return [
            'title' => 'Não há estorno em andamento',
            'body' => 'Nenhum pedido seu gerou devolução até agora. Se você cancelou algo e acha que deveria ter estorno, conta pra gente.',
            'resolved' => true,
            'can_open_ticket' => true,
        ];
    }

    $route = REFUND_ROUTES[(string) ($order['payment_method'] ?? '')] ?? null;
    $eta = $route['eta'] ?? '—';
    $how = $route['how'] ?? 'pelo mesmo caminho do pagamento';
    $amount = number_format((float) $refund['amount'], 2, ',', '.');
    $when = date('d/m', strtotime((string) $refund['created_at']));

    return [
        'title' => "R$ {$amount} a caminho de volta",
        'body' => "O estorno do #{$refund['public_code']} foi registrado em {$when}. {$how} — prazo de {$eta}. O prazo é do banco/adquirente, não nosso: depois que a gente manda, não há o que acelerar deste lado.",
        'resolved' => true,
        'can_open_ticket' => true,
    ];
}

/**
 * Código legível do ticket ("#T-8841" no mock). Sequencial não serve: o
 * cliente leria quantos chamados a plataforma inteira já teve.
 */
function support_ticket_code(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $code = 'T-' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT 1 FROM tickets WHERE code = :code');
        $stmt->execute(['code' => $code]);
        if ($stmt->fetch() === false) {
            return $code;
        }
    }

    // Cinco colisões seguidas em 9.000 códigos é sinal de tabela cheia, não
    // de azar: aí o código ganha um dígito em vez de tentar pra sempre.
    return 'T-' . random_int(10000, 99999);
}
