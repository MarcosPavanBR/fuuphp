<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Gera docs/DATABASE.md -- o mapa do banco: cada tabela com a migração que
// a criou, o comentário que a explica (as linhas `--` logo acima do CREATE
// TABLE), as colunas com tipo e as chaves estrangeiras.
//
// A fonte é o banco MIGRADO (information_schema), não a leitura do SQL: é o
// que existe de verdade depois de todas as migrações, inclusive colunas
// acrescentadas por ALTER TABLE em migrações posteriores. O comentário vem
// dos arquivos, porque é lá que o "por quê" foi escrito.
//
// USO (precisa de DATABASE_URL de um banco com todas as migrações aplicadas)
//   php bin/generate_db_map.php          reescreve docs/DATABASE.md
//   php bin/generate_db_map.php --check  só confere (CI); sai 1 se diferir

$root = APP_ROOT;
$target = $root . '/docs/DATABASE.md';
$check = ($argv[1] ?? '') === '--check';
$pdo = db();

// Tabela → [migração, comentário] a partir dos arquivos .up.sql.
$origins = [];
foreach (glob($root . '/db/migrations/*.up.sql') as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    // Primeiro parágrafo do cabeçalho do arquivo (depois da linha com o nome):
    // contexto de reserva pra tabela sem comentário próprio.
    $header = [];
    foreach (array_slice($lines, 1) as $line) {
        if (!preg_match('/^--\s?(.*)$/', $line, $c) || trim($c[1]) === '') {
            if ($header !== []) {
                break;
            }
            continue;
        }
        $header[] = trim($c[1]);
    }
    $headerText = preg_replace('/\s+/', ' ', implode(' ', $header));
    if (mb_strlen($headerText) > 220) {
        $headerText = rtrim(mb_substr($headerText, 0, 217)) . '…';
    }
    foreach ($lines as $i => $line) {
        if (!preg_match('/^\s*CREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF NOT EXISTS\s+)?([a-z_]+)/i', $line, $m)) {
            continue;
        }
        $comment = [];
        for ($j = $i - 1; $j >= 0 && preg_match('/^\s*--\s?(.*)$/', $lines[$j], $c); $j--) {
            array_unshift($comment, trim($c[1]));
        }
        $own = trim(preg_replace('/\s+/', ' ', implode(' ', $comment)));
        $origins[$m[1]] ??= [
            'migration' => basename($file, '.up.sql'),
            'comment' => $own !== '' ? $own : ($headerText !== '' ? "Da migração: {$headerText}" : ''),
        ];
    }
}

$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$columns = $pdo->prepare(
    "SELECT column_name, CASE WHEN data_type = 'USER-DEFINED' THEN udt_name
                              WHEN data_type = 'ARRAY' THEN ltrim(udt_name, '_') || '[]'
                              ELSE data_type END AS type,
            is_nullable = 'YES' AS nullable, column_default IS NOT NULL AS has_default
       FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = :t ORDER BY ordinal_position"
);
$foreign = $pdo->prepare(
    "SELECT kcu.column_name, ccu.table_name AS ref_table, ccu.column_name AS ref_column
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu
         ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
       JOIN information_schema.constraint_column_usage ccu
         ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
      WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = :t
      ORDER BY kcu.column_name"
);
$enums = $pdo->query(
    "SELECT t.typname, string_agg(e.enumlabel, ', ' ORDER BY e.enumsortorder) AS labels
       FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid
      GROUP BY t.typname ORDER BY t.typname"
)->fetchAll();

$out = "# Mapa do banco\n\n";
$out .= "> Gerado por `php bin/generate_db_map.php` a partir do banco com todas as migrações\n";
$out .= "> aplicadas. Não edite à mão: o CI confere (`--check`).\n\n";
$out .= count($tables) . " tabelas em PostgreSQL 16. Regras que o esquema garante sozinho (e que o\n";
$out .= "código não precisa repetir): `orders.status` só muda por `advance_order()`; `ledger_entries`\n";
$out .= "é só de inserção (sem UPDATE/DELETE); um pagamento aprovado por pedido\n";
$out .= "(`payments_one_approved`). Contexto de cada regra: `docs/ARCHITECTURE.md`.\n\n";
$out .= "## Tipos enumerados\n\n";
foreach ($enums as $e) {
    $out .= "- `{$e['typname']}`: {$e['labels']}\n";
}

$out .= "\n## Índice\n\n";
foreach ($tables as $t) {
    $out .= "[`{$t}`](#{$t}) ";
}
$out .= "\n";

foreach ($tables as $t) {
    $origin = $origins[$t] ?? ['migration' => '?', 'comment' => ''];
    $out .= "\n## {$t}\n\n";
    $out .= "Criada em `db/migrations/{$origin['migration']}.up.sql`.";
    if ($origin['comment'] !== '') {
        $out .= ' ' . $origin['comment'];
    }
    $out .= "\n\n| Coluna | Tipo | Nulo | Referência |\n|---|---|---|---|\n";

    $foreign->execute(['t' => $t]);
    $refs = [];
    foreach ($foreign->fetchAll() as $fk) {
        $refs[$fk['column_name']] = "`{$fk['ref_table']}.{$fk['ref_column']}`";
    }
    $columns->execute(['t' => $t]);
    foreach ($columns->fetchAll() as $c) {
        $out .= sprintf(
            "| `%s` | %s%s | %s | %s |\n",
            $c['column_name'],
            $c['type'],
            $c['has_default'] ? ' (padrão)' : '',
            $c['nullable'] ? 'sim' : '',
            $refs[$c['column_name']] ?? ''
        );
    }
}

if ($check) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current !== $out) {
        fwrite(STDERR, "docs/DATABASE.md está desatualizado. Rode: php bin/generate_db_map.php\n");
        exit(1);
    }
    echo 'docs/DATABASE.md em dia (' . count($tables) . " tabelas).\n";
    exit(0);
}
file_put_contents($target, $out);
echo 'docs/DATABASE.md: ' . count($tables) . " tabelas.\n";
