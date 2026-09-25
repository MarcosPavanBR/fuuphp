<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.4 — "Painel da loja: configurar formas de pagamento".
//
// "O restaurante decide o que aceitar: pode ficar só no online (mais seguro
// e sem caixa) ou abrir dinheiro e maquininha para ganhar pedidos. Cada
// opção mostra a consequência operacional, não só um interruptor."
//
// `restaurant_payment_settings` existe desde a migração 003 e o checkout já
// lia dela (é ela que barra método que a loja não aceita). O que não existia
// era a loja poder MUDAR: os valores entravam por INSERT manual no banco.
//
// A trava da tela — "Sua praça permite todas as formas acima. Lojas com
// atraso de repasse ficam limitadas a pagamento online até regularizar" — é
// server-side e tem duas camadas:
//   1. `platform_policies.enabled_methods`: o que a plataforma permite.
//   2. `restaurants.online_only_until`: loja em atraso fica só no online,
//      e nem o dono dela pode religar dinheiro antes de regularizar.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

// Cada método com a consequência operacional escrita — é o que a tela mostra
// ao lado do interruptor, e é o motivo de ela existir.
const METHOD_CONSEQUENCE = [
    'mp_card' => [
        'label' => 'Cartão na entrega (Mercado Pago)',
        'note' => 'Aprovação imediata · dinheiro na sua conta em D+1 · sem risco de caixa',
        'recommended' => true,
    ],
    'pix_auto' => [
        'label' => 'Pix automático (online)',
        'note' => 'Cobrança com confirmação automática · nada de conferir comprovante',
        'recommended' => true,
    ],
    'pix_manual' => [
        'label' => 'Pix manual com comprovante',
        'note' => 'Cliente paga na sua chave e envia foto · alguém precisa validar em 15 min',
        'recommended' => false,
    ],
    'cash' => [
        'label' => 'Dinheiro na entrega',
        'note' => 'O entregador devolve o valor integral no seu caixa, com código de baixa',
        'recommended' => false,
    ],
    'pos_machine' => [
        'label' => 'Maquininha da loja na entrega',
        'note' => 'A máquina é sua: o entregador leva, cobra e devolve no mesmo turno',
        'recommended' => false,
    ],
];

// A política CRUA, não o snapshot de `resolve_policy()`: aquele já cruza
// `enabled_methods` com o que a loja aceita hoje, e aqui a pergunta é
// justamente o contrário -- o que a plataforma permitiria ligar.
$policy = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
if ($policy === false) {
    error_response(500, 'no_policy', 'Nenhuma política de plataforma cadastrada.');
}
$storeStmt = $pdo->prepare('SELECT online_only_until FROM restaurants WHERE id = :id');
$storeStmt->execute(['id' => $restaurantId]);
$onlineOnlyUntil = $storeStmt->fetchColumn();
$onlineOnly = $onlineOnlyUntil !== null && $onlineOnlyUntil !== false
    && strtotime((string) $onlineOnlyUntil) > time();

function settings_payload(PDO $pdo, string $restaurantId, array $policy, bool $onlineOnly, $onlineOnlyUntil): array
{
    $stmt = $pdo->prepare('SELECT * FROM restaurant_payment_settings WHERE restaurant_id = :id');
    $stmt->execute(['id' => $restaurantId]);
    $settings = $stmt->fetch();

    $devices = $pdo->prepare(
        "SELECT d.*,
                c.id AS custody_id, c.taken_at, c.due_at, c.returned_at,
                u.full_name AS holder_name,
                -- Fora do balcão até a loja CONFIRMAR, não até ele marcar
                -- devolvi: é esta linha que mostra o botão de confirmar.
                (c.id IS NOT NULL AND c.confirmed_by IS NULL) AS out_with_courier,
                (c.id IS NOT NULL AND c.returned_at IS NULL AND c.due_at < now()) AS overdue
           FROM pos_devices d
           LEFT JOIN LATERAL (
                SELECT * FROM pos_custody pc
                 WHERE pc.device_id = d.id
                 ORDER BY pc.taken_at DESC LIMIT 1
           ) c ON true
           LEFT JOIN couriers co ON co.id = c.courier_id
           LEFT JOIN users u ON u.id = co.user_id
          WHERE d.restaurant_id = :id
          ORDER BY d.label"
    );
    $devices->execute(['id' => $restaurantId]);

    $allowed = pg_text_array_to_php((string) $policy['enabled_methods']);

    return [
        'settings' => $settings === false ? null : $settings,
        'methods' => METHOD_CONSEQUENCE,
        // O que a praça permite, e o que a loja pode ligar AGORA (que é
        // menos, quando ela está em atraso).
        'allowed_by_platform' => $allowed,
        'selectable' => $onlineOnly
            ? array_values(array_intersect($allowed, ONLINE_PAYMENT_METHODS))
            : array_values($allowed),
        'online_only' => $onlineOnly,
        'online_only_until' => $onlineOnlyUntil === false ? null : $onlineOnlyUntil,
        'devices' => $devices->fetchAll(),
        'pos_return_deadline' => $policy['pos_return_deadline'] ?? null,
        'cash_ceiling' => $policy['cash_ceiling'] ?? null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(200, settings_payload($pdo, $restaurantId, $policy, $onlineOnly, $onlineOnlyUntil));
}

require_method('POST');
$body = read_json_body();

$methods = $body['methods'] ?? null;
if (!is_array($methods) || $methods === []) {
    error_response(422, 'methods_required', 'Escolha ao menos uma forma de pagamento.', fields: ['methods' => 'obrigatório']);
}
// Lista de códigos (texto): lista dentro da lista dava TypeError (500).
foreach ($methods as $method) {
    if (!is_string($method)) {
        error_response(422, 'unknown_method', 'Forma de pagamento desconhecida.', fields: ['methods' => 'inválido']);
    }
}
$methods = array_values(array_unique($methods));

$allowed = pg_text_array_to_php((string) $policy['enabled_methods']);
$selectable = $onlineOnly ? array_intersect($allowed, ONLINE_PAYMENT_METHODS) : $allowed;

foreach ($methods as $method) {
    if (!array_key_exists($method, METHOD_CONSEQUENCE)) {
        error_response(422, 'unknown_method', 'Forma de pagamento desconhecida: ' . $method, fields: ['methods' => 'inválido']);
    }
    if (!in_array($method, $allowed, true)) {
        error_response(409, 'method_not_allowed_by_platform', 'Sua praça não libera ' . METHOD_CONSEQUENCE[$method]['label'] . '.');
    }
    if (!in_array($method, $selectable, true)) {
        // A regra que a tela escreve: atraso de repasse limita a loja ao
        // online até regularizar. Quem religa é o acerto, não o interruptor.
        error_response(409, 'online_only', 'Sua loja está limitada a pagamento online até regularizar o débito.');
    }
}

$numeric = static function (string $key) use ($body): ?float {
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }
    // Teto de sanidade: 1e30 passava no is_numeric e estourava a coluna.
    if (!is_number_between($body[$key], 0, 100000)) {
        error_response(422, 'invalid_' . $key, 'Valor inválido em ' . $key . '.', fields: [$key => 'de 0 a 100.000']);
    }

    return round((float) $body[$key], 2);
};

$maxCash = $numeric('max_cash');
$maxMachine = $numeric('max_card_machine');
$maxChange = $numeric('max_change');
$minOrder = $numeric('min_order');

// O teto de espécie da loja não pode passar do teto da plataforma: quem
// carrega o dinheiro é o entregador, e o risco é nosso.
$ceiling = $policy['cash_ceiling'] ?? null;
if ($maxCash !== null && $ceiling !== null && $maxCash > (float) $ceiling) {
    error_response(422, 'above_cash_ceiling', 'O teto de dinheiro da plataforma é R$ ' . number_format((float) $ceiling, 2, ',', '.') . '.', fields: ['max_cash' => 'acima do teto']);
}

// Troco máximo e pedido mínimo em branco = mantém o que já estava (ou o
// padrão da coluna, na primeira vez). Tem que ser resolvido AQUI: o
// PostgreSQL confere o NOT NULL da linha nova antes do ON CONFLICT, então
// mandar NULL e deixar o COALESCE do UPDATE resolver dava 500 sempre que a
// loja salvava com um desses campos vazio.
$current = $pdo->prepare('SELECT max_change, min_order FROM restaurant_payment_settings WHERE restaurant_id = :id');
$current->execute(['id' => $restaurantId]);
$currentRow = $current->fetch() ?: ['max_change' => 100.00, 'min_order' => 0];
$maxChange ??= (float) $currentRow['max_change'];
$minOrder ??= (float) $currentRow['min_order'];

$stmt = $pdo->prepare(
    'INSERT INTO restaurant_payment_settings
        (restaurant_id, methods, max_cash, max_card_machine, max_change, min_order, updated_by, updated_at)
     VALUES (:id, :methods::payment_method[], :max_cash, :max_machine, :max_change, :min_order, :by, now())
     ON CONFLICT (restaurant_id) DO UPDATE
       SET methods = EXCLUDED.methods, max_cash = EXCLUDED.max_cash,
           max_card_machine = EXCLUDED.max_card_machine,
           max_change = EXCLUDED.max_change, min_order = EXCLUDED.min_order,
           updated_by = EXCLUDED.updated_by, updated_at = now()
     RETURNING *'
);
$stmt->execute([
    'id' => $restaurantId,
    'methods' => '{' . implode(',', array_map(static fn ($m) => (string) $m, $methods)) . '}',
    'max_cash' => $maxCash,
    'max_machine' => $maxMachine,
    'max_change' => $maxChange,
    'min_order' => $minOrder,
    'by' => $claims['sub'],
]);

json_response(200, settings_payload($pdo, $restaurantId, $policy, $onlineOnly, $onlineOnlyUntil));
