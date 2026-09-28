<?php
declare(strict_types=1);

// O que sai na impressora da loja, descrito como linhas (lib/printing/escpos.php
// transforma em bytes ESC/POS ou texto). Dois documentos:
//
//   comanda  (7.3/11.1) -- "Aprovar dispara advance_order(), que grava o
//            evento, imprime a comanda": itens, variações, observações, como
//            foi pago e, em dinheiro, o troco EM NEGRITO ("O valor sai impresso
//            em negrito na comanda" -- 4.5; "evita erro e volta do motoboy").
//            O código de entrega do cliente NÃO sai: é o segredo que prova a
//            entrega (8.6), e papel fica largado no balcão.
//   recibo   (9.3/9.4) -- a baixa de espécie, com a assinatura que o app do
//            entregador mostra igual: "Recibo com hash dos dois lados".

const PRINT_METHOD_LABEL = [
    'mp_card' => 'PAGO NO APP · CARTÃO',
    'pix_auto' => 'PAGO NO APP · PIX',
    'pix_manual' => 'PAGO · PIX CONFERIDO PELA LOJA',
    'cash' => 'COBRAR NA ENTREGA · DINHEIRO',
    'pos_machine' => 'COBRAR NA ENTREGA · MAQUININHA',
];

/**
 * CNPJ com máscara pro papel: 12.345.678/0001-90.
 */
function print_cnpj(string $cnpj): string
{
    $d = preg_replace('/\D/', '', $cnpj) ?? '';

    return strlen($d) === 14
        ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12)
        : $cnpj;
}

/**
 * Valor em reais pro papel: R$ 1.234,56.
 */
function print_money(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

/**
 * Nome no papel: primeiro e último ("Marcos Pava"). Basta pro entregador
 * achar a pessoa na porta, sem o nome inteiro num papel que fica no balcão,
 * no saco e na lixeira.
 */
function print_customer_name(?string $fullName): string
{
    $parts = preg_split('/\s+/', trim((string) $fullName)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    if ($parts === []) {
        return 'Cliente';
    }
    $first = mb_convert_case($parts[0], MB_CASE_TITLE);

    return count($parts) === 1 ? $first : $first . ' ' . mb_convert_case(end($parts), MB_CASE_TITLE);
}

/**
 * A comanda de um pedido. Devolve null se o pedido não existe.
 *
 * Um papel só serve a cozinha e vai grampeado no saco pro entregador e pro
 * cliente, então segue a ordem que o balcão já conhece (a do papel do
 * MaisDelivery que o Marcos mandou): quem é e pra onde vai no topo, depois
 * os itens, o dinheiro e, no fim, como cobrar.
 *
 * @return ?array{title:string, items:list<array>}
 */
function print_order_ticket(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT o.*, r.name AS store_name, u.full_name AS customer_name,
                a.street, a.number, a.complement, a.neighborhood, a.reference, a.city,
                to_char(o.created_at AT TIME ZONE restaurant_timezone(o.restaurant_id), 'DD/MM/YYYY HH24:MI') AS created_local,
                to_char(lower(o.scheduled_for) AT TIME ZONE restaurant_timezone(o.restaurant_id), 'DD/MM HH24:MI') AS slot_start,
                to_char(upper(o.scheduled_for) AT TIME ZONE restaurant_timezone(o.restaurant_id), 'HH24:MI') AS slot_end,
                -- Cliente novo NA LOJA: nenhum pedido feito (pago ou em
                -- dinheiro/maquininha) antes deste -- o mesmo critério do
                -- cupom de primeiro pedido (lib/ordering/coupons.php).
                NOT EXISTS (SELECT 1 FROM orders p
                             WHERE p.user_id = o.user_id AND p.restaurant_id = o.restaurant_id
                               AND p.id <> o.id AND p.created_at < o.created_at
                               AND p.status NOT IN ('cart', 'pending_payment')) AS first_at_store
           FROM orders o
           JOIN restaurants r ON r.id = o.restaurant_id
           JOIN users u ON u.id = o.user_id
           LEFT JOIN addresses a ON a.id = o.address_id
          WHERE o.id = :id"
    );
    $stmt->execute(['id' => $orderId]);
    $o = $stmt->fetch();
    if ($o === false) {
        return null;
    }

    // ── quem, onde, quando ──────────────────────────────────────────────
    $lines = [
        ['text' => 'FUUdelivery', 'align' => 'center', 'bold' => true],
        ['text' => 'PEDIDO #' . $o['public_code'], 'align' => 'center', 'big' => true, 'bold' => true],
        ['text' => (string) $o['created_local'], 'align' => 'center'],
        ['rule' => true],
        ['text' => mb_strtoupper((string) $o['store_name']), 'bold' => true],
        ['text' => 'Cliente: ' . print_customer_name($o['customer_name'])],
        // Loja capricha no primeiro pedido (brinde, bilhete): é o aviso que
        // o balcão procura no papel.
        ['text' => 'Cliente novo: ' . ($o['first_at_store'] === true ? 'SIM' : 'Não'), 'bold' => $o['first_at_store'] === true],
    ];
    if ($o['pickup_by_customer'] === true) {
        $lines[] = ['text' => 'RETIRADA NO BALCÃO', 'bold' => true];
    } elseif ($o['street'] !== null) {
        // Endereço em negrito, inteiro, com complemento e referência entre
        // parênteses: é o que o entregador lê no saco, na rua.
        $address = 'Endereço: ' . $o['street'] . ($o['number'] ? ', ' . $o['number'] : '')
            . ($o['complement'] ? ' (' . $o['complement'] . ')' : '')
            . ($o['reference'] ? ' (' . $o['reference'] . ')' : '')
            . ($o['neighborhood'] ? ' - ' . $o['neighborhood'] : '')
            . ($o['city'] ? ', ' . $o['city'] : '');
        $lines[] = ['text' => $address, 'bold' => true];
    }
    if ($o['slot_start'] !== null) {
        $lines[] = ['text' => "AGENDADO {$o['slot_start']}–{$o['slot_end']}", 'bold' => true];
    }
    $lines[] = ['rule' => true];

    // ── o que vai no saco ───────────────────────────────────────────────
    foreach (fetch_order_items($pdo, $orderId) as $item) {
        $lines[] = ['pair' => [$item['quantity'] . '× ' . $item['name_snapshot'], print_money((float) $item['line_total'])], 'bold' => true];
        $variants = is_string($item['variants_snapshot']) ? (json_decode($item['variants_snapshot'], true) ?: []) : [];
        foreach ($variants as $v) {
            $lines[] = ['text' => '   + ' . $v['name']];
        }
        if (($item['notes'] ?? '') !== '' && $item['notes'] !== null) {
            $lines[] = ['text' => '   OBS: ' . $item['notes'], 'bold' => true];
        }
    }

    // ── dinheiro ────────────────────────────────────────────────────────
    $lines[] = ['rule' => true];
    $lines[] = ['pair' => ['Subtotal (+)', print_money((float) $o['subtotal'])]];
    if ((float) $o['delivery_fee'] > 0) {
        $lines[] = ['pair' => ['Taxa de entrega (+)', print_money((float) $o['delivery_fee'])]];
    }
    if ((float) $o['surge_fee'] > 0) {
        $lines[] = ['pair' => ['Entrega turbinada (+)', print_money((float) $o['surge_fee'])]];
    }
    if ((float) $o['tip'] > 0) {
        $lines[] = ['pair' => ['Gorjeta (+)', print_money((float) $o['tip'])]];
    }
    if ((float) $o['discount'] > 0) {
        $lines[] = ['pair' => ['Desconto (-)', '-' . print_money((float) $o['discount'])]];
    }
    $lines[] = ['pair' => ['TOTAL', print_money((float) $o['total'])], 'bold' => true];

    // ── como cobrar ─────────────────────────────────────────────────────
    $lines[] = ['rule' => true];
    // Maquininha sempre tem crédito/débito (CHECK machine_needs_kind): a
    // linha própria dela, abaixo, já diz tudo.
    if ($o['payment_method'] !== 'pos_machine' || $o['machine_kind'] === null) {
        $lines[] = ['text' => PRINT_METHOD_LABEL[$o['payment_method']] ?? (string) $o['payment_method'], 'bold' => true];
    }

    if ($o['payment_method'] === 'cash') {
        if ($o['change_for'] !== null && (float) $o['change_for'] > (float) $o['total']) {
            $change = round((float) $o['change_for'] - (float) $o['total'], 2);
            $lines[] = ['text' => 'TROCO PARA ' . print_money((float) $o['change_for']), 'big' => true, 'bold' => true];
            $lines[] = ['text' => 'LEVAR ' . print_money($change) . ' DE TROCO', 'bold' => true];
        } else {
            $lines[] = ['text' => 'SEM TROCO (valor exato)', 'bold' => true];
        }
    }
    if ($o['payment_method'] === 'pos_machine' && $o['machine_kind'] !== null) {
        // "Cobrar no cartão de crédito": a linha que o entregador precisa
        // ler antes de sair, pra levar a maquininha certa.
        $lines[] = ['text' => 'COBRAR NO CARTÃO DE ' . ($o['machine_kind'] === 'credit' ? 'CRÉDITO' : 'DÉBITO'), 'bold' => true];
        $lines[] = ['text' => 'Na entrega · levar a maquininha'];
    }

    // A marca no papel que chega na mão do cliente.
    $lines[] = ['rule' => true];
    $lines[] = ['text' => 'Pediu, fuu, chegou.', 'align' => 'center'];
    $lines[] = ['feed' => 1];

    return ['title' => 'Comanda #' . $o['public_code'], 'items' => $lines];
}

/**
 * A assinatura do recibo de baixa: HMAC-SHA256 do conteúdo que importa, com
 * uma chave derivada do segredo do servidor. Não dá pra forjar um recibo
 * "válido" sem o servidor, e a mesma baixa sempre dá a mesma assinatura --
 * é isso que a loja (papel) e o entregador (app) comparam.
 */
function settlement_receipt_signature(array $intent): string
{
    $canonical = implode('|', [
        'BX' . $intent['id'],
        number_format((float) $intent['amount'], 2, '.', ''),
        $intent['courier_id'],
        $intent['restaurant_id'],
        $intent['confirmed_by'] ?? '',
        $intent['confirmed_at'] ?? '',
    ]);

    return hash_hmac('sha256', $canonical, hash('sha256', 'fuu-settlement-receipt|' . jwt_secret()));
}

/** A forma curta que as telas mostram: "9f2c…4a1b". */
function settlement_signature_short(string $signature): string
{
    return substr($signature, 0, 4) . '…' . substr($signature, -4);
}

/**
 * Dados do recibo de uma baixa confirmada (o que a tela 9.4 e o papel mostram).
 */
function settlement_receipt(PDO $pdo, int $intentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.*, r.name AS store_name, r.cnpj AS store_cnpj,
                cu.full_name AS courier_name, st.full_name AS confirmer_name,
                to_char(i.confirmed_at AT TIME ZONE restaurant_timezone(i.restaurant_id), 'DD/MM/YYYY HH24:MI') AS confirmed_local
           FROM cash_settlement_intents i
           JOIN restaurants r ON r.id = i.restaurant_id
           JOIN couriers c ON c.id = i.courier_id
           JOIN users cu ON cu.id = c.user_id
           LEFT JOIN users st ON st.id = i.confirmed_by
          WHERE i.id = :id AND i.state = 'settled'"
    );
    $stmt->execute(['id' => $intentId]);
    $intent = $stmt->fetch();
    if ($intent === false) {
        return null;
    }
    $signature = settlement_receipt_signature($intent);

    return [
        'code' => 'BX-' . $intent['id'],
        'amount' => (float) $intent['amount'],
        'method' => $intent['method'],
        'store_name' => $intent['store_name'],
        'store_cnpj' => $intent['store_cnpj'],
        'courier_name' => $intent['courier_name'],
        'confirmer_first_name' => strtok((string) $intent['confirmer_name'], ' ') ?: null,
        'confirmed_at' => $intent['confirmed_at'],
        'confirmed_local' => $intent['confirmed_local'],
        'signature' => $signature,
        'signature_short' => settlement_signature_short($signature),
        'courier_id' => $intent['courier_id'],
        'restaurant_id' => $intent['restaurant_id'],
    ];
}

/** O recibo de baixa como documento de impressão (via da loja). */
function print_settlement_receipt(PDO $pdo, int $intentId): ?array
{
    $r = settlement_receipt($pdo, $intentId);
    if ($r === null) {
        return null;
    }
    $lines = [
        ['text' => 'RECIBO DE BAIXA DE ESPÉCIE', 'align' => 'center', 'bold' => true],
        ['text' => $r['code'], 'align' => 'center', 'big' => true, 'bold' => true],
        ['text' => (string) $r['confirmed_local'], 'align' => 'center'],
        ['rule' => true],
        ['text' => (string) $r['store_name'], 'bold' => true],
        ['text' => 'CNPJ ' . print_cnpj((string) $r['store_cnpj'])],
        ['pair' => ['Entregador', (string) $r['courier_name']]],
        ['pair' => ['Forma', $r['method'] === 'pix' ? 'Pix (comprovante)' : 'Dinheiro no balcão']],
        ['pair' => ['Recebido por', (string) ($r['confirmer_first_name'] ?? '—')]],
        ['pair' => ['VALOR', print_money($r['amount'])], 'bold' => true, 'big' => true],
        ['rule' => true],
        ['text' => 'O dinheiro do pedido em espécie é da loja; o entregador é apenas portador.'],
        ['text' => 'Assinatura (confira no app do entregador):'],
        ['text' => $r['signature'], 'bold' => true],
        ['feed' => 1],
    ];

    return ['title' => 'Recibo ' . $r['code'], 'items' => $lines];
}
