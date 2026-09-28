<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Tela 7.2 — o worker que a outbox esperava desde a migração 004.
//
// Lê a outbox, transforma aprovação e saída pra entrega em notificação,
// varre os Pix manuais perto de vencer, e acorda os aparelhos
// (lib/messaging/push.php). Tudo idempotente: rodar duas vezes não avisa duas vezes.
//
// COMO RODAR (uma linha no cron, a cada minuto):
//
//   * * * * * /usr/local/bin/php /home/USUARIO/app/bin/push_worker.php >> /home/USUARIO/logs/push.log 2>&1
//
// Latência de até um minuto é aceitável pros três avisos da tela: nenhum é
// "agora ou nunca". Se um dia for, o pg_notify que advance_order() já emite
// é o gancho pra um processo residente.

$pdo = db();
$fromOutbox = notifications_from_outbox($pdo);
$deadlines = notifications_proof_deadlines($pdo);

printf(
    "[%s] push (%s): %d aviso(s) de eventos, %d de prazo\n",
    date('c'),
    push_mode(),
    $fromOutbox,
    $deadlines
);
