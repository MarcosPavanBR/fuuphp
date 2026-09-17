<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

require_method('GET');
$claims = require_auth();

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM addresses WHERE user_id = :user_id ORDER BY is_default DESC, created_at DESC');
$stmt->execute(['user_id' => $claims['sub']]);

json_response(200, ['addresses' => $stmt->fetchAll()]);
