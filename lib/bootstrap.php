<?php
declare(strict_types=1);

// Ponto de entrada de TODO script PHP (api/v1/*, bin/*): carrega o .env, as
// bibliotecas e o CORS de desenvolvimento. Cada endpoint começa com
// `require_once .../lib/bootstrap.php` e mais nada -- nenhum arquivo de lib
// é incluído direto, então mover um deles é mudar uma linha aqui.
//
// Camadas (docs/ARCHITECTURE.md): `core` não sabe nada de delivery; as
// pastas de domínio usam `core` e umas às outras só por função (sem estado
// global). A ordem abaixo só importa pro `core`, que vem primeiro.

// O relógio do negócio é o de Brasília: faixa de agendamento, "hoje", a
// hora carimbada no comprovante, a semana do acerto. Sem isto o PHP usa o
// fuso do servidor (UTC na VPS) e uma loja aberta das 11h às 23h ofereceria
// faixas das 8h às 20h. Comparar instantes (expiração, prazos) não muda:
// timestamp é o mesmo em qualquer fuso. O banco já faz o mesmo nas tarefas
// dele (cron.timezone e as funções da migração 018).
date_default_timezone_set('America/Sao_Paulo');

// ── core: ambiente, banco, HTTP, segurança ─────────────────────────────
require_once __DIR__ . '/core/env.php';
require_once __DIR__ . '/core/money.php';
// FUU_ENV_FILE aponta outro arquivo (ex.: /etc/fuuphp/staging.env, ou
// /dev/null nos testes da trava de produção); o padrão é o .env da raiz.
load_env(getenv('FUU_ENV_FILE') ?: APP_ROOT . '/.env');
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/app_errors.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/core/validation.php';
require_once __DIR__ . '/core/uuid.php';
require_once __DIR__ . '/core/jwt.php';
require_once __DIR__ . '/core/sessions.php';
require_once __DIR__ . '/core/otp.php';
require_once __DIR__ . '/core/totp.php';
require_once __DIR__ . '/core/auth_guard.php';
require_once __DIR__ . '/core/idempotency.php';
require_once __DIR__ . '/core/production_guard.php';
require_once __DIR__ . '/core/file_crypto.php';

// ── catálogo: loja, política comercial, card da loja ───────────────────
require_once __DIR__ . '/catalog/policy.php';
require_once __DIR__ . '/catalog/store.php';
require_once __DIR__ . '/catalog/restaurant_facts.php';
require_once __DIR__ . '/catalog/cities.php';
require_once __DIR__ . '/catalog/public_images.php';
require_once __DIR__ . '/storage/avatars.php';

// ── pedido: carrinho, cupom, agendamento, frete, máquina de estados ────
require_once __DIR__ . '/ordering/orders.php';
require_once __DIR__ . '/ordering/cart.php';
require_once __DIR__ . '/ordering/coupons.php';
require_once __DIR__ . '/ordering/scheduling.php';
require_once __DIR__ . '/ordering/delivery.php';

// ── pagamentos: Mercado Pago, Pix, estornos, carteira ──────────────────
require_once __DIR__ . '/payments/mercadopago.php';
require_once __DIR__ . '/payments/pix.php';
require_once __DIR__ . '/payments/proof_images.php';
require_once __DIR__ . '/payments/refunds.php';
require_once __DIR__ . '/payments/refund_executor.php';
require_once __DIR__ . '/payments/gateway_status.php';
require_once __DIR__ . '/payments/wallet.php';

// ── livro contábil: acerto do pedido, maquininha, netting semanal ──────
require_once __DIR__ . '/ledger/order_ledger.php';
require_once __DIR__ . '/ledger/pos.php';
require_once __DIR__ . '/ledger/netting.php';

// ── entrega: despacho em rodadas, ocorrências ──────────────────────────
require_once __DIR__ . '/dispatch/dispatch.php';
require_once __DIR__ . '/dispatch/incidents.php';
require_once __DIR__ . '/dispatch/delivery_photos.php';

// ── mensagens: push, notificações, suporte ─────────────────────────────
require_once __DIR__ . '/messaging/push.php';
require_once __DIR__ . '/messaging/notifications.php';
require_once __DIR__ . '/messaging/support.php';
require_once __DIR__ . '/messaging/otp_sender.php';

// ── impressão: ESC/POS da comanda e do recibo de baixa ──────────────────
require_once __DIR__ . '/printing/escpos.php';
require_once __DIR__ . '/printing/documents.php';

// ── conta: privacidade (LGPD) e fidelidade ──────────────────────────────────────────
require_once __DIR__ . '/account/addresses.php';
require_once __DIR__ . '/account/account_privacy.php';
require_once __DIR__ . '/account/loyalty.php';

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
    // true: em dev o Vite roda noutra porta, e o cookie HttpOnly do refresh
    // (lib/core/sessions.php) só vai junto com credentials: 'include'. A
    // origem é uma só, fixa, nunca "*".
    header('Access-Control-Allow-Credentials: true');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { // CLI (bin/*) não tem REQUEST_METHOD
    http_response_code(204);
    exit;
}

// Nenhum stack trace escapa para o cliente: vira log estruturado com o
// trace_id que a resposta 500 também carrega (Especificação, Parte I §8).
// Uma linha JSON por erro (auditoria DevSecOps de 27/09/2026): o monitor
// externo filtra por campo (trace_id, rota) em vez de adivinhar o texto.
set_exception_handler(static function (Throwable $e): void {
    error_log('fuu ' . json_encode([
        'level' => 'error',
        'event' => 'uncaught',
        'trace_id' => trace_id(),
        'class' => get_class($e),
        'message' => mb_substr($e->getMessage(), 0, 1000),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'cli',
        'route' => PHP_SAPI === 'cli'
            ? basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'cli'))
            : (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    // E no banco, pro admin ver sem abrir o log (lib/core/app_errors.php).
    record_app_error(
        PHP_SAPI === 'cli' ? 'worker' : 'api',
        $e,
        PHP_SAPI === 'cli' ? basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'cli')) : (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)
    );
    error_response(500, 'internal_error', 'Erro interno. Tente novamente em instantes.');
});

// Caractere nulo na query string nunca chega numa rota (lib/core/response.php).
if (PHP_SAPI !== 'cli') {
    reject_nul_in_query();
}

/**
 * Encerra com 405 se o método HTTP não for o esperado pela rota.
 */
function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        error_response(405, 'method_not_allowed', 'Método não permitido nesta rota.');
    }
}

/**
 * O segredo que assina os tokens (JWT_SECRET). Sem ele, nada autentica.
 */
function jwt_secret(): string
{
    return env_required('JWT_SECRET');
}

const ACCESS_TOKEN_TTL_SECONDS = 15 * 60;
const REFRESH_TOKEN_TTL_SECONDS = 30 * 24 * 60 * 60;

// Produção falha fechado (lib/core/production_guard.php): em production/
// staging com integração simulada ou sem segredo, nada abaixo daqui roda.
enforce_production_guard();

