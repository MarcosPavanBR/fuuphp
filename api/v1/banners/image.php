<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Imagem de banner da Home. Pública, com cache de um ano: a chave é o hash
// do conteúdo (lib/catalog/public_images.php), então banner novo é URL nova.
//
// GET ?key=<sha256>.jpg

require_method('GET');
public_image_serve(input_str($_GET, 'key'), PROMO_BANNER_SUBDIR);
