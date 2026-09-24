<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.2 — pausar, fechar por hoje e voltar.
//
// "Pausa esconde a loja do app e para novos pedidos. Os pedidos já aceitos
// continuam — nada é cancelado por pausar." É literalmente o que este
// endpoint faz: mexe em `restaurants.is_open`/`pause_until` e em nada mais.
// Nenhum pedido muda de status aqui, de propósito.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$action = $body['action'] ?? null;
if (!in_array($action, ['pause', 'close_today', 'resume'], true)) {
    error_response(422, 'invalid_action', 'Informe action: pause, close_today ou resume.', fields: ['action' => 'obrigatório']);
}

$pdo = db();

if ($action === 'resume') {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE store_pauses SET ended_at = now() WHERE restaurant_id = :id AND ended_at IS NULL')
            ->execute(['id' => $restaurantId]);
        // Voltar NÃO é o mesmo que abrir: quem decide se a loja está aberta
        // agora é o horário (apply_business_hours, migração 018). Reabrir na
        // marra às 3h da manhã porque alguém apertou "voltar" seria aceitar
        // pedido que ninguém vai preparar.
        $pdo->prepare('UPDATE restaurants SET pause_until = NULL WHERE id = :id')
            ->execute(['id' => $restaurantId]);
        $pdo->query('SELECT apply_business_hours()');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT is_open, pause_until FROM restaurants WHERE id = :id');
    $stmt->execute(['id' => $restaurantId]);

    json_response(200, ['store' => $stmt->fetch(), 'paused' => false]);
}

$reason = $body['reason'] ?? null;
$validReasons = ['busy_kitchen', 'out_of_stock', 'no_courier', 'technical'];
if (!in_array($reason, $validReasons, true)) {
    error_response(422, 'reason_required', 'Escolha o motivo — é ele que o cliente vê.', fields: ['reason' => 'obrigatório']);
}

// Pausa curta: 15, 30 ou 60 min, os três botões do mock. Valor livre não
// entra porque "volta sozinha" precisa caber num turno -- pausa de 6 h é
// fechar, e fechar tem botão próprio.
$minutes = null;
if ($action === 'pause') {
    $minutes = (int) ($body['minutes'] ?? 0);
    if (!in_array($minutes, [15, 30, 60], true)) {
        error_response(422, 'invalid_minutes', 'Pausa curta é de 15, 30 ou 60 minutos.', fields: ['minutes' => 'inválido']);
    }
}

$pdo->beginTransaction();
try {
    // Uma pausa por vez: pausar de novo por cima substitui a anterior em vez
    // de empilhar duas linhas abertas que ninguém saberia somar.
    $pdo->prepare('UPDATE store_pauses SET ended_at = now() WHERE restaurant_id = :id AND ended_at IS NULL')
        ->execute(['id' => $restaurantId]);

    if ($action === 'pause') {
        $insert = $pdo->prepare(
            "INSERT INTO store_pauses (restaurant_id, kind, reason, until, created_by)
             VALUES (:id, 'short', :reason, now() + (:m || ' minutes')::interval, :by)
             RETURNING *"
        );
        $insert->execute(['id' => $restaurantId, 'reason' => $reason, 'm' => $minutes, 'by' => $claims['sub']]);
        $pause = $insert->fetch();

        // is_open continua true: a loja não fechou, está pausada. Quem
        // esconde do app e barra o checkout é `pause_until`.
        $pdo->prepare('UPDATE restaurants SET pause_until = :until WHERE id = :id')
            ->execute(['until' => $pause['until'], 'id' => $restaurantId]);
    } else {
        // "Fechar por hoje — reabre no horário de amanhã": sem relógio de
        // volta. `until` NULL diz exatamente isso, e quem reabre é o job de
        // horário quando o dia virar.
        $insert = $pdo->prepare(
            "INSERT INTO store_pauses (restaurant_id, kind, reason, until, created_by)
             VALUES (:id, 'rest_of_day', :reason, NULL, :by)
             RETURNING *"
        );
        $insert->execute(['id' => $restaurantId, 'reason' => $reason, 'by' => $claims['sub']]);
        $pause = $insert->fetch();

        // Fechar por hoje precisa durar até o fim do dia mesmo que o job de
        // horário rode a cada minuto -- por isso o carimbo, e não só is_open.
        // "Fim do dia" é a meia-noite NA CIDADE DA LOJA (migração 038).
        $pdo->prepare(
            "UPDATE restaurants
                SET is_open = false,
                    pause_until = (timezone(restaurant_timezone(:id), now())::date + 1)::timestamp
                      AT TIME ZONE restaurant_timezone(:id)
              WHERE id = :id"
        )->execute(['id' => $restaurantId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$stmt = $pdo->prepare('SELECT is_open, pause_until FROM restaurants WHERE id = :id');
$stmt->execute(['id' => $restaurantId]);

json_response(200, ['store' => $stmt->fetch(), 'pause' => $pause, 'paused' => true]);
