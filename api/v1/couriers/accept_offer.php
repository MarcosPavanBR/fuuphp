<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 8.2 — "aceite idempotente: dois entregadores não pegam a mesma
// corrida."
//
// A corrida de dois entregadores tocando "Aceitar" no mesmo segundo é ganha
// por um UPDATE condicional, exatamente como a própria migração 007 sugere
// em comentário: UPDATE ... WHERE courier_id IS NULL RETURNING id. Zero linhas
// significa que o outro chegou primeiro -- sem transação explícita, sem lock
// pessimista, sem SELECT antes.

require_method('POST');
$claims = require_auth();
$courierId = require_courier($claims);
$body = read_json_body();

$offerId = (int) ($body['offer_id'] ?? 0);
if ($offerId <= 0) {
    error_response(422, 'offer_id_required', 'Informe offer_id.', fields: ['offer_id' => 'obrigatório']);
}

$pdo = db();

$shift = $pdo->prepare('SELECT id FROM courier_shifts WHERE courier_id = :id AND ended_at IS NULL');
$shift->execute(['id' => $courierId]);
if ($shift->fetch() === false) {
    error_response(409, 'no_open_shift', 'Abra o turno pra aceitar corrida.');
}

$offerStmt = $pdo->prepare(
    'SELECT of.*, o.payment_method, o.status AS order_status
     FROM offers of JOIN orders o ON o.id = of.order_id WHERE of.id = :id'
);
$offerStmt->execute(['id' => $offerId]);
$offer = $offerStmt->fetch();
if ($offer === false) {
    error_response(404, 'offer_not_found', 'Corrida não encontrada.');
}

// Aceitar de novo a MESMA corrida que já é sua não é erro: o app pode ter
// perdido a resposta e tentado de novo.
if ($offer['state'] === 'accepted' && $offer['courier_id'] === $courierId) {
    json_response(200, ['offer' => $offer, 'already_mine' => true]);
}

$courierStmt = $pdo->prepare('SELECT cash_blocked FROM couriers WHERE id = :id');
$courierStmt->execute(['id' => $courierId]);
if ($courierStmt->fetchColumn() === true && $offer['payment_method'] === 'cash') {
    error_response(409, 'cash_blocked', 'Seu caixa está bloqueado: baixe a espécie pra voltar a pegar corrida em dinheiro.');
}

$pdo->beginTransaction();
try {
    $claim = $pdo->prepare(
        "UPDATE offers SET courier_id = :courier, state = 'accepted'
         WHERE id = :id AND courier_id IS NULL AND state = 'open' AND expires_at > now()
         RETURNING *"
    );
    $claim->execute(['courier' => $courierId, 'id' => $offerId]);
    $accepted = $claim->fetch();

    if ($accepted === false) {
        $pdo->rollBack();
        error_response(409, 'offer_taken', 'Outro entregador pegou essa corrida.');
    }

    // orders.courier_id não passa pelo advance_order(): não é mudança de
    // status, é atribuição de responsável. O status continua 'ready' até a
    // loja entregar a sacola em mãos (tela 11.1).
    $pdo->prepare('UPDATE orders SET courier_id = :courier WHERE id = :id')
        ->execute(['courier' => $courierId, 'id' => $offer['order_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, [
    'offer' => $accepted,
    'order' => fetch_order($pdo, (int) $offer['order_id']),
]);
