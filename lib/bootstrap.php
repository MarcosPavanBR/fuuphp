<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
load_env(__DIR__ . '/../.env');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/uuid.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/otp.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/idempotency.php';
require_once __DIR__ . '/pix.php';
require_once __DIR__ . '/mercadopago.php';
require_once __DIR__ . '/refunds.php';
require_once __DIR__ . '/dispatch.php';

// Em produção, PWA e API ficam atrás do mesmo domínio via Cloudflare (a
// especificação nunca fala em domínios separados) -- CORS não seria
// necessário. Em dev, o Vite roda em outra porta (origem diferente pro
// navegador), então precisa disto pra o front conseguir chamar a API.
// ALLOWED_ORIGIN vazio em produção = nenhum Access-Control-* enviado.
$__allowedOrigin = env('ALLOWED_ORIGIN', 'http://localhost:5173');
if ($__allowedOrigin !== '') {
    header("Access-Control-Allow-Origin: {$__allowedOrigin}");
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Idempotency-Key, X-Trace-Id');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Credentials: false');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Nenhum stack trace escapa para o cliente: vira log estruturado com o
// trace_id que a resposta 500 também carrega (Especificação, Parte I §8).
set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[%s] uncaught %s: %s in %s:%d',
        trace_id(),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    error_response(500, 'internal_error', 'Erro interno. Tente novamente em instantes.');
});

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        error_response(405, 'method_not_allowed', 'Método não permitido nesta rota.');
    }
}

function jwt_secret(): string
{
    return env_required('JWT_SECRET');
}

const ACCESS_TOKEN_TTL_SECONDS = 15 * 60;
const REFRESH_TOKEN_TTL_SECONDS = 30 * 24 * 60 * 60;
