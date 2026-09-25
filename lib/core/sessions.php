<?php
declare(strict_types=1);

// Emissão e rotação de sessão (Especificação, Parte I §7): access de 15 min,
// refresh de 30 dias com rotação. Um refresh_hash reaparecendo depois de já
// ter sido rotacionado é a assinatura de token roubado -- revoga a família
// inteira, não só a sessão.

function issue_tokens(
    PDO $pdo,
    string $userId,
    string $role,
    array $extraClaims = [],
    ?string $deviceLabel = null,
    ?string $ip = null
): array {
    return issue_tokens_in_family($pdo, $userId, $role, uuid_v4(), null, $extraClaims, $deviceLabel, $ip);
}

/**
 * Emite o par access + refresh dentro de uma família de sessão (rotação).
 * Só o hash do refresh vai pro banco; o refresh em claro sai uma vez, na
 * resposta.
 */
function issue_tokens_in_family(
    PDO $pdo,
    string $userId,
    string $role,
    string $familyId,
    ?string $rotatedFrom,
    array $extraClaims,
    ?string $deviceLabel,
    ?string $ip
): array {
    $rawRefresh = bin2hex(random_bytes(32));
    $refreshHash = hash('sha256', $rawRefresh);
    $sessionId = uuid_v4();
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . REFRESH_TOKEN_TTL_SECONDS . ' seconds');

    $stmt = $pdo->prepare(
        'INSERT INTO sessions (id, user_id, family_id, refresh_hash, device_label, ip, rotated_from, expires_at, claims)
         VALUES (:id, :user_id, :family_id, :refresh_hash, :device_label, :ip, :rotated_from, :expires_at, :claims)'
    );
    $stmt->execute([
        'id' => $sessionId,
        'user_id' => $userId,
        'family_id' => $familyId,
        'refresh_hash' => $refreshHash,
        'device_label' => $deviceLabel,
        'ip' => $ip,
        'rotated_from' => $rotatedFrom,
        'expires_at' => $expiresAt->format(DATE_ATOM),
        // Os claims extras ficam na sessão pra rotação devolver o MESMO
        // token (migração 039): loja renovada continua sendo a loja.
        'claims' => json_encode((object) $extraClaims),
    ]);

    return [
        'access_token' => Jwt::encode(array_merge(['sub' => $userId, 'role' => $role], $extraClaims), jwt_secret(), ACCESS_TOKEN_TTL_SECONDS),
        'refresh_token' => $rawRefresh,
        'expires_in' => ACCESS_TOKEN_TTL_SECONDS,
    ];
}

/**
 * @throws never — erros terminam a request via error_response()
 */
function rotate_refresh_token(
    PDO $pdo,
    string $rawRefreshToken,
    string $role,
    array $extraClaims = [],
    ?string $deviceLabel = null,
    ?string $ip = null
): array {
    $hash = hash('sha256', $rawRefreshToken);

    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE refresh_hash = :h');
    $stmt->execute(['h' => $hash]);
    $session = $stmt->fetch();

    if ($session === false) {
        error_response(401, 'invalid_refresh_token', 'Sessão inválida. Faça login novamente.');
    }

    if ($session['revoked_at'] !== null) {
        $pdo->prepare('UPDATE sessions SET revoked_at = now() WHERE family_id = :f AND revoked_at IS NULL')
            ->execute(['f' => $session['family_id']]);
        error_response(401, 'refresh_reused', 'Sessão comprometida — todos os aparelhos foram desconectados. Faça login novamente.');
    }

    if (strtotime((string) $session['expires_at']) < time()) {
        error_response(401, 'refresh_expired', 'Sessão expirada. Faça login novamente.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE sessions SET revoked_at = now() WHERE id = :id')->execute(['id' => $session['id']]);
        $tokens = issue_tokens_in_family(
            $pdo,
            (string) $session['user_id'],
            $role,
            (string) $session['family_id'],
            (string) $session['id'],
            $extraClaims,
            $deviceLabel ?? $session['device_label'],
            $ip ?? $session['ip']
        );
        $pdo->commit();
        return $tokens;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
