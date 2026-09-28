<?php
declare(strict_types=1);

// Contrato de API (Especificação, Parte I §3): code é contrato, message é
// texto pt-BR, trace_id em toda resposta (inclusive sucesso), versão no
// caminho (/v1), nunca em header.

function trace_id(): string
{
    static $id = null;
    if ($id === null) {
        $incoming = $_SERVER['HTTP_X_TRACE_ID'] ?? null;
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $incoming)
            ? $incoming
            : strtoupper(bin2hex(random_bytes(8)));
    }
    return $id;
}

/**
 * Responde JSON com o status e encerra a requisição.
 */
function json_response(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body['trace_id'] = $body['trace_id'] ?? trace_id();
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<string,string>|null $fields
 */
function error_response(int $status, string $code, string $message, ?string $detail = null, ?array $fields = null): never
{
    $body = ['code' => $code, 'message' => $message];
    if ($detail !== null) {
        $body['detail'] = $detail;
    }
    if ($fields !== null) {
        $body['fields'] = $fields;
    }
    json_response($status, $body);
}

/**
 * Corpo JSON da requisição como array; corpo inválido encerra com 400.
 *
 * Texto com caractere nulo (\u0000) também é recusado aqui, pra todas as
 * rotas de uma vez: o PostgreSQL não guarda esse caractere em `text` (e dá
 * 500), e funções do PHP como DateTimeImmutable estouram com ele. Ninguém
 * digita um nulo; quem manda é robô ou ataque.
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_response(400, 'invalid_json', 'Corpo da requisição não é um JSON válido.');
    }
    if (has_nul_byte($decoded)) {
        error_response(400, 'invalid_characters', 'O texto enviado tem um caractere inválido.');
    }
    return $decoded;
}

/** Algum texto (valor ou chave, em qualquer nível) tem o caractere nulo? */
function has_nul_byte(mixed $value): bool
{
    if (is_string($value)) {
        return str_contains($value, "\0");
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if ((is_string($k) && str_contains($k, "\0")) || has_nul_byte($v)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * A mesma trava pra query string (?id=a%00b). Chamada pelo bootstrap em
 * toda requisição HTTP, antes da rota.
 */
function reject_nul_in_query(): void
{
    if (has_nul_byte($_GET)) {
        error_response(400, 'invalid_characters', 'O endereço da requisição tem um caractere inválido.');
    }
}
