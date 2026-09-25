<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.4 — "Feriados e datas especiais".
//
// Exceção de um dia, não edição da semana: "07/10 · Nossa Senhora — fechado
// o dia todo" e "15/11 · Proclamação — só jantar 18:00–23:00". Quem edita o
// horário semanal pra um feriado esquece de desfazer, e a loja passa o ano
// fechada às quintas.

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);
$body = read_json_body();

$action = $body['action'] ?? 'save';
$pdo = db();

if ($action === 'remove') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        error_response(422, 'id_required', 'Informe o id do feriado.', fields: ['id' => 'obrigatório']);
    }
    $stmt = $pdo->prepare('DELETE FROM holiday_overrides WHERE id = :id AND restaurant_id = :rid RETURNING id');
    $stmt->execute(['id' => $id, 'rid' => $restaurantId]);
    if ($stmt->fetch() === false) {
        error_response(404, 'holiday_not_found', 'Essa data não está cadastrada nesta loja.');
    }
    $pdo->query('SELECT apply_business_hours()');

    json_response(200, ['removed' => $id]);
}

$day = $body['day'] ?? '';
if (!is_valid_date($day)) {
    // 2026-02-30 passava no formato e dava 500 no banco.
    error_response(422, 'invalid_day', 'Data no formato AAAA-MM-DD.', fields: ['day' => 'inválido']);
}

$closed = $body['closed'] ?? true;
if (!is_bool($closed)) {
    error_response(422, 'invalid_closed', 'closed é true ou false.', fields: ['closed' => 'inválido']);
}

$opens = $closed ? null : ($body['opens'] ?? null);
$closes = $closed ? null : ($body['closes'] ?? null);
$lastOrder = $closed ? null : ($body['last_order'] ?? null);

if (!$closed) {
    foreach (['opens' => $opens, 'closes' => $closes] as $field => $value) {
        if (!is_valid_time($value)) {
            error_response(422, 'invalid_time', 'Dia com horário especial precisa de abertura e fechamento.', fields: [$field => 'inválido']);
        }
    }
    if ($lastOrder !== null && !is_valid_time($lastOrder)) {
        error_response(422, 'invalid_time', 'Último pedido no formato HH:MM.', fields: ['last_order' => 'inválido']);
    }
}

$note = is_string($body['note'] ?? null) ? trim($body['note']) : null;
if ($note !== null && mb_strlen($note) > 120) {
    error_response(422, 'invalid_note', 'A observação vai até 120 caracteres.', fields: ['note' => 'até 120 caracteres']);
}

// UNIQUE (restaurant_id, day): cadastrar a mesma data duas vezes é corrigir
// a primeira, não criar uma segunda regra concorrente pro mesmo dia.
$stmt = $pdo->prepare(
    'INSERT INTO holiday_overrides (restaurant_id, day, closed, opens, closes, last_order, note)
     VALUES (:rid, :day, :closed, :opens, :closes, :last_order, :note)
     ON CONFLICT (restaurant_id, day) DO UPDATE
       SET closed = EXCLUDED.closed, opens = EXCLUDED.opens, closes = EXCLUDED.closes,
           last_order = EXCLUDED.last_order, note = EXCLUDED.note
     RETURNING *'
);
$stmt->execute([
    'rid' => $restaurantId,
    'day' => $day,
    'closed' => $closed ? 'true' : 'false',
    'opens' => $opens,
    'closes' => $closes,
    'last_order' => $lastOrder,
    'note' => $note,
]);
$holiday = $stmt->fetch();

// Cadastrar o feriado de hoje tem que fechar a loja agora, não no dia
// seguinte.
$pdo->query('SELECT apply_business_hours()');

json_response(200, ['holiday' => $holiday]);
