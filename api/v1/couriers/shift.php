<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 8.1 — abrir e fechar turno. O índice `one_open_shift` (migração 007)
// garante um turno aberto por entregador: abrir duas vezes não cria dois
// registros, e é o banco que diz isso, não um `if` no PHP.

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);
$body = read_json_body();

$action = $body['action'] ?? null;
if (!in_array($action, ['start', 'end'], true)) {
    error_response(422, 'invalid_action', 'Informe action: start ou end.', fields: ['action' => 'obrigatório']);
}

$pdo = db();

if ($action === 'start') {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO courier_shifts (courier_id) VALUES (:id) RETURNING *'
        );
        $stmt->execute(['id' => $courierId]);
        json_response(201, ['shift' => $stmt->fetch()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            error_response(409, 'shift_already_open', 'Seu turno já está aberto.');
        }
        throw $e;
    }
}

// Fechar turno com corrida em andamento deixaria um pedido sem dono: o
// pedido continua sendo responsabilidade de quem aceitou.
$pending = $pdo->prepare(
    "SELECT count(*) FROM orders WHERE courier_id = :id AND status IN ('ready','delivering')"
);
$pending->execute(['id' => $courierId]);
if ((int) $pending->fetchColumn() > 0) {
    error_response(409, 'ride_in_progress', 'Termine a corrida em andamento antes de fechar o turno.');
}

$stmt = $pdo->prepare(
    'UPDATE courier_shifts SET ended_at = now()
     WHERE courier_id = :id AND ended_at IS NULL RETURNING *'
);
$stmt->execute(['id' => $courierId]);
$shift = $stmt->fetch();
if ($shift === false) {
    error_response(409, 'no_open_shift', 'Você não tem turno aberto.');
}

json_response(200, ['shift' => $shift]);
