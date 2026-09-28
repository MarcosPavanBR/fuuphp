-- 040_address_reuse_signal.up.sql
-- Sinal de fraude novo: o cupom de primeiro pedido pedido de novo no mesmo
-- endereço por outra conta (auditoria NEG-01). O CPF só passa pelo dígito
-- verificador e o telefone novo custa um chip -- o endereço de entrega é o
-- que não se inventa: a comida tem que chegar lá.
BEGIN;
ALTER TABLE fraud_signals DROP CONSTRAINT fraud_signals_kind_check;
ALTER TABLE fraud_signals ADD CONSTRAINT fraud_signals_kind_check CHECK (kind IN
  ('proof_reuse','proof_phash','ip_velocity','geo_impossible','cpf_multi','address_reuse'));
COMMIT;
