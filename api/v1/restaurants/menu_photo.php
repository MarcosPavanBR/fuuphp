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
// A imagem é RECODIFICADA com GD (JPEG, lado maior até MENU_PHOTO_MAX_PX):
// isso tira EXIF (inclusive GPS de quem fotografou na cozinha de casa),
// neutraliza arquivo que finge ser imagem e deixa o cardápio leve no 4G.

const MENU_PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const MENU_PHOTO_MAX_PX = 900;

function menu_photo_dir(): string
{
    $dir = rtrim((string) env('MENU_PHOTO_DIR', 'storage/menu'), '/');

    return app_path($dir);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = (string) ($_GET['key'] ?? '');
    // Só o formato que o próprio POST gera: nada de "../" nem outro arquivo.
    if (preg_match('/^[0-9a-f]{64}\.jpg$/', $key) !== 1) {
        error_response(422, 'invalid_key', 'Chave de foto inválida.');
    }
    $path = menu_photo_dir() . '/' . $key;
    if (!is_file($path)) {
        error_response(404, 'photo_not_found', 'Foto não encontrada.');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($path);
    exit;
}

require_method('POST');
$claims = require_auth();
$restaurantId = require_store_staff($claims);

$itemId = (int) ($_POST['menu_item_id'] ?? 0);
if ($itemId <= 0) {
    error_response(422, 'menu_item_id_required', 'Informe menu_item_id — salve o item antes de pôr a foto.');
}
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    error_response(422, 'photo_required', 'Envie a foto no campo "photo".', fields: ['photo' => 'obrigatório']);
}
if ($_FILES['photo']['size'] > MENU_PHOTO_MAX_BYTES) {
    error_response(422, 'file_too_large', 'Foto maior que 8 MB.');
}

$pdo = db();
$owner = $pdo->prepare('SELECT 1 FROM menu_items WHERE id = :id AND restaurant_id = :r');
$owner->execute(['id' => $itemId, 'r' => $restaurantId]);
if ($owner->fetchColumn() === false) {
    error_response(404, 'menu_item_not_found', 'Item não encontrado no seu cardápio.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']) ?: '';
$source = match ($mime) {
    'image/jpeg' => @imagecreatefromjpeg($_FILES['photo']['tmp_name']),
    'image/png' => @imagecreatefrompng($_FILES['photo']['tmp_name']),
    'image/webp' => @imagecreatefromwebp($_FILES['photo']['tmp_name']),
    default => false,
};
if ($source === false) {
    error_response(422, 'invalid_file_type', 'Envie uma foto JPEG, PNG ou WEBP.', fields: ['photo' => 'tipo não aceito']);
}

$width = imagesx($source);
$height = imagesy($source);
$scale = min(1.0, MENU_PHOTO_MAX_PX / max($width, $height));
$target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
// Fundo branco: PNG com transparência viraria preto no JPEG.
imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

ob_start();
imagejpeg($target, null, 82);
$jpeg = (string) ob_get_clean();

$dir = menu_photo_dir();
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException("não deu pra criar {$dir}");
}
$key = hash('sha256', $jpeg) . '.jpg';
file_put_contents($dir . '/' . $key, $jpeg);

$pdo->prepare('UPDATE menu_items SET photo_key = :key WHERE id = :id')->execute(['key' => $key, 'id' => $itemId]);

json_response(200, ['menu_item_id' => $itemId, 'photo_key' => $key]);
