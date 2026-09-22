<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

/*
 * POST /v1/push/pending.php — o que o service worker mostra ao ser acordado.
 *
 * O push chega sem conteúdo (ver lib/push.php). O service worker não tem o
 * token da sessão -- ele roda com o app fechado --, então a credencial é o
 * ENDPOINT da própria assinatura: um endereço longo e único que só o
 * navegador daquele aparelho conhece. Vai no corpo (POST), não na URL, pra
 * não parar em log de servidor nem de proxy.
 *
 * Devolve as notificações ainda não entregues da pessoa dona da assinatura
 * (no máximo 5, as mais novas) e as marca como entregues.
 */

require_method('POST');
$body = read_json_body();
$endpoint = (string) ($body['endpoint'] ?? '');
if ($endpoint === '') {
    error_response(422, 'endpoint_required', 'Informe o endpoint da assinatura.');
}

$pdo = db();
$subStmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE endpoint = :e');
$subStmt->execute(['e' => $endpoint]);
$sub = $subStmt->fetch();
if ($sub === false) {
    // Assinatura desconhecida recebe lista vazia, não 404: não se confirma
    // pra quem pergunta se um endpoint existe.
    json_response(200, ['notifications' => []]);
}

// Só os tipos que ESTA assinatura quer: a preferência é por aparelho.
$kinds = [];
foreach (PUSH_KIND_PREF as $kind => $pref) {
    if ($sub[$pref] === true) {
        $kinds[] = $kind;
    }
}
if ($kinds === []) {
    json_response(200, ['notifications' => []]);
}

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'UPDATE notifications SET delivered_at = now()
      WHERE id IN (
            SELECT id FROM notifications
             WHERE user_id = :u AND delivered_at IS NULL
               AND kind = ANY(:kinds::text[])
             ORDER BY created_at DESC LIMIT 5
             FOR UPDATE SKIP LOCKED)
      RETURNING id, kind, title, body, order_id, created_at'
);
$stmt->execute(['u' => $sub['user_id'], 'kinds' => '{' . implode(',', $kinds) . '}']);
$rows = $stmt->fetchAll();
$pdo->commit();

json_response(200, ['notifications' => $rows]);
