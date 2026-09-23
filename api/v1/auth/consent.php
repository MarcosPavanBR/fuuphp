<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.3 — registra o aceite de um termo (termos, privacidade, marketing,
// localização) com versão e IP. Termos + privacidade marcam a conta como
// `lgpd_accepted_at`; marketing e localização ficam como consentimentos
// separados e revogáveis.

require_method('POST');
$claims = require_auth();
$body = read_json_body();

$kind = $body['kind'] ?? null;
if (!in_array($kind, ['terms', 'privacy_policy', 'marketing', 'location'], true)) {
    error_response(422, 'invalid_kind', 'Informe kind: terms, privacy_policy, marketing ou location.');
}

$version = trim((string) ($body['version'] ?? ''));
if ($version === '') {
    error_response(422, 'version_required', 'Informe a versão do termo aceito.', fields: ['version' => 'obrigatório']);
}

$pdo = db();
$userId = (string) $claims['sub'];

$pdo->prepare(
    'INSERT INTO consents (user_id, kind, version, ip) VALUES (:user_id, :kind, :version, :ip)'
)->execute([
    'user_id' => $userId,
    'kind' => $kind,
    'version' => $version,
    'ip' => client_ip(),
]);

// terms/privacy_policy são os dois exigidos por lei para operar a conta
// (Especificação, Parte I §7 "Retenção declarada" + cadastro com LGPD).
if (in_array($kind, ['terms', 'privacy_policy'], true)) {
    $pdo->prepare('UPDATE users SET lgpd_accepted_at = now() WHERE id = :id')->execute(['id' => $userId]);
}

json_response(201, ['recorded' => true, 'kind' => $kind, 'version' => $version]);
