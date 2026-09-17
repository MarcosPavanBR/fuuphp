-- 002_catalog.up.sql
-- restaurants, restaurant_credentials, cardapio (menu_items, item_variants),
-- business_hours, addresses.
-- Libera: catalogo e cardapio (Fases 2, 3, 11 das telas).

BEGIN;

CREATE TYPE payment_method AS ENUM
  ('mp_card','pix_auto','pix_manual','cash','pos_machine');

CREATE TABLE restaurants (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name           text NOT NULL,
  cnpj           char(14) NOT NULL UNIQUE,
  city_ibge_code char(7) NOT NULL,
  commission_bps int  NOT NULL DEFAULT 800,
  credit_limit   numeric(12,2) NOT NULL DEFAULT 3000.00,
  online_only_until date,          -- periodo de teste da loja nova
  is_open        boolean NOT NULL DEFAULT false,
  pause_until    timestamptz,
  approved_at    timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now()
);

-- O ativo mais sensivel do sistema: o token que move o dinheiro DELE.
CREATE TABLE restaurant_credentials (
  restaurant_id   uuid PRIMARY KEY REFERENCES restaurants(id) ON DELETE CASCADE,
  mp_public_key   text,            -- pode ficar em claro: e publico
  mp_access_token bytea,           -- pgp_sym_encrypt(token, :key_from_vault)
  mp_user_id      text,
  pix_key         text,
  rotated_at      timestamptz NOT NULL DEFAULT now()
);
REVOKE SELECT ON restaurant_credentials FROM app_ro;   -- relatorio nao ve segredo

CREATE TABLE menu_items (
  id            bigserial PRIMARY KEY,
  restaurant_id uuid NOT NULL REFERENCES restaurants(id) ON DELETE CASCADE,
  name          text NOT NULL,
  description   text,
  price         numeric(12,2) NOT NULL CHECK (price >= 0),
  category      text,
  photo_key     text,
  available     boolean NOT NULL DEFAULT true,
  sold_out_at   timestamptz,
  position      int NOT NULL DEFAULT 0,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX menu_items_restaurant_idx ON menu_items (restaurant_id) WHERE available;

CREATE TABLE item_variants (
  id             bigserial PRIMARY KEY,
  menu_item_id   bigint NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
  group_name     text NOT NULL,      -- "Tamanho", "Adicionais"
  name           text NOT NULL,
  price_delta    numeric(12,2) NOT NULL DEFAULT 0,
  max_selections int,
  required       boolean NOT NULL DEFAULT false,
  position       int NOT NULL DEFAULT 0
);
CREATE INDEX item_variants_item_idx ON item_variants (menu_item_id);

CREATE TABLE business_hours (
  restaurant_id uuid NOT NULL REFERENCES restaurants(id) ON DELETE CASCADE,
  dow           int  NOT NULL CHECK (dow BETWEEN 0 AND 6),
  shift         text NOT NULL CHECK (shift IN ('lunch','dinner')),
  opens         time NOT NULL, closes time NOT NULL,
  last_order    time NOT NULL,
  active        boolean NOT NULL DEFAULT true,
  PRIMARY KEY (restaurant_id, dow, shift)
);

CREATE TABLE addresses (
  id             bigserial PRIMARY KEY,
  user_id        uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  label          text,
  street         text NOT NULL,
  number         text,
  complement     text,
  neighborhood   text,
  city           text NOT NULL,
  city_ibge_code char(7) NOT NULL,
  state          char(2) NOT NULL,
  postal_code    char(8) NOT NULL,
  lat            numeric(9,6) NOT NULL,
  lng            numeric(9,6) NOT NULL,
  is_default     boolean NOT NULL DEFAULT false,
  created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX addresses_user_idx ON addresses (user_id);

-- Deferida da 001: partner_accounts.restaurant_id so podia ganhar FK
-- depois que restaurants existisse.
ALTER TABLE partner_accounts
  ADD CONSTRAINT partner_accounts_restaurant_fk
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id);

COMMIT;
