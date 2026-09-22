<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.3 — "Baixar meus dados (LGPD)". Devolve um JSON com tudo que o
// sistema guarda sobre o cliente logado (lib/account_privacy.php decide o
// que entra e o que fica de fora), como anexo pra download.

require_method('GET');
$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'A exportação desta tela é da conta de cliente.');
}

$data = account_export(db(), (string) $claims['sub']);
$filename = 'fuudelivery-meus-dados-' . gmdate('Y-m-d') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
