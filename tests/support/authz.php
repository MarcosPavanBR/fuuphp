<?php

declare(strict_types=1);

// Motor do tests/smoke_authz.sh. Recebe do shell (FUZZ_*) os tokens e ids do
// mundo de A (tests/support/seed_world.sh) e do lado B.
//
// Parte 1 -- matriz de papéis: a lista de rotas sai do código. Rota que chama
// require_admin() é de admin; require_store_staff() ou a checagem manual
// "!== 'restaurant_staff'" é de loja; require_courier() é de entregador; a
// checagem manual "!== 'customer'" é só de cliente. Cada uma é chamada (GET
// e POST) sem login e com cada papel que não é o dela: tem que dar 401, 403
// ou 405 (método errado recusado antes de olhar o login).
//
// Parte 2 -- acesso cruzado: B tenta, com o próprio login, ler e mexer no
// que é de A. Resposta 2xx é vazamento; 5xx é defeito. O shell confere
// depois que nada de A mudou.

$env = static fn (string $k): string => (string) (getenv('FUZZ_' . $k) ?: '');
$base = $env('BASE');
$root = $env('ROOT');

$tokens = [
    'anônimo' => '',
    'cliente' => $env('CUST'),
    'loja' => $env('STAFF'),
    'entregador' => $env('COURIER'),
    'admin' => $env('ADMIN'),
    'cliente B' => $env('CUST_B'),
    'loja B' => $env('STAFF_B'),
    'entregador B' => $env('COURIER_B'),
];

$ch = curl_init();
/** @return array{0:int,1:string} */
$send = static function (string $role, string $method, string $route, array $data = [], ?array $files = null) use ($ch, $base, $tokens): array {
    $hex = bin2hex(random_bytes(16));
    $headers = ['X-Idempotency-Key: ' . sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12))];
    if ($tokens[$role] !== '') {
        $headers[] = 'Authorization: Bearer ' . $tokens[$role];
    }
    $url = "{$base}/{$route}";
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => null, CURLOPT_HTTPGET => false];
    if ($method === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
        $opts[CURLOPT_CUSTOMREQUEST] = null;
        $url .= $data === [] ? '' : '?' . http_build_query($data);
    } elseif ($files !== null) {
        $opts[CURLOPT_POSTFIELDS] = $data + $files;
    } else {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($data === [] ? new stdClass() : $data);
    }
    curl_setopt_array($ch, $opts + [CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $headers]);
    $resp = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $resp];
};

$problems = [];
$calls = 0;

// ── parte 1: matriz de papéis ────────────────────────────────────────────
$groups = ['admin' => [], 'loja' => [], 'entregador' => [], 'cliente' => []];
foreach (glob($root . '/api/v1/*/*.php') as $file) {
    $route = substr($file, strlen($root . '/api/v1/'));
    $code = (string) file_get_contents($file);
    if ($route === 'admin/guard.php') {
        continue;
    }
    if (str_contains($code, 'require_admin(')) {
        $groups['admin'][] = $route;
    } elseif (str_contains($code, 'require_store_staff(') || preg_match("/\\(\\\$claims\\['role'\\] \\?\\? null\\) !== 'restaurant_staff'\\)/", $code)) {
        $groups['loja'][] = $route;
    } elseif (str_contains($code, 'require_courier(')) {
        $groups['entregador'][] = $route;
    } elseif (preg_match("/\\(\\\$claims\\['role'\\] \\?\\? null\\) !== 'customer'\\)/", $code)) {
        $groups['cliente'][] = $route;
    }
}
$allowed = ['401', '403', '405'];
foreach ($groups as $owner => $routes) {
    foreach ($routes as $route) {
        // Foto do item e logo da loja: o GET serve a imagem PÚBLICA (vitrine
        // do app); só o POST (enviar a foto) é da loja.
        $publicGet = str_contains((string) file_get_contents($root . '/api/v1/' . $route), 'public_image_serve(');
        foreach (['anônimo', 'cliente', 'loja', 'entregador', 'admin'] as $role) {
            if ($role === $owner) {
                continue;
            }
            foreach ($publicGet ? ['POST'] : ['GET', 'POST'] as $method) {
                [$status, $resp] = $send($role, $method, $route, $method === 'GET' ? ['id' => 1] : []);
                $calls++;
                $ok = $role === 'anônimo' ? in_array((string) $status, ['401', '405'], true) : in_array((string) $status, $allowed, true);
                if (!$ok) {
                    $problems[] = sprintf('papel: %s %s de %s aceitou %s -> %d %s', $method, $route, $owner, $role, $status, substr($resp, 0, 140));
                }
            }
        }
    }
}
printf("matriz de papéis: %d rotas de admin, %d de loja, %d de entregador, %d só de cliente\n",
    count($groups['admin']), count($groups['loja']), count($groups['entregador']), count($groups['cliente']));

// ── parte 2: acesso cruzado (B mexendo no que é de A) ────────────────────
$order = (int) $env('ORDER');
$orderDone = (int) $env('ORDER_DONE');
$orderPay = (int) $env('ORDER_PAY');
$orderPix = (int) $env('ORDER_PIX');
$addr = (int) $env('ADDR');
$addr2 = (int) $env('ADDR2');
$store = $env('STORE');

$photo = tempnam(sys_get_temp_dir(), 'authz') . '.jpg';
$img = imagecreatetruecolor(40, 30);
imagejpeg($img, $photo);
$jpeg = static fn (string $field): array => [$field => new CURLFile($photo, 'image/jpeg', 'foto.jpg')];

$cases = [
    // cliente B no que é do cliente A
    ['cliente B', 'GET', 'orders/show.php', ['id' => $order]],
    ['cliente B', 'GET', 'orders/receipt.php', ['id' => $orderDone]],
    ['cliente B', 'GET', 'orders/cancel_quote.php', ['id' => $orderPay]],
    ['cliente B', 'GET', 'orders/courier_location.php', ['id' => $order]],
    ['cliente B', 'GET', 'orders/dispatch_status.php', ['id' => $order]],
    ['cliente B', 'GET', 'orders/messages.php', ['id' => $order]],
    ['cliente B', 'POST', 'orders/messages.php', ['order_id' => $order, 'body' => 'oi, sou o B']],
    ['cliente B', 'POST', 'orders/reorder.php', ['order_id' => $orderDone]],
    ['cliente B', 'POST', 'orders/track_ticket.php', ['order_id' => $order]],
    ['cliente B', 'POST', 'orders/status.php', ['order_id' => $orderPay, 'to' => 'cancelled', 'reason' => 'desisti']],
    ['cliente B', 'POST', 'orders/dispatch_action.php', ['order_id' => $orderPay, 'action' => 'boost', 'amount' => 2]],
    ['cliente B', 'POST', 'payments/change_method.php', ['order_id' => $orderPay, 'payment_method' => 'cash']],
    ['cliente B', 'POST', 'payments/pay.php', ['order_id' => $orderPay, 'card_token' => 'APRO-authz']],
    // B paga o PRÓPRIO pedido com o cartão salvo de A
    ['cliente B', 'POST', 'payments/pay.php', ['order_id' => (int) $env('ORDER_B'), 'saved_card_id' => (int) $env('CARD'), 'installments' => 1]],
    ['cliente B', 'POST', 'payments/upload_proof.php', ['order_id' => (string) $orderPix], $jpeg('proof')],
    ['cliente B', 'POST', 'reviews/create.php', ['order_id' => $orderDone, 'rating' => 1]],
    ['cliente B', 'POST', 'support/ticket.php', ['category' => 'late', 'message' => 'cadê o pedido?', 'order_id' => $order]],
    ['cliente B', 'GET', 'support/answer.php', ['order_id' => $order, 'topic' => 'late']],
    ['cliente B', 'POST', 'addresses/update.php', ['id' => $addr2, 'street' => 'Rua do B']],
    ['cliente B', 'POST', 'addresses/delete.php', ['id' => $addr2]],
    ['cliente B', 'GET', 'addresses/quote.php', ['address_id' => $addr, 'restaurant_id' => $store]],
    // fechar o carrinho de B entregando no endereço de A
    ['cliente B', 'POST', 'orders/checkout.php', ['restaurant_id' => $store, 'address_id' => $addr, 'payment_method' => 'cash', 'change_for' => 200]],
    ['cliente B', 'POST', 'cart/update_quantity.php', ['order_item_id' => (int) $env('CART_ITEM'), 'quantity' => 5]],
    ['cliente B', 'POST', 'cart/remove_item.php', ['order_item_id' => (int) $env('CART_ITEM')]],
    ['cliente B', 'POST', 'cards/update.php', ['id' => (int) $env('CARD'), 'is_default' => true]],
    ['cliente B', 'POST', 'cards/delete.php', ['id' => (int) $env('CARD')]],
    ['cliente B', 'POST', 'profile/wallet.php', ['credit_id' => (int) $env('CREDIT'), 'decision' => 'accept']],

    // loja B no que é da loja A
    ['loja B', 'POST', 'orders/status.php', ['order_id' => $orderPay, 'to' => 'rejected', 'reason' => 'sem ingrediente']],
    ['loja B', 'GET', 'orders/show.php', ['id' => $order]],
    ['loja B', 'GET', 'orders/messages.php', ['id' => $order]],
    ['loja B', 'GET', 'restaurants/orders.php', ['id' => $store]],
    ['loja B', 'POST', 'restaurants/approve_pix.php', ['proof_id' => (int) $env('PROOF'), 'decision' => 'reject', 'reason' => 'não confere']],
    ['loja B', 'GET', 'restaurants/proof_image.php', ['id' => (int) $env('PROOF')]],
    ['loja B', 'POST', 'restaurants/settlement_proofs.php', ['proof_id' => (int) $env('SETTLEMENT_PROOF'), 'decision' => 'reject', 'reason' => 'ilegível']],
    ['loja B', 'GET', 'restaurants/settlement_proofs.php', ['image' => (int) $env('SETTLEMENT_PROOF')]],
    ['loja B', 'POST', 'restaurants/confirm_settlement.php', ['code' => $env('SETTLE_CODE'), 'counted_amount' => 40]],
    ['loja B', 'GET', 'restaurants/print_queue.php', ['kind' => 'order_ticket', 'ref_id' => $order]],
    ['loja B', 'POST', 'restaurants/print_queue.php', ['kind' => 'order_ticket', 'ref_id' => $order, 'reprint' => true]],
    ['loja B', 'POST', 'restaurants/menu_availability.php', ['menu_item_id' => (int) $env('ITEM'), 'available' => false]],
    ['loja B', 'POST', 'restaurants/menu_item.php', ['id' => (int) $env('ITEM'), 'name' => 'Item da loja B', 'price' => 1]],
    ['loja B', 'POST', 'restaurants/pos_devices.php', ['action' => 'deactivate', 'device_id' => $env('POS')]],
    ['loja B', 'POST', 'restaurants/pos_devices.php', ['action' => 'confirm_return', 'custody_id' => (int) $env('CUSTODY_A')]],
    ['loja B', 'POST', 'restaurants/holiday.php', ['action' => 'remove', 'id' => (int) $env('HOLIDAY_A')]],
    ['loja B', 'POST', 'restaurants/coupons.php', ['action' => 'deactivate', 'coupon_id' => (int) $env('COUPON_A')]],
    ['loja B', 'POST', 'restaurants/menu_photo.php', ['menu_item_id' => (string) $env('ITEM')], $jpeg('photo')],

    // entregador B na corrida do entregador A (com o código certo, até)
    ['entregador B', 'POST', 'couriers/deliver.php', ['order_id' => $order, 'delivery_code' => $env('ORDER_CODE')]],
    ['entregador B', 'POST', 'couriers/pickup.php', ['order_id' => $order, 'event' => 'picked_up']],
    ['entregador B', 'POST', 'couriers/incident.php', ['order_id' => $order, 'action' => 'arrive']],
    ['entregador B', 'GET', 'couriers/incident.php', ['order_id' => $order]],
    ['entregador B', 'POST', 'couriers/pos.php', ['action' => 'sale', 'order_id' => $order, 'amount' => (float) $env('ORDER_TOTAL'), 'nsu' => '777']],
    ['entregador B', 'GET', 'couriers/settlements.php', ['intent_id' => (int) $env('INTENT')]],
    ['entregador B', 'POST', 'couriers/incident_photo.php', ['order_id' => (string) $order], $jpeg('photo')],
    ['entregador B', 'GET', 'orders/show.php', ['id' => $order]],
    ['entregador B', 'POST', 'orders/messages.php', ['order_id' => $order, 'body' => 'sou outro entregador']],
    ['entregador B', 'GET', 'orders/courier_location.php', ['id' => $order]],
];

foreach ($cases as $case) {
    [$role, $method, $route, $data] = $case;
    [$status, $resp] = $send($role, $method, $route, $data, $case[4] ?? null);
    $calls++;
    if ($status < 400 || $status >= 500) {
        $problems[] = sprintf('dono: %s %s por %s -> %d %s', $method, $route, $role, $status, substr($resp, 0, 160));
    }
}
printf("acesso cruzado: %d tentativas de B no que é de A\n", count($cases));
@unlink($photo);

foreach ($problems as $p) {
    echo "PROBLEMA {$p}\n";
}
printf("%d chamadas, %d problemas\n", $calls, count($problems));
exit($problems === [] ? 0 : 1);
