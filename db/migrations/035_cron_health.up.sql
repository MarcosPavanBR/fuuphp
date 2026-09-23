-- 035_cron_health.up.sql
-- "O pg_cron está rodando?" -- pra rota de saúde (api/v1/health.php) e pro
-- bin/check_production.php.
--
-- Achado no go-live: com a configuração padrão, o pg_cron abre conexão TCP
-- em localhost como postgres, a autenticação barra, e TODA tarefa falha
-- ("connection failed") sem ninguém ver -- Pix vencido não é recusado, loja
-- não abre/fecha sozinha, repasse de terça não sai. A correção é de
-- configuração (cron.use_background_workers = on, docs/GO_LIVE.md); isto
-- aqui é o alarme que prova que ela está valendo.
--
-- SECURITY DEFINER porque o app_rw não enxerga o esquema `cron` (nem deve:
-- lá dentro estão os comandos de todas as tarefas). A função devolve só um
-- booleano: alguma tarefa terminou com sucesso nos últimos 5 minutos (a de
-- Pix vencido roda a cada minuto).

BEGIN;

CREATE FUNCTION cron_healthy() RETURNS boolean
  LANGUAGE sql STABLE SECURITY DEFINER
  SET search_path = pg_catalog
AS $$
  SELECT EXISTS (
    SELECT 1 FROM cron.job_run_details
     WHERE status = 'succeeded' AND end_time > now() - interval '5 minutes'
  )
$$;

REVOKE ALL ON FUNCTION cron_healthy() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION cron_healthy() TO app_rw, app_ro;

COMMIT;
