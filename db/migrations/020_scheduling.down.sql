-- 020_scheduling.down.sql
BEGIN;

DROP INDEX IF EXISTS orders_scheduled_idx;

ALTER TABLE restaurants
  DROP COLUMN IF EXISTS slot_capacity;

COMMIT;
