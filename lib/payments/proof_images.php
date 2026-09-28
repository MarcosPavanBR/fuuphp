<?php
declare(strict_types=1);

// Imagem de comprovante: o que se faz com a foto de um Pix antes de guardar.
// Usado pelo comprovante do cliente (tela 4.4, payments/upload_proof.php) e
// pelo da baixa de espécie do entregador (tela 9.5, couriers/settle_proof.php)
// -- "mesma fila do Pix do cliente: sha256 + phash + conferência de valor".
//
//   sha256    pega o MESMO arquivo reenviado;
//   aHash     (phash simplificado, 64 bits) pega o print recortado/reeditado:
//             média de luminância numa grade 8x8, sem DCT -- mais barato,
//             sem dependência nova, e cobre o caso que o mock descreve;
//   marca     d'água com o código do pedido/baixa e a hora, gravada na imagem
//             com a fonte embutida do GD (sem arquivo .ttf).

const PROOF_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const PROOF_MAX_BYTES = 10 * 1024 * 1024;

/**
 * Impressão digital visual do comprovante (average hash 8x8, 64 bits): a
 * mesma imagem reenviada, mesmo recomprimida, dá o mesmo hash. É o que pega
 * comprovante reaproveitado em outro pedido.
 */
function pix_proof_average_hash(string $bytes, string $mime): ?string
{
    $image = pix_proof_load_image($bytes, $mime);
    if ($image === false) {
        return null;
    }
    $small = imagescale($image, 8, 8);
    imagedestroy($image);
    if ($small === false) {
        return null;
    }

    $values = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $values[] = (int) round(($r + $g + $b) / 3);
        }
    }
    imagedestroy($small);

    $avg = array_sum($values) / count($values);
    $bits = '';
    foreach ($values as $v) {
        $bits .= $v >= $avg ? '1' : '0';
    }

    $hex = '';
    foreach (str_split($bits, 4) as $nibble) {
        $hex .= dechex(bindec(str_pad($nibble, 4, '0')));
    }
    return $hex;
}

/**
 * Carimba o comprovante com o pedido e a hora do envio, pra imagem não servir
 * de prova em outro lugar. Devolve null se a imagem não abre. A hora é a de
 * parede da loja (`$tz`, o fuso da cidade dela); sem ela, a de Brasília.
 */
function pix_proof_watermark(string $bytes, string $mime, string $label, ?DateTimeZone $tz = null): ?string
{
    $image = pix_proof_load_image($bytes, $mime);
    if ($image === false) {
        return null;
    }
    $width = imagesx($image);
    $height = imagesy($image);

    $text = "FUUDELIVERY · {$label} · " . (new DateTimeImmutable('now', $tz))->format('d/m/Y H:i');
    $white = imagecolorallocatealpha($image, 255, 255, 255, 40);
    $black = imagecolorallocatealpha($image, 0, 0, 0, 60);
    $y = max(0, $height - 18);
    imagestring($image, 3, 9, $y + 1, $text, $black);
    imagestring($image, 3, 8, $y, $text, $white);

    ob_start();
    match ($mime) {
        'image/png' => imagepng($image),
        'image/webp' => imagewebp($image),
        default => imagejpeg($image, null, 85),
    };
    $out = ob_get_clean();
    imagedestroy($image);
    return $out === false ? null : $out;
}

/**
 * @return \GdImage|false
 */
function pix_proof_load_image(string $bytes, string $mime)
{
    return match ($mime) {
        'image/png' => @imagecreatefromstring($bytes),
        'image/webp' => @imagecreatefromstring($bytes),
        'image/jpeg' => @imagecreatefromstring($bytes),
        default => false,
    };
}

/**
 * Valida o arquivo enviado em $_FILES[$field] e devolve [bytes, mime]; encerra
 * com 422 quando não é uma foto aceitável. O MIME é o do conteúdo (finfo),
 * nunca o que o navegador declarou.
 *
 * @return array{0:string,1:string}
 */
function proof_read_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        error_response(422, 'proof_required', "Envie o arquivo do comprovante no campo \"{$field}\".", fields: [$field => 'obrigatório']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']) ?: '';
    if (!in_array($mime, PROOF_ALLOWED_MIMES, true)) {
        error_response(422, 'invalid_file_type', 'Envie uma foto (JPEG, PNG ou WEBP) do comprovante.', fields: [$field => 'tipo de arquivo não aceito']);
    }
    $bytes = (string) file_get_contents($_FILES[$field]['tmp_name']);
    if ($bytes === '') {
        error_response(422, 'empty_file', 'Arquivo vazio.');
    }
    if (strlen($bytes) > PROOF_MAX_BYTES) {
        error_response(422, 'file_too_large', 'Comprovante maior que 10 MB.');
    }

    return [$bytes, $mime];
}

/**
 * Grava o comprovante (com marca d'água, ex.: "PEDIDO #C71A04") na pasta privada de comprovantes e
 * devolve a chave de armazenamento "<sha256>.<ext>" + sha256 + aHash.
 *
 * @return array{storage_key:string, sha256:string, phash:?string}
 */
function proof_store(string $bytes, string $mime, string $watermarkLabel, string $subdir = '', ?DateTimeZone $tz = null): array
{
    $sha256 = hash('sha256', $bytes);
    $phash = pix_proof_average_hash($bytes, $mime);
    $watermarked = pix_proof_watermark($bytes, $mime, $watermarkLabel, $tz);

    $dir = app_path(rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/') . ($subdir !== '' ? '/' . $subdir : ''));
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("não deu pra criar {$dir}");
    }
    $extension = match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };
    $key = "{$sha256}.{$extension}";
    file_put_contents("{$dir}/{$key}", $watermarked ?? $bytes);

    return ['storage_key' => $key, 'sha256' => $sha256, 'phash' => $phash];
}
