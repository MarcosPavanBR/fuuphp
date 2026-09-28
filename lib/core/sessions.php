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
        // Só um pedido vence a rotação: o UPDATE condicional serializa os
        // concorrentes (o segundo espera o COMMIT do primeiro e vê 0 linhas).
        $claim = $pdo->prepare('UPDATE sessions SET revoked_at = now() WHERE id = :id AND revoked_at IS NULL');
        $claim->execute(['id' => $session['id']]);
        if ($claim->rowCount() === 0) {
            $pdo->rollBack();
            $pdo->prepare('UPDATE sessions SET revoked_at = now() WHERE family_id = :f AND revoked_at IS NULL')
                ->execute(['f' => $session['family_id']]);
            error_response(401, 'refresh_reused', 'Sessão comprometida — todos os aparelhos foram desconectados. Faça login novamente.');
        }
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

// ── Refresh token em cookie HttpOnly (auditoria DevSecOps de 27/09/2026) ──
// O refresh de 30 dias ficava no localStorage, ao alcance de qualquer script
// da página: um XSS que passasse pela CSP levava a sessão por um mês. Agora
// ele mora num cookie que o JavaScript não lê:
//   - HttpOnly, SameSite=Strict (outro site não faz o navegador mandá-lo);
//   - Path=/api/v1/auth/: só vai junto no refresh e no logout;
//   - Secure em produção e homologação (lá só existe HTTPS);
//   - um por app ("realm"), como eram as chaves do localStorage: o mesmo
//     navegador pode ter o painel da loja e o app do cliente abertos.
// O access token de 15 min continua com o app (vai no cabeçalho
// Authorization de cada chamada).
const REFRESH_REALMS = ['customer', 'staff', 'courier', 'admin'];

/** O app que pediu (corpo "realm"), ou o padrão do papel. */
function refresh_realm(mixed $requested, string $role): string
{
    if (is_string($requested) && in_array($requested, REFRESH_REALMS, true)) {
        return $requested;
    }

    return match ($role) {
        'restaurant_staff' => 'staff',
        'courier' => 'courier',
        'admin' => 'admin',
        default => 'customer',
    };
}

function refresh_cookie_name(string $realm): string
{
    return 'fuu_rt_' . $realm;
}

/** Grava (ou apaga, com null) o cookie do refresh deste app. */
function set_refresh_cookie(string $realm, ?string $rawRefresh): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    setcookie(refresh_cookie_name($realm), $rawRefresh ?? '', [
        'expires' => $rawRefresh === null ? time() - 3600 : time() + 30 * 86400,
        'path' => '/api/v1/auth/',
        'secure' => is_production_like() || (($_SERVER['HTTPS'] ?? '') === 'on'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/** O refresh deste app que veio no cookie, ou null. */
function refresh_from_cookie(string $realm): ?string
{
    $raw = $_COOKIE[refresh_cookie_name($realm)] ?? null;

    return is_string($raw) && $raw !== '' ? $raw : null;
}

/**
 * Resposta de login/renovação: o refresh vai no cookie. No corpo, só fora
 * de produção/homologação -- é o que os testes de ponta a ponta usam com
 * curl; o app nunca lê de lá.
 */
function tokens_response(array $tokens, string $realm, array $extra = []): never
{
    set_refresh_cookie($realm, (string) $tokens['refresh_token']);
    if (is_production_like()) {
        unset($tokens['refresh_token']);
    }
    json_response(200, $extra + $tokens);
}
