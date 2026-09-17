-- 006_ledger.down.sql
BEGIN;

ALTER TABLE card_transactions DROP CONSTRAINT IF EXISTS card_transactions_device_fk;

DROP TABLE IF EXISTS pos_custody;
DROP TABLE IF EXISTS pos_devices;
DROP TABLE IF EXISTS payouts;
DROP TABLE IF EXISTS cash_settlement_intents;
DROP VIEW IF EXISTS store_balance;
DROP VIEW IF EXISTS courier_cash_balance;
DROP TABLE IF EXISTS ledger_entries;
DROP TYPE IF EXISTS ledger_account;

COMMIT;
