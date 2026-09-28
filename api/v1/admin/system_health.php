<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Saúde do sistema pro admin (aba Relatórios; auditoria INFRA-02): os erros
// que a API e os scripts registraram (lib/core/app_errors.php, agrupados) e
// o resultado do backup (deploy/backup/backup.sh -> system_status).
//
// GET                        erros abertos (mais recentes primeiro, até 50),
//                            quantos apareceram nas últimas 24 h e o status
//                            das rotinas.
// POST {id, action: resolve} marca o erro como resolvido; se voltar, reabre
//                            sozinho. Vai pro audit_log.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $errors = $pdo->query(
        'SELECT id, source, message, route, trace_id, count, first_seen, last_seen
           FROM app_errors WHERE resolved_at IS NULL
          ORDER BY last_seen DESC LIMIT 50'
    )->fetchAll();
    $last24h = (int) $pdo->query("SELECT count(*) FROM app_errors WHERE last_seen > now() - interval '24 hours'")->fetchColumn();
    $status = $pdo->query('SELECT key, ok, detail, updated_at FROM system_status ORDER BY key')->fetchAll();

    json_response(200, ['errors' => $errors, 'last_24h' => $last24h, 'status' => $status]);
}

require_method('POST');
$body = read_json_body();
if (($body['action'] ?? null) !== 'resolve' || !is_int($body['id'] ?? null)) {
    error_response(422, 'invalid_request', 'Informe {id, action: "resolve"}.', fields: ['id' => 'número', 'action' => 'resolve']);
}
$stmt = $pdo->prepare('UPDATE app_errors SET resolved_at = now() WHERE id = :id AND resolved_at IS NULL RETURNING message');
$stmt->execute(['id' => $body['id']]);
$message = $stmt->fetchColumn();
if ($message === false) {
    error_response(404, 'error_not_found', 'Erro não encontrado ou já resolvido.');
}
$pdo->prepare(
    'INSERT INTO audit_log (actor_id, action, target, before, after, ip)
     VALUES (:actor, :action, :target, NULL, :after, :ip)'
)->execute([
    'actor' => $adminId,
    'action' => 'app_error.resolved',
    'target' => 'app_errors:' . $body['id'],
    'after' => json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
    'ip' => client_ip(),
]);

json_response(200, ['id' => $body['id'], 'resolved' => true]);
