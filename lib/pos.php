<?php
declare(strict_types=1);

// Telas 9.6, 10.4 e 10.6 — a maquininha física.
//
// A frase que organiza tudo é da tela 10.4: **"a máquina é do
// estabelecimento"**. Daí saem as três consequências que o código precisa
// respeitar:
//
//   1. O dinheiro NÃO passa pelo entregador. "Quando o cliente paga na
//      porta, o dinheiro entra direto na adquirente do restaurante — o
//      entregador não fica devendo nada." Por isso venda na maquininha não
//      lança `courier_cash`: não há dívida, há conferência.
//   2. A baixa é por CONCILIAÇÃO: o entregador informa NSU e valor, e o
//      sistema cruza com o extrato da adquirente.
//   3. O equipamento tem dono e prazo: sai registrado em `pos_custody` e
//      volta com confirmação da loja.

// "Atraso alerta em 30 min e bloqueia nova retirada" (tela 10.6).
const POS_LATE_ALERT_MINUTES = 30;

/**
 * A máquina está livre pra sair? Só quando a última custódia dela foi
 * confirmada pela loja. O índice `pos_one_holder` do banco cobre o caso
 * "ninguém devolveu"; este cobre "devolveu e a loja ainda não conferiu".
 */
function pos_device_free(PDO $pdo, string $deviceId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM pos_custody WHERE device_id = :id AND confirmed_by IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $deviceId]);

    return $stmt->fetchColumn() === false;
}

/**
 * A custódia deste entregador que ainda não FECHOU, se houver.
 *
 * Fechar é a loja confirmar (`confirmed_by`), não ele marcar "devolvi"
 * (`returned_at`): "custódia com dois lados: ele marca 'devolvi', a loja
 * confirma no painel" (tela 10.6). Uma versão anterior deste código
 * considerava fechada no "devolvi" -- e aí a custódia sumia das duas telas
 * antes de a loja confirmar, e a segunda ponta nunca aparecia pra ninguém.
 * Achado no navegador.
 */
function pos_custody_open(PDO $pdo, string $courierId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT c.*, d.label, d.acquirer, d.restaurant_id, r.name AS restaurant_name,
                EXTRACT(epoch FROM (c.due_at - now())) AS seconds_left,
                (c.due_at < now()) AS overdue
           FROM pos_custody c
           JOIN pos_devices d ON d.id = c.device_id
           JOIN restaurants r ON r.id = d.restaurant_id
          WHERE c.courier_id = :id AND c.confirmed_by IS NULL
          ORDER BY c.taken_at DESC LIMIT 1"
    );
    $stmt->execute(['id' => $courierId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * As vendas feitas nesta custódia — "VENDAS FEITAS NELA HOJE" (tela 10.6),
 * incluindo as que ainda estão sem NSU, que são justamente as que travam o
 * fechamento do dia.
 */
function pos_custody_sales(PDO $pdo, array $custody): array
{
    $stmt = $pdo->prepare(
        "SELECT t.*, o.public_code
           FROM card_transactions t
           LEFT JOIN orders o ON o.id = t.order_id
          WHERE t.device_id = :device AND t.created_at >= :since
          ORDER BY t.created_at"
    );
    $stmt->execute(['device' => $custody['device_id'], 'since' => $custody['taken_at']]);

    return $stmt->fetchAll();
}

/**
 * Quanto falta pra devolver, em texto curto ("1:02"). Negativo vira atraso,
 * e atraso é o que bloqueia nova retirada.
 */
function pos_deadline_label(float $secondsLeft): string
{
    $abs = (int) abs($secondsLeft);
    $label = sprintf('%d:%02d', intdiv($abs, 3600), intdiv($abs % 3600, 60));

    return $secondsLeft >= 0 ? $label : ('atrasada há ' . $label);
}

/**
 * A conferência do dia (tela 9.6): cada venda informada no app ao lado do
 * que veio no extrato.
 *
 * As três situações da tela saem do `CHECK` de `card_transactions.state`
 * mais a ausência de NSU:
 *   - CONCILIADO   → state 'reconciled'
 *   - DIVERGÊNCIA  → state 'divergent' (com a diferença em dinheiro)
 *   - PENDENTE     → state 'pending' (sem par no extrato, ou sem NSU)
 */
function pos_reconciliation(PDO $pdo, string $restaurantId, string $day): array
{
    $stmt = $pdo->prepare(
        "SELECT t.*, o.public_code, d.label AS device_label,
                u.full_name AS courier_name
           FROM card_transactions t
           LEFT JOIN orders o ON o.id = t.order_id
           LEFT JOIN pos_devices d ON d.id = t.device_id
           LEFT JOIN couriers c ON c.id = o.courier_id
           LEFT JOIN users u ON u.id = c.user_id
          WHERE d.restaurant_id = :rid
            AND t.created_at >= :day::date
            AND t.created_at < :day::date + 1
          ORDER BY t.created_at"
    );
    $stmt->execute(['rid' => $restaurantId, 'day' => $day]);
    $rows = $stmt->fetchAll();

    $reconciled = 0;
    $toResolve = 0;
    $total = 0.0;
    foreach ($rows as &$row) {
        $row['difference'] = $row['amount_statement'] === null
            ? null
            : round((float) $row['amount_statement'] - (float) $row['amount_app'], 2);
        $row['situation'] = match ((string) $row['state']) {
            'reconciled' => 'CONCILIADO',
            'divergent' => 'DIVERGÊNCIA',
            default => 'PENDENTE',
        };
        if ($row['state'] === 'reconciled') {
            $reconciled++;
        } else {
            $toResolve++;
        }
        $total += (float) $row['amount_app'];
    }
    unset($row);

    return [
        'day' => $day,
        'rows' => $rows,
        'reconciled' => $reconciled,
        'to_resolve' => $toResolve,
        'total' => round($total, 2),
        // "Divergência de valor ou NSU faltando trava o fechamento do dia."
        // O fechamento é a afirmação "este dia está conferido"; enquanto
        // houver linha aberta, ela é falsa.
        'day_closed' => $toResolve === 0 && $rows !== [],
    ];
}

/**
 * Cruza uma linha do extrato com a venda informada no app.
 *
 * "match por nsu, fallback valor+hora" (chip da tela). O NSU é a chave de
 * verdade -- `UNIQUE (acquirer, nsu)` no banco --; o fallback existe porque
 * extrato de adquirente chega com NSU truncado mais vezes do que se gosta de
 * admitir, e uma venda com valor e horário batendo na janela é par bom o
 * bastante pra apresentar ao humano.
 */
function pos_match_statement_row(PDO $pdo, string $restaurantId, array $line): ?array
{
    if (($line['nsu'] ?? '') !== '') {
        $stmt = $pdo->prepare(
            "SELECT t.* FROM card_transactions t
               JOIN pos_devices d ON d.id = t.device_id
              WHERE d.restaurant_id = :rid AND t.acquirer = :acq AND t.nsu = :nsu
              LIMIT 1"
        );
        $stmt->execute(['rid' => $restaurantId, 'acq' => $line['acquirer'], 'nsu' => $line['nsu']]);
        $found = $stmt->fetch();
        if ($found !== false) {
            return $found;
        }
    }

    // Fallback: mesmo valor, mesma adquirente, dentro de 30 min, ainda sem
    // par. Não casa duas linhas com a mesma venda porque só pega 'pending'.
    $stmt = $pdo->prepare(
        "SELECT t.* FROM card_transactions t
           JOIN pos_devices d ON d.id = t.device_id
          WHERE d.restaurant_id = :rid AND t.acquirer = :acq
            AND t.state = 'pending' AND t.amount_statement IS NULL
            AND t.amount_app = :amount
            AND t.created_at BETWEEN :at::timestamptz - interval '30 min'
                                 AND :at::timestamptz + interval '30 min'
          ORDER BY abs(EXTRACT(epoch FROM (t.created_at - :at::timestamptz)))
          LIMIT 1"
    );
    $stmt->execute([
        'rid' => $restaurantId,
        'acq' => $line['acquirer'],
        'amount' => $line['amount'],
        'at' => $line['at'],
    ]);
    $found = $stmt->fetch();

    return $found === false ? null : $found;
}

/**
 * Lê o CSV da adquirente. Formato mínimo e declarado na tela de importação:
 * nsu, valor, data/hora, bandeira, tipo.
 *
 * Não há integração com API de adquirente neste projeto -- "conecte a API" é
 * o outro caminho que a tela oferece e que não existe aqui. O CSV é o que dá
 * pra fazer de verdade com o que existe, e é o que bancos e adquirentes
 * entregam de qualquer jeito.
 */
function pos_parse_statement(string $csv): array
{
    $lines = [];
    $errors = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $header = fgetcsv($handle, 0, ',', '"', '');
    if ($header === false) {
        fclose($handle);

        return [[], ['arquivo vazio']];
    }
    $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
    $need = ['nsu', 'valor', 'data_hora'];
    foreach ($need as $column) {
        if (!in_array($column, $header, true)) {
            fclose($handle);

            return [[], ['falta a coluna "' . $column . '" no cabeçalho']];
        }
    }

    $index = array_flip($header);
    $number = 1;
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $number++;
        if ($row === [null] || $row === []) {
            continue;
        }
        $amount = str_replace(',', '.', trim((string) ($row[$index['valor']] ?? '')));
        $at = trim((string) ($row[$index['data_hora']] ?? ''));
        if (!is_numeric($amount) || strtotime($at) === false) {
            $errors[] = 'linha ' . $number . ': valor ou data inválidos';
            continue;
        }
        $lines[] = [
            'nsu' => trim((string) ($row[$index['nsu']] ?? '')),
            'amount' => round((float) $amount, 2),
            'at' => date('c', (int) strtotime($at)),
            'brand' => isset($index['bandeira']) ? trim((string) ($row[$index['bandeira']] ?? '')) : null,
            'kind' => isset($index['tipo']) && str_starts_with(strtolower((string) ($row[$index['tipo']] ?? '')), 'cr')
                ? 'credit'
                : 'debit',
        ];
    }
    fclose($handle);

    return [$lines, $errors];
}
