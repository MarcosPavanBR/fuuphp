<?php
declare(strict_types=1);

// Carrega .env sem depender de biblioteca externa (cláusula zero: nada
// entra na stack sem autorização). Variáveis já definidas no ambiente
// (docker-compose, Render, CI) sempre vencem o arquivo.
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Variável de ambiente (já carregada do .env), ou o padrão.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

/**
 * Variável obrigatória: ausente ou vazia é exceção, não padrão silencioso.
 */
function env_required(string $key): string
{
    $value = env($key);
    if ($value === null || $value === '') {
        throw new RuntimeException("variável de ambiente obrigatória ausente: {$key}");
    }
    return $value;
}

// Raiz do projeto (onde ficam .env, storage/, api/). Definida uma vez aqui
// pra nenhum arquivo precisar contar "../" a partir de onde está -- a conta
// quebrava a cada pasta que um arquivo mudava de lugar.
define('APP_ROOT', dirname(__DIR__, 2));

/**
 * Caminho absoluto de algo do projeto. Caminho que já é absoluto (vindo de
 * variável de ambiente, ex.: PROOF_STORAGE_DIR=/var/fuu/proofs) passa direto.
 */
function app_path(string $path): string
{
    return str_starts_with($path, '/') ? $path : APP_ROOT . '/' . ltrim($path, '/');
}
