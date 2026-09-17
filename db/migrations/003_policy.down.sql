-- 003_policy.down.sql
BEGIN;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS restaurant_payment_settings;
DROP TABLE IF EXISTS policy_overrides;
DROP TABLE IF EXISTS platform_policies;

COMMIT;
