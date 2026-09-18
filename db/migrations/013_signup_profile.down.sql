-- 013_signup_profile.down.sql
BEGIN;

ALTER TABLE users
  DROP COLUMN IF EXISTS birth_date;

COMMIT;
