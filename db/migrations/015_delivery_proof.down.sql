-- 015_delivery_proof.down.sql
BEGIN;

DROP TABLE IF EXISTS delivery_proofs;

ALTER TABLE orders
  DROP COLUMN IF EXISTS delivery_code;

COMMIT;
