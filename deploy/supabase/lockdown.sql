-- deploy/supabase/lockdown.sql
-- Tranca o banco do FUUdelivery no Supabase. Rodar UMA vez, logo depois das
-- migrações, e de novo sempre que uma migração criar tabela ou função
-- (é idempotente). Pelo SQL Editor do painel, ou:
--   psql "<conexão do postgres>" -v ON_ERROR_STOP=1 -f deploy/supabase/lockdown.sql
--
-- Por quê: o Supabase publica o schema `public` na API REST dele (a Data
-- API), e dá privilégio em tudo que o `postgres` cria ali aos papéis `anon`
-- e `authenticated` -- os da chave pública do projeto. O FUUdelivery não usa
-- essa API: o app fala só com o PHP, que conecta como app_rw. Sem este
-- arquivo, quem tivesse a chave anon leria e alteraria qualquer tabela,
-- inclusive o livro-razão e os tokens de sessão.
--
-- Além disto, desligue a Data API no painel (docs/SUPABASE_RENDER.md).

BEGIN;

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON SCHEMA public FROM anon, authenticated;

-- Funções: só as do FUUdelivery (de quem roda este arquivo), nunca as de
-- extensão -- essas são do Supabase e mexer nelas dá "permission denied".
DO $$
DECLARE f record;
BEGIN
  FOR f IN
    SELECT p.oid::regprocedure AS sig
      FROM pg_proc p
     WHERE p.pronamespace = 'public'::regnamespace
       AND p.proowner = current_user::regrole
       AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')
  LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM anon, authenticated, PUBLIC', f.sig);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO app_rw, app_ro, migrator', f.sig);
  END LOOP;
END $$;

-- O que for criado depois também nasce trancado.
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON FUNCTIONS FROM anon, authenticated, PUBLIC;

-- Tirar EXECUTE de PUBLIC (no laço acima) também tirou dos papéis da API do
-- FUUdelivery: o laço devolve só a eles (as funções do banco --
-- advance_order, purga, conciliação -- são chamadas pelo PHP e pelo
-- pg_cron), e as funções novas já nascem com esse acesso.
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO app_rw, app_ro, migrator;

COMMIT;

-- Conferência: tem que voltar zero linhas.
SELECT table_name, privilege_type, grantee
  FROM information_schema.role_table_grants
 WHERE table_schema = 'public' AND grantee IN ('anon', 'authenticated');

-- Os papéis da aplicação criados pela migração 001 não herdam o search_path
-- do Supabase: sem isto, o tipo citext (esquema extensions) não é achado.
ALTER ROLE app_rw SET search_path = public, extensions;
ALTER ROLE app_ro SET search_path = public, extensions;
ALTER ROLE migrator SET search_path = public, extensions;
