-- 014_cancellation.down.sql
BEGIN;

ALTER TABLE platform_policies
  DROP COLUMN IF EXISTS cancel_fee;

COMMIT;
