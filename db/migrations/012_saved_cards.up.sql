-- 012_saved_cards.up.sql
-- saved_cards: cartões salvos via Mercado Pago (Fase 6.2 das telas).
--
-- "Nenhum dado sensível de cartão fica no nosso banco: só bandeira, 4
-- últimos dígitos e o identificador do cartão salvo no Mercado Pago" --
-- a tabela reflete exatamente isso. users ganha mp_customer_id porque o
-- Mercado Pago modela "cartão salvo" como Customer -> Cards: um Customer
-- por usuário, N cartões por Customer -- sem guardar esse id em algum
-- lugar, cada cartão novo criaria um Customer novo à toa.

BEGIN;

ALTER TABLE users
  ADD COLUMN mp_customer_id text;

CREATE TABLE saved_cards (
  id             bigserial PRIMARY KEY,
  user_id        uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  mp_card_id     text NOT NULL,
  brand          text NOT NULL,
  last4          char(4) NOT NULL,
  kind           text CHECK (kind IN ('debit','credit')),
  exp_month      smallint NOT NULL CHECK (exp_month BETWEEN 1 AND 12),
  exp_year       smallint NOT NULL CHECK (exp_year >= 2000),
  is_default     boolean NOT NULL DEFAULT false,
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (user_id, mp_card_id)
);
CREATE INDEX saved_cards_user_idx ON saved_cards (user_id);

COMMIT;
