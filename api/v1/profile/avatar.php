<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Foto de perfil do cliente (lib/storage/avatars.php).
//
//   POST   (logado, multipart: photo)  troca a foto -> {avatar_url}
//   DELETE (logado)                    tira a foto  -> {avatar_url: null}
//   GET    ?key=<id>/<sha256>.jpg      só sem Supabase (disco): serve a foto
//
// A imagem é validada pelo conteúdo, cortada em quadrado, reduzida a 256 px
// e recodificada em JPEG (sai sem EXIF) antes de ir pra qualquer lugar.

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = input_str($_GET, 'key');
    if (avatar_remote() || preg_match(AVATAR_KEY_PATTERN, $key) !== 1) {
        error_response(404, 'not_found', 'Imagem não encontrada.');
    }
    public_image_serve(basename($key), AVATAR_LOCAL_SUBDIR . '/' . dirname($key));
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    require_method('POST');
}
$claims = require_auth();
$userId = (string) $claims['sub'];
$pdo = db();

$old = $pdo->prepare('SELECT avatar_key FROM users WHERE id = :id');
$old->execute(['id' => $userId]);
$oldKey = $old->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pdo->prepare('UPDATE users SET avatar_key = NULL WHERE id = :id')->execute(['id' => $userId]);
    avatar_delete($oldKey);
    json_response(200, ['avatar_url' => null]);
}

$jpeg = public_image_jpeg_from_upload('photo', AVATAR_MAX_PX, square: true);
try {
    $key = avatar_store($userId, $jpeg);
} catch (Throwable $e) {
    error_log(sprintf('[%s] avatar_store: %s', trace_id(), $e->getMessage()));
    error_response(503, 'storage_unavailable', 'Não deu pra salvar a foto agora. Tente de novo em instantes.');
}
$pdo->prepare('UPDATE users SET avatar_key = :k WHERE id = :id')->execute(['k' => $key, 'id' => $userId]);
if ($oldKey !== $key) {
    avatar_delete($oldKey);
}

json_response(200, ['avatar_url' => avatar_url($key)]);
