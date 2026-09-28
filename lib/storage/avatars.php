<?php
declare(strict_types=1);

// Foto de perfil (avatar) do cliente.
//
// Onde mora:
//   - SUPABASE_URL definida: bucket "avatars" do Supabase Storage. O bucket
//     é público só pra LEITURA (a foto aparece no app); gravar e apagar só
//     o servidor, com SUPABASE_SERVICE_KEY -- não existe política de escrita
//     pra anon/authenticated, então a chave pública do Supabase não sobe
//     nada. A chave secreta fica só no ambiente do servidor.
//   - sem SUPABASE_URL (desenvolvimento, VPS): disco, na pasta das imagens
//     públicas (MENU_PHOTO_DIR/avatars), servido por profile/avatar.php.
//
// A chave do arquivo é <id do usuário>/<sha256>.jpg: não dá pra adivinhar
// a foto de ninguém pelo id, e trocar de foto gera outra URL (cache longo).

const AVATAR_MAX_PX = 256;
const AVATAR_BUCKET_DEFAULT = 'avatars';
const AVATAR_LOCAL_SUBDIR = 'avatars';
const AVATAR_KEY_PATTERN = '/^[0-9a-f-]{36}\/[0-9a-f]{64}\.jpg$/';

function avatar_remote(): bool
{
    return (string) env('SUPABASE_URL', '') !== '';
}

/** Metade da configuração do Supabase é erro, não "modo local". */
function avatar_config_problem(): ?string
{
    if (avatar_remote() && (string) env('SUPABASE_SERVICE_KEY', '') === '') {
        return 'SUPABASE_URL definida sem SUPABASE_SERVICE_KEY (avatar não teria onde gravar)';
    }
    if (avatar_remote() && !str_starts_with((string) env('SUPABASE_URL'), 'https://')) {
        return 'SUPABASE_URL sem https://';
    }

    return null;
}

function avatar_url(?string $key): ?string
{
    if ($key === null || preg_match(AVATAR_KEY_PATTERN, $key) !== 1) {
        return null;
    }
    if (avatar_remote()) {
        return rtrim((string) env('SUPABASE_URL'), '/') . '/storage/v1/object/public/'
            . env('AVATAR_BUCKET', AVATAR_BUCKET_DEFAULT) . '/' . $key;
    }

    return '/api/v1/profile/avatar.php?key=' . rawurlencode($key);
}

/** Grava a foto (JPEG já recodificado) e devolve a chave. */
function avatar_store(string $userId, string $jpeg): string
{
    $key = $userId . '/' . hash('sha256', $jpeg) . '.jpg';
    if (avatar_remote()) {
        avatar_storage_request('POST', $key, $jpeg);
    } else {
        $path = public_image_dir(AVATAR_LOCAL_SUBDIR) . '/' . $key;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('não deu pra criar a pasta do avatar');
        }
        file_put_contents($path, $jpeg);
    }

    return $key;
}

/** Apaga a foto antiga. Falha aqui não desfaz a troca: só sobra um arquivo. */
function avatar_delete(?string $key): void
{
    if ($key === null || preg_match(AVATAR_KEY_PATTERN, $key) !== 1) {
        return;
    }
    try {
        if (avatar_remote()) {
            avatar_storage_request('DELETE', $key, null);
        } else {
            @unlink(public_image_dir(AVATAR_LOCAL_SUBDIR) . '/' . $key);
        }
    } catch (Throwable $e) {
        error_log(sprintf('[%s] avatar_delete: %s', trace_id(), $e->getMessage()));
    }
}

/** API REST do Supabase Storage, com curl nativo (sem SDK). */
function avatar_storage_request(string $method, string $key, ?string $body): void
{
    $secret = (string) env('SUPABASE_SERVICE_KEY', '');
    $url = rtrim((string) env('SUPABASE_URL'), '/') . '/storage/v1/object/'
        . env('AVATAR_BUCKET', AVATAR_BUCKET_DEFAULT) . '/' . $key;
    $headers = ['apikey: ' . $secret];
    // Chave antiga (JWT service_role) também vai no Authorization; a nova
    // (sb_secret_...) só no apikey -- o Supabase recusa ela como Bearer.
    if (str_starts_with($secret, 'eyJ')) {
        $headers[] = 'Authorization: Bearer ' . $secret;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: image/jpeg';
        $headers[] = 'Cache-Control: max-age=31536000';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        // O corpo da resposta não vai pro cliente; só pro log.
        throw new RuntimeException("supabase storage {$method} {$status} {$error} " . substr((string) $response, 0, 200));
    }
}
