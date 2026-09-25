<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Sair: revoga no servidor a sessão do refresh token informado. Sem isso o
// "Sair" só apagava o token do aparelho, e o refresh continuaria valendo 30
// dias pra quem tivesse copiado. A credencial é o próprio refresh token --
// não pede access token, porque quem sai com o access já vencido também
// precisa conseguir sair.
//
// Responde 204 sempre: token desconhecido ou já revogado não é erro (sair
// duas vezes é sair), e não confirma pra quem pergunta se um token existe.

require_method('POST');
$body = read_json_body();

$rawRefresh = $body['refresh_token'] ?? null;
if (is_string($rawRefresh) && $rawRefresh !== '') {
    db()->prepare('UPDATE sessions SET revoked_at = now() WHERE refresh_hash = :h AND revoked_at IS NULL')
        ->execute(['h' => hash('sha256', $rawRefresh)]);
}

http_response_code(204);
