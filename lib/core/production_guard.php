<?php
declare(strict_types=1);

// Trava de produção: "produção falha fechado".
//
// Em APP_ENV=production (e staging, com uma exceção), a API NÃO sobe se alguma
// integração estiver em modo simulado ou sem segredo. Antes desta trava o
// código fazia o contrário -- sem token do Mercado Pago caía em modo fake,
// sem segredo do webhook aceitava qualquer notificação, sem provedor de OTP
// "enviava" o código só pro log. Em produção, isso é cobrar de mentira e
// deixar qualquer um aprovar pagamento.
//
// Chamada no fim de lib/bootstrap.php, então vale pra toda rota da API e todo
// script de bin/ (que começam pelo bootstrap). Falhou:
//   HTTP → 503 {code: service_unavailable} sem detalhe nenhum pro cliente; os
//          motivos vão pro log do servidor, com trace_id;
//   CLI  → lista os motivos no stderr e sai com código 1.
//
// Scripts que precisam rodar ANTES de tudo estar pronto (gerar a chave VAPID,
// criar o admin fundador, gerar documentação) definem
// FUU_SKIP_PRODUCTION_GUARD antes do bootstrap.
//
// staging = homologação com o sandbox do Mercado Pago: tudo igual à produção,
// menos o token, que PODE ser de teste (TEST-...).

const PRODUCTION_LIKE_ENVS = ['production', 'staging'];

// O segredo de exemplo do .env.example: se chegou em produção, ninguém trocou.
const JWT_SECRET_EXAMPLE = 'troque-por-um-segredo-de-verdade-no-vault';

function app_env(): string
{
    return (string) env('APP_ENV', 'development');
}

function is_production_like(): bool
{
    return in_array(app_env(), PRODUCTION_LIKE_ENVS, true);
}

/**
 * Tudo que impede subir em produção/staging. Lista vazia = pode subir.
 *
 * @return list<string>
 */
function production_problems(): array
{
    if (!is_production_like()) {
        return [];
    }
    $problems = [];
    $env = app_env();

    $jwt = (string) env('JWT_SECRET', '');
    if ($jwt === '' || strlen($jwt) < 32 || $jwt === JWT_SECRET_EXAMPLE) {
        $problems[] = 'JWT_SECRET vazio, curto (< 32) ou igual ao do .env.example';
    }

    if (mp_mode() !== 'live') {
        $problems[] = 'Mercado Pago não está em modo live (MERCADOPAGO_MODE / MERCADOPAGO_ACCESS_TOKEN)';
    }
    foreach (['MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_WEBHOOK_SECRET', 'MERCADOPAGO_PUBLIC_KEY'] as $key) {
        if ((string) env($key, '') === '') {
            $problems[] = "{$key} vazio";
        }
    }
    if ($env === 'production' && str_starts_with((string) env('MERCADOPAGO_ACCESS_TOKEN', ''), 'TEST-')) {
        $problems[] = 'MERCADOPAGO_ACCESS_TOKEN de sandbox (TEST-) em produção';
    }

    if (push_mode() !== 'live') {
        $problems[] = 'PUSH_MODE não é live';
    }
    if (!is_readable(push_key_path())) {
        $problems[] = 'chave VAPID ausente ou ilegível (' . push_key_path() . ') -- rode php bin/generate_vapid_keys.php';
    }

    $otpProblem = otp_sender_problem();
    if ($otpProblem !== null) {
        $problems[] = $otpProblem;
    }

    // Vazio é válido (mesmo domínio, sem CORS); AUSENTE não: o padrão de
    // desenvolvimento liberaria http://localhost:5173.
    if (getenv('ALLOWED_ORIGIN') === false) {
        $problems[] = 'ALLOWED_ORIGIN não definida (defina vazia se PWA e API estão no mesmo domínio)';
    }

    foreach (production_storage_dirs() as $label => $dir) {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            $problems[] = "pasta de {$label} não existe: {$dir}";
            continue;
        }
        if (!is_writable($real)) {
            $problems[] = "pasta de {$label} sem permissão de escrita: {$real}";
        }
        // Arquivo enviado não mora dentro do projeto: lá ele fica a um erro de
        // configuração do servidor web de ser servido direto.
        if (str_starts_with($real . '/', realpath(APP_ROOT) . '/')) {
            $problems[] = "pasta de {$label} dentro do projeto ({$real}); use um caminho absoluto fora dele, ex.: /var/fuuphp/storage";
        }
    }

    return $problems;
}

/** As pastas de arquivo que precisam existir, graváveis e fora do projeto. */
function production_storage_dirs(): array
{
    return [
        'comprovantes' => app_path(rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/')),
        'fotos do cardápio' => app_path(rtrim((string) env('MENU_PHOTO_DIR', 'storage/menu'), '/')),
        'documentos de entregador' => app_path(rtrim((string) env('COURIER_DOC_DIR', 'storage/courier_docs'), '/')),
    ];
}

/** Aplica a trava: em produção/staging com problema, não segue. */
function enforce_production_guard(): void
{
    if (defined('FUU_SKIP_PRODUCTION_GUARD')) {
        return;
    }
    $problems = production_problems();
    if ($problems === []) {
        return;
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'APP_ENV=' . app_env() . " recusado -- configuração incompleta:\n  - " . implode("\n  - ", $problems) . "\n");
        exit(1);
    }

    $trace = trace_id();
    foreach ($problems as $problem) {
        error_log("[{$trace}] production_guard: {$problem}");
    }
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 300');
    echo json_encode([
        'code' => 'service_unavailable',
        'message' => 'Serviço temporariamente indisponível.',
        'trace_id' => $trace,
    ]);
    exit;
}
