<?php

declare(strict_types=1);

// Confere, antes de pôr uma versão no ar, se ela sobe em produção.
//
//   FUU_ENV_FILE=/etc/fuuphp/fuuphp.env php bin/check_production.php
//
// Chamado pelo deploy/deploy.sh antes de trocar o symlink. Sai com 0 e "ok"
// quando dá; com 1 e a lista do que falta quando não dá.
//
// Além da trava de produção (lib/core/production_guard.php, que o
// bootstrap já aplica e que sai com 1 sozinha), confere o que a trava não
// confere a cada requisição porque custaria uma ida ao banco:
//   - o banco responde com o DATABASE_URL do .env;
//   - a API NÃO conecta como superusuário nem como papel que ignora RLS
//     (senão a RLS por loja da migração 009 é só enfeite);
//   - pg_cron está instalado E rodando (as tarefas do banco dependem dele).

require_once __DIR__ . '/../lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!is_production_like()) {
    fwrite(STDERR, 'APP_ENV=' . app_env() . ": este script é pra production/staging.\n");
    exit(1);
}

$problems = [];
try {
    $role = db()->query(
        'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
    )->fetch();
    if ($role['rolsuper'] === true || $role['rolbypassrls'] === true) {
        $problems[] = "DATABASE_URL conecta como {$role['name']}, que ignora RLS -- use app_rw (deploy/postgres/set_passwords.sql)";
    }
    $cron = db()->query("SELECT 1 FROM pg_extension WHERE extname = 'pg_cron'")->fetchColumn();
    if ($cron === false) {
        $problems[] = 'extensão pg_cron ausente: as tarefas do banco não vão rodar';
    } elseif (db()->query('SELECT cron_healthy()')->fetchColumn() !== true) {
        // Instalado não é rodando: com a configuração padrão, toda tarefa
        // falha com "connection failed" e ninguém vê.
        $problems[] = 'pg_cron instalado mas nenhuma tarefa terminou nos últimos 5 min: ligue cron.use_background_workers = on (docs/GO_LIVE.md) e veja cron.job_run_details';
    }
} catch (Throwable $e) {
    $problems[] = 'banco inacessível com o DATABASE_URL do .env: ' . $e->getMessage();
}

if ($problems !== []) {
    fwrite(STDERR, "Não sobe:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

echo 'ok: APP_ENV=' . app_env() . ", banco como {$role['name']}, sem modo simulado.\n";
