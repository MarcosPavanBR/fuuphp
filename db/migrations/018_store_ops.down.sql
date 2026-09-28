-- 018_store_ops.down.sql
BEGIN;

SELECT cron.unschedule('business-hours');

DROP FUNCTION IF EXISTS apply_business_hours();

ALTER TABLE restaurants
  DROP COLUMN IF EXISTS prep_auto_bump,
  DROP COLUMN IF EXISTS prep_minutes;

DROP TABLE IF EXISTS holiday_overrides;
DROP TABLE IF EXISTS store_pauses;

COMMIT;
