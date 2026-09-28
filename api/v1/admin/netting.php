<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Tela 9.7 — "Painel da plataforma: netting semanal e repasse".
//
// "Quem paga o motoboy somos nós, e o restaurante nos devolve taxa + frete
// em um débito único por semana."
//
// `generate_weekly_payouts()` já existia (migração 009, agendada no pg_cron
// pra terça 3h): ela rascunha os payouts das duas pontas a partir do livro.
// O que não existia era a MESA — ver a semana, gerar quando faltou, mandar o
// lote, dar baixa e aplicar os bloqueios que a tela promete.
//
// Nada aqui edita saldo. "Livro append-only: saldo é soma de lançamentos,
// correção é contrapartida — nunca edição." Dar baixa num repasse muda o
// estado do `payouts`, e o lançamento de contrapartida no livro é o que
// zera o que se devia.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

$policy = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
if ($policy === false) {
    error_response(500, 'no_policy', 'Nenhuma política de plataforma cadastrada.');
}
$debitDow = (int) ($policy['store_debit_dow'] ?? 2);

function netting_payload(PDO $pdo, string $start, string $end, int $debitDow): array
{
    $stores = netting_by_store($pdo, $start, $end);
    foreach ($stores as &$store) {
        $due = netting_due($end, $debitDow);
        $paid = (string) ($store['payout_state'] ?? '') === 'paid';
        $store['due_on'] = $due['due_on'];
        $store['late_days'] = $paid ? 0 : $due['late_days'];
        // "A COBRAR" é a soma do que ficou em aberto: taxa não retida na
        // hora mais o frete que adiantamos. É exatamente `store_receivable`
        // da semana -- por isso a coluna vem do livro e não de uma conta
        // refeita aqui.
        $store['to_charge'] = round((float) $store['owed'], 2);
        $store['status'] = match (true) {
            $paid => 'PAGO',
            (string) ($store['payout_state'] ?? '') === 'sent' && $store['late_days'] === 0 => 'DÉBITO OK',
            $store['late_days'] > 0 => 'ATRASO ' . $store['late_days'] . 'D',
            default => 'A VENCER',
        };
    }
    unset($store);

    return [
        'summary' => netting_summary($pdo, $start, $end),
        'stores' => $stores,
        'couriers' => netting_courier_batch($pdo, $start, $end),
        'blocks' => netting_blocks($pdo),
        'debit_dow' => $debitDow,
        // As quatro linhas de "como o dinheiro se organiza", pra a regra
        // ficar ao lado do número em vez de na cabeça de quem opera.
        'rules' => [
            'Pedido pago no app: nossa taxa sai na hora, por split do Mercado Pago (application_fee). Nada a cobrar depois.',
            'Pedido em espécie: o entregador devolve o bruto à loja (nada retido). Nossa taxa e o frete ficam como crédito nosso contra ela.',
            'Quem paga o entregador é o FUUdelivery, sempre e no mesmo dia da semana — por isso todo frete entra na coluna "frete a nós", independente da forma de pagamento.',
            'Um único débito por loja. Atraso bloqueia novas corridas em espécie naquela loja, não o pagamento do entregador.',
        ],
    ];
}

$week = netting_last_week();
$start = is_valid_date($_GET['start'] ?? null) ? $_GET['start'] : $week['start'];
$end = is_valid_date($_GET['end'] ?? null) ? $_GET['end'] : $week['end'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(200, array_merge(netting_payload($pdo, $start, $end, $debitDow), [
        'period' => ['start' => $start, 'end' => $end],
    ]));
}

require_method('POST');
$body = read_json_body();
$action = input_str($body, 'action');
$start = $body['start'] ?? $start;
$end = $body['end'] ?? $end;
// Data que existe no calendário, e começo antes do fim: o texto ia direto
// pro ::date do banco, e "" ou 30/02 davam 500.
if (!is_valid_date($start) || !is_valid_date($end) || $start > $end) {
    error_response(422, 'invalid_period', 'Período inválido (AAAA-MM-DD, começo antes do fim).', fields: ['start' => 'inválido', 'end' => 'inválido']);
}

if ($action === 'generate') {
    // A mesma função do pg_cron, chamada à mão quando a terça passou e
    // alguém precisa do fechamento agora. `ON CONFLICT DO NOTHING` lá dentro
    // faz rodar duas vezes ser inofensivo.
    $stmt = $pdo->prepare('SELECT generate_weekly_payouts(:start::date, :end::date)');
    $stmt->execute(['start' => $start, 'end' => $end]);

    json_response(200, array_merge(netting_payload($pdo, $start, $end, $debitDow), [
        'period' => ['start' => $start, 'end' => $end],
        'notice' => 'Fechamento gerado a partir do livro. Rodar de novo não duplica nada.',
    ]));
}

if ($action === 'send_batch') {
    // "Gerar lote de Pix": aqui ele sai de 'draft' pra 'sent'. Não há
    // integração de pagamento em lote neste projeto -- o lote é a LISTA, e
    // quem transfere é gente com a lista na mão. Dizer isso é melhor que
    // fingir um Pix que não sai.
    $stmt = $pdo->prepare(
        "UPDATE payouts SET state = 'sent'
          WHERE party_kind = 'courier' AND state = 'draft'
            AND period_start = :start::date AND period_end = :end::date
            AND net > 0
          RETURNING *"
    );
    $stmt->execute(['start' => $start, 'end' => $end]);
    $sent = $stmt->fetchAll();

    json_response(200, [
        'sent' => $sent,
        'count' => count($sent),
        'notice' => count($sent) === 0
            ? 'Nada em rascunho nesta semana.'
            : 'Lote marcado como enviado. A transferência em si é feita no banco — não há Pix em lote integrado neste projeto.',
    ]);
}

if ($action !== 'settle') {
    error_response(422, 'invalid_action', 'Ação inválida: generate, send_batch ou settle.', fields: ['action' => 'inválida']);
}

$payoutId = positive_id($body['payout_id'] ?? null) ?? 0;
if ($payoutId <= 0) {
    error_response(422, 'payout_required', 'Informe payout_id.', fields: ['payout_id' => 'obrigatório']);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM payouts WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $payoutId]);
    $payout = $stmt->fetch();
    if ($payout === false) {
        $pdo->rollBack();
        error_response(404, 'payout_not_found', 'Acerto não encontrado.');
    }
    if ((string) $payout['state'] === 'paid') {
        $pdo->rollBack();
        error_response(409, 'already_paid', 'Esse acerto já foi baixado.');
    }

    $pdo->prepare(
        "UPDATE payouts SET state = 'paid', provider_ref = :ref WHERE id = :id"
    )->execute([
        'ref' => body_text($body, 'provider_ref', 100),
        'id' => $payoutId,
    ]);

    // O lançamento de contrapartida: a loja pagou o débito, então o que ela
    // nos devia sai do livro por um lançamento NEGATIVO com origem 'payout'
    // -- não por edição do saldo, que o banco nem permitiria (REVOKE UPDATE,
    // DELETE em ledger_entries, migração 006).
    if ((string) $payout['party_kind'] === 'restaurant' && (float) $payout['net'] > 0) {
        ledger_add(
            $pdo,
            'store_receivable',
            (string) $payout['party_id'],
            -1 * (float) $payout['net'],
            'payout',
            'payout:' . $payoutId,
            null,
            $adminId,
            'débito semanal ' . $payout['period_start'] . ' a ' . $payout['period_end'] . ' quitado'
        );

        // Regularizou: a loja sai da trava de "somente online".
        $pdo->prepare(
            'UPDATE restaurants SET online_only_until = NULL
              WHERE id = :id AND online_only_until IS NOT NULL'
        )->execute(['id' => $payout['party_id']]);
    }

    if ((string) $payout['party_kind'] === 'courier' && (float) $payout['net'] > 0) {
        ledger_add(
            $pdo,
            'courier_payable',
            (string) $payout['party_id'],
            -1 * (float) $payout['net'],
            'payout',
            'payout:' . $payoutId,
            null,
            $adminId,
            'repasse semanal ' . $payout['period_start'] . ' a ' . $payout['period_end'] . ' transferido'
        );
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$stmt = $pdo->prepare('SELECT * FROM payouts WHERE id = :id');
$stmt->execute(['id' => $payoutId]);

json_response(200, [
    'payout' => $stmt->fetch(),
    'notice' => 'Baixa lançada como contrapartida no livro — nada foi editado.',
]);
