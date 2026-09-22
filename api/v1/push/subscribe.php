<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

/*
 * POST /v1/push/subscribe.php — liga, ajusta ou desliga o push deste aparelho.
 *
 * Corpo: { "subscription": PushSubscription.toJSON(), "prefs": {status,
 * payment, promotion}, "action": "subscribe" | "prefs" | "unsubscribe" }.
 *
 * As três preferências são as da tela 6.3 ("push separado por tipo, status
 * × promoção, pro usuário não desligar tudo e perder o aviso da aprovação"),
 * e moram na ASSINATURA, não no usuário: o celular pode querer promoção e o
 * computador do trabalho não.
 */

require_method('POST');
$claims = require_auth();
$body = read_json_body();
$pdo = db();

$action = (string) ($body['action'] ?? 'subscribe');
$sub = $body['subscription'] ?? [];
$endpoint = is_array($sub) ? (string) ($sub['endpoint'] ?? '') : '';
if ($endpoint === '' || !str_starts_with($endpoint, 'https://')) {
    error_response(422, 'invalid_subscription', 'Assinatura de push inválida (endpoint https obrigatório).', fields: ['subscription' => 'inválida']);
}

if ($action === 'unsubscribe') {
    $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = :e AND user_id = :u')
        ->execute(['e' => $endpoint, 'u' => $claims['sub']]);
    json_response(200, ['subscribed' => false]);
}

$prefs = is_array($body['prefs'] ?? null) ? $body['prefs'] : [];
$flag = static fn (string $key, bool $default): string => (($prefs[$key] ?? $default) === true) ? 'true' : 'false';

if ($action === 'prefs') {
    $stmt = $pdo->prepare(
        'UPDATE push_subscriptions
            SET want_status = :s, want_payment = :p, want_promotion = :m
          WHERE endpoint = :e AND user_id = :u RETURNING *'
    );
    $stmt->execute(['s' => $flag('status', true), 'p' => $flag('payment', true), 'm' => $flag('promotion', false), 'e' => $endpoint, 'u' => $claims['sub']]);
    $row = $stmt->fetch();
    if ($row === false) {
        error_response(404, 'subscription_not_found', 'Este aparelho não está com o push ligado.');
    }
    json_response(200, ['subscription' => $row]);
}

$p256dh = (string) ($sub['keys']['p256dh'] ?? '');
$auth = (string) ($sub['keys']['auth'] ?? '');
if ($p256dh === '' || $auth === '') {
    error_response(422, 'invalid_subscription', 'Assinatura sem as chaves do navegador.', fields: ['subscription' => 'sem keys']);
}

// O mesmo endpoint pode mudar de dono (outra pessoa entrou neste aparelho):
// a assinatura vai pra quem está logado agora, e o aviso de um não chega
// no celular emprestado do outro.
$stmt = $pdo->prepare(
    'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, want_status, want_payment, want_promotion)
     VALUES (:u, :e, :k, :a, :s, :p, :m)
     ON CONFLICT (endpoint) DO UPDATE
       SET user_id = EXCLUDED.user_id, p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth,
           want_status = EXCLUDED.want_status, want_payment = EXCLUDED.want_payment,
           want_promotion = EXCLUDED.want_promotion, failures = 0
     RETURNING *'
);
$stmt->execute([
    'u' => $claims['sub'], 'e' => $endpoint, 'k' => $p256dh, 'a' => $auth,
    's' => $flag('status', true), 'p' => $flag('payment', true), 'm' => $flag('promotion', false),
]);

json_response(201, ['subscription' => $stmt->fetch(), 'subscribed' => true]);
