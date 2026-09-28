<?php
declare(strict_types=1);

// Contrato de API (Especificação, Parte I §3): repetir a mesma
// X-Idempotency-Key devolve a MESMA resposta gravada -- 100 requisições
// simultâneas viram 1 cobrança, 99 repeats idênticos. A chave é validada
// contra a rota e o corpo da requisição: reusar a mesma chave para uma
// requisição diferente é tratada como erro (409), não como retry legítimo.

function require_idempotency_key(): string
{
    $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
    if (!is_string($key) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $key) !== 1) {
        error_response(400, 'idempotency_key_required', 'Informe X-Idempotency-Key (UUID v4).');
    }
    return strtolower($key);
}

/**
 * Executa $handler no máximo uma vez por chave. $handler devolve
 * [status_http, corpo_array] em vez de chamar json_response() diretamente
 * -- quem decide a resposta HTTP final (inclusive em replay) é esta função.
 *
 * @param callable(): array{0:int,1:array} $handler
 */
function idempotent_response(PDO $pdo, string $route, string $key, array $requestBody, callable $handler): never
{
    $requestHash = hash('sha256', $route . '|' . json_encode($requestBody, JSON_UNESCAPED_UNICODE));

    $select = $pdo->prepare('SELECT * FROM idempotency_keys WHERE key = :key');
    $select->execute(['key' => $key]);
    $existing = $select->fetch();

    if ($existing === false) {
        $insert = $pdo->prepare(
            'INSERT INTO idempotency_keys (key, route, request_hash) VALUES (:key, :route, :hash) ON CONFLICT (key) DO NOTHING'
        );
        $insert->execute(['key' => $key, 'route' => $route, 'hash' => $requestHash]);

        if ($insert->rowCount() === 1) {
            // $handler() pode terminar a requisição por dentro (error_response()
            // chama exit(), não lança exceção -- não dá pra capturar com catch).
            // Se isso acontecer, a reserva fica com status_code NULL pra sempre
            // e a chave vira "em processamento" eterno; este shutdown function
            // libera a reserva nesse caso, pra um retry com a mesma chave poder
            // tentar de novo em vez de travar em idempotency_key_in_flight.
            register_shutdown_function(static function () use ($pdo, $key): void {
                $pdo->prepare('DELETE FROM idempotency_keys WHERE key = :key AND status_code IS NULL')
                    ->execute(['key' => $key]);
            });

            [$status, $body] = $handler();
            $pdo->prepare('UPDATE idempotency_keys SET status_code = :status, response_body = :body WHERE key = :key')
                ->execute(['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'key' => $key]);
            json_response($status, $body);
        }

        // Perdeu a corrida: outra requisição inseriu a chave entre o SELECT e
        // o INSERT. Recarrega para cair no mesmo tratamento de "já existe".
        $select->execute(['key' => $key]);
        $existing = $select->fetch();
    }

    if ($existing === false || $existing['route'] !== $route || $existing['request_hash'] !== $requestHash) {
        error_response(409, 'idempotency_key_reused', 'Essa chave de idempotência já foi usada para uma requisição diferente.');
    }
    if ($existing['status_code'] === null) {
        error_response(409, 'idempotency_key_in_flight', 'Essa requisição ainda está sendo processada. Tente novamente em instantes.');
    }
    json_response((int) $existing['status_code'], (array) json_decode((string) $existing['response_body'], true));
}
