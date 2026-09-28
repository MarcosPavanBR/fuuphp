<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 9.6 — "Painel da loja: conciliação da maquininha física".
//
// "Resposta a 'como é com a maquininha': não há entrega de dinheiro, há
// conferência. NSU informado no app × extrato da adquirente; divergência
// trava o fechamento e abre ocorrência."
//
// GET  → a conferência do dia, com as três situações e os totais.
// POST → importa o CSV da adquirente e cruza, linha por linha.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

$day = is_valid_date($_GET['day'] ?? null)
    ? $_GET['day']
    // "16/09 · fechamento do dia anterior": a conferência olha pra ontem,
    // porque o extrato da adquirente do dia de hoje ainda não existe.
    // "Ontem" no relógio da loja, que pode não ser o de Brasília.
    : store_local_day($pdo, $restaurantId, -1);

function reconciliation_payload(PDO $pdo, string $restaurantId, string $day): array
{
    $view = pos_reconciliation($pdo, $restaurantId, $day);

    $last = $pdo->prepare(
        'SELECT * FROM acquirer_statements WHERE restaurant_id = :id
          ORDER BY imported_at DESC LIMIT 1'
    );
    $last->execute(['id' => $restaurantId]);

    $issues = $pdo->prepare(
        "SELECT d.*, o.public_code
           FROM disputes d
           LEFT JOIN orders o ON o.id = d.order_id
          WHERE d.kind = 'nsu_divergent' AND d.state = 'open'
            AND o.restaurant_id = :id
          ORDER BY d.created_at DESC"
    );
    $issues->execute(['id' => $restaurantId]);

    $policy = $pdo->query(
        'SELECT allow_courier_own_pos FROM platform_policies ORDER BY version DESC LIMIT 1'
    )->fetchColumn();

    return array_merge($view, [
        'last_statement' => $last->fetch() ?: null,
        'open_issues' => $issues->fetchAll(),
        // "Maquininha do próprio entregador: o valor cai na conta dele, então
        // vira dívida com a loja e segue o mesmo fluxo da espécie." A regra
        // é política (`allow_courier_own_pos`, migração 003) e hoje o
        // cadastro de máquina é sempre da loja -- ver README.
        'allow_courier_own_pos' => $policy === false ? false : $policy,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(200, reconciliation_payload($pdo, $restaurantId, $day));
}

require_method('POST');

// Importação é multipart (o CSV que a adquirente manda). "Arraste o CSV da
// adquirente ou conecte a API": a API não existe neste projeto, e fingir que
// existe seria pior que a tela dizer isso.
if (!isset($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) {
    error_response(422, 'statement_required', 'Envie o CSV da adquirente no campo "statement".', fields: ['statement' => 'obrigatório']);
}
$acquirer = strtolower(trim((string) ($_POST['acquirer'] ?? '')));
if ($acquirer === '') {
    error_response(422, 'acquirer_required', 'Informe a adquirente do extrato.', fields: ['acquirer' => 'obrigatório']);
}
$day = isset($_POST['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['day']) === 1
    ? (string) $_POST['day']
    : $day;

$bytes = file_get_contents($_FILES['statement']['tmp_name']);
if ($bytes === false || trim($bytes) === '') {
    error_response(422, 'empty_file', 'Arquivo vazio.');
}
if (strlen($bytes) > 5 * 1024 * 1024) {
    error_response(422, 'file_too_large', 'Extrato maior que 5 MB.');
}
$sha256 = hash('sha256', $bytes);

[$lines, $errors] = pos_parse_statement($bytes);
if ($lines === []) {
    error_response(422, 'invalid_statement', 'Não deu pra ler nenhuma linha do extrato.', detail: implode('; ', $errors));
}

$matched = 0;
$orphan = [];
$divergent = [];

$pdo->beginTransaction();
try {
    foreach ($lines as $line) {
        $line['acquirer'] = $acquirer;
        $found = pos_match_statement_row($pdo, $restaurantId, $line);

        if ($found === null) {
            // "#A38F63 · não informado · sem par · PENDENTE" ao contrário:
            // linha do extrato sem venda informada no app. Não se inventa
            // venda -- registra-se o órfão pra o humano olhar.
            $orphan[] = $line;
            continue;
        }

        $difference = round($line['amount'] - (float) $found['amount_app'], 2);
        $state = abs($difference) < 0.005 ? 'reconciled' : 'divergent';

        $pdo->prepare(
            'UPDATE card_transactions
                SET amount_statement = :amount, state = :state,
                    brand = COALESCE(:brand, brand),
                    nsu = COALESCE(nsu, :nsu)
              WHERE id = :id'
        )->execute([
            'amount' => $line['amount'],
            'state' => $state,
            'brand' => $line['brand'] === '' ? null : $line['brand'],
            'nsu' => $line['nsu'] === '' ? null : $line['nsu'],
            'id' => $found['id'],
        ]);
        $matched++;

        if ($state !== 'divergent') {
            continue;
        }

        // "Divergência de valor ou NSU faltando trava o fechamento do dia e
        // vira ocorrência para a plataforma." A ocorrência é `disputes` com
        // kind 'nsu_divergent' (migração 008) -- a mesma fila do admin, não
        // uma lista nova só pra esta tela.
        $exists = $pdo->prepare(
            "SELECT 1 FROM disputes
              WHERE order_id = :order AND kind = 'nsu_divergent' AND state = 'open'"
        );
        $exists->execute(['order' => $found['order_id']]);
        if ($exists->fetchColumn() === false) {
            $pdo->prepare(
                "INSERT INTO disputes (order_id, kind, risk, amount, state)
                 VALUES (:order, 'nsu_divergent', :risk, :amount, 'open')"
            )->execute([
                'order' => $found['order_id'],
                'risk' => abs($difference) >= 50 ? 'high' : 'medium',
                'amount' => abs($difference),
            ]);
        }
        $divergent[] = ['nsu' => $line['nsu'], 'difference' => $difference];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO acquirer_statements
            (restaurant_id, acquirer, reference_day, filename, rows_total, rows_matched, rows_orphan, sha256, imported_by)
         VALUES (:rid, :acq, :day, :file, :total, :matched, :orphan, :sha, :by)
         RETURNING *'
    );
    $stmt->execute([
        'rid' => $restaurantId,
        'acq' => $acquirer,
        'day' => $day,
        'file' => (string) ($_FILES['statement']['name'] ?? 'extrato.csv'),
        'total' => count($lines),
        'matched' => $matched,
        'orphan' => count($orphan),
        'sha' => $sha256,
        'by' => $claims['sub'],
    ]);
    $statement = $stmt->fetch();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (str_contains($e->getMessage(), 'acquirer_statements_file_idx')) {
        // Reimportar o mesmo arquivo é erro de operação, não correção: as
        // linhas dele já mudaram de estado na primeira vez.
        error_response(409, 'statement_already_imported', 'Esse mesmo arquivo já foi importado.');
    }
    throw $e;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, array_merge(reconciliation_payload($pdo, $restaurantId, $day), [
    'statement' => $statement,
    'matched' => $matched,
    'divergent' => $divergent,
    'orphan' => $orphan,
    'parse_errors' => $errors,
]));
