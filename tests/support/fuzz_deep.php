<?php

declare(strict_types=1);

// Motor do tests/smoke_fuzz_deep.sh. Recebe do shell (FUZZ_*) os ids e
// tokens de um mundo semeado de verdade -- loja, cliente com pedido,
// entregador com a corrida, admin -- e, pra cada rota abaixo, parte de um
// corpo VÁLIDO e troca UM campo por vez por cada valor de BAD_VALUES.
//
// Nenhuma troca pode dar 5xx. Quando dá, a linha do erro no log do servidor
// (achada pelo trace_id) sai junto, pra ninguém precisar reproduzir à mão.
//
// FUZZ_ONLY=rota  roda só as rotas cujo caminho contém o texto.
// FUZZ_VERBOSE=1  mostra também o status do corpo válido de cada rota (pra
//                 conferir que o teste chega fundo: 4xx no corpo válido quer
//                 dizer que a rota parou antes do que devia).

$env = static fn (string $k): string => (string) (getenv('FUZZ_' . $k) ?: '');
$base = $env('BASE');

// Os valores ruins. Cada um já derrubou alguma API por aí: tipo trocado
// (lista, objeto, texto no lugar de número), número que não cabe na coluna,
// caractere nulo (o PostgreSQL recusa em text), texto enorme e data
// impossível.
const BAD_VALUES = [
    'lista' => [],
    'objeto' => ['a' => 1],
    'texto' => 'x',
    'negativo' => -1,
    'zero' => 0,
    'gigante' => 1e30,
    'inteiro_grande' => 99999999999,
    'null' => null,
    'vazio' => '',
    'booleano' => true,
    'nan' => 'NaN',
    'nulo_no_meio' => "a\0b",
    'dez_mil' => 'LONG',
    'data_impossivel' => '2026-02-30',
    'hora_impossivel' => '25:99',
    'lista_de_listas' => [[1]],
];

$store = $env('STORE');
$order = (int) $env('ORDER');          // em rota, com o entregador do teste
$orderDone = (int) $env('ORDER_DONE'); // entregue (avaliação, reembolso)
$orderPay = (int) $env('ORDER_PAY');   // aguardando pagamento no cartão
$addr = (int) $env('ADDR');            // já usado em pedido
$addr2 = (int) $env('ADDR2');          // nunca usado (edição e exclusão)
$cpf = $env('CPF');
$phone = $env('PHONE');
$item = (int) $env('ITEM');
$variant = (int) $env('VARIANT');
$city = $env('CITY');
$stamp = substr((string) hrtime(true), -8);

$tokens = [
    'cliente' => $env('CUST'),
    'loja' => $env('STAFF'),
    'entregador' => $env('COURIER'),
    'admin' => $env('ADMIN'),
    'anônimo' => '',
];

$address = [
    'label' => 'Casa', 'street' => 'Rua Fuzz', 'number' => '10', 'complement' => 'ap 1',
    'reference' => 'portão azul', 'neighborhood' => 'Centro', 'city' => 'São Paulo',
    'city_ibge_code' => $city, 'state' => 'SP', 'postal_code' => '01001000',
    'lat' => -23.56, 'lng' => -46.64,
];

// [papel, rota, corpo válido, opções]. Opções: 'nested' (caminhos a.b.c
// também trocados), 'prep' (chamado antes de cada envio, pra rota que
// consome o próprio estado, como o checkout), 'get' (vai como query).
// A ORDEM importa: o que destrói estado (apagar, sair, excluir conta) fica
// no fim, pra não esvaziar as rotas seguintes.
$routes = [
    // ── cliente ─────────────────────────────────────────────────────────
    ['cliente', 'addresses/create.php', $address + ['is_default' => false]],
    ['cliente', 'addresses/update.php', ['id' => $addr2] + $address + ['is_default' => true]],
    ['cliente', 'addresses/quote.php', ['address_id' => $addr, 'restaurant_id' => $store, 'lat' => -23.56, 'lng' => -46.64, 'city_ibge_code' => $city], ['get' => true]],
    ['cliente', 'cart/show.php', ['restaurant_id' => $store], ['get' => true]],
    ['cliente', 'cart/add_item.php', ['restaurant_id' => $store, 'menu_item_id' => $item, 'quantity' => 1, 'variant_ids' => [$variant], 'notes' => 'sem cebola'], ['nested' => ['variant_ids.0']]],
    ['cliente', 'cart/update_quantity.php', ['order_item_id' => (int) $env('CART_ITEM'), 'quantity' => 2]],
    ['cliente', 'cart/apply_coupon.php', ['restaurant_id' => $store, 'code' => 'NAOEXISTE']],
    ['cliente', 'orders/checkout.php', [
        'restaurant_id' => $store, 'address_id' => $addr, 'payment_method' => 'cash', 'change_for' => 200,
        'tip' => 0, 'coupon_code' => 'NAOEXISTE', 'machine_kind' => 'credit', 'slot' => '2030-01-01T12:00:00',
    ], ['prep' => 'cart']],
    ['cliente', 'orders/slots.php', ['restaurant_id' => $store], ['get' => true]],
    ['cliente', 'orders/show.php', ['id' => $order], ['get' => true]],
    ['cliente', 'orders/receipt.php', ['id' => $order], ['get' => true]],
    ['cliente', 'orders/cancel_quote.php', ['id' => $order], ['get' => true]],
    ['cliente', 'orders/courier_location.php', ['id' => $order], ['get' => true]],
    ['cliente', 'orders/dispatch_status.php', ['id' => $order], ['get' => true]],
    ['cliente', 'orders/messages.php', ['id' => $order], ['get' => true]],
    ['cliente', 'orders/messages.php', ['order_id' => $order, 'body' => 'Pode deixar na portaria']],
    ['cliente', 'orders/reorder.php', ['order_id' => $order]],
    ['cliente', 'orders/track_ticket.php', ['order_id' => $order]],
    ['cliente', 'payments/change_method.php', ['order_id' => $orderPay, 'payment_method' => 'mp_card', 'machine_kind' => 'credit', 'change_for' => 150]],
    ['cliente', 'payments/pay.php', ['order_id' => $orderPay, 'card_token' => 'REJECT-token-fuzz', 'installments' => 1, 'payer_cpf' => $cpf, 'saved_card_id' => (int) $env('CARD')]],
    ['cliente', 'profile/favorites.php', ['restaurant_id' => $store, 'favorite' => true]],
    ['cliente', 'profile/loyalty.php', ['reward_id' => (int) $env('REWARD')]],
    ['cliente', 'profile/update.php', ['full_name' => 'Cliente Fuzz Fundo', 'email' => "fdeep{$stamp}@test.com", 'cpf' => $cpf, 'birth_date' => '1990-05-20']],
    ['cliente', 'profile/wallet.php', ['credit_id' => (int) $env('CREDIT'), 'decision' => 'decline']],
    ['cliente', 'push/subscribe.php', [
        'action' => 'subscribe',
        'subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/fuzz' . $stamp, 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('A', 22)]],
        'prefs' => ['status' => true, 'payment' => true, 'promotion' => false],
    ], ['nested' => ['subscription.endpoint', 'subscription.keys', 'subscription.keys.p256dh', 'prefs.status']]],
    ['cliente', 'push/pending.php', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/fuzz' . $stamp]],
    ['cliente', 'reviews/create.php', ['order_id' => $orderDone, 'rating' => 5, 'comment' => 'Chegou quente', 'tags' => ['rápido'], 'courier_tip' => 0], ['nested' => ['tags.0']]],
    ['cliente', 'support/ticket.php', ['category' => 'late', 'message' => 'Preciso de ajuda com o pedido', 'order_id' => $order]],
    ['cliente', 'support/answer.php', ['order_id' => $order, 'topic' => 'late'], ['get' => true]],
    ['cliente', 'cards/create.php', ['card_token' => 'tok_fuzz', 'kind' => 'credit']],
    ['cliente', 'cards/update.php', ['id' => (int) $env('CARD'), 'is_default' => true]],
    ['cliente', 'auth/consent.php', ['kind' => 'marketing', 'version' => '2026-09']],
    ['cliente', 'couriers/apply.php', ['full_name' => 'Candidato Fuzz', 'cpf' => $cpf, 'phone' => $phone, 'vehicle' => 'moto', 'plate' => 'QQP1B34', 'pix_key' => $cpf]],
    ['cliente', 'couriers/submit_application.php', ['accept_contract' => true]],
    ['cliente', 'cart/remove_item.php', ['order_item_id' => (int) $env('CART_ITEM')]],

    // ── públicas ────────────────────────────────────────────────────────
    ['anônimo', 'restaurants/list.php', ['city_ibge_code' => $city, 'lat' => -23.55, 'lng' => -46.63, 'category' => 'Pizza', 'limit' => 10, 'offset' => 0, 'ids' => $store, 'open_only' => 1], ['get' => true]],
    ['anônimo', 'restaurants/show.php', ['id' => $store], ['get' => true]],
    ['anônimo', 'restaurants/menu.php', ['id' => $store], ['get' => true]],
    ['anônimo', 'restaurants/popular_items.php', ['city_ibge_code' => $city], ['get' => true]],
    ['anônimo', 'restaurants/search_products.php', ['city_ibge_code' => $city, 'lat' => -23.55, 'lng' => -46.63, 'q' => 'pizza'], ['get' => true]],
    ['anônimo', 'banners/list.php', ['city_ibge_code' => $city], ['get' => true]],
    ['anônimo', 'auth/otp_request.php', ['purpose' => 'login', 'phone' => $phone, 'full_name' => 'Fuzz', 'email' => 'f@test.com', 'channel' => 'sms']],
    ['anônimo', 'auth/otp_verify.php', ['purpose' => 'login', 'phone' => $phone, 'code' => '000000', 'email' => 'f@test.com', 'device_label' => 'Fuzz', 'totp' => '000000']],
    ['anônimo', 'restaurants/signup.php', [
        'name' => 'Loja Cadastro Fuzz', 'cnpj' => '11222333000181', 'email' => "loja{$stamp}@test.com", 'password' => 'senha-forte-123',
        'contact_name' => 'Dona Fuzz', 'contact_phone' => '11987654321', 'category' => 'Pizza', 'city_ibge_code' => $city,
        'address' => 'Rua Fuzz, 10', 'lat' => -23.55, 'lng' => -46.63, 'pix_key' => '11222333000181', 'accept_terms' => true,
    ], ['fresh' => static function () use ($stamp): array {
        $cnpj = fresh_cnpj();
        return ['cnpj' => $cnpj, 'pix_key' => $cnpj, 'email' => 'loja' . bin2hex(random_bytes(4)) . "{$stamp}@test.com"];
    }]],
    ['anônimo', 'payments/webhook_mercadopago.php', ['type' => 'payment', 'topic' => 'payment', 'data' => ['id' => '123'], 'status' => 'approved', 'status_detail' => 'accredited'], ['nested' => ['data.id']]],

    // ── loja ────────────────────────────────────────────────────────────
    ['loja', 'restaurants/orders.php', ['id' => $store, 'scope' => 'kds'], ['get' => true]],
    ['loja', 'restaurants/reconciliation.php', ['day' => date('Y-m-d')], ['get' => true]],
    ['loja', 'restaurants/print_queue.php', ['kind' => 'order_ticket', 'ref_id' => $order, 'columns' => 48], ['get' => true]],
    ['loja', 'restaurants/print_queue.php', ['kind' => 'order_ticket', 'ref_id' => $order, 'reprint' => true]],
    ['loja', 'restaurants/menu_availability.php', ['menu_item_id' => $item, 'available' => true]],
    ['loja', 'restaurants/menu_item.php', [
        'id' => $item, 'name' => 'Pizza Fuzz', 'description' => 'Molho e muçarela', 'category' => 'Pizzas', 'price' => 40.0, 'available' => true,
        'variants' => [['group_name' => 'Borda', 'name' => 'Catupiry', 'price_delta' => 5.0, 'required' => false]],
    ], ['nested' => ['variants.0', 'variants.0.price_delta', 'variants.0.name', 'variants.0.required']]],
    ['loja', 'restaurants/hours_save.php', [
        'days' => [1, 2], 'slot_capacity' => 5,
        'shifts' => [['shift' => 'lunch', 'opens' => '11:00', 'closes' => '15:00', 'last_order' => '14:30']],
    ], ['nested' => ['days.0', 'shifts.0', 'shifts.0.opens', 'shifts.0.shift']]],
    ['loja', 'restaurants/holiday.php', ['day' => '2030-12-25', 'closed' => false, 'opens' => '10:00', 'closes' => '14:00', 'last_order' => '13:30', 'note' => 'Natal']],
    ['loja', 'restaurants/pause.php', ['action' => 'pause', 'minutes' => 15, 'reason' => 'busy_kitchen']],
    ['loja', 'restaurants/pause.php', ['action' => 'resume']],
    ['loja', 'restaurants/payment_settings.php', ['methods' => ['cash', 'pos_machine', 'pix_manual', 'mp_card'], 'max_cash' => 300, 'max_card_machine' => 400, 'max_change' => 200, 'min_order' => 0], ['nested' => ['methods.0']]],
    ['loja', 'restaurants/prep_time.php', ['prep_minutes' => 30, 'prep_auto_bump' => true]],
    ['loja', 'restaurants/pix_key.php', ['pix_key' => 'loja-fuzz@test.com']],
    ['loja', 'restaurants/store_profile.php', ['category' => 'Pizza']],
    ['loja', 'restaurants/coupons.php', ['action' => 'create', 'code' => 'LOJA' . $stamp, 'kind' => 'fixed', 'value' => 5, 'min_order' => 20, 'budget_cap' => 50, 'days' => 30, 'audience' => 'all', 'dry_run' => true]],
    ['loja', 'restaurants/pos_devices.php', ['action' => 'register', 'label' => 'POS Fuzz', 'acquirer' => 'stone', 'serial' => 'SN-F' . $stamp]],
    ['loja', 'restaurants/pos_devices.php', ['action' => 'deactivate', 'device_id' => $env('POS')]],
    ['loja', 'restaurants/approve_pix.php', ['proof_id' => (int) $env('PROOF'), 'decision' => 'reject', 'counted_amount' => 10, 'reason' => 'valor não bate']],
    ['loja', 'restaurants/settlement_proofs.php', ['proof_id' => (int) $env('SETTLEMENT_PROOF'), 'decision' => 'reject', 'fraud' => false, 'reason' => 'ilegível']],
    ['loja', 'restaurants/confirm_settlement.php', ['code' => '123456', 'counted_amount' => 10]],
    ['cliente', 'orders/dispatch_action.php', ['order_id' => $orderPay, 'action' => 'boost', 'amount' => 2]],

    // ── entregador ──────────────────────────────────────────────────────
    ['entregador', 'couriers/earnings.php', ['limit' => 10], ['get' => true]],
    ['entregador', 'couriers/settlements.php', ['intent_id' => (int) $env('INTENT')], ['get' => true]],
    ['entregador', 'couriers/incident.php', ['order_id' => $order], ['get' => true]],
    ['entregador', 'couriers/position.php', ['lat' => -23.5505, 'lng' => -46.6333, 'heading' => 90]],
    ['entregador', 'couriers/accept_offer.php', ['offer_id' => (int) $env('OFFER')]],
    ['entregador', 'couriers/pickup.php', ['order_id' => $order, 'event' => 'arrived_at_store']],
    ['entregador', 'couriers/incident.php', ['order_id' => $order, 'action' => 'arrive', 'kind' => 'unsafe_area', 'lat' => -23.56, 'lng' => -46.64, 'photo_storage_key' => 'nao-existe']],
    ['entregador', 'couriers/pos.php', ['action' => 'sale', 'order_id' => $order, 'amount' => 40, 'nsu' => '123456', 'brand' => 'visa', 'acquirer' => 'stone', 'device_id' => $env('POS'), 'label' => 'POS', 'serial' => 'SN', 'own' => false]],
    ['entregador', 'couriers/settle_intent.php', ['restaurant_id' => $store, 'amount' => 10, 'method' => 'in_person']],
    ['entregador', 'couriers/deliver.php', ['order_id' => $order, 'delivery_code' => '0000', 'lat' => -23.56, 'lng' => -46.64, 'photo_storage_key' => 'nao-existe']],
    ['entregador', 'couriers/shift.php', ['action' => 'start']],

    // ── admin ───────────────────────────────────────────────────────────
    ['admin', 'admin/fraud_signals.php', ['days' => 30], ['get' => true]],
    ['admin', 'admin/reports.php', ['days' => 30], ['get' => true]],
    ['admin', 'admin/export.php', ['kind' => 'ledger', 'from' => '2026-01-01', 'to' => '2026-12-31'], ['get' => true]],
    ['admin', 'admin/netting.php', ['start' => '2026-09-14', 'end' => '2026-09-20'], ['get' => true]],
    ['admin', 'admin/partner_devices.php', ['q' => 'Fuzz'], ['get' => true]],
    ['admin', 'admin/store_coupon_limits.php', ['q' => 'Fuzz'], ['get' => true]],
    ['admin', 'admin/incident_photo.php', ['id' => (int) $env('INCIDENT')], ['get' => true]],
    ['admin', 'admin/banners.php', ['action' => 'position', 'id' => (int) $env('BANNER'), 'position' => 1]],
    ['admin', 'admin/campaigns.php', ['code' => 'CAMP' . $stamp, 'kind' => 'fixed', 'value' => 5, 'min_order' => 20, 'budget_cap' => 100, 'ends_at' => '2030-01-01', 'audience' => 'all', 'payer' => 'platform', 'restaurant_id' => $store, 'dry_run' => true]],
    ['admin', 'admin/cities.php', ['ibge_code' => '3550308', 'name' => 'São Paulo', 'uf' => 'SP', 'lat' => -23.55, 'lng' => -46.63, 'timezone' => 'America/Sao_Paulo', 'neighborhoods' => ['Centro']], ['nested' => ['neighborhoods.0']]],
    ['admin', 'admin/couriers.php', ['application_id' => (int) $env('APPLICATION'), 'decision' => 'needs_fix', 'note' => 'foto ilegível', 'city_ibge_code' => $city]],
    ['admin', 'admin/disputes.php', ['dispute_id' => (int) $env('DISPUTE'), 'resolution' => 'conferido', 'charge' => 'platform']],
    ['admin', 'admin/incidents.php', ['incident_id' => (int) $env('INCIDENT'), 'resolution' => 'returned', 'refund' => false, 'refund_payer' => 'platform']],
    ['admin', 'admin/netting.php', ['action' => 'generate', 'start' => '2026-09-14', 'end' => '2026-09-20', 'payout_id' => (int) $env('PAYOUT'), 'provider_ref' => 'ref']],
    ['admin', 'admin/refunds.php', ['refund_id' => (int) $env('REFUND'), 'action' => 'refund', 'fee_adjustment' => 'keep', 'bonus' => 0, 'note' => 'fuzz', 'provider_ref' => 'ref']],
    ['admin', 'admin/restaurants.php', ['restaurant_id' => $env('PENDING_STORE'), 'decision' => 'reject', 'reason' => 'documento ilegível']],
    ['admin', 'admin/store_coupon_limits.php', ['restaurant_id' => $store, 'limit' => 100]],
    ['admin', 'admin/policy_overrides.php', ['scope' => 'restaurant', 'scope_id' => $store, 'patch' => ['delivery_base_fee' => 5], 'reason' => 'motivo do fuzz', 'ends_on' => '2030-01-01'], ['nested' => ['patch.delivery_base_fee']]],
    ['admin', 'admin/policy_overrides.php', ['action' => 'end', 'id' => (int) $env('OVERRIDE')]],
    ['admin', 'admin/system_health.php', ['action' => 'resolve', 'id' => (int) $env('APP_ERROR')]],
    ['admin', 'admin/totp.php', ['action' => 'start']],
    ['admin', 'admin/totp.php', ['action' => 'confirm', 'code' => '000000']],
    ['admin', 'admin/partner_devices.php', ['partner_account_id' => $env('STAFF_ACCOUNT'), 'reason' => 'troca de aparelho']],

    // ── o que destrói estado: por último ────────────────────────────────
    ['entregador', 'couriers/shift.php', ['action' => 'end']],
    ['cliente', 'cards/delete.php', ['id' => (int) $env('CARD')]],
    ['cliente', 'addresses/delete.php', ['id' => $addr2]],
    ['cliente', 'auth/refresh.php', ['refresh_token' => $env('CUST_REFRESH')]],
    ['cliente', 'auth/logout.php', ['refresh_token' => 'nao-e-um-token']],
    ['cliente', 'profile/delete_account.php', ['confirm' => 'NAO', 'forfeit_wallet' => false]],
];

/** CNPJ com dígito verificador válido (cadastro de loja precisa de um novo a cada envio). */
function fresh_cnpj(): string
{
    $n = array_map(static fn () => random_int(0, 9), range(1, 8));
    array_push($n, 0, 0, 0, 1);
    foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $n[$i] * $w;
        }
        $n[] = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
    }
    return implode('', $n);
}

/** Troca o valor num caminho a.b.c (cria o que faltar). */
function set_path(array $data, string $path, mixed $value): array
{
    $keys = explode('.', $path);
    $ref = &$data;
    foreach ($keys as $k) {
        if (!is_array($ref)) {
            $ref = [];
        }
        $ref = &$ref[ctype_digit($k) ? (int) $k : $k];
    }
    $ref = $value;
    return $data;
}

$ch = curl_init();
$send = static function (string $role, string $route, array $data, bool $get) use ($ch, $base, $tokens): array {
    $hex = bin2hex(random_bytes(16));
    $uuid = sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    $headers = ['Content-Type: application/json', 'X-Idempotency-Key: ' . $uuid];
    if ($tokens[$role] !== '') {
        $headers[] = 'Authorization: Bearer ' . $tokens[$role];
    }
    $url = "{$base}/{$route}";
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30];
    if ($get) {
        $opts[CURLOPT_HTTPGET] = true;
        $url .= '?' . http_build_query($data);
    } else {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts + [CURLOPT_URL => $url]);
    $resp = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $resp];
};

$only = $env('ONLY');
$verbose = $env('VERBOSE') !== '';
$failures = [];
$calls = 0;
$started = microtime(true);

foreach ($routes as $r) {
    [$role, $route, $valid] = $r;
    $opts = $r[3] ?? [];
    if ($only !== '' && !str_contains($route, $only)) {
        continue;
    }
    $get = !empty($opts['get']);
    $prep = static function () use ($opts, $send, $store, $item): void {
        if (($opts['prep'] ?? null) === 'cart') {
            $send('cliente', 'cart/add_item.php', ['restaurant_id' => $store, 'menu_item_id' => $item, 'quantity' => 1], false);
        }
    };

    if ($verbose) {
        $prep();
        [$status, $resp] = $send($role, $route, isset($opts['fresh']) ? array_merge($valid, $opts['fresh']()) : $valid, $get);
        printf("  base %-36s %-10s %d %s\n", $route, $role, $status, substr($resp, 0, 90));
    }

    $paths = array_merge(array_map('strval', array_keys($valid)), $opts['nested'] ?? []);
    foreach ($paths as $path) {
        foreach (BAD_VALUES as $label => $bad) {
            if ($bad === 'LONG') {
                $bad = str_repeat('a', 10000);
            }
            // Na query, lista vira id[]=..., o que o PHP entrega como array:
            // o mesmo "tipo trocado" do corpo JSON.
            $prep();
            // Campos que precisam ser novos a cada envio (CNPJ do cadastro),
            // menos o que está sendo trocado agora.
            $data = isset($opts['fresh']) ? array_merge($valid, array_diff_key($opts['fresh'](), [explode('.', $path)[0] => 1])) : $valid;
            [$status, $resp] = $send($role, $route, set_path($data, $path, $bad), $get);
            $calls++;
            if ($status >= 500 || $status === 0) {
                $trace = json_decode($resp, true)['trace_id'] ?? null;
                $failures[] = [$role, $route, $path, $label, $status, $trace];
            }
        }
    }
}

$log = $env('LOG') !== '' && is_readable($env('LOG')) ? (string) file_get_contents($env('LOG')) : '';
foreach ($failures as [$role, $route, $path, $label, $status, $trace]) {
    $why = '';
    if ($trace !== null && preg_match('/\[' . preg_quote((string) $trace, '/') . '\][^\n]*/', $log, $m)) {
        $why = ' -> ' . substr($m[0], 0, 300);
    }
    printf("5XX %s %s (%s) campo %s = %s%s\n", $status, $route, $role, $path, $label, $why);
}
printf("%d chamadas em %.0f s, %d com 5xx\n", $calls, microtime(true) - $started, count($failures));
exit($failures === [] ? 0 : 1);
