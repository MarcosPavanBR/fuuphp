<?php
declare(strict_types=1);

// Tela 6.3 — "LGPD: exportar/excluir".
//
// Dois direitos do titular (LGPD art. 18): ACESSO aos dados (exportar tudo
// que o sistema guarda sobre a pessoa, num arquivo legível) e ELIMINAÇÃO
// (excluir a conta).
//
// Eliminação aqui é ANONIMIZAÇÃO (migração 026): pedidos, pagamentos,
// estornos e lançamentos do livro continuam existindo porque a lei manda
// guardar registro fiscal e contábil (art. 16, I) -- e porque a loja e o
// entregador envolvidos têm direito ao próprio histórico. O que deixa de
// existir é o que identifica a pessoa.

// Status em que o pedido ainda está "vivo": excluir a conta no meio de um
// deles deixaria loja e entregador sem ter com quem falar.
const ACCOUNT_ACTIVE_ORDER_STATUSES = ['pending_payment', 'pending_verification', 'paid', 'preparing', 'ready', 'delivering'];

/**
 * Tudo que o sistema guarda sobre o cliente, agrupado por assunto. Não entra
 * o que é segredo de outra pessoa ou do sistema: hash de refresh token,
 * código OTP, token do cartão no Mercado Pago, endpoint completo de push.
 */
function account_export(PDO $pdo, string $userId): array
{
    $all = static function (string $sql, array $params) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    };
    $me = ['id' => $userId];

    $user = $all('SELECT id, role, full_name, cpf, phone, email, birth_date, lgpd_accepted_at, created_at FROM users WHERE id = :id', $me)[0] ?? null;
    $orders = $all(
        "SELECT id, public_code, status, restaurant_id, payment_method, subtotal, delivery_fee, surge_fee, tip, discount, total,
                created_at, updated_at
           FROM orders WHERE user_id = :id AND status <> 'cart' ORDER BY created_at",
        $me
    );
    $orderIds = array_map(static fn (array $o): int => (int) $o['id'], $orders);
    $inOrders = $orderIds === [] ? '0' : implode(',', $orderIds);

    return [
        'generated_at' => gmdate('c'),
        'notice' => 'Dados pessoais guardados pelo FUUdelivery sobre você (LGPD art. 18, II). '
            . 'Pedidos e pagamentos ficam guardados mesmo após excluir a conta, sem seus dados pessoais, pela obrigação fiscal.',
        'account' => $user,
        'consents' => $all('SELECT kind, version, accepted_at, ip FROM consents WHERE user_id = :id ORDER BY accepted_at', $me),
        'sessions' => $all('SELECT device_label, ip, created_at, expires_at, revoked_at FROM sessions WHERE user_id = :id ORDER BY created_at', $me),
        'addresses' => $all('SELECT label, street, number, complement, neighborhood, city, state, postal_code, reference, lat, lng, is_default, created_at FROM addresses WHERE user_id = :id', $me),
        'saved_cards' => $all('SELECT brand, last4, kind, exp_month, exp_year, is_default, created_at FROM saved_cards WHERE user_id = :id', $me),
        'orders' => $orders,
        'order_items' => $all("SELECT order_id, name_snapshot AS name, quantity, unit_price, notes FROM order_items WHERE order_id IN ({$inOrders}) ORDER BY order_id, id", []),
        'payments' => $all("SELECT order_id, provider, amount, status, created_at FROM payments WHERE order_id IN ({$inOrders}) ORDER BY created_at", []),
        'payment_proofs' => $all('SELECT order_id, sha256, state, created_at FROM payment_proofs WHERE uploaded_by = :id', $me),
        'order_messages' => $all('SELECT order_id, body, created_at FROM order_messages WHERE sender_id = :id ORDER BY created_at', $me),
        'reviews' => $all('SELECT order_id, rating, tags, comment, courier_tip, tip_state, created_at FROM reviews WHERE user_id = :id', $me),
        'support_tickets' => $all('SELECT code, order_id, category, state, created_at FROM tickets WHERE user_id = :id ORDER BY created_at', $me),
        'wallet_credits' => $all('SELECT order_id, amount, bonus, state, created_at FROM wallet_credits WHERE user_id = :id ORDER BY created_at', $me),
        'coupon_redemptions' => $user !== null && $user['cpf'] !== null
            ? $all('SELECT c.code, r.order_id, r.amount, r.created_at FROM coupon_redemptions r JOIN coupons c ON c.id = r.coupon_id WHERE r.cpf = :cpf', ['cpf' => $user['cpf']])
            : [],
        'push_devices' => $all(
            'SELECT substr(endpoint, 1, 40) || \'…\' AS endpoint, want_status, want_payment, want_promotion, created_at, last_push_at
               FROM push_subscriptions WHERE user_id = :id',
            $me
        ),
        'notifications' => $all('SELECT kind, title, body, order_id, created_at, delivered_at FROM notifications WHERE user_id = :id ORDER BY created_at', $me),
    ];
}

/**
 * O que impede excluir agora. Lista vazia = pode excluir.
 *
 * @return list<array{code:string,message:string}>
 */
function account_delete_blockers(PDO $pdo, string $userId): array
{
    $blockers = [];

    $placeholders = implode(',', array_fill(0, count(ACCOUNT_ACTIVE_ORDER_STATUSES), '?'));
    $active = $pdo->prepare("SELECT count(*) FROM orders WHERE user_id = ? AND status IN ({$placeholders})");
    $active->execute([$userId, ...ACCOUNT_ACTIVE_ORDER_STATUSES]);
    if ((int) $active->fetchColumn() > 0) {
        $blockers[] = ['code' => 'active_order', 'message' => 'Você tem um pedido em andamento. Espere ele terminar pra excluir a conta.'];
    }

    $refunds = $pdo->prepare(
        "SELECT count(*) FROM refunds r JOIN orders o ON o.id = r.order_id
          WHERE o.user_id = :id AND r.state IN ('pending','sent')"
    );
    $refunds->execute(['id' => $userId]);
    if ((int) $refunds->fetchColumn() > 0) {
        $blockers[] = ['code' => 'refund_in_progress', 'message' => 'Há um reembolso seu em andamento. Excluir agora impediria de devolver o dinheiro.'];
    }

    return $blockers;
}

/**
 * Encerra a conta apagando o que identifica a pessoa. Roda numa transação só.
 * Cartões salvos são removidos também no Mercado Pago (fora da transação,
 * depois do commit: rede não segura lock, e falhar lá não pode deixar a
 * conta meio excluída aqui -- o erro vai pro log e o cartão fica órfão no MP,
 * sem vínculo com ninguém do nosso lado).
 */
function account_anonymize(PDO $pdo, string $userId): void
{
    $cardsStmt = $pdo->prepare('SELECT s.mp_card_id, u.mp_customer_id FROM saved_cards s JOIN users u ON u.id = s.user_id WHERE s.user_id = :id');
    $cardsStmt->execute(['id' => $userId]);
    $cards = $cardsStmt->fetchAll();

    $pdo->beginTransaction();
    try {
        // E-mail sintético: a CHECK `users_contact_present` exige um contato,
        // e `.invalid` é o domínio reservado pra endereço que nunca entrega.
        $pdo->prepare(
            "UPDATE users SET full_name = 'Conta excluída', cpf = NULL, phone = NULL, birth_date = NULL,
                              email = 'excluida-' || id || '@anon.invalid', password_hash = NULL,
                              mp_customer_id = NULL, blocked = true, deleted_at = now()
              WHERE id = :id"
        )->execute(['id' => $userId]);

        // Endereços usados em pedido ficam (o pedido aponta pra eles), mas só
        // com a região aproximada: cidade, UF, CEP de 5 dígitos e coordenada
        // arredondada (~1 km) -- o suficiente pro histórico de frete.
        $pdo->prepare(
            "UPDATE addresses SET label = NULL, street = 'Endereço removido', number = NULL, complement = NULL,
                                  neighborhood = NULL, reference = NULL,
                                  postal_code = left(postal_code, 5) || '000',
                                  lat = round(lat, 2), lng = round(lng, 2), is_default = false
              WHERE user_id = :id"
        )->execute(['id' => $userId]);
        $pdo->prepare(
            'DELETE FROM addresses a WHERE a.user_id = :id AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.address_id = a.id)'
        )->execute(['id' => $userId]);

        foreach (['saved_cards', 'push_subscriptions', 'notifications', 'otp_codes', 'consents'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE user_id = :id")->execute(['id' => $userId]);
        }
        $pdo->prepare('UPDATE sessions SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL')->execute(['id' => $userId]);
        // Carrinho aberto não é histórico de nada.
        $pdo->prepare("DELETE FROM orders WHERE user_id = :id AND status = 'cart'")->execute(['id' => $userId]);

        $pdo->prepare(
            "INSERT INTO audit_log (actor_id, action, target) VALUES (:actor, 'account.deleted', :target)"
        )->execute(['actor' => $userId, 'target' => 'users:' . $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    foreach ($cards as $card) {
        if ($card['mp_customer_id'] === null) {
            continue;
        }
        try {
            mp_delete_card((string) $card['mp_customer_id'], (string) $card['mp_card_id']);
        } catch (Throwable $e) {
            error_log('account_anonymize: cartão não removido no Mercado Pago: ' . $e->getMessage());
        }
    }
}
