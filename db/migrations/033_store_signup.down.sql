-- 033_store_signup.down.sql

BEGIN;

DROP INDEX IF EXISTS restaurants_signup_ip_idx;
ALTER TABLE restaurants
  DROP COLUMN contact_name,
  DROP COLUMN contact_phone,
  DROP COLUMN address_text,
  DROP COLUMN signup_ip;

COMMIT;
