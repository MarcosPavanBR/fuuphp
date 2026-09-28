-- 007_dispatch.up.sql
-- courier_applications, courier_documents, couriers, courier_shifts, offers,
-- dispatch_attempts.
-- Libera: app do entregador (Fases 8 e 15 das telas).

BEGIN;

CREATE TABLE courier_applications (       -- entrada na plataforma
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name   text NOT NULL,
  cpf         char(11) NOT NULL UNIQUE,
  phone       text NOT NULL, email citext,
  vehicle     text NOT NULL CHECK (vehicle IN ('moto','bike','car','foot')),
  plate       text,
  pix_key     text NOT NULL,              -- tem de ser do mesmo CPF
  face_match  numeric(5,2),               -- selfie x documento
  state       text NOT NULL DEFAULT 'draft' CHECK (state IN
                ('draft','review','approved','rejected','needs_fix')),
  reviewed_by uuid REFERENCES users(id),
  contract_version int,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE courier_documents (
  id             bigserial PRIMARY KEY,
  application_id uuid NOT NULL REFERENCES courier_applications(id) ON DELETE CASCADE,
  kind           text NOT NULL CHECK (kind IN
                   ('cnh','selfie','crlv','address_proof')),
  storage_key    text NOT NULL,
  sha256         char(64) NOT NULL,
  expires_on     date,
  state          text NOT NULL DEFAULT 'pending'
                   CHECK (state IN ('pending','valid','invalid')),
  UNIQUE (application_id, kind)
);

CREATE TABLE couriers (
  id             uuid PRIMARY KEY,          -- = application_id ao aprovar
  user_id        uuid NOT NULL REFERENCES users(id),
  city_ibge_code char(7) NOT NULL,
  rating         numeric(3,2),
  cash_blocked   boolean NOT NULL DEFAULT false,
  active         boolean NOT NULL DEFAULT true
);

CREATE TABLE courier_shifts (
  id         bigserial PRIMARY KEY,
  courier_id uuid NOT NULL REFERENCES couriers(id),
  started_at timestamptz NOT NULL DEFAULT now(),
  ended_at   timestamptz
);
CREATE UNIQUE INDEX one_open_shift ON courier_shifts (courier_id)
  WHERE ended_at IS NULL;

CREATE TABLE offers (                     -- corrida oferecida
  id         bigserial PRIMARY KEY,
  order_id   bigint NOT NULL REFERENCES orders(id),
  courier_id uuid REFERENCES couriers(id),   -- NULL ate alguem aceitar
  fee        numeric(12,2) NOT NULL,
  bonus      numeric(12,2) NOT NULL DEFAULT 0,
  expires_at timestamptz NOT NULL,
  state      text NOT NULL DEFAULT 'open' CHECK (state IN
               ('open','accepted','declined','expired')),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX offers_open_idx ON offers (order_id) WHERE state = 'open';
-- aceite atomico, sem transacao explicita:
--   UPDATE offers SET courier_id=:c, state='accepted'
--    WHERE id=:o AND courier_id IS NULL RETURNING id;
--   zero linhas = outro entregador chegou primeiro

CREATE TABLE dispatch_attempts (          -- por que nao achou entregador
  id         bigserial PRIMARY KEY,
  order_id   bigint NOT NULL REFERENCES orders(id),
  round      int NOT NULL,
  radius_km  numeric(5,2) NOT NULL,
  candidates int NOT NULL,
  surge      numeric(12,2) NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now()
);

-- Deferidas: couriers agora existe, entao as FKs pendentes de 001, 004 e 006
-- podem ser adicionadas.
ALTER TABLE partner_accounts
  ADD CONSTRAINT partner_accounts_courier_fk
  FOREIGN KEY (courier_id) REFERENCES couriers(id);

ALTER TABLE orders
  ADD CONSTRAINT orders_courier_fk
  FOREIGN KEY (courier_id) REFERENCES couriers(id);

ALTER TABLE cash_settlement_intents
  ADD CONSTRAINT cash_settlement_intents_courier_fk
  FOREIGN KEY (courier_id) REFERENCES couriers(id);

ALTER TABLE pos_custody
  ADD CONSTRAINT pos_custody_courier_fk
  FOREIGN KEY (courier_id) REFERENCES couriers(id);

COMMIT;
