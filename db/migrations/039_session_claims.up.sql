-- 039_session_claims.up.sql
-- A sessão guarda os claims extras do token (kind, restaurant_id,
-- courier_id do login de parceiro). Sem isso, o refresh emitia um access
-- token só com sub e role: a loja renovada perdia o restaurant_id e o
-- painel parava ("Esse login não está vinculado a uma loja").
--
--   sessions.claims   o que vai no JWT além de sub/role; copiado a cada
--                     rotação e revalidado contra partner_accounts.

BEGIN;

ALTER TABLE sessions ADD COLUMN claims jsonb NOT NULL DEFAULT '{}'::jsonb;

COMMIT;
