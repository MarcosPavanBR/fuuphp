<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Varredura que executa os reembolsos decididos na tela 13.4.
//
// POR QUE NÃO NO pg_cron: chamar a API do Mercado Pago é HTTP, e o banco não
// faz HTTP. Mesmo motivo de lib/payments/refund_executor.php morar em PHP.
//
// COMO RODAR (uma linha no cron, a cada minuto):
//
//   * * * * * /usr/local/bin/php /home/USUARIO/app/bin/execute_refunds.php >> /home/USUARIO/logs/refunds.log 2>&1
//
// Duas instâncias ao mesmo tempo são seguras: `FOR UPDATE SKIP LOCKED` faz
// cada linha ser pega por uma só, e a chave de idempotência no gateway cobre
// o caso de o processo cair depois de mandar e antes de gravar.

$pdo = db();

// Só os automáticos entram na varredura. Os manuais (Pix manual, maquininha)
// ficam em 'sent' esperando a confirmação humana no console.
$stmt = $pdo->query(
    "SELECT r.id FROM refunds r
       JOIN orders o ON o.id = r.order_id
      WHERE r.state = 'sent'
        AND o.payment_method IN ('mp_card','pix_auto')
        AND r.channel IN ('gateway','pix_return')
      ORDER BY r.created_at
      LIMIT 50"
);

$done = 0;
$failed = 0;
$retry = 0;
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $result = refund_execute($pdo, (int) $id);
    if ($result['skipped']) {
        continue;
    }
    match ($result['state']) {
        'done' => $done++,
        'failed' => $failed++,
        default => $retry++,
    };
    printf("reembolso %d -> %s\n", $id, $result['state']);
}

printf("[%s] estornos: %d concluído(s), %d pra tentar de novo, %d falho(s)\n", date('c'), $done, $retry, $failed);
