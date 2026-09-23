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

// ── core: ambiente, banco, HTTP, segurança ─────────────────────────────
require_once __DIR__ . '/core/env.php';
load_env(APP_ROOT . '/.env');
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/core/validation.php';
require_once __DIR__ . '/core/uuid.php';
require_once __DIR__ . '/core/jwt.php';
require_once __DIR__ . '/core/sessions.php';
require_once __DIR__ . '/core/otp.php';
require_once __DIR__ . '/core/auth_guard.php';
require_once __DIR__ . '/core/idempotency.php';

// ── catálogo: loja, política comercial, card da loja ───────────────────
require_once __DIR__ . '/catalog/policy.php';
require_once __DIR__ . '/catalog/store.php';
require_once __DIR__ . '/catalog/restaurant_facts.php';

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
require_once __DIR__ . '/payments/wallet.php';

// ── livro contábil: acerto do pedido, maquininha, netting semanal ──────
require_once __DIR__ . '/ledger/order_ledger.php';
require_once __DIR__ . '/ledger/pos.php';
require_once __DIR__ . '/ledger/netting.php';

// ── entrega: despacho em rodadas, ocorrências ──────────────────────────
require_once __DIR__ . '/dispatch/dispatch.php';
require_once __DIR__ . '/dispatch/incidents.php';

// ── mensagens: push, notificações, suporte ─────────────────────────────
require_once __DIR__ . '/messaging/push.php';
require_once __DIR__ . '/messaging/notifications.php';
require_once __DIR__ . '/messaging/support.php';

// ── impressão: ESC/POS da comanda e do recibo de baixa ──────────────────
require_once __DIR__ . '/printing/escpos.php';
require_once __DIR__ . '/printing/documents.php';

// ── conta: privacidade (LGPD) e fidelidade ──────────────────────────────────────────
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
    header('Access-Control-Allow-Credentials: false');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { // CLI (bin/*) não tem REQUEST_METHOD
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
