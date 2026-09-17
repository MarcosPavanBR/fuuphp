<?php
declare(strict_types=1);

// Conexão PDO única por request, sempre prepared statements (cláusula zero:
// "PHP com PDO e prepared statements sempre").
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $url = env_required('DATABASE_URL');
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'], $parts['path'])) {
        throw new RuntimeException('DATABASE_URL inválida');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $parts['host'],
        $parts['port'] ?? 5432,
        ltrim($parts['path'], '/')
    );

    $pdo = new PDO($dsn, $parts['user'] ?? null, $parts['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/**
 * Com ATTR_EMULATE_PREPARES=false, PDO::execute(array) manda todo valor
 * como string -- um PHP `false` vira '' na conexão, e o pgsql rejeita ''
 * como boolean ("invalid input syntax for type boolean"). Use isto em
 * qualquer parâmetro que vá para uma coluna `boolean`.
 */
function pg_bool(bool $value): string
{
    return $value ? 'true' : 'false';
}
