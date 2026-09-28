<?php
declare(strict_types=1);

// Registro de erro no banco (migração 041, auditoria INFRA-02): o que antes
// só ia pro log do servidor agora aparece pro admin, em "Saúde do sistema"
// (aba Relatórios). Sem serviço de fora: é a mesma API e o mesmo banco.
//
// Agrupa pela impressão digital (origem + tipo + arquivo + linha): a mesma
// falha repetida vira uma linha com contador, não mil linhas.

/**
 * Registra um erro. Nunca lança: registrar erro que falha não pode virar
 * um segundo erro (o log do servidor continua sendo o plano B).
 */
function record_app_error(string $source, Throwable|string $what, ?string $route = null): void
{
    try {
        if ($what instanceof Throwable) {
            $message = get_class($what) . ': ' . $what->getMessage()
                . ' em ' . basename($what->getFile()) . ':' . $what->getLine();
            $key = $source . '|' . get_class($what) . '|' . $what->getFile() . '|' . $what->getLine();
        } else {
            $message = $what;
            $key = $source . '|' . mb_substr($what, 0, 120);
        }
        // Nada de segredo na tela do admin: token (JWT), query string e
        // números longos (telefone, CPF, cartão) saem.
        $message = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/', '[token]', $message) ?? $message;
        $message = preg_replace('/\?\S*/', '', $message) ?? $message;
        $message = preg_replace('/\d{8,}/', '[número]', $message) ?? $message;
        $message = mb_substr($message, 0, 600);

        db_connect()->prepare(
            "INSERT INTO app_errors (source, fingerprint, message, route, trace_id)
             VALUES (:source, :fp, :message, :route, :trace)
             ON CONFLICT (fingerprint) DO UPDATE
                SET count = app_errors.count + 1, last_seen = now(), message = EXCLUDED.message,
                    route = EXCLUDED.route, trace_id = EXCLUDED.trace_id, resolved_at = NULL"
        )->execute([
            'source' => $source,
            'fp' => hash('sha256', $key),
            'message' => $message,
            'route' => $route,
            'trace' => trace_id(),
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[%s] app_errors: não deu pra registrar (%s)', trace_id(), $e->getMessage()));
    }
}
