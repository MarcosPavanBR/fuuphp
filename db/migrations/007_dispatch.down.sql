-- 007_dispatch.down.sql
BEGIN;

ALTER TABLE pos_custody DROP CONSTRAINT IF EXISTS pos_custody_courier_fk;
ALTER TABLE cash_settlement_intents DROP CONSTRAINT IF EXISTS cash_settlement_intents_courier_fk;
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_courier_fk;
ALTER TABLE partner_accounts DROP CONSTRAINT IF EXISTS partner_accounts_courier_fk;

DROP TABLE IF EXISTS dispatch_attempts;
DROP TABLE IF EXISTS offers;
DROP TABLE IF EXISTS courier_shifts;
DROP TABLE IF EXISTS couriers;
DROP TABLE IF EXISTS courier_documents;
DROP TABLE IF EXISTS courier_applications;

COMMIT;
