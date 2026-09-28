-- 001_identity.up.sql
-- Extensoes, roles de acesso e o modulo identity: users, partner_accounts,
-- otp_codes, consents, sessions.
-- Libera: login e cadastro (Fase 10 das telas).

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
CREATE EXTENSION IF NOT EXISTS pg_cron;

-- Tres roles, nenhuma superusuaria na aplicacao (secao 10, Parte I).
-- Autenticacao (senha/cert) e definida fora das migracoes, pelo secret manager.
DO $$ BEGIN
  CREATE ROLE app_rw LOGIN;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
  CREATE ROLE app_ro LOGIN;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
  CREATE ROLE migrator LOGIN;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

CREATE TABLE users (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  role              text NOT NULL CHECK (role IN
                      ('customer','restaurant_staff','courier','admin','support')),
  full_name         text NOT NULL,
  cpf               char(11) UNIQUE,
  phone             text UNIQUE,
  email             citext UNIQUE,
  password_hash     text,               -- login com senha (loja/admin no painel)
  lgpd_accepted_at  timestamptz,
  blocked           boolean NOT NULL DEFAULT false,
  created_at        timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT users_contact_present CHECK (phone IS NOT NULL OR email IS NOT NULL)
);
CREATE INDEX users_role_idx ON users (role);

-- Login de parceiros: loja com CNPJ+senha no tablet, entregador com CPF+codigo,
-- 2FA por aparelho. restaurant_id/courier_id ganham FK quando essas tabelas
-- existirem (002 e 007) -- nao existem ainda nesta migracao.
CREATE TABLE partner_accounts (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind              text NOT NULL CHECK (kind IN ('restaurant','courier')),
  restaurant_id     uuid,
  courier_id        uuid,
  login_code        text NOT NULL,        -- CNPJ (loja) ou CPF (entregador)
  password_hash     text,                 -- loja: CNPJ + senha
  access_code_hash  char(64),             -- entregador: CPF + codigo
  device_id         text,                 -- 2FA por aparelho
  created_at        timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT partner_accounts_scope CHECK (
    (kind = 'restaurant' AND restaurant_id IS NOT NULL AND courier_id IS NULL) OR
    (kind = 'courier'    AND courier_id    IS NOT NULL AND restaurant_id IS NULL))
);
CREATE UNIQUE INDEX partner_accounts_login_idx ON partner_accounts (kind, login_code);

CREATE TABLE otp_codes (
  id          bigserial PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  channel     text NOT NULL CHECK (channel IN ('sms','whatsapp','email')),
  code_hash   char(64) NOT NULL,          -- nunca o codigo em claro
  purpose     text NOT NULL CHECK (purpose IN ('login','signup','phone_verify')),
  attempts    int NOT NULL DEFAULT 0,
  expires_at  timestamptz NOT NULL,
  consumed_at timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX otp_codes_pending_idx ON otp_codes (user_id, purpose) WHERE consumed_at IS NULL;

-- LGPD: termo aceito, versao e IP de cada consentimento.
CREATE TABLE consents (
  id          bigserial PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind        text NOT NULL CHECK (kind IN ('terms','privacy_policy','marketing','location')),
  version     text NOT NULL,
  accepted_at timestamptz NOT NULL DEFAULT now(),
  ip          inet
);
CREATE INDEX consents_user_idx ON consents (user_id, kind);

-- Access token de 15 min (fora do banco); refresh de 30 dias com rotacao.
-- family_id agrupa a cadeia de rotacao: um refresh_hash reaparecendo depois
-- de ja ter sido rotacionado (rotated_from apontado por outra sessao) e a
-- assinatura de token roubado -- a aplicacao revoga a familia inteira.
CREATE TABLE sessions (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  family_id     uuid NOT NULL,
  refresh_hash  char(64) NOT NULL UNIQUE,
  device_label  text,
  ip            inet,
  rotated_from  uuid REFERENCES sessions(id),
  revoked_at    timestamptz,
  expires_at    timestamptz NOT NULL,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sessions_family_idx ON sessions (family_id);
CREATE INDEX sessions_user_idx ON sessions (user_id) WHERE revoked_at IS NULL;

COMMIT;
