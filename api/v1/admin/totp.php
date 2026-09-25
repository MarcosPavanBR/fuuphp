<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Segundo fator do PRÓPRIO admin (migração 042, auditoria SEG-04): o código
// de um app autenticador, além do SMS. Opcional, mas recomendado a todo admin
// -- quem clona o chip do dono não entra só com o SMS.
//
// GET                          {enabled, pending}
// POST {action: start}         gera um segredo novo (ainda não exigido) e
//                              devolve o segredo + otpauth:// pro app.
// POST {action: confirm, code} confere o primeiro código do app: a partir
//                              daí o login pede os dois.
// POST {action: disable, code} desliga, com um código válido do app.
// Tudo vai pro audit_log. Perdeu o celular do autenticador: quem tem acesso
// ao servidor apaga a linha de admin_totp -- docs/OPERATIONS.md. Não há rota
// pra um admin desligar o fator de outro de propósito: seria o atalho de
// quem roubou uma sessão de admin pra tirar a trava das outras.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

$row = $pdo->prepare('SELECT secret, confirmed_at, last_step FROM admin_totp WHERE user_id = :u');
$row->execute(['u' => $adminId]);
$current = $row->fetch();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_response(200, [
        'enabled' => $current !== false && $current['confirmed_at'] !== null,
        'pending' => $current !== false && $current['confirmed_at'] === null,
    ]);
}

require_method('POST');
$body = read_json_body();
$action = $body['action'] ?? null;
$code = isset($body['code']) && is_scalar($body['code']) ? only_digits((string) $body['code']) : '';

$audit = static function (string $what) use ($pdo, $adminId): void {
    $pdo->prepare(
        'INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, :action, :target, NULL, NULL, :ip)'
    )->execute(['actor' => $adminId, 'action' => 'admin_totp.' . $what, 'target' => 'users:' . $adminId, 'ip' => client_ip()]);
};

if ($action === 'start') {
    if ($current !== false && $current['confirmed_at'] !== null) {
        error_response(409, 'totp_already_enabled', 'O segundo fator já está ligado. Desligue antes de trocar.');
    }
    $secret = totp_new_secret();
    $pdo->prepare(
        'INSERT INTO admin_totp (user_id, secret) VALUES (:u, :s)
         ON CONFLICT (user_id) DO UPDATE SET secret = EXCLUDED.secret, confirmed_at = NULL, last_step = NULL, created_at = now()'
    )->execute(['u' => $adminId, 's' => $secret]);
    $who = $pdo->prepare('SELECT coalesce(email, phone, full_name) FROM users WHERE id = :u');
    $who->execute(['u' => $adminId]);
    $audit('started');
    json_response(200, ['secret' => $secret, 'uri' => totp_uri($secret, (string) $who->fetchColumn())]);
}

if ($action === 'confirm' || $action === 'disable') {
    if ($current === false || ($action === 'confirm') !== ($current['confirmed_at'] === null)) {
        error_response(409, 'totp_wrong_state', $action === 'confirm' ? 'Comece a configuração antes de confirmar.' : 'O segundo fator não está ligado.');
    }
    $step = totp_verify((string) $current['secret'], $code, $current['last_step'] === null ? null : (int) $current['last_step']);
    if ($step === null) {
        error_response(422, 'totp_invalid', 'Código do autenticador incorreto (confira a hora do celular).', fields: ['code' => 'inválido']);
    }
    if ($action === 'confirm') {
        $pdo->prepare('UPDATE admin_totp SET confirmed_at = now(), last_step = :s WHERE user_id = :u AND confirmed_at IS NULL')
            ->execute(['s' => $step, 'u' => $adminId]);
        $audit('enabled');
        json_response(200, ['enabled' => true]);
    }
    $pdo->prepare('DELETE FROM admin_totp WHERE user_id = :u')->execute(['u' => $adminId]);
    $audit('disabled');
    json_response(200, ['enabled' => false]);
}

error_response(422, 'invalid_action', 'Ação: start, confirm ou disable.', fields: ['action' => 'inválido']);
