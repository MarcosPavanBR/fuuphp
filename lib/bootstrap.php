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
