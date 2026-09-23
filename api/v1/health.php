<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';

// Saúde do sistema, pro monitoramento externo (UptimeRobot, Better Stack...)
// e pro deploy conferir que a versão nova respondeu.
//
// GET /api/v1/health.php
//   200 {status: ok}        API no ar, banco respondendo e pg_cron rodando;
//   503 {status: degraded}  algo não responde -- e o motivo vem só como
//                           nome curto (db, cron), sem detalhe interno.
//
// Público e barato de propósito: uma consulta ao banco. Com a trava de
// produção reprovada, nem chega aqui -- o bootstrap já responde 503, que é
// exatamente o que o monitor precisa ver.
//
// O pg_cron conta como saúde: sem ele, Pix vencido não é recusado, loja não
// abre/fecha sozinha e o repasse de terça não sai -- o site parece no ar e o
// dinheiro para. "Rodando" = alguma tarefa do cron terminou nos últimos 5
// minutos (a de Pix vencido roda a cada minuto).

require_method('GET');
header('Cache-Control: no-store');

$checks = ['db' => false, 'cron' => false];
try {
    $pdo = db();
    $checks['db'] = $pdo->query('SELECT 1')->fetchColumn() === 1;
    // cron_healthy() (migração 035): SECURITY DEFINER, porque o app_rw não
    // enxerga o esquema do cron -- só o booleano sai de lá.
    $checks['cron'] = $pdo->query('SELECT cron_healthy()')->fetchColumn() === true;
} catch (Throwable $e) {
    error_log(sprintf('[%s] health: %s', trace_id(), $e->getMessage()));
}

$failing = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
json_response($failing === [] ? 200 : 503, [
    'status' => $failing === [] ? 'ok' : 'degraded',
    'failing' => $failing,
    'time' => gmdate('c'),
]);
