<?php

declare(strict_types=1);

// Imagens públicas da vitrine: foto de item do cardápio, logo da loja e
// banner da Home. As três seguem a mesma regra, que nasceu na foto do
// cardápio (restaurants/menu_photo.php):
//
//   - a imagem é RECODIFICADA com GD em JPEG: tira EXIF (inclusive o GPS de
//     quem fotografou em casa), neutraliza arquivo que finge ser imagem e
//     deixa a vitrine leve no 4G;
//   - o nome é o SHA-256 do conteúdo: imagem nova é sempre URL nova, então
//     dá pra servir com cache de um ano;
//   - fica em MENU_PHOTO_DIR (fora da raiz servida), numa subpasta por tipo,
//     e só sai por rota que aceita exatamente o formato de chave gerado aqui.
//
// Comprovante de Pix e documento de entregador NÃO passam por aqui: esses
// são privados (lib/payments/proof_images.php).

const PUBLIC_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const PUBLIC_IMAGE_KEY_PATTERN = '/^[0-9a-f]{64}\.jpg$/';

// Banners da Home (migração 037): subpasta, lado maior e quantos ficam no ar
// ao mesmo tempo (mais que isso o carrossel vira ruído e ninguém vê o último).
const PROMO_BANNER_SUBDIR = 'banners';
const PROMO_BANNER_MAX_PX = 1200;
const PROMO_BANNERS_MAX_LIVE = 8;

/** A pasta de um tipo de imagem pública ('' = fotos do cardápio, as primeiras). */
function public_image_dir(string $subdir = ''): string
{
    $base = app_path(rtrim((string) env('MENU_PHOTO_DIR', 'storage/menu'), '/'));

    return $subdir === '' ? $base : $base . '/' . $subdir;
}

/**
 * Lê o arquivo enviado no campo `$field`, recodifica e grava. Devolve a chave.
 *
 * `$maxPx` limita o lado maior; `$square` corta o centro num quadrado antes
 * (logo). Erros de envio viram 422 com o campo marcado.
 */
function public_image_from_upload(string $field, int $maxPx, string $subdir = '', bool $square = false): string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        error_response(422, 'image_required', "Envie a imagem no campo \"{$field}\".", fields: [$field => 'obrigatório']);
    }
    if ($file['size'] > PUBLIC_IMAGE_MAX_BYTES) {
        error_response(422, 'file_too_large', 'Imagem maior que 8 MB.', fields: [$field => 'maior que 8 MB']);
    }

    // O tipo vem do conteúdo, nunca do nome nem do que o navegador disse.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png' => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default => false,
    };
    if ($source === false) {
        error_response(422, 'invalid_file_type', 'Envie uma imagem JPEG, PNG ou WEBP.', fields: [$field => 'tipo não aceito']);
    }

    $srcX = 0;
    $srcY = 0;
    $width = imagesx($source);
    $height = imagesy($source);
    if ($square) {
        $side = min($width, $height);
        $srcX = intdiv($width - $side, 2);
        $srcY = intdiv($height - $side, 2);
        $width = $height = $side;
    }

    $scale = min(1.0, $maxPx / max($width, $height));
    $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
    // Fundo branco: PNG com transparência viraria preto no JPEG.
    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
    imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, imagesx($target), imagesy($target), $width, $height);

    ob_start();
    imagejpeg($target, null, 82);
    $jpeg = (string) ob_get_clean();

    $dir = public_image_dir($subdir);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("não deu pra criar {$dir}");
    }
    $key = hash('sha256', $jpeg) . '.jpg';
    file_put_contents($dir . '/' . $key, $jpeg);

    return $key;
}

/**
 * Serve uma imagem pública pela chave e encerra. Só aceita o formato que
 * public_image_from_upload gera: nada de "../" nem outro arquivo da pasta.
 */
function public_image_serve(string $key, string $subdir = ''): never
{
    if (preg_match(PUBLIC_IMAGE_KEY_PATTERN, $key) !== 1) {
        error_response(422, 'invalid_key', 'Chave de imagem inválida.');
    }
    $path = public_image_dir($subdir) . '/' . $key;
    if (!is_file($path)) {
        error_response(404, 'image_not_found', 'Imagem não encontrada.');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($path);
    exit;
}
