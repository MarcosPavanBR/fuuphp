<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Relatório de violação da Content-Security-Policy (deploy/nginx/fuuphp.conf,
// auditoria SEG-02). O navegador manda sozinho quando a política bloqueia
// algo -- é assim que um bloqueio indevido (um domínio novo do Mercado Pago,
// por exemplo) aparece no log em vez de virar "o cartão não funciona" sem
// ninguém saber por quê.
//
// Público por natureza (quem manda é o navegador de qualquer visitante), então
// não confia no conteúdo: lê no máximo 8 KB, grava uma linha curta no log (a
// diretiva, o que foi bloqueado e a página, sem query string) e responde 204.
// O Nginx limita a frequência por IP nesta rota.

require_method('POST');

$raw = (string) file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw, true);
// Dois formatos: o antigo (report-uri, {"csp-report": {...}}) e o da
// Reporting API ([{"type":"csp-violation","body":{...}}]).
$report = is_array($data) ? ($data['csp-report'] ?? ($data[0]['body'] ?? null)) : null;

// Ruído conhecido: o sweetalert (os diálogos do app) testa `Function(...)`
// e `eval` dentro de try/catch pra achar o objeto global; a CSP bloqueia, o
// catch cai no `window` e nada quebra (conferido no navegador). Sem este
// filtro, toda abertura de tela gravaria uma linha inútil.
$blocked = is_array($report) ? (string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? '') : '';
$source = is_array($report) ? (string) ($report['source-file'] ?? $report['sourceFile'] ?? '') : '';
if ($blocked === 'eval' && str_contains($source, 'sweetalert')) {
    $report = null;
}

if (is_array($report)) {
    $clean = static function (mixed $v): string {
        $s = is_string($v) ? $v : '';
        $s = preg_replace('/[?#].*$/', '', $s) ?? '';           // sem query (pode ter token)
        return mb_substr(preg_replace('/[^\x20-\x7E]/', '', $s) ?? '', 0, 200);
    };
    error_log(sprintf(
        '[%s] csp: %s bloqueou %s em %s',
        trace_id(),
        $clean($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? ''),
        $clean($report['blocked-uri'] ?? $report['blockedURL'] ?? ''),
        $clean($report['document-uri'] ?? $report['documentURL'] ?? '')
    ));
}

http_response_code(204);
