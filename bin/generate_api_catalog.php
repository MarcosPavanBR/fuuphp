<?php

declare(strict_types=1);

// Gera docs/API.md -- o catálogo de TODAS as rotas de api/v1 -- lendo os
// próprios arquivos, não uma lista mantida à mão. Por rota: métodos HTTP,
// quem pode chamar, se exige X-Idempotency-Key e o resumo (o primeiro
// parágrafo do comentário de cabeçalho do arquivo).
//
// Por que gerado: catálogo escrito à mão desatualiza no primeiro endpoint
// novo. Aqui a fonte é o código, e o CI roda `--check`, que falha quando
// docs/API.md não bate com o que os arquivos dizem.
//
// USO
//   php bin/generate_api_catalog.php            reescreve docs/API.md
//   php bin/generate_api_catalog.php --check    só confere (CI); sai 1 se diferir
//   php bin/generate_api_catalog.php --missing  lista rotas sem comentário de cabeçalho

$root = dirname(__DIR__);
$apiDir = $root . '/api/v1';
$target = $root . '/docs/API.md';
$mode = $argv[1] ?? '';

/** O primeiro parágrafo de comentário depois do require do bootstrap. */
function api_summary(string $code): ?string
{
    $lines = explode("\n", $code);
    $afterBootstrap = false;
    $paragraph = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if (!$afterBootstrap) {
            $afterBootstrap = str_contains($trim, 'bootstrap.php');
            continue;
        }
        // Linhas em branco e outros require (ex.: admin/guard.php) antes do comentário.
        if ($paragraph === [] && ($trim === '' || str_starts_with($trim, 'require_once'))) {
            continue;
        }
        if (!str_starts_with($trim, '//')) {
            break;
        }
        $text = trim(substr($trim, 2));
        if ($text === '') {
            if ($paragraph !== []) {
                break;
            }
            continue;
        }
        $paragraph[] = $text;
    }
    if ($paragraph === []) {
        return null;
    }
    $summary = preg_replace('/\s+/', ' ', implode(' ', $paragraph));

    return mb_strlen($summary) > 260 ? rtrim(mb_substr($summary, 0, 257)) . '…' : $summary;
}

/** Quem pode chamar, pelo guarda que o arquivo usa. */
function api_access(string $code, string $route): string
{
    return match (true) {
        str_contains($route, 'webhook') => 'Mercado Pago (assinatura)',
        str_contains($code, 'require_admin(') => 'admin',
        str_contains($code, 'require_store_staff(') => 'loja',
        str_contains($code, 'require_courier(') => 'entregador',
        str_contains($code, 'require_auth_header_or_query(') => 'autenticado (token também por query, SSE)',
        (bool) preg_match("/!==\s*'customer'/", $code) => 'cliente',
        str_contains($code, 'require_auth(') => 'autenticado',
        default => 'público',
    };
}

$routes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    $relative = substr($file->getPathname(), strlen($root));
    // Arquivo sem require_method não é rota: é ajudante incluído por outras (ex.: admin/guard.php).
    if (!str_contains($code, 'require_method(') && !str_contains($code, "REQUEST_METHOD'] === 'GET'")) {
        continue;
    }
    preg_match_all("/require_method\('([A-Z]+)'\)/", $code, $m);
    $methods = $m[1];
    if (str_contains($code, "REQUEST_METHOD'] === 'GET'")) {
        $methods[] = 'GET';
    }
    $methods = array_values(array_unique($methods));
    sort($methods);

    $group = explode('/', trim(substr($relative, strlen('/api/v1/')), '/'))[0];
    $routes[$group][] = [
        'route' => $relative,
        'methods' => implode(', ', $methods),
        'access' => api_access($code, $relative),
        'idempotent' => str_contains($code, 'require_idempotency_key(') || str_contains($code, 'HTTP_X_IDEMPOTENCY_KEY'),
        'summary' => api_summary($code),
    ];
}
ksort($routes);

if ($mode === '--missing') {
    $missing = 0;
    foreach ($routes as $list) {
        foreach ($list as $r) {
            if ($r['summary'] === null) {
                echo $r['route'], "\n";
                $missing++;
            }
        }
    }
    exit($missing > 0 ? 1 : 0);
}

$titles = [
    'addresses' => 'Endereços do cliente (6.1, 14.3)',
    'admin' => 'Painel da plataforma (Fase 12, 10.5, 13.4, 15.2, 15.3)',
    'auth' => 'Acesso: OTP, parceiros, sessão (Fase 10)',
    'cards' => 'Cartões salvos (6.2)',
    'cart' => 'Carrinho (Fase 3)',
    'couriers' => 'App do entregador (Fases 8, 9, 13.3, 15)',
    'orders' => 'Pedido: checkout, acompanhamento, recibo (Fases 4, 5, 13, 14, 15.1)',
    'payments' => 'Pagamentos (Fase 4, 7.1)',
    'profile' => 'Conta do cliente e LGPD (2.5, 6.3, 13.4)',
    'push' => 'Notificações push (7.2)',
    'restaurants' => 'Lojas: catálogo público e painel da loja (Fases 2, 3, 7.3, 9, 11)',
    'reviews' => 'Avaliação (5.5)',
    'support' => 'Ajuda e chat (14.1, 14.2)',
];

$total = array_sum(array_map('count', $routes));
$out = "# Catálogo da API\n\n";
$out .= "> Gerado por `php bin/generate_api_catalog.php` a partir dos próprios arquivos de\n";
$out .= "> `api/v1`. Não edite à mão: o CI confere (`--check`) e falha se estiver desatualizado.\n\n";
$out .= "{$total} rotas. Toda rota responde JSON (exceto as de imagem, CSV e SSE); erro é sempre\n";
$out .= "`{code, message, trace_id}` com o status HTTP certo (`lib/core/response.php`). As URLs são\n";
$out .= "contrato público: mudar um caminho quebra app instalado.\n\n";
$out .= "**Quem:** `público` sem login · `autenticado` qualquer token válido · `cliente`, `loja`,\n";
$out .= "`entregador`, `admin` pelo papel do token. **Idem.:** exige `X-Idempotency-Key` (UUID).\n";

foreach ($routes as $group => $list) {
    usort($list, static fn (array $a, array $b): int => strcmp($a['route'], $b['route']));
    $out .= "\n## " . ($titles[$group] ?? $group) . "\n\n";
    $out .= "| Rota | Métodos | Quem | Idem. | O que faz |\n|---|---|---|---|---|\n";
    foreach ($list as $r) {
        $summary = str_replace('|', '\\|', $r['summary'] ?? '_(sem comentário de cabeçalho)_');
        $out .= sprintf(
            "| `%s` | %s | %s | %s | %s |\n",
            $r['route'],
            $r['methods'],
            $r['access'],
            $r['idempotent'] ? 'sim' : '',
            $summary
        );
    }
}

if ($mode === '--check') {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current !== $out) {
        fwrite(STDERR, "docs/API.md está desatualizado. Rode: php bin/generate_api_catalog.php\n");
        exit(1);
    }
    echo "docs/API.md em dia ({$total} rotas).\n";
    exit(0);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, $out);
echo "docs/API.md: {$total} rotas.\n";
