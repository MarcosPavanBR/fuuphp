-- 002_catalog.down.sql
BEGIN;

ALTER TABLE partner_accounts DROP CONSTRAINT IF EXISTS partner_accounts_restaurant_fk;

DROP TABLE IF EXISTS addresses;
DROP TABLE IF EXISTS business_hours;
DROP TABLE IF EXISTS item_variants;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS restaurant_credentials;
DROP TABLE IF EXISTS restaurants;
DROP TYPE IF EXISTS payment_method;

COMMIT;
