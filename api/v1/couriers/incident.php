<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 13.3 — "Entregador: ocorrência na entrega".
//
// É o botão "Problema" da tela 8.5 aberto. A tela existe por um motivo que a
// própria especificação diz em voz alta: "sem isso, entregador abandona
// pedido difícil em vez de registrar". Então tudo aqui está a serviço de
// deixar registrar ser MELHOR que sumir.
//
// Quatro ações num endpoint só, porque são o mesmo gesto em etapas:
//   arrive → marca a chegada (é o relógio dos "6 min no local")
//   call   → registra uma ligação, com hora e GPS
//   bell   → registra a campainha
//   open   → abre a ocorrência com a prova obrigatória
//
// O que este endpoint NÃO faz: decidir o destino da comida. "Passado o
// prazo, o suporte libera: devolver à loja ou descartar" -- quem resolve é o
// admin (api/v1/admin/incidents.php). Aqui o pedido continua em
// 'delivering', porque a comida continua com o entregador.

$claims = require_auth();
$courierId = require_courier($claims);
$pdo = db();

$body = $_SERVER['REQUEST_METHOD'] === 'POST' ? read_json_body() : [];
$orderId = (int) ($_GET['order_id'] ?? $body['order_id'] ?? 0);
if ($orderId <= 0) {
    error_response(422, 'order_id_required', 'Informe order_id.', fields: ['order_id' => 'obrigatório']);
}

$order = fetch_order($pdo, $orderId);
if ($order === null || $order['courier_id'] !== $courierId) {
    error_response(404, 'order_not_found', 'Pedido não encontrado.');
}

$existingStmt = $pdo->prepare(
    'SELECT * FROM delivery_incidents WHERE order_id = :id ORDER BY id DESC LIMIT 1'
);
$existingStmt->execute(['id' => $orderId]);
$existing = $existingStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(200, [
        'context' => incident_context($pdo, $order),
        'incident' => $existing === false ? null : $existing,
        'attempts' => incident_attempts($pdo, $orderId),
        'resolutions' => INCIDENT_RESOLUTIONS,
        // "Você recebe a corrida integral nas duas saídas" -- o número é o
        // frete real deste pedido, não uma promessa genérica.
        'guaranteed_fee' => (float) $order['delivery_fee'],
    ]);
}

require_method('POST');
$action = (string) ($body['action'] ?? '');

if ($order['status'] !== 'delivering') {
    error_response(409, 'order_not_in_delivery', 'Esse pedido não está em rota de entrega.');
}

$lat = coord_input($body['lat'] ?? null, 90);
$lng = coord_input($body['lng'] ?? null, 180);

if (in_array($action, ['arrive', 'call', 'bell'], true)) {
    $kind = $action === 'arrive' ? 'arrival' : $action;

    // A chegada é uma só por corrida (índice único parcial da migração 021):
    // "6 min no local" conta do primeiro pé no endereço, e um retry de rede
    // não pode zerar esse relógio. Ligação e campainha empilham -- são
    // justamente o que se conta.
    $conflict = $kind === 'arrival' ? " ON CONFLICT (order_id) WHERE kind = 'arrival' DO NOTHING" : '';
    $pdo->prepare(
        'INSERT INTO delivery_attempts (order_id, courier_id, kind, lat, lng, ip)
         VALUES (:order_id, :courier_id, :kind, :lat, :lng, :ip)' . $conflict
    )->execute([
        'order_id' => $orderId,
        'courier_id' => $courierId,
        'kind' => $kind,
        'lat' => $lat,
        'lng' => $lng,
        'ip' => client_ip(),
    ]);

    json_response(200, ['context' => incident_context($pdo, $order)]);
}

if ($action !== 'open') {
    error_response(422, 'invalid_action', 'Ação inválida: use arrive, call, bell ou open.', fields: ['action' => 'inválida']);
}

if ($existing !== false) {
    error_response(409, 'incident_already_open', 'Já existe uma ocorrência aberta neste pedido.');
}

$kind = (string) ($body['kind'] ?? '');
if (!array_key_exists($kind, INCIDENT_KINDS)) {
    error_response(422, 'invalid_kind', 'Escolha o que aconteceu.', fields: ['kind' => 'inválido']);
}

// "PROVA (OBRIGATÓRIA)". Obrigatória de verdade: sem foto não abre. É ela
// que sustenta a decisão do suporte e a disputa depois.
$photoKey = isset($body['photo_storage_key']) ? body_text($body, 'photo_storage_key', 200) ?? '' : '';
if ($photoKey === '') {
    error_response(422, 'photo_required', 'A foto do local é obrigatória — é ela que sustenta a ocorrência.', fields: ['photo_storage_key' => 'obrigatória']);
}
// A foto tem que ser deste pedido e ter chegado (lib/dispatch/delivery_photos.php):
// texto qualquer abria a ocorrência "com prova".
if (verified_delivery_photo($orderId, $photoKey) === null) {
    error_response(422, 'photo_not_found', 'A foto do local não chegou. Tire e envie de novo.', fields: ['photo_storage_key' => 'não encontrada']);
}

$context = incident_context($pdo, $order);

// "Espere 10 min no local." Vale só pra cliente ausente: endereço que não
// existe não melhora esperando, e local sem segurança piora. E cliente
// ausente exige ligação registrada -- "não atende" é uma afirmação sobre uma
// ligação que aconteceu.
if (INCIDENT_KINDS[$kind]['needs_call']) {
    if ($context['call_attempts'] < 1) {
        error_response(422, 'call_required', 'Registre a ligação antes: "não atende" precisa de uma tentativa.', fields: ['call_attempts' => 'obrigatória']);
    }
    if (!$context['wait_satisfied']) {
        $waited = $context['waited_minutes'];
        error_response(409, 'wait_not_satisfied', $waited === null
            ? 'Marque a chegada no endereço — o prazo de 10 min conta a partir dela.'
            : sprintf('Espere %d min no local. Faltam %d.', INCIDENT_WAIT_MINUTES, INCIDENT_WAIT_MINUTES - $waited));
    }
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO delivery_incidents
           (order_id, courier_id, kind, photo_key, geo_lat, geo_lng, call_attempts, waited)
         VALUES (:order_id, :courier_id, :kind, :photo, :lat, :lng, :calls, make_interval(mins => :waited))
         RETURNING *'
    );
    $stmt->execute([
        'order_id' => $orderId,
        'courier_id' => $courierId,
        'kind' => $kind,
        'photo' => $photoKey,
        'lat' => $lat,
        'lng' => $lng,
        'calls' => $context['call_attempts'],
        'waited' => $context['waited_minutes'] ?? 0,
    ]);
    $incident = $stmt->fetch();

    // O chip "→ disputes" da tela: a ocorrência não fica num canto do banco
    // esperando alguém lembrar dela -- ela entra na mesma fila que o admin
    // já olha (tela 14.5), com o valor em jogo.
    $pdo->prepare(
        "INSERT INTO disputes (order_id, kind, risk, amount, state)
         VALUES (:order_id, 'not_delivered', :risk, :amount, 'open')"
    )->execute([
        'order_id' => $orderId,
        // Dinheiro na mão e local sem segurança sobem o risco: são os dois
        // casos em que sumir com a sacola é tentador ou compreensível.
        'risk' => in_array($kind, ['unsafe_area', 'no_cash'], true) ? 'high' : 'medium',
        'amount' => $order['total'],
    ]);

    // A trilha vai pra linha do tempo do pedido, que é o que o cliente lê no
    // acompanhamento -- ele precisa saber que alguém esteve na porta.
    $pdo->prepare(
        'INSERT INTO order_events (order_id, from_status, to_status, actor_id, actor_kind, meta)
         VALUES (:order_id, :status, :status, :actor, :kind, :meta::jsonb)'
    )->execute([
        'order_id' => $orderId,
        'status' => $order['status'],
        'actor' => $claims['sub'],
        'kind' => 'courier',
        'meta' => json_encode([
            'event' => 'delivery_incident',
            'incident_kind' => $kind,
            'call_attempts' => $context['call_attempts'],
            'waited_minutes' => $context['waited_minutes'],
        ], JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(201, [
    'incident' => $incident,
    'summary' => incident_summary($incident),
    'guaranteed_fee' => (float) $order['delivery_fee'],
    // A garantia, dita com a hora em que ela se cumpre. O frete entra no
    // livro quando o suporte resolve -- creditar agora e de novo numa
    // entrega que ainda pode acontecer pagaria a mesma corrida duas vezes.
    'notice' => sprintf(
        'Ocorrência registrada. O suporte libera o destino da sacola; a corrida de %s é sua nas duas saídas.',
        'R$ ' . number_format((float) $order['delivery_fee'], 2, ',', '.')
    ),
]);
