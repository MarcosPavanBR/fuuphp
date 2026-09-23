-- 030_platform_mp_token.down.sql
-- Devolve as colunas vazias (o que elas guardavam nunca foi usado).

BEGIN;

COMMENT ON TABLE restaurant_credentials IS NULL;

ALTER TABLE restaurant_credentials
  ADD COLUMN mp_public_key   text,
  ADD COLUMN mp_access_token bytea,
  ADD COLUMN mp_user_id      text;

COMMIT;
