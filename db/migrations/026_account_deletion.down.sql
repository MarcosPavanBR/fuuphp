-- 026_account_deletion.down.sql

BEGIN;

ALTER TABLE users DROP COLUMN deleted_at;

COMMIT;
