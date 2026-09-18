-- 015_delivery_proof.up.sql
-- Prova de entrega da tela 8.6: "Código de 4 dígitos ou foto com GPS — prova
-- de entrega para disputa."
--
-- orders.delivery_code: o cliente vê no app, o entregador digita na porta.
-- Nasce com o pedido (DEFAULT aleatório por linha) porque o cliente precisa
-- vê-lo antes de o entregador chegar. Não é segredo criptográfico -- são 4
-- dígitos, adivinháveis por força bruta --, é prova de presença: quem digita
-- certo esteve com o cliente.
--
-- delivery_proofs: a alternativa de quando o cliente não sabe informar o
-- código (porta, portaria, cliente ausente). Foto com GPS e horário, que é o
-- que sustenta disputa depois (Fase 14). Uma linha por pedido.

BEGIN;

ALTER TABLE orders
  ADD COLUMN delivery_code char(4) NOT NULL
    DEFAULT lpad((floor(random() * 10000))::int::text, 4, '0');

CREATE TABLE delivery_proofs (
  id          bigserial PRIMARY KEY,
  order_id    bigint NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
  courier_id  uuid NOT NULL REFERENCES couriers(id),
  kind        text NOT NULL CHECK (kind IN ('code','photo')),
  storage_key text,                      -- só quando kind='photo'
  sha256      char(64),
  lat         numeric(9,6),
  lng         numeric(9,6),
  created_at  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT photo_needs_file CHECK (kind <> 'photo' OR storage_key IS NOT NULL)
);
CREATE INDEX delivery_proofs_courier_idx ON delivery_proofs (courier_id, created_at DESC);

COMMIT;
