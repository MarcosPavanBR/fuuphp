<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 8.2 — "Tudo que decide o aceite em uma tela: ganho, distância, forma
// de pagamento e troco."
//
// Só aparece oferta pra quem está em turno aberto: fora do turno o app não é
// uma vitrine de corridas. E corrida em dinheiro some pra quem está com o
// caixa bloqueado (teto estourado ou prazo vencido) -- é essa trava que
// impede furo de caixa, e ela vale aqui, não só na tela de baixa.

require_method('GET');
$claims = require_auth();
$courierId = require_courier($claims);

$pdo = db();

$shift = $pdo->prepare('SELECT id FROM courier_shifts WHERE courier_id = :id AND ended_at IS NULL');
$shift->execute(['id' => $courierId]);
if ($shift->fetch() === false) {
    error_response(409, 'no_open_shift', 'Abra o turno pra receber corrida.');
}

$courierStmt = $pdo->prepare('SELECT city_ibge_code, cash_blocked FROM couriers WHERE id = :id');
$courierStmt->execute(['id' => $courierId]);
$courier = $courierStmt->fetch();
if ($courier === false) {
    error_response(404, 'courier_not_found', 'Entregador não encontrado.');
}

// A distância é a mesma conta de Haversine da descoberta (lib/db.php), entre
// a loja e o endereço de entrega -- "distância" na tela do entregador é o
// trecho que ele vai rodar depois da coleta.
$sql = "SELECT of.id AS offer_id, of.fee, of.bonus, of.expires_at,
               o.id AS order_id, o.public_code, o.total, o.payment_method,
               o.change_for, o.machine_kind,
               r.name AS restaurant_name, r.city_ibge_code,
               a.street, a.number, a.complement, a.neighborhood,
               round((2 * 6371 * asin(sqrt(
                 power(sin(radians(a.lat - r.lat) / 2), 2) +
                 cos(radians(r.lat)) * cos(radians(a.lat)) *
                 power(sin(radians(a.lng - r.lng) / 2), 2)
               )))::numeric, 2) AS distance_km
        FROM offers of
        JOIN orders o ON o.id = of.order_id
        JOIN restaurants r ON r.id = o.restaurant_id
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE of.state = 'open'
          AND of.expires_at > now()
          AND o.courier_id IS NULL
          AND o.status = 'ready'
          AND r.city_ibge_code = :city";

if ($courier['cash_blocked'] === true) {
    $sql .= " AND o.payment_method <> 'cash'";
}
$sql .= ' ORDER BY of.created_at ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute(['city' => $courier['city_ibge_code']]);

json_response(200, [
    'offers' => $stmt->fetchAll(),
    'cash_blocked' => $courier['cash_blocked'],
]);
