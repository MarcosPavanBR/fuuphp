<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.1 — os endereços salvos do cliente logado, o padrão primeiro. Os
// arquivados (versão antiga de um endereço editado depois de usado, ou
// apagado depois de usado -- migração 043) ficam de fora: só os pedidos
// antigos apontam pra eles.

require_method('GET');
$claims = require_auth();

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM addresses WHERE user_id = :user_id AND archived_at IS NULL ORDER BY is_default DESC, created_at DESC');
$stmt->execute(['user_id' => $claims['sub']]);

json_response(200, ['addresses' => $stmt->fetchAll()]);
