-- 041_app_errors.down.sql
BEGIN;
SELECT cron.unschedule('app-errors-purge');
DROP TABLE IF EXISTS system_status;
DROP TABLE IF EXISTS app_errors;
COMMIT;
