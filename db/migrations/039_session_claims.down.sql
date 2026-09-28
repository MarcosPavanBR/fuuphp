-- 039_session_claims.down.sql
BEGIN;
ALTER TABLE sessions DROP COLUMN IF EXISTS claims;
COMMIT;
