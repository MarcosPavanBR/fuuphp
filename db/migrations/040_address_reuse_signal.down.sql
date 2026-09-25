-- 040_address_reuse_signal.down.sql
BEGIN;
DELETE FROM fraud_signals WHERE kind = 'address_reuse';
ALTER TABLE fraud_signals DROP CONSTRAINT fraud_signals_kind_check;
ALTER TABLE fraud_signals ADD CONSTRAINT fraud_signals_kind_check CHECK (kind IN
  ('proof_reuse','proof_phash','ip_velocity','geo_impossible','cpf_multi'));
COMMIT;
