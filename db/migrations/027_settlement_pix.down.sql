-- 027_settlement_pix.down.sql

BEGIN;

CREATE OR REPLACE FUNCTION expire_cash_settlements() RETURNS void
LANGUAGE sql AS $$
  UPDATE cash_settlement_intents
  SET state = 'expired'
  WHERE state = 'open' AND expires_at < now();
$$;

DROP TABLE settlement_proofs;

COMMIT;
