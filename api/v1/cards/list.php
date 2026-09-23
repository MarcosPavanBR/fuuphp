<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.2 — os cartões salvos do cliente (bandeira, final, validade; nunca
// o número): referência tokenizada no Mercado Pago, o padrão primeiro.

require_method('GET');
$claims = require_auth();

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM saved_cards WHERE user_id = :id ORDER BY is_default DESC, created_at DESC');
$stmt->execute(['id' => $claims['sub']]);

json_response(200, ['cards' => $stmt->fetchAll()]);
