-- 006_ledger.up.sql
-- ledger_entries (append-only) + views, cash_settlement_intents, payouts,
-- pos_devices, pos_custody.
-- Libera: caixa e repasses (Fase 9 das telas).

BEGIN;

CREATE TYPE ledger_account AS ENUM (
  'courier_cash',      -- especie em posse do entregador
  'courier_payable',   -- o que devemos a ele (frete, bonus, gorjeta)
  'store_receivable',  -- o que a loja nos deve (comissao + frete)
  'platform_revenue', 'platform_expense');

CREATE TABLE ledger_entries (
  id         bigserial PRIMARY KEY,
  account    ledger_account NOT NULL,
  party_id   uuid NOT NULL,               -- courier_id ou restaurant_id
  amount     numeric(12,2) NOT NULL,      -- + credita, - debita
  origin     text NOT NULL CHECK (origin IN
               ('order','cash_settlement','payout','refund','dispute',
                'coupon','compensation','adjustment')),
  origin_id  text NOT NULL,
  order_id   bigint REFERENCES orders(id),
  actor_id   uuid REFERENCES users(id),
  memo       text,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ledger_balance_idx ON ledger_entries (account, party_id);
REVOKE UPDATE, DELETE ON ledger_entries FROM app_rw;  -- append-only garantido

CREATE VIEW courier_cash_balance AS
  SELECT party_id AS courier_id, SUM(amount) AS balance
  FROM ledger_entries WHERE account='courier_cash' GROUP BY 1;

CREATE VIEW store_balance AS
  SELECT party_id AS restaurant_id, SUM(amount) AS owed
  FROM ledger_entries WHERE account='store_receivable' GROUP BY 1;

-- Baixa de especie: intencao do entregador + confirmacao da loja.
-- courier_id ganha FK na migracao 007, quando couriers existir.
CREATE TABLE cash_settlement_intents (
  id             bigserial PRIMARY KEY,
  courier_id     uuid NOT NULL,
  restaurant_id  uuid NOT NULL REFERENCES restaurants(id),
  amount         numeric(12,2) NOT NULL CHECK (amount > 0),
  method         text NOT NULL CHECK (method IN ('in_person','pix')),
  code_hash      char(64),               -- nunca o codigo em claro
  expires_at     timestamptz NOT NULL,
  state          text NOT NULL DEFAULT 'open'
                   CHECK (state IN ('open','settled','expired','disputed')),
  counted_amount numeric(12,2),          -- o que a loja contou de fato
  confirmed_by   uuid REFERENCES users(id),
  confirmed_at   timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX one_open_intent ON cash_settlement_intents (courier_id)
  WHERE state = 'open';                  -- uma baixa em voo por entregador

CREATE TABLE payouts (
  id           bigserial PRIMARY KEY,
  party_kind   text NOT NULL CHECK (party_kind IN ('courier','restaurant')),
  party_id     uuid NOT NULL,
  period_start date NOT NULL, period_end date NOT NULL,
  gross        numeric(12,2) NOT NULL,
  withheld     numeric(12,2) NOT NULL DEFAULT 0,  -- especie nao baixada
  net          numeric(12,2) NOT NULL,
  provider_ref text,
  state        text NOT NULL DEFAULT 'draft'
                 CHECK (state IN ('draft','sent','paid','failed')),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (party_kind, party_id, period_start, period_end)
);

CREATE TABLE pos_devices (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  restaurant_id uuid NOT NULL REFERENCES restaurants(id),
  label         text NOT NULL,            -- "POS-01"
  acquirer      text NOT NULL,            -- stone, cielo, getnet...
  serial        text,
  active        boolean NOT NULL DEFAULT true,
  UNIQUE (restaurant_id, label)
);

-- courier_id ganha FK na migracao 007, quando couriers existir.
CREATE TABLE pos_custody (
  id           bigserial PRIMARY KEY,
  device_id    uuid NOT NULL REFERENCES pos_devices(id),
  courier_id   uuid NOT NULL,
  taken_at     timestamptz NOT NULL DEFAULT now(),
  due_at       timestamptz NOT NULL,      -- fim do turno, por politica
  returned_at  timestamptz,
  confirmed_by uuid REFERENCES users(id)  -- a loja confirma a devolucao
);
CREATE UNIQUE INDEX pos_one_holder ON pos_custody (device_id)
  WHERE returned_at IS NULL;              -- a maquina esta com UMA pessoa

-- Deferida da 005: card_transactions.device_id so podia ganhar FK depois
-- que pos_devices existisse.
ALTER TABLE card_transactions
  ADD CONSTRAINT card_transactions_device_fk
  FOREIGN KEY (device_id) REFERENCES pos_devices(id);

COMMIT;
