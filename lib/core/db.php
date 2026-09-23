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

    // RLS da migração 009 (orders, payments, payment_proofs, order_messages):
    // em produção a API conecta como `app_rw`, que só enxerga linhas se a
    // sessão disser quem é. Toda conexão começa como "platform" -- quem pode
    // ver o quê é decidido no PHP (authorize_order_access, require_*) -- e a
    // equipe de loja é estreitada pra própria loja em db_scope_to_restaurant().
    // Conexão não persistente: a configuração morre com a requisição.
    // Mesmo relógio do PHP (lib/bootstrap.php): `now()::date` e
    // `created_at::date` falam do dia de Brasília, não do dia UTC -- que vira
    // às 21h daqui. Instantes (timestamptz) não mudam; muda só como a data
    // é lida e o texto com que o horário volta (com -03:00).
    $pdo->exec("SELECT set_config('app.role', 'platform', false), set_config('TimeZone', 'America/Sao_Paulo', false)");

    return $pdo;
}

/**
 * Estreita a conexão à loja do token: dali pra frente, pedido, pagamento,
 * comprovante e mensagem de outra loja não existem nem pro banco (RLS), mesmo
 * que uma rota esqueça de filtrar. Chamada por require_store_staff().
 */
function db_scope_to_restaurant(string $restaurantId): void
{
    $stmt = db()->prepare("SELECT set_config('app.role', 'store', false), set_config('app.restaurant_id', :id, false)");
    $stmt->execute(['id' => $restaurantId]);
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

/**
 * Conexão pgsql "crua" (extensão ext-pgsql, não PDO) -- só existe porque
 * PDO não tem LISTEN/NOTIFY assíncrono. Usada exclusivamente por
 * orders/track.php (SSE da Fase 5.3) pra esperar por pg_notify('order_changed', ...)
 * sem ficar em polling na tabela.
 */
function raw_pg_connect(): \PgSql\Connection
{
    $url = env_required('DATABASE_URL');
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'], $parts['path'])) {
        throw new RuntimeException('DATABASE_URL inválida');
    }

    $connStr = sprintf(
        "host=%s port=%d dbname=%s user=%s password=%s",
        $parts['host'],
        $parts['port'] ?? 5432,
        ltrim($parts['path'], '/'),
        $parts['user'] ?? '',
        $parts['pass'] ?? ''
    );

    $conn = pg_connect($connStr);
    if ($conn === false) {
        throw new RuntimeException('não deu pra abrir conexão pgsql crua pro LISTEN');
    }
    return $conn;
}
