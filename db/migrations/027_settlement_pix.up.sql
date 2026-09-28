-- 027_settlement_pix.up.sql
-- Tela 9.5 — "Entregador — baixa por Pix (loja fechada)".
--
-- "Reaproveita a validação humana já existente. Prazo e bloqueio automático
-- evitam que o dinheiro 'durma' com o entregador." O entregador transfere a
-- espécie pra chave Pix da loja e manda o comprovante; a loja confere como
-- confere o Pix do cliente (7.3). Aprovado, a baixa nasce igual à de balcão
-- (9.3): os dois lançamentos na mesma transação.
--
-- `cash_settlement_intents.method = 'pix'` existe desde a 006, mas nada
-- usava: faltava onde guardar o comprovante. `payment_proofs` não serve --
-- exige pagamento e pedido, e a baixa não é de pedido nenhum.

BEGIN;

CREATE TABLE settlement_proofs (
  id             bigserial PRIMARY KEY,
  intent_id      bigint NOT NULL REFERENCES cash_settlement_intents(id),
  courier_id     uuid NOT NULL,
  restaurant_id  uuid NOT NULL REFERENCES restaurants(id),
  storage_key    text NOT NULL,          -- storage/proofs/settlements, privado
  sha256         char(64) NOT NULL,      -- mesmo arquivo reenviado
  phash          char(16),               -- print reeditado
  upload_key     uuid,                   -- idempotência do envio (UUID do app)
  state          text NOT NULL DEFAULT 'pending'
                   CHECK (state IN ('pending','approved','rejected')),
  reject_reason  text,
  reviewed_by    uuid REFERENCES users(id),
  reviewed_at    timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now()
);
-- Uma conferência por vez: comprovante novo só depois de o anterior ser recusado.
CREATE UNIQUE INDEX settlement_proofs_one_pending ON settlement_proofs (intent_id) WHERE state = 'pending';
CREATE UNIQUE INDEX settlement_proofs_upload_key ON settlement_proofs (upload_key) WHERE upload_key IS NOT NULL;
CREATE INDEX settlement_proofs_queue ON settlement_proofs (restaurant_id, created_at) WHERE state = 'pending';

-- Baixa com comprovante esperando a loja NÃO expira: o entregador já pagou;
-- expirar agora jogaria a culpa da demora da loja nele.
CREATE OR REPLACE FUNCTION expire_cash_settlements() RETURNS void
LANGUAGE sql AS $$
  UPDATE cash_settlement_intents i
  SET state = 'expired'
  WHERE i.state = 'open' AND i.expires_at < now()
    AND NOT EXISTS (SELECT 1 FROM settlement_proofs p WHERE p.intent_id = i.id AND p.state = 'pending');
$$;

COMMIT;
