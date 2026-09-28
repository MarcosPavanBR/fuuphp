<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// A impressora térmica da loja (ESC/POS): o que falta imprimir, e o registro
// do que saiu no papel. Quem imprime é o tablet do balcão, que está ligado na
// impressora por USB -- o servidor não alcança a rede da loja.
//
//   GET ?columns=48                  a fila: comandas de pedidos pagos (7.3:
//                                    "Aprovar ... imprime a comanda") e recibos
//                                    de baixa confirmada (9.3) ainda sem papel,
//                                    cada um com os bytes ESC/POS em base64 e o
//                                    texto pra pré-visualizar.
//   GET ?kind=&ref_id=&columns=      um documento avulso (reimpressão).
//   POST {kind, ref_id, reprint?}    "saiu no papel": tira da fila.
//
// `columns` é da impressora do aparelho: 48 (80 mm), 42 ou 32 (58 mm).
// Janela da fila: últimas 12 h -- pedido de ontem que nunca imprimiu não é
// mais comanda útil, é histórico.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

$columns = (int) ($_GET['columns'] ?? 48);
if (!in_array($columns, ESCPOS_COLUMNS, true)) {
    error_response(422, 'invalid_columns', 'Largura da impressora: 32, 42 ou 48 colunas.');
}

/** Monta o documento pra resposta: título, bytes (base64) e texto. */
function print_job(string $kind, int $refId, ?array $doc, int $columns): ?array
{
    if ($doc === null) {
        return null;
    }

    return [
        'kind' => $kind,
        'ref_id' => $refId,
        'title' => $doc['title'],
        'escpos_base64' => base64_encode(escpos_render($doc['items'], $columns)),
        'text' => escpos_text($doc['items'], $columns),
    ];
}

function print_document(PDO $pdo, string $kind, int $refId): ?array
{
    return match ($kind) {
        'order_ticket' => print_order_ticket($pdo, $refId),
        'settlement_receipt' => print_settlement_receipt($pdo, $refId),
        default => null,
    };
}

/** O documento é desta loja? (pedido ou baixa) */
function print_belongs_to(PDO $pdo, string $kind, int $refId, string $restaurantId): bool
{
    $table = $kind === 'order_ticket' ? 'orders' : 'cash_settlement_intents';
    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id AND restaurant_id = :r");
    $stmt->execute(['id' => $refId, 'r' => $restaurantId]);

    return $stmt->fetchColumn() !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['kind'])) {
        $kind = input_str($_GET, 'kind');
        $refId = positive_id($_GET['ref_id'] ?? null) ?? 0;
        if (!in_array($kind, ['order_ticket', 'settlement_receipt'], true) || !print_belongs_to($pdo, $kind, $refId, $restaurantId)) {
            error_response(404, 'document_not_found', 'Documento não encontrado.');
        }
        $job = print_job($kind, $refId, print_document($pdo, $kind, $refId), $columns);
        if ($job === null) {
            error_response(404, 'document_not_found', 'Documento não encontrado.');
        }
        json_response(200, ['job' => $job]);
    }

    $pending = $pdo->prepare(
        "SELECT 'order_ticket' AS kind, o.id AS ref_id, o.updated_at AS at FROM orders o
          WHERE o.restaurant_id = :r AND o.status IN ('paid','preparing')
            AND o.updated_at > now() - interval '12 hours'
            AND NOT EXISTS (SELECT 1 FROM print_log p WHERE p.kind = 'order_ticket' AND p.ref_id = o.id)
         UNION ALL
         SELECT 'settlement_receipt', i.id, i.confirmed_at FROM cash_settlement_intents i
          WHERE i.restaurant_id = :r AND i.state = 'settled'
            AND i.confirmed_at > now() - interval '12 hours'
            AND NOT EXISTS (SELECT 1 FROM print_log p WHERE p.kind = 'settlement_receipt' AND p.ref_id = i.id)
         ORDER BY at"
    );
    $pending->execute(['r' => $restaurantId]);
    $jobs = [];
    foreach ($pending->fetchAll() as $row) {
        $job = print_job($row['kind'], (int) $row['ref_id'], print_document($pdo, $row['kind'], (int) $row['ref_id']), $columns);
        if ($job !== null) {
            $jobs[] = $job;
        }
    }
    json_response(200, ['jobs' => $jobs]);
}

require_method('POST');
$body = read_json_body();
$kind = input_str($body, 'kind');
$refId = positive_id($body['ref_id'] ?? null) ?? 0;
$reprint = ($body['reprint'] ?? false) === true;
if (!in_array($kind, ['order_ticket', 'settlement_receipt'], true) || !print_belongs_to($pdo, $kind, $refId, $restaurantId)) {
    error_response(404, 'document_not_found', 'Documento não encontrado.');
}

// Primeira via repetida (duas abas confirmando juntas) não é erro: já saiu.
$pdo->prepare(
    'INSERT INTO print_log (restaurant_id, kind, ref_id, reprint, printed_by)
     VALUES (:r, :kind, :ref, :reprint, :by)
     ON CONFLICT DO NOTHING'
)->execute([
    'r' => $restaurantId,
    'kind' => $kind,
    'ref' => $refId,
    'reprint' => $reprint ? 'true' : 'false',
    'by' => $claims['sub'],
]);

json_response(200, ['printed' => true]);
