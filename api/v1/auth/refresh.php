<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Troca um refresh token válido por um par novo (rotação: o antigo deixa de
// valer). Sessão revogada -- logout, conta excluída -- responde 401; conta
// bloqueada, 403. Os apps chamam isto sozinhos quando o access token de
// 15 min vence (web/src/lib/services/api.js).

require_method('POST');
$body = read_json_body();

$rawRefresh = $body['refresh_token'] ?? null;
if (!is_string($rawRefresh) || $rawRefresh === '') {
    error_response(422, 'refresh_token_required', 'Informe refresh_token.', fields: ['refresh_token' => 'obrigatório']);
}

$pdo = db();

// O papel vem do usuário (pode ter mudado); os claims extras (kind,
// restaurant_id, courier_id do login de parceiro) vêm da sessão (migração
// 039) -- sem eles, a loja renovada deixaria de ser a loja.
$lookup = $pdo->prepare(
    'SELECT u.id AS user_id, u.role, u.blocked, s.claims
       FROM sessions s JOIN users u ON u.id = s.user_id
      WHERE s.refresh_hash = :h'
);
$lookup->execute(['h' => hash('sha256', $rawRefresh)]);
$found = $lookup->fetch();

if ($found === false) {
    error_response(401, 'invalid_refresh_token', 'Sessão inválida. Faça login novamente.');
}
if ($found['blocked'] === true) {
    // Bloqueio vale em até 15 min (a vida do access token), não em 30 dias.
    error_response(403, 'user_blocked', 'Conta bloqueada. Fale com o suporte.');
}

$claims = json_decode((string) $found['claims'], true);
$claims = is_array($claims) ? $claims : [];
$role = (string) $found['role'];

// Parceiro: o vínculo com a loja/o entregador ainda precisa existir. Sessão
// criada antes da 039 (sem claims) reconstrói pelo único vínculo do usuário.
if (in_array($role, ['restaurant_staff', 'courier'], true)) {
    $accounts = $pdo->prepare('SELECT kind, restaurant_id, courier_id FROM partner_accounts WHERE user_id = :u');
    $accounts->execute(['u' => $found['user_id']]);
    $links = $accounts->fetchAll();

    $match = null;
    foreach ($links as $link) {
        $same = isset($claims['restaurant_id'])
            ? $link['restaurant_id'] === $claims['restaurant_id']
            : (isset($claims['courier_id']) ? $link['courier_id'] === $claims['courier_id'] : count($links) === 1);
        if ($same) {
            $match = $link;
            break;
        }
    }
    if ($match === null) {
        error_response(401, 'partner_link_gone', 'Esse acesso não está mais vinculado. Faça login novamente.');
    }
    $claims = $match['kind'] === 'restaurant'
        ? ['kind' => 'restaurant', 'restaurant_id' => $match['restaurant_id']]
        : ['kind' => 'courier', 'courier_id' => $match['courier_id']];
}

$tokens = rotate_refresh_token(
    $pdo,
    $rawRefresh,
    $role,
    $claims,
    ip: client_ip()
);

json_response(200, $tokens);
