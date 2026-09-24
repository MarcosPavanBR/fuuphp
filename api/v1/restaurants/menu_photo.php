<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Foto do item do cardápio (tela 11.1 no painel, 3.1/3.2 no app do cliente).
//
//   POST (equipe da loja, multipart: menu_item_id + photo)
//        Sobe a foto de um item DA PRÓPRIA loja e grava menu_items.photo_key.
//        Vale na hora, como disponibilidade (não passa pelo rascunho do
//        editor: foto não muda preço nem regra, e o dono quer ver já).
//   GET  ?key=<sha256>.jpg  (público -- cardápio é público)
//        Serve a imagem com cache longo: a chave é o hash do conteúdo, então
//        uma foto nova é sempre uma URL nova.
//
// A imagem é recodificada (sem EXIF, lado maior até MENU_PHOTO_MAX_PX) e
// guardada pelo hash: lib/catalog/public_images.php, que também serve o logo
// da loja e os banners.

const MENU_PHOTO_MAX_PX = 900;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    public_image_serve((string) ($_GET['key'] ?? ''));
}

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);

$itemId = (int) ($_POST['menu_item_id'] ?? 0);
if ($itemId <= 0) {
    error_response(422, 'menu_item_id_required', 'Informe menu_item_id — salve o item antes de pôr a foto.');
}

$pdo = db();
$owner = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id AND restaurant_id = :r');
$owner->execute(['id' => $itemId, 'r' => $restaurantId]);
if ($owner->fetchColumn() === false) {
    error_response(404, 'menu_item_not_found', 'Item não encontrado no seu cardápio.');
}

$key = public_image_from_upload('photo', MENU_PHOTO_MAX_PX);

$pdo->prepare('UPDATE menu_items SET photo_key = :key WHERE id = :id')->execute(['key' => $key, 'id' => $itemId]);

json_response(200, ['menu_item_id' => $itemId, 'photo_key' => $key]);
