<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Fase 15 — as rodadas do despacho andando sozinhas.
//
// A vitrine de ofertas (couriers/offers.php) já avança as rodadas quando um
// entregador pergunta. Este script cobre a madrugada: pedido pronto e
// NENHUM app aberto perguntando. Sem ele, o raio não cresceria e o surge não
// subiria justamente quando mais falta gente.
//
// COMO RODAR (uma linha no cron, a cada minuto):
//
//   * * * * * /usr/local/bin/php /home/USUARIO/app/bin/dispatch_rounds.php >> /home/USUARIO/logs/dispatch.log 2>&1
//
// Idempotente: sem rodada nova pra abrir, não escreve nada.

$advanced = dispatch_tick(db());
printf("[%s] rodadas abertas: %d\n", date('c'), $advanced);
