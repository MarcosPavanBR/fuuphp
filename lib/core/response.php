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
    return $decoded;
}
