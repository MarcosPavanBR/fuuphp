<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Logo da loja: aparece no card da Home e da busca e no topo da loja.
//
//   POST (equipe da loja, multipart: logo)
//        Sobe o logo DA PRÓPRIA loja e grava restaurants.logo_key. O centro
//        da imagem é cortado num quadrado (o card é quadrado) e reduzido a
//        LOGO_MAX_PX. Vale na hora, inclusive com a loja em análise.
//   GET  ?key=<sha256>.jpg  (público -- a vitrine é pública)
//
// Mesma regra de imagem da foto do cardápio: lib/catalog/public_images.php.

const LOGO_MAX_PX = 400;
const LOGO_SUBDIR = 'logos';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    public_image_serve((string) ($_GET['key'] ?? ''), LOGO_SUBDIR);
}

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);

$key = public_image_from_upload('logo', LOGO_MAX_PX, LOGO_SUBDIR, square: true);
db()->prepare('UPDATE restaurants SET logo_key = :key WHERE id = :id')->execute(['key' => $key, 'id' => $restaurantId]);

json_response(200, ['logo_key' => $key]);
