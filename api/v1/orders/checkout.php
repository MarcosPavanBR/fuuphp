<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Converte o carrinho incremental (status='cart', montado por api/v1/cart/*.php,
// Fase 3) num pedido aguardando pagamento -- o passo que faltava entre
// "Ir para pagamento" (CartDrawer.svelte) e a Fase 4 (seleção de método).
// É o ÚNICO jeito de fechar pedido (o checkout de um passo, orders/create.php,
// foi removido na auditoria ARQ-01): reaproveita o pedido em status='cart'
// que o cliente já vinha montando -- não cria um segundo pedido nem
// duplica itens.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Só cliente faz checkout de carrinho.');
}
$body = read_json_body();

$restaurantId = $body['restaurant_id'] ?? null;
$addressId = $body['address_id'] ?? null;
$paymentMethod = $body['payment_method'] ?? null;

if (!is_string($restaurantId) || !is_valid_uuid($restaurantId)) {
    error_response(422, 'restaurant_id_required', 'Informe restaurant_id.', fields: ['restaurant_id' => 'obrigatório']);
}
$addressId = positive_id($addressId);
if ($addressId === null) {
    error_response(422, 'address_id_required', 'Informe address_id.', fields: ['address_id' => 'obrigatório']);
}
if (!in_array($paymentMethod, ['mp_card', 'pix_auto', 'pix_manual', 'cash', 'pos_machine'], true)) {
    error_response(422, 'invalid_payment_method', 'payment_method inválido.', fields: ['payment_method' => 'inválido']);
}

$pdo = db();

$cartStmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = :user_id AND restaurant_id = :restaurant_id AND status = 'cart'");
$cartStmt->execute(['user_id' => $claims['sub'], 'restaurant_id' => $restaurantId]);
$cart = $cartStmt->fetch();
if ($cart === false) {
    error_response(404, 'cart_not_found', 'Você não tem um carrinho aberto nessa loja.');
}

$itemCountStmt = $pdo->prepare('SELECT count(*) FROM order_items WHERE order_id = :id');
$itemCountStmt->execute(['id' => $cart['id']]);
if ((int) $itemCountStmt->fetchColumn() === 0) {
    error_response(422, 'cart_empty', 'Seu carrinho está vazio.');
}

// Existe, foi aprovada pela plataforma e está aberta (lib/catalog/store.php).
$restaurant = require_store_accepting_orders($pdo, $restaurantId);
if ($restaurant['pause_until'] !== null && strtotime((string) $restaurant['pause_until']) > time()) {
    error_response(409, 'store_paused', 'Essa loja está pausada no momento.', detail: 'volta às ' . $restaurant['pause_until']);
}

// Arquivado (versão antiga de endereço editado, ou apagado -- migração 043)
// não recebe pedido novo.
$addressStmt = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :user_id AND archived_at IS NULL');
$addressStmt->execute(['id' => $addressId, 'user_id' => $claims['sub']]);
if ($addressStmt->fetchColumn() === false) {
    error_response(404, 'address_not_found', 'Endereço não encontrado para esse usuário.');
}

$policy = resolve_policy($pdo, $restaurantId);
if (!in_array($paymentMethod, $policy['enabled_methods'], true)) {
    error_response(422, 'payment_method_not_allowed', 'Essa loja não aceita esse método de pagamento.', fields: ['payment_method' => 'não habilitado por esta loja']);
}

$subtotal = (float) $cart['subtotal'];
if ($subtotal < (float) $policy['min_order']) {
    error_response(422, 'below_minimum_order', "Pedido mínimo dessa loja é R$ {$policy['min_order']}.");
}

// Troco é número em reais ("x" virava 0; 1e30 estourava a coluna).
$changeFor = isset($body['change_for']) ? money_input($body['change_for'], 0, 100000) : null;
if (isset($body['change_for']) && $changeFor === null) {
    error_response(422, 'invalid_change_for', 'Troco em reais.', fields: ['change_for' => 'inválido']);
}
if ($paymentMethod === 'cash' && $changeFor !== null && $changeFor < $subtotal) {
    error_response(422, 'invalid_change_for', 'Troco precisa ser maior ou igual ao subtotal.', fields: ['change_for' => 'inválido']);
}

$machineKind = $body['machine_kind'] ?? null;
if ($paymentMethod === 'pos_machine' && !in_array($machineKind, ['debit', 'credit'], true)) {
    error_response(422, 'machine_kind_required', 'Informe machine_kind: debit ou credit.', fields: ['machine_kind' => 'obrigatório']);
}

// Tela 14.3 — "a taxa aparece antes de salvar, não na hora de pagar", e
// "área de cobertura validada no servidor". O frete NÃO vem mais do corpo da
// requisição: é calculado aqui, do mesmo jeito que preço de item, mínimo e
// comissão sempre foram. Era o último número do dinheiro que confiava no
// cliente -- bastava mandar delivery_fee: 0 pra não pagar entrega.
$quote = delivery_quote_for($pdo, (string) $restaurantId, $addressId, $policy);
if (!$quote['in_area']) {
    error_response(409, 'out_of_delivery_area', $quote['reason'], detail: 'endereço fora do raio de entrega');
}
$deliveryFee = (float) $quote['fee'];
$tip = isset($body['tip']) ? (money_input($body['tip'], 0, 1000000) ?? -1.0) : 0.0;
if (!is_valid_tip($tip)) {
    error_response(422, 'invalid_tip', 'Gorjeta inválida (de R$ 0 a R$ 200).', fields: ['tip' => 'de 0 a 200']);
}

// Tela 14.4 — agendamento. A faixa é validada contra o horário declarado da
// loja (não contra o relógio) e a vaga é reservada em delivery_slots, onde o
// CHECK (taken <= capacity) da migração 004 é quem garante o "3 vagas".
$scheduledRange = null;
if (isset($body['slot']) && is_array($body['slot'])) {
    if ((int) $restaurant['slot_capacity'] <= 0) {
        error_response(409, 'scheduling_disabled', 'Essa loja não aceita pedido agendado.');
    }
    $slotStart = (string) ($body['slot']['start'] ?? '');
    $slotEnd = (string) ($body['slot']['end'] ?? '');
    if (strtotime($slotStart) === false || strtotime($slotEnd) === false) {
        error_response(422, 'invalid_slot', 'Faixa inválida.', fields: ['slot' => 'inválida']);
    }

    // Mesmo horizonte que a tela oferece. Uma loja aberta 24h tem faixa
    // "válida" em qualquer data do calendário; sem este limite, uma
    // requisição direta agendaria pedido para 2030.
    $limit = strtotime('+' . SLOT_HORIZON_DAYS . ' days');
    if (strtotime($slotStart) > $limit) {
        error_response(409, 'slot_too_far', 'Só dá pra agendar nos próximos ' . SLOT_HORIZON_DAYS . ' dias.');
    }

    // A faixa precisa ser uma das que a loja oferece NAQUELE dia -- aceitar
    // um range qualquer deixaria o cliente agendar pras 4h da manhã.
    // O dia da faixa no relógio da loja (fuso da cidade dela).
    $day = (new DateTimeImmutable('@' . strtotime($slotStart)))
        ->setTimezone(store_timezone($pdo, (string) $restaurant['id']))->format('Y-m-d');
    $offered = delivery_slots_for_day($pdo, $restaurant, $day);
    $match = null;
    foreach ($offered as $slot) {
        if (strtotime($slot['start']) === strtotime($slotStart)) {
            $match = $slot;
            break;
        }
    }
    if ($match === null) {
        error_response(409, 'slot_unavailable', 'Essa faixa não está mais disponível.');
    }
    if ($match['free'] <= 0) {
        error_response(409, 'slot_full', 'Essa faixa esgotou — escolha outra.');
    }

    $scheduledRange = reserve_slot($pdo, $restaurant, $match['start'], $match['end']);
}

$commission = round($subtotal * $policy['commission_bps'] / 10000, 2);

// Cupom: vale o que o CARRINHO guarda (orders.coupon_id, migração 044), não
// um código mandado agora. Antes era o código do corpo -- e sem ele o
// desconto passava sem resgate nenhum (cupom de uso único infinito), e com
// outro código o desconto somava. O app ainda manda coupon_code: se mandar,
// tem que ser o mesmo do carrinho.
$couponId = $cart['coupon_id'] === null ? null : (int) $cart['coupon_id'];
$couponCode = body_text($body, 'coupon_code', 40);
if ($couponCode !== null && $couponCode !== '') {
    $applied = $couponId === null ? null : fetch_coupon($pdo, $couponId);
    if ($applied === null || strtoupper($couponCode) !== $applied['code']) {
        error_response(409, 'coupon_not_applied', 'Aplique o cupom no carrinho antes de fechar o pedido.');
    }
}
$customerCpf = null;
if ($couponId !== null) {
    $cpfStmt = $pdo->prepare('SELECT cpf FROM users WHERE id = :id');
    $cpfStmt->execute(['id' => $claims['sub']]);
    $customerCpf = $cpfStmt->fetchColumn();
    if (!is_string($customerCpf) || $customerCpf === '') {
        error_response(409, 'cpf_required', 'Cupom exige CPF no cadastro — é um uso por CPF, não por conta.');
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE orders SET address_id = :address_id, delivery_fee = :delivery_fee, tip = :tip,
                            payment_method = :payment_method, change_for = :change_for, machine_kind = :machine_kind,
                            policy_snapshot = :policy_snapshot, commission = :commission,
                            scheduled_for = :scheduled_for::tstzrange
         WHERE id = :id'
    )->execute([
        'address_id' => $addressId,
        'delivery_fee' => $deliveryFee,
        'tip' => $tip,
        'payment_method' => $paymentMethod,
        'change_for' => $paymentMethod === 'cash' ? $changeFor : null,
        'machine_kind' => $machineKind,
        'policy_snapshot' => json_encode($policy, JSON_UNESCAPED_UNICODE),
        'commission' => $commission,
        'scheduled_for' => $scheduledRange,
        'id' => $cart['id'],
    ]);

    call_advance_order($pdo, (int) $cart['id'], 'pending_payment', (string) $claims['sub'], 'customer');

    // O cupom foi aplicado no carrinho (cart/apply_coupon.php), mas o consumo
    // do orçamento só vira registro aqui: é neste ponto que existe pedido de
    // verdade pra referenciar, e carrinho abandonado não pode segurar
    // dinheiro de campanha. A UNIQUE (coupon_id, cpf) e o CHECK
    // `within_budget` fazem o resto -- se o orçamento estourou entre aplicar
    // e fechar, o banco recusa e o checkout inteiro volta atrás.
    // O desconto do pedido é o do cupom, recalculado AGORA com o subtotal e o
    // frete de agora (o carrinho pode ter mudado depois de aplicar).
    $discount = 0.0;

    if ($couponId !== null) {
        $coupon = fetch_coupon($pdo, $couponId, true);
        $couponAmount = $coupon === null ? 0.0 : coupon_discount($coupon, $subtotal, $deliveryFee);

        // Tudo conferido de novo no fechamento, com a linha do cupom travada:
        // prazo, loja, pedido mínimo sobre o subtotal de agora, uso por CPF E
        // por conta (o CPF se troca no perfil entre aplicar e fechar), público
        // (a pessoa pode ter feito outro pedido e deixado de ser "primeiro
        // pedido") e orçamento contando este desconto.
        $rejection = $coupon === null
            ? [404, 'coupon_not_found', 'Cupom inválido ou fora do prazo.']
            : coupon_rejection($pdo, $coupon, $cart, (string) $customerCpf, (string) $claims['sub'], $couponAmount);
        if ($rejection !== null) {
            $pdo->rollBack();
            error_response(...$rejection);
        }
        // Primeiro pedido é um por ENDEREÇO, não só por CPF (NEG-01): conta
        // nova com CPF gerado não repete o cupom na mesma casa. Fica o sinal
        // de fraude pro admin ver.
        if ($coupon['audience'] === 'first_order' && first_order_used_at_address($pdo, (int) $addressId, (string) $claims['sub'])) {
            $pdo->rollBack();
            $pdo->prepare(
                "INSERT INTO fraud_signals (kind, subject, user_id, order_id, score)
                 VALUES ('address_reuse', :subject, :uid, :oid, 60)"
            )->execute(['subject' => 'address:' . (int) $addressId, 'uid' => $claims['sub'], 'oid' => $cart['id']]);
            error_response(409, 'coupon_address_used', 'O cupom de primeiro pedido já foi usado neste endereço.');
        }

        // Frete grátis só tem valor agora: no carrinho ainda não há endereço,
        // então o frete era zero e o desconto também. Aqui o frete acabou de
        // ser calculado ($deliveryFee), e é ele que o cupom paga.
        $discount = $couponAmount;

        if ($couponAmount > 0) {
            $pdo->prepare(
                'INSERT INTO coupon_redemptions (coupon_id, order_id, cpf, amount)
                 VALUES (:coupon_id, :order_id, :cpf, :amount)'
            )->execute([
                'coupon_id' => $coupon['id'],
                'order_id' => $cart['id'],
                'cpf' => $customerCpf,
                'amount' => $couponAmount,
            ]);
            $pdo->prepare('UPDATE coupons SET spent = spent + :amount WHERE id = :id')
                ->execute(['amount' => $couponAmount, 'id' => $coupon['id']]);

            // Tela 15.3 — "o desconto entra como linha própria no pedido E no
            // ledger, com a conta de quem pagou". Sem isto, `coupons.payer` era
            // um rótulo bonito que não movia dinheiro nenhum.
            record_coupon_ledger($pdo, $coupon, $cart, $couponAmount, (string) $claims['sub']);

            // "Estourado, o cupom desativa sozinho -- nada de descobrir no
            // fechamento." O CHECK within_budget impede passar do teto; isto
            // aqui é o que faz o cupom sumir da vitrine ao ENCOSTAR nele.
            $pdo->prepare('UPDATE coupons SET active = false WHERE id = :id AND spent >= budget_cap')
                ->execute(['id' => $coupon['id']]);
        }
    }
    // Grava o desconto recalculado (e zera o de um carrinho sem cupom).
    $pdo->prepare('UPDATE orders SET discount = :d WHERE id = :id')->execute(['d' => $discount, 'id' => $cart['id']]);

    // Crédito em carteira (tela 13.4): saldo aceito entra sozinho no próximo
    // pedido -- é o que a mensagem de aceite promete. Entra DEPOIS do cupom
    // porque o cupom é uma campanha com orçamento e o crédito é dívida nossa
    // com esta pessoa; misturar os dois no mesmo cálculo tiraria do
    // orçamento da campanha um desconto que ela não bancou.
    //
    // O custo já foi lançado no livro quando a oferta foi aceita: aqui só se
    // consome o passivo. Lançar de novo contaria a mesma despesa duas vezes.
    $walletApplied = 0.0;
    $chargeable = round(
        (float) $cart['subtotal'] + $deliveryFee + $tip
            + (float) $cart['surge_fee'] - $discount,
        2
    );
    if ($chargeable > 0) {
        $walletApplied = wallet_spend($pdo, (string) $claims['sub'], (int) $cart['id'], $chargeable);
        if ($walletApplied > 0) {
            $pdo->prepare('UPDATE orders SET discount = discount + :credit WHERE id = :id')
                ->execute(['credit' => $walletApplied, 'id' => $cart['id']]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$order = fetch_order($pdo, (int) $cart['id']);
json_response(200, [
    'order' => $order,
    'items' => fetch_order_items($pdo, (int) $cart['id']),
    // Linha própria na resposta: o crédito abateu o total, e o app tem que
    // conseguir dizer POR QUE o valor mudou entre o carrinho e o checkout.
    'wallet_applied' => $walletApplied,
    'wallet_balance' => wallet_balance($pdo, (string) $claims['sub']),
]);
