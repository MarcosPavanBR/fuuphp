<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 14.4 — "Quando você quer receber?"
//
// Devolve os dias que a loja oferece e as faixas de cada um, com vaga real.
// "Assim que ficar pronto" não precisa de rota: é o pedido normal, sem
// scheduled_for.

require_method('GET');
require_auth();

$restaurantId = $_GET['restaurant_id'] ?? '';
if (!is_string($restaurantId) || $restaurantId === '') {
    error_response(422, 'restaurant_id_required', 'Informe restaurant_id.', fields: ['restaurant_id' => 'obrigatório']);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, name, slot_capacity, prep_minutes FROM restaurants WHERE id = :id');
$stmt->execute(['id' => $restaurantId]);
$restaurant = $stmt->fetch();
if ($restaurant === false) {
    error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
}

// Quatro dias, como no mock (hoje + três). Mais que isso é vitrine: a loja
// mal sabe o que vai ter de insumo depois de amanhã. O mesmo limite é
// aplicado no checkout -- ver SLOT_HORIZON_DAYS.
$days = [];
$slotsByDay = [];
for ($i = 0; $i < SLOT_HORIZON_DAYS; $i++) {
    $day = date('Y-m-d', strtotime("+{$i} days"));
    $slots = delivery_slots_for_day($pdo, $restaurant, $day);
    $days[] = [
        'day' => $day,
        'weekday' => (int) date('w', strtotime($day)),
        'label' => $i === 0 ? 'HOJE' : strtoupper(['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'][(int) date('w', strtotime($day))]),
        'number' => (int) date('j', strtotime($day)),
        'has_slots' => $slots !== [],
    ];
    $slotsByDay[$day] = $slots;
}

json_response(200, [
    // Zero é "essa loja não aceita agendamento", e a tela diz isso em vez de
    // mostrar uma lista vazia sem explicação.
    'scheduling_enabled' => (int) $restaurant['slot_capacity'] > 0,
    'restaurant_name' => $restaurant['name'],
    'prep_minutes' => (int) $restaurant['prep_minutes'],
    'days' => $days,
    'slots' => $slotsByDay,
    'free_cancel_minutes' => SLOT_FREE_CANCEL_MINUTES,
]);
