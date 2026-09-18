<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 11.4 — "Horário, feriados e último pedido".
//
// "Dois turnos por dia, feriado como exceção e o histograma de pedidos por
// hora ao lado — quem edita horário vê na hora quanto está deixando na
// mesa." O histograma é o ponto: sem ele a tela é um formulário; com ele é
// uma decisão.

require_method('GET');
$claims = require_auth();
$restaurantId = require_store_staff($claims);

$pdo = db();

$hoursStmt = $pdo->prepare(
    'SELECT dow, shift, opens, closes, last_order, active
       FROM business_hours WHERE restaurant_id = :id
      ORDER BY dow, shift'
);
$hoursStmt->execute(['id' => $restaurantId]);

$holidayStmt = $pdo->prepare(
    "SELECT id, day, closed, opens, closes, last_order, note
       FROM holiday_overrides
      WHERE restaurant_id = :id AND day >= timezone('America/Sao_Paulo', now())::date
      ORDER BY day"
);
$holidayStmt->execute(['id' => $restaurantId]);

// Pedidos por hora das últimas quatro semanas, no fuso de quem lê a tela.
// Hora cheia, 0–23, sem buraco: o histograma precisa das 24 colunas mesmo
// quando não houve pedido, senão o gráfico mente sobre a forma do dia.
$histStmt = $pdo->prepare(
    "SELECT h AS hour, COALESCE(o.orders_count, 0) AS orders_count
       FROM generate_series(0, 23) h
       LEFT JOIN (
         SELECT EXTRACT(hour FROM timezone('America/Sao_Paulo', created_at))::int AS hour,
                count(*) AS orders_count
           FROM orders
          WHERE restaurant_id = :id
            AND created_at >= now() - interval '28 days'
            AND status NOT IN ('cart','pending_payment','rejected','cancelled')
          GROUP BY 1
       ) o ON o.hour = h
      ORDER BY h"
);
$histStmt->execute(['id' => $restaurantId]);
$histogram = $histStmt->fetchAll();

// "Seu pico é entre 19h e 21h": a frase sai do próprio histograma -- duas
// horas seguidas com o maior total. Com o dia todo zerado não há pico, e a
// tela diz isso em vez de apontar pra meia-noite.
$peak = null;
$best = 0;
for ($i = 0; $i < 23; $i++) {
    $sum = (int) $histogram[$i]['orders_count'] + (int) $histogram[$i + 1]['orders_count'];
    if ($sum > $best) {
        $best = $sum;
        $peak = ['from' => $i, 'to' => $i + 2];
    }
}

json_response(200, [
    'hours' => $hoursStmt->fetchAll(),
    'holidays' => $holidayStmt->fetchAll(),
    'histogram' => $histogram,
    'peak' => $best > 0 ? $peak : null,
    'today_dow' => (int) $pdo->query(
        "SELECT EXTRACT(dow FROM timezone('America/Sao_Paulo', now()))::int"
    )->fetchColumn(),
]);
