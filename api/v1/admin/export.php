<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 12.3 — a exportação contábil que a tela promete.
//
// Os números da 12.3 já estavam na tela; faltava levá-los pra quem fecha a
// contabilidade. Três arquivos, cada um uma pergunta que o contador faz:
//
//   ledger   → "de onde veio e pra onde foi cada centavo": o livro inteiro
//              do período, linha por linha, com origem e autor.
//   orders   → "o que foi vendido": pedido, forma de pagamento, total,
//              comissão e frete.
//   payouts  → "o que foi acertado": repasses e débitos semanais.
//
// Formato: CSV com `;` e vírgula decimal, com BOM UTF-8 -- é o que o Excel
// em português abre direto com acento e número certo. Separador `,` com
// ponto decimal é o padrão americano e chega na planilha como texto.

require_method('GET');
$claims = require_auth();
require_admin($claims);
$pdo = db();

const EXPORT_KINDS = ['ledger', 'orders', 'payouts'];

$kind = (string) ($_GET['kind'] ?? '');
if (!in_array($kind, EXPORT_KINDS, true)) {
    error_response(422, 'invalid_kind', 'Informe kind: ledger, orders ou payouts.', fields: ['kind' => 'inválido']);
}

// Data que existe no calendário (2026-02-30 dava 500 no banco).
$isDate = static fn ($v) => is_valid_date($v);
$from = $isDate($_GET['from'] ?? null) ? (string) $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = $isDate($_GET['to'] ?? null) ? (string) $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    error_response(422, 'invalid_period', 'O início do período vem depois do fim.');
}

[$sql, $columns] = match ($kind) {
    'ledger' => [
        "SELECT l.id, l.created_at, l.account::text, l.party_id, l.amount, l.origin, l.origin_id,
                o.public_code, u.full_name AS actor, l.memo
           FROM ledger_entries l
           LEFT JOIN orders o ON o.id = l.order_id
           LEFT JOIN users u ON u.id = l.actor_id
          WHERE l.created_at >= :from::date AND l.created_at < :to::date + 1
          ORDER BY l.created_at, l.id",
        ['id', 'data_hora', 'conta', 'parte', 'valor', 'origem', 'origem_id', 'pedido', 'autor', 'memo'],
    ],
    'orders' => [
        "SELECT o.public_code, o.created_at, r.name AS restaurante, o.status, o.payment_method::text,
                o.subtotal, o.delivery_fee, o.surge_fee, o.discount, o.tip, o.total, o.commission
           FROM orders o
           JOIN restaurants r ON r.id = o.restaurant_id
          WHERE o.status <> 'cart'
            AND o.created_at >= :from::date AND o.created_at < :to::date + 1
          ORDER BY o.created_at",
        ['pedido', 'data_hora', 'restaurante', 'status', 'pagamento', 'subtotal', 'frete', 'turbo',
         'desconto', 'gorjeta', 'total', 'comissao'],
    ],
    'payouts' => [
        "SELECT p.id, p.party_kind, COALESCE(r.name, u.full_name) AS parte, p.period_start, p.period_end,
                p.gross, p.withheld, p.net, p.state, p.provider_ref
           FROM payouts p
           LEFT JOIN restaurants r ON p.party_kind = 'restaurant' AND r.id = p.party_id
           LEFT JOIN couriers c ON p.party_kind = 'courier' AND c.id = p.party_id
           LEFT JOIN users u ON u.id = c.user_id
          WHERE p.period_start >= :from::date AND p.period_end <= :to::date
          ORDER BY p.period_start, p.party_kind",
        ['id', 'tipo', 'parte', 'inicio', 'fim', 'bruto', 'retido', 'liquido', 'estado', 'referencia'],
    ],
};

$stmt = $pdo->prepare($sql);
$stmt->execute(['from' => $from, 'to' => $to]);

// Campos numéricos saem com vírgula decimal; o resto sai como veio.
$numeric = ['valor', 'subtotal', 'frete', 'turbo', 'desconto', 'gorjeta', 'total', 'comissao',
            'bruto', 'retido', 'liquido'];

header('Content-Type: text/csv; charset=utf-8');
header(sprintf('Content-Disposition: attachment; filename="fuu-%s-%s-a-%s.csv"', $kind, $from, $to));
header('Cache-Control: private, no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $columns, ';', '"', '');
while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
    foreach ($row as $i => $value) {
        if ($value !== null && in_array($columns[$i], $numeric, true)) {
            $row[$i] = number_format((float) $value, 2, ',', '');
        }
    }
    fputcsv($out, $row, ';', '"', '');
}
fclose($out);
