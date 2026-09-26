<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Fase 7.3 — painel do restaurante, validação humana do comprovante de Pix
// manual. "SELECT ... FOR UPDATE impede aprovação dupla": o lock pega a
// linha de payment_proofs antes de decidir, então duas abas do painel
// clicando "Aprovar" ao mesmo tempo não aprovam duas vezes. Aprovar chama
// advance_order() (grava o evento) no mesmo commit da revisão -- imprimir
// a comanda (ESC/POS) e notificar o cliente ficam para quando a fila de
// impressão/push existir (Fase 7.2/11), fora do escopo deste módulo.

require_method('POST');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'restaurant_staff') {
    error_response(403, 'forbidden', 'Só a loja valida comprovante de Pix.');
}
$body = read_json_body();

$proofId = positive_id($body['proof_id'] ?? null) ?? 0;
$decision = $body['decision'] ?? null;
if ($proofId <= 0) {
    error_response(422, 'proof_id_required', 'Informe proof_id.', fields: ['proof_id' => 'obrigatório']);
}
if (!in_array($decision, ['approve', 'reject'], true)) {
    error_response(422, 'invalid_decision', 'decision precisa ser "approve" ou "reject".', fields: ['decision' => 'inválido']);
}
// Valor que a loja viu no comprovante: número de R$ 0 a R$ 100.000, ou
// nada. Antes, negativo passava e 1e30 dava 500 no meio da transação.
$countedAmount = null;
if (isset($body['counted_amount'])) {
    $countedAmount = money_input($body['counted_amount'], 0, 100000);
    if ($countedAmount === null) {
        error_response(422, 'invalid_counted_amount', 'Valor conferido em reais.', fields: ['counted_amount' => 'inválido']);
    }
}
$reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : null;
if ($reason !== null && mb_strlen($reason) > 300) {
    error_response(422, 'reason_too_long', 'O motivo vai até 300 caracteres.', fields: ['reason' => 'até 300 caracteres']);
}
if ($decision === 'reject' && ($reason === null || $reason === '')) {
    error_response(422, 'reason_required', 'Informe o motivo da recusa — ele aparece pro cliente (Fase 5.4).', fields: ['reason' => 'obrigatório']);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $lockStmt = $pdo->prepare('SELECT * FROM payment_proofs WHERE id = :id FOR UPDATE');
    $lockStmt->execute(['id' => $proofId]);
    $proof = $lockStmt->fetch();

    if ($proof === false) {
        $pdo->rollBack();
        error_response(404, 'proof_not_found', 'Comprovante não encontrado.');
    }
    if ($proof['restaurant_id'] !== ($claims['restaurant_id'] ?? null)) {
        $pdo->rollBack();
        error_response(404, 'proof_not_found', 'Comprovante não encontrado.');
    }
    if ($proof['state'] !== 'pending') {
        $pdo->rollBack();
        error_response(409, 'proof_already_reviewed', 'Esse comprovante já foi revisado.', detail: "estado atual: {$proof['state']}");
    }

    $newState = $decision === 'approve' ? 'approved' : 'rejected';
    $pdo->prepare(
        'UPDATE payment_proofs SET state = :state, counted_amount = :counted_amount, reviewed_by = :reviewed_by, reviewed_at = now()
         WHERE id = :id'
    )->execute([
        'state' => $newState,
        'counted_amount' => $countedAmount,
        'reviewed_by' => $claims['sub'],
        'id' => $proofId,
    ]);

    $orderId = (int) $proof['order_id'];
    if ($decision === 'approve') {
        $pdo->prepare("UPDATE payments SET status = 'approved' WHERE id = :id")->execute(['id' => $proof['payment_id']]);
        call_advance_order($pdo, $orderId, 'paid', (string) $claims['sub'], 'store', ['proof_id' => $proofId]);
    } else {
        $pdo->prepare("UPDATE payments SET status = 'rejected' WHERE id = :id")->execute(['id' => $proof['payment_id']]);
        $pdo->prepare('UPDATE orders SET reject_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $orderId]);
        call_advance_order($pdo, $orderId, 'rejected', (string) $claims['sub'], 'store', ['proof_id' => $proofId, 'reason' => $reason]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$proofStmt = $pdo->prepare('SELECT * FROM payment_proofs WHERE id = :id');
$proofStmt->execute(['id' => $proofId]);

json_response(200, [
    'proof' => $proofStmt->fetch(),
    'order' => fetch_order($pdo, $orderId),
]);
