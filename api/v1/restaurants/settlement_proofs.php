<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 9.5, lado da loja — conferir a baixa de espécie que o entregador fez
// por Pix: "Reaproveita a validação humana já existente."
//
//   GET                  os comprovantes esperando conferência, com valor,
//                        entregador e se o arquivo (ou um print parecido) já
//                        apareceu antes -- a mesma pista da fila do cliente (7.3).
//   GET ?image=<id>      a imagem do comprovante (só da própria loja).
//   POST {proof_id, decision: "approve" | "reject", reason?, fraud?}
//        approve → a baixa acontece: os dois lançamentos da 9.3
//                  (ledger_cash_settled), na mesma transação da aprovação;
//        reject  → nada é lançado; o entregador pode mandar outro comprovante
//                  enquanto a baixa estiver no prazo;
//        reject + fraud → "Enviar comprovante falso bloqueia a conta e gera
//                  ocorrência": disputa `fake_proof` pro time da plataforma e
//                  o entregador fica sem corridas em dinheiro.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['image'])) {
    $stmt = $pdo->prepare('SELECT storage_key FROM settlement_proofs WHERE id = :id AND restaurant_id = :r');
    $stmt->execute(['id' => (positive_id($_GET['image']) ?? 0), 'r' => $restaurantId]);
    $key = $stmt->fetchColumn();
    $path = $key === false ? null : app_path(rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/') . '/settlements/' . basename((string) $key));
    if ($path === null || !is_file($path)) {
        error_response(404, 'proof_not_found', 'Comprovante não encontrado.');
    }
    header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream'));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.intent_id, p.created_at, i.amount, i.expires_at, u.full_name AS courier_name,
                EXISTS (SELECT 1 FROM settlement_proofs o WHERE o.id <> p.id
                          AND (o.sha256 = p.sha256 OR (p.phash IS NOT NULL AND o.phash = p.phash)))
             OR EXISTS (SELECT 1 FROM payment_proofs pp
                         WHERE pp.sha256 = p.sha256 OR (p.phash IS NOT NULL AND pp.phash = p.phash)) AS seen_before
           FROM settlement_proofs p
           JOIN cash_settlement_intents i ON i.id = p.intent_id
           JOIN couriers c ON c.id = p.courier_id
           JOIN users u ON u.id = c.user_id
          WHERE p.restaurant_id = :r AND p.state = 'pending'
          ORDER BY p.created_at"
    );
    $stmt->execute(['r' => $restaurantId]);
    json_response(200, ['proofs' => $stmt->fetchAll()]);
}

require_method('POST');
$body = read_json_body();
$proofId = positive_id($body['proof_id'] ?? null) ?? 0;
$decision = $body['decision'] ?? null;
$fraud = ($body['fraud'] ?? false) === true;
$reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
if (mb_strlen($reason) > 300) {
    error_response(422, 'reason_too_long', 'O motivo vai até 300 caracteres.', fields: ['reason' => 'até 300 caracteres']);
}
if ($proofId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    error_response(422, 'invalid_request', 'Informe proof_id e decision (approve ou reject).');
}
if ($decision === 'reject' && $reason === '') {
    error_response(422, 'reason_required', 'Diga o motivo da recusa — o entregador vai ler.', fields: ['reason' => 'obrigatório']);
}

$pdo->beginTransaction();
try {
    // Trava comprovante e baixa: dois atendentes conferindo juntos não baixam duas vezes.
    $stmt = $pdo->prepare(
        "SELECT p.*, i.amount, i.state AS intent_state FROM settlement_proofs p
           JOIN cash_settlement_intents i ON i.id = p.intent_id
          WHERE p.id = :id AND p.restaurant_id = :r
          FOR UPDATE OF p, i"
    );
    $stmt->execute(['id' => $proofId, 'r' => $restaurantId]);
    $proof = $stmt->fetch();
    if ($proof === false) {
        $pdo->rollBack();
        error_response(404, 'proof_not_found', 'Comprovante não encontrado.');
    }
    if ($proof['state'] !== 'pending' || $proof['intent_state'] !== 'open') {
        $pdo->rollBack();
        error_response(409, 'already_decided', 'Esse comprovante já foi conferido.');
    }

    $pdo->prepare(
        'UPDATE settlement_proofs SET state = :s, reject_reason = :why, reviewed_by = :by, reviewed_at = now() WHERE id = :id'
    )->execute([
        's' => $decision === 'approve' ? 'approved' : 'rejected',
        'why' => $decision === 'reject' ? $reason : null,
        'by' => $claims['sub'],
        'id' => $proofId,
    ]);

    if ($decision === 'approve') {
        $pdo->prepare(
            "UPDATE cash_settlement_intents SET state = 'settled', counted_amount = amount, confirmed_by = :by, confirmed_at = now()
              WHERE id = :id"
        )->execute(['by' => $claims['sub'], 'id' => $proof['intent_id']]);
        $intent = $pdo->prepare('SELECT * FROM cash_settlement_intents WHERE id = :id');
        $intent->execute(['id' => $proof['intent_id']]);
        ledger_cash_settled($pdo, $intent->fetch(), (string) $claims['sub'], 'por Pix');
    } elseif ($fraud) {
        $pdo->prepare(
            "INSERT INTO disputes (order_id, courier_id, restaurant_id, kind, risk, amount)
             VALUES (NULL, :c, :r, 'fake_proof', 'high', :amount)"
        )->execute(['c' => $proof['courier_id'], 'r' => $restaurantId, 'amount' => $proof['amount']]);
        $pdo->prepare('UPDATE couriers SET cash_blocked = true WHERE id = :id')->execute(['id' => $proof['courier_id']]);
        $pdo->prepare("UPDATE cash_settlement_intents SET state = 'disputed' WHERE id = :id")->execute(['id' => $proof['intent_id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'decision' => $decision,
    'fraud' => $fraud,
    'courier_cash_balance' => courier_cash_balance($pdo, (string) $proof['courier_id']),
]);
