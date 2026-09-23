-- 035_cron_health.down.sql

BEGIN;

DROP FUNCTION cron_healthy();

COMMIT;
