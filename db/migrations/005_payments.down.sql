-- 005_payments.down.sql
BEGIN;

DROP TABLE IF EXISTS card_transactions;
DROP TABLE IF EXISTS refunds;
DROP TABLE IF EXISTS idempotency_keys;
DROP TABLE IF EXISTS payment_proofs;
DROP TABLE IF EXISTS payments;

COMMIT;
