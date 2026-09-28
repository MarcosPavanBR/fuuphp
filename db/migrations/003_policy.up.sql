-- 003_policy.up.sql
-- platform_policies, policy_overrides, restaurant_payment_settings, audit_log.
-- Libera: checkout consciente de politica (teto, prazo, comissao, metodos).

BEGIN;

CREATE TABLE platform_policies (
  version               int PRIMARY KEY,
  cash_ceiling          numeric(12,2) NOT NULL DEFAULT 300.00,
  cash_settle_deadline  interval NOT NULL DEFAULT '1 day 23:59',
  allow_partial_settle  boolean NOT NULL DEFAULT true,
  withhold_unsettled    boolean NOT NULL DEFAULT true,
  require_cash_photo    boolean NOT NULL DEFAULT false,
  commission_bps        int NOT NULL DEFAULT 800
                          CHECK (commission_bps BETWEEN 0 AND 3000),
  courier_payout_dow    int NOT NULL DEFAULT 2,     -- terca
  store_debit_dow       int NOT NULL DEFAULT 2,
  pos_return_deadline   text NOT NULL DEFAULT 'shift_end',
  allow_courier_own_pos boolean NOT NULL DEFAULT false,
  enabled_methods       payment_method[] NOT NULL,
  new_store_online_only_days int NOT NULL DEFAULT 30,
  no_courier_timeout    interval NOT NULL DEFAULT '15 min',
  created_by            uuid NOT NULL REFERENCES users(id),
  created_at            timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE policy_overrides (            -- por praca, loja ou entregador
  id         bigserial PRIMARY KEY,
  scope      text NOT NULL CHECK (scope IN ('city','restaurant','courier')),
  scope_id   text NOT NULL,
  patch      jsonb NOT NULL,               -- so os campos que mudam
  reason     text NOT NULL,
  created_by uuid NOT NULL REFERENCES users(id),
  expires_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX policy_overrides_scope_idx ON policy_overrides (scope, scope_id);

CREATE TABLE restaurant_payment_settings (
  restaurant_id    uuid PRIMARY KEY REFERENCES restaurants(id) ON DELETE CASCADE,
  methods          payment_method[] NOT NULL,
  max_cash         numeric(12,2),
  max_card_machine numeric(12,2),
  max_change       numeric(12,2) NOT NULL DEFAULT 100.00,
  min_order        numeric(12,2) NOT NULL DEFAULT 0,
  updated_by       uuid REFERENCES users(id),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE audit_log (                   -- toda acao de admin
  id         bigserial PRIMARY KEY,
  actor_id   uuid NOT NULL REFERENCES users(id),
  action     text NOT NULL,
  target     text NOT NULL,
  before     jsonb, after jsonb,
  ip         inet,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_log_target_idx ON audit_log (target, created_at DESC);

-- Padroes decididos (documento II.5): comissao 8%, teto de especie R$300 com
-- prazo D+1 23:59, maquininha propria do entregador proibida, loja nova
-- so-online por 30 dias, cancelamento automatico por falta de entregador em
-- 15 min -- ja sao os DEFAULT das colunas acima. A linha version=1 de
-- platform_policies e inserida pela aplicacao no primeiro deploy, junto com
-- o usuario admin fundador (created_by e NOT NULL e nao ha seed de usuario
-- nas migracoes).

COMMIT;
