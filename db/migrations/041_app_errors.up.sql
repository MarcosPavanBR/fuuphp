-- 041_app_errors.up.sql
-- Monitoramento de erro sem serviço novo (auditoria INFRA-02). Antes, um 500
-- em produção só aparecia pra quem lesse o log do servidor.
--
--   app_errors      erro agrupado pela "impressão digital" (origem + tipo +
--                   arquivo + linha): a mesma falha 300 vezes é uma linha
--                   com count = 300, primeira e última vez. Voltar depois de
--                   marcada como resolvida reabre a linha. O admin vê na aba
--                   Relatórios ("Saúde do sistema").
--   system_status   o último resultado de rotinas de fora da API -- hoje, a
--                   restauração semanal do backup (deploy/backup/backup.sh).
BEGIN;

CREATE TABLE app_errors (
  id           bigserial PRIMARY KEY,
  source       text NOT NULL CHECK (source IN ('api', 'worker', 'backup')),
  fingerprint  char(64) NOT NULL UNIQUE,
  message      text NOT NULL CHECK (length(message) <= 600),
  route        text,
  trace_id     text,
  count        int NOT NULL DEFAULT 1 CHECK (count > 0),
  first_seen   timestamptz NOT NULL DEFAULT now(),
  last_seen    timestamptz NOT NULL DEFAULT now(),
  resolved_at  timestamptz
);
CREATE INDEX app_errors_open_idx ON app_errors (last_seen DESC) WHERE resolved_at IS NULL;

CREATE TABLE system_status (
  key         text PRIMARY KEY,
  ok          boolean NOT NULL,
  detail      text,
  updated_at  timestamptz NOT NULL DEFAULT now()
);

-- Erro resolvido há mais de 90 dias não ajuda mais ninguém.
SELECT cron.schedule('app-errors-purge', '30 4 * * *',
  $$DELETE FROM app_errors WHERE resolved_at IS NOT NULL AND resolved_at < now() - interval '90 days'$$);

COMMIT;
