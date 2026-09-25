<?php
declare(strict_types=1);

// Contrato front x back (auditoria TST-01/COE): toda rota que o front chama
// ("/area/acao.php" em web/src) precisa existir em api/v1. Rota renomeada ou
// apagada no back sem mexer no front quebra o CI aqui, e não em produção.
//
// USO: php bin/check_front_routes.php   (sai 1 e lista o que falta)

$root = dirname(__DIR__);
$called = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/web/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!preg_match('/\.(js|svelte)$/', $file->getFilename())) {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    if (preg_match_all('#[\'"`](/[a-z_]+/[a-z_]+\.php)#', $code, $m)) {
        foreach ($m[1] as $route) {
            $called[$route][] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}

$missing = [];
foreach ($called as $route => $files) {
    if (!is_file($root . '/api/v1' . $route)) {
        $missing[] = $route . '  (chamada em ' . implode(', ', array_unique($files)) . ')';
    }
}
if ($missing !== []) {
    fwrite(STDERR, "rotas chamadas pelo front que não existem em api/v1:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}
echo count($called) . " rotas chamadas pelo front, todas existem em api/v1.\n";
