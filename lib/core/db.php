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
    $pdo = db_connect();

    return $pdo;
}

/**
 * Uma conexão nova, fora da única da requisição. Quem precisa é o registro
 * de erro (lib/core/app_errors.php): o erro pode ter acontecido no meio de
 * uma transação que já falhou, e nada mais roda nela até o ROLLBACK.
 */
function db_connect(): PDO
{
    $c = db_url_parts();
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['dbname']);
    if ($c['sslmode'] !== null) {
        $dsn .= ';sslmode=' . $c['sslmode'];
    }

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
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
    $c = db_url_parts();
    // Formato chave='valor' do libpq: aspas simples e barra escapadas, pra
    // senha com espaço ou aspas não quebrar a string.
    $q = static fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
    $connStr = sprintf(
        'host=%s port=%d dbname=%s user=%s password=%s',
        $q($c['host']),
        $c['port'],
        $q($c['dbname']),
        $q((string) $c['user']),
        $q((string) $c['pass'])
    );
    if ($c['sslmode'] !== null) {
        $connStr .= ' sslmode=' . $q($c['sslmode']);
    }

    $conn = pg_connect($connStr);
    if ($conn === false) {
        throw new RuntimeException('não deu pra abrir conexão pgsql crua pro LISTEN');
    }
    return $conn;
}

/**
 * DATABASE_URL decomposta, igual pro PDO e pro pg_connect.
 *
 * - Usuário e senha vêm decodificados (%40 vira @): a senha gerada pelo
 *   Supabase/Render pode ter caractere que precisa ir codificado na URL.
 * - ?sslmode=require (ou verify-full etc.) é repassado ao libpq. Banco
 *   gerenciado fora da máquina (Supabase) exige TLS; na VPS, com o banco em
 *   127.0.0.1, a URL não traz sslmode e nada muda.
 *
 * @return array{host:string,port:int,dbname:string,user:?string,pass:?string,sslmode:?string}
 */
function db_url_parts(): array
{
    $parts = parse_url(env_required('DATABASE_URL'));
    if ($parts === false || !isset($parts['host'], $parts['path'])) {
        throw new RuntimeException('DATABASE_URL inválida');
    }
    parse_str($parts['query'] ?? '', $query);
    $sslmode = $query['sslmode'] ?? null;
    if ($sslmode !== null && (!is_string($sslmode) || !in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true))) {
        throw new RuntimeException('DATABASE_URL com sslmode inválido');
    }

    return [
        'host' => $parts['host'],
        'port' => (int) ($parts['port'] ?? 5432),
        'dbname' => rawurldecode(ltrim($parts['path'], '/')),
        'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
        'pass' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        'sslmode' => $sslmode,
    ];
}
