<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// A vitrine da própria loja, no painel (aba Loja): nome, categoria e logo.
//
//   GET   {name, category, logo_key, categories}  -- categories = as aceitas
//   POST  {category}  troca a categoria (tem de ser uma de STORE_CATEGORIES:
//         a categoria vira filtro na Home, e texto livre quebraria o filtro)
//
// O nome não muda por aqui: é o que a plataforma aprovou junto com o CNPJ.
// O logo sobe por restaurants/logo.php. Troca de categoria vai pro audit_log.

$claims = require_auth();
$restaurantId = require_store_staff($claims);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->prepare('SELECT name, category, logo_key FROM restaurants WHERE id = :id');
    $stmt->execute(['id' => $restaurantId]);
    json_response(200, ($stmt->fetch() ?: []) + ['categories' => STORE_CATEGORIES]);
}

require_method('POST');
$body = read_json_body();
$category = input_str($body, 'category');
if (!in_array($category, STORE_CATEGORIES, true)) {
    error_response(422, 'invalid_category', 'Escolha uma das categorias da lista.', fields: ['category' => 'categoria inválida']);
}

$pdo->beginTransaction();
try {
    $prev = $pdo->prepare('SELECT category FROM restaurants WHERE id = :id FOR UPDATE');
    $prev->execute(['id' => $restaurantId]);
    $before = $prev->fetchColumn();
    $pdo->prepare('UPDATE restaurants SET category = :c WHERE id = :id')->execute(['c' => $category, 'id' => $restaurantId]);
    if ($before !== $category) {
        $pdo->prepare(
            "INSERT INTO audit_log (actor_id, action, target, before, after, ip)
             VALUES (:actor, 'restaurant.category_changed', :target, :before, :after, :ip)"
        )->execute([
            'actor' => $claims['sub'],
            'target' => 'restaurants:' . $restaurantId,
            'before' => json_encode(['category' => $before], JSON_UNESCAPED_UNICODE),
            'after' => json_encode(['category' => $category], JSON_UNESCAPED_UNICODE),
            'ip' => client_ip(),
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(200, ['category' => $category]);
