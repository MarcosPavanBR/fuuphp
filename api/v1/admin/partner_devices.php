<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Suporte: liberar a troca de aparelho de uma loja ou de um entregador.
//
// O login de parceiro (auth/partner_login.php) confia no primeiro aparelho que
// entra ("2FA por aparelho", partner_accounts.device_id). Tablet quebrado,
// celular novo: o login seguinte recebe "Este login está vinculado a outro
// aparelho. Peça ao suporte para liberar a troca." -- esta rota é essa troca.
//
// GET ?q=  busca por CNPJ/CPF (só dígitos, começo) ou nome da loja/entregador.
// POST     {partner_account_id, reason} desvincula o aparelho e encerra todas
//          as sessões daquela conta: quem estava com o aparelho antigo (que
//          pode ter sido roubado) sai na próxima renovação do token. O
//          próximo login, de qualquer aparelho, vira o aparelho confiável.
//
// O motivo é obrigatório e vai pro audit_log junto do aparelho antigo:
// liberar aparelho é a porta pra alguém tomar uma conta, então fica registro.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $q = trim(input_str($_GET, 'q'));
    if (mb_strlen($q) < 3) {
        error_response(422, 'query_too_short', 'Digite pelo menos 3 caracteres (CNPJ, CPF ou nome).');
    }
    $digits = only_digits($q);
    $stmt = $pdo->prepare(
        "SELECT pa.id, pa.kind, pa.login_code, pa.device_id IS NOT NULL AS device_bound,
                COALESCE(r.name, u.full_name) AS name,
                (SELECT max(s.created_at) FROM sessions s WHERE s.user_id = pa.user_id) AS last_login_at,
                (SELECT s.device_label FROM sessions s WHERE s.user_id = pa.user_id
                  ORDER BY s.created_at DESC LIMIT 1) AS last_device
           FROM partner_accounts pa
           JOIN users u ON u.id = pa.user_id
           LEFT JOIN restaurants r ON r.id = pa.restaurant_id
          WHERE (:digits <> '' AND pa.login_code LIKE :digits || '%')
             OR COALESCE(r.name, u.full_name) ILIKE '%' || :q || '%'
          ORDER BY name
          LIMIT 20"
    );
    $stmt->execute(['digits' => strlen($digits) >= 3 ? $digits : '', 'q' => like_escape(mb_substr($q, 0, 100))]);

    json_response(200, ['accounts' => $stmt->fetchAll()]);
}

require_method('POST');
$body = read_json_body();
$accountId = input_str($body, 'partner_account_id');
$reason = body_text($body, 'reason', 300) ?? '';
if (!is_valid_uuid($accountId)) {
    error_response(422, 'invalid_request', 'Informe partner_account_id.');
}
if (mb_strlen($reason) < 5) {
    error_response(422, 'reason_required', 'Escreva o motivo (ex.: "tablet quebrou, confirmado por telefone").',
        fields: ['reason' => 'obrigatório']);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id, user_id, kind, login_code, device_id FROM partner_accounts WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $accountId]);
    $account = $stmt->fetch();
    if ($account === false) {
        $pdo->rollBack();
        error_response(404, 'not_found', 'Conta de parceiro não encontrada.');
    }
    if ($account['device_id'] === null) {
        $pdo->rollBack();
        error_response(409, 'no_device_bound', 'Essa conta não está presa a nenhum aparelho: o próximo login já entra.');
    }

    $pdo->prepare('UPDATE partner_accounts SET device_id = NULL WHERE id = :id')->execute(['id' => $accountId]);
    $revoked = $pdo->prepare('UPDATE sessions SET revoked_at = now() WHERE user_id = :u AND revoked_at IS NULL');
    $revoked->execute(['u' => $account['user_id']]);

    $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, 'partner.device_released', :target, :before, :after, :ip)"
    )->execute([
        'actor' => $adminId,
        'target' => 'partner_accounts:' . $accountId,
        'before' => json_encode(['device_id' => $account['device_id']]),
        'after' => json_encode(['device_id' => null, 'reason' => $reason, 'sessions_revoked' => $revoked->rowCount()]),
        'ip' => client_ip(),
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'released' => true,
    'kind' => $account['kind'],
    'sessions_revoked' => $revoked->rowCount(),
]);
