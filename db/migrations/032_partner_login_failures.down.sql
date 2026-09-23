-- 032_partner_login_failures.down.sql

BEGIN;

SELECT cron.unschedule('partner-login-failures-purge');
DROP TABLE partner_login_failures;

COMMIT;
