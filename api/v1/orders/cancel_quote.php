<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 13.1 — "Taxa e valor do estorno na mesma tela, antes de confirmar —
// nada de descobrir depois."
//
// Só lê. Devolve exatamente o que a tela precisa mostrar antes do botão
// vermelho: se ainda dá pra cancelar, se sai de graça, quanto foi pago,
// quanto é a taxa, quanto volta, por onde volta e em quanto tempo.
//
// Quem cancela de fato é orders/status.php (a porta única), que recalcula o
// mesmo plano na hora -- esta rota não reserva nem congela nada, e se o
// pedido avançar entre ver a tela e confirmar, quem manda é o estado novo.

require_method('GET');
$claims = require_auth();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'id_required', 'Informe id do pedido.', fields: ['id' => 'obrigatório']);
}

$pdo = db();
$order = fetch_order($pdo, $orderId);
if ($order === null) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}
authorize_order_access($order, $claims);

// A legalidade de verdade é a do banco (advance_order); aqui só se sabe se
// vale a pena mostrar o botão. 'ready' fica de fora de propósito: a comida
// está na bancada esperando o entregador, e a função do banco não permite
// essa transição.
$cancellable = ['pending_payment', 'pending_verification', 'paid', 'preparing', 'delivering'];
$canCancel = in_array((string) $order['status'], $cancellable, true);

// A mesma rota serve as duas telas: 13.1 (cliente cancela) e 13.2 (loja
// recusa pedido já aceito). A causa sai do papel de quem perguntou, igual ao
// que status.php faz na hora de gravar -- é ela que zera a taxa quando quem
// desiste é a loja ("recusar tem custo", e o custo não é do cliente).
$isStore = ($claims['role'] ?? null) === 'restaurant_staff';
$cause = $isStore ? 'store_reject' : 'customer_cancel';
$plan = refund_plan($order, policy_for_order($pdo, $order), $cause);

$restaurantStmt = $pdo->prepare('SELECT name FROM restaurants WHERE id = :id');
$restaurantStmt->execute(['id' => $order['restaurant_id']]);

// Motivos fechados dos dois lados: do cliente porque "alimenta o ranking da
// loja", da loja porque é o texto que o cliente lê. Texto livre não agrega
// nem informa.
$customerReasons = [
    ['code' => 'late', 'label' => 'Demora acima do previsto'],
    ['code' => 'mistake', 'label' => 'Pedi por engano'],
    ['code' => 'wrong_address', 'label' => 'Endereço errado'],
    ['code' => 'changed_mind', 'label' => 'Não quero mais'],
];
$storeReasons = [
    ['code' => 'out_of_stock', 'label' => 'Item acabou agora'],
    ['code' => 'no_capacity', 'label' => 'Cozinha sem capacidade'],
    ['code' => 'out_of_area', 'label' => 'Endereço fora da área'],
    ['code' => 'suspected_prank', 'label' => 'Suspeita de trote'],
];

$response = [
    'can_cancel' => $canCancel,
    'order' => [
        'id' => $order['id'],
        'public_code' => $order['public_code'],
        'status' => $order['status'],
        'total' => $order['total'],
        'payment_method' => $order['payment_method'],
        'restaurant_name' => $restaurantStmt->fetchColumn(),
    ],
    'quote' => $plan,
    'reasons' => $isStore ? $storeReasons : $customerReasons,
];

// Tela 13.2 — "Recusar tem custo e a tela mostra qual." A taxa de recusa é
// calculada dos pedidos reais desta loja (não um número de exemplo); o
// "depois" simula este pedido já recusado, que é a pergunta que a pessoa tem
// na cabeça antes de apertar o botão.
if ($isStore) {
    // "Recusa" aqui é o ATO da loja, não o status final: um pedido já em
    // preparo não pode ir pra 'rejected' (advance_order não permite), vai pra
    // 'cancelled'. Contar por status deixaria a taxa de recusa presa em zero
    // justamente nas recusas que mais doem. Quem sabe quem desfez é
    // order_events.actor_kind.
    $rateStmt = $pdo->prepare(
        "SELECT count(*) FILTER (WHERE o.status NOT IN ('cart','pending_payment')) AS total,
                count(*) FILTER (WHERE EXISTS (
                  SELECT 1 FROM order_events ev
                   WHERE ev.order_id = o.id AND ev.actor_kind = 'store'
                     AND ev.to_status IN ('rejected','cancelled')
                )) AS rejected
         FROM orders o WHERE o.restaurant_id = :id"
    );
    $rateStmt->execute(['id' => $order['restaurant_id']]);
    $row = $rateStmt->fetch();
    $total = max(1, (int) $row['total']);
    $rejected = (int) $row['rejected'];

    $response['store_impact'] = [
        'reject_rate' => round($rejected * 100 / $total, 1),
        'reject_rate_after' => round(($rejected + 1) * 100 / $total, 1),
        'courier_assigned' => $order['courier_id'] !== null,
    ];
}

json_response(200, $response);
