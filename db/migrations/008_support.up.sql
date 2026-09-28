-- 008_support.up.sql
-- disputes, fraud_signals, delivery_incidents, tickets, order_messages,
-- coupons, coupon_redemptions.
-- Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

BEGIN;

CREATE TABLE disputes (
  id           bigserial PRIMARY KEY,
  order_id     bigint NOT NULL REFERENCES orders(id),
  kind         text NOT NULL CHECK (kind IN
                 ('fake_proof','nsu_divergent','not_delivered',
                  'cash_unsettled','wrong_item','pos_not_returned')),
  risk         text NOT NULL DEFAULT 'medium' CHECK (risk IN ('low','medium','high')),
  amount       numeric(12,2),
  state        text NOT NULL DEFAULT 'open'
                 CHECK (state IN ('open','resolved','archived')),
  resolution   text, decided_by uuid REFERENCES users(id),
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX disputes_open_idx ON disputes (state) WHERE state = 'open';

CREATE TABLE fraud_signals (
  id         bigserial PRIMARY KEY,
  kind       text NOT NULL CHECK (kind IN
               ('proof_reuse','proof_phash','ip_velocity','geo_impossible','cpf_multi')),
  subject    text NOT NULL,                -- hash, ip, cpf, device
  user_id    uuid REFERENCES users(id),
  courier_id uuid REFERENCES couriers(id),
  order_id   bigint REFERENCES orders(id),
  score      int NOT NULL CHECK (score BETWEEN 0 AND 100),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX fraud_subject_idx ON fraud_signals (kind, subject);

CREATE TABLE delivery_incidents (
  id            bigserial PRIMARY KEY,
  order_id      bigint NOT NULL REFERENCES orders(id),
  courier_id    uuid NOT NULL REFERENCES couriers(id),
  kind          text NOT NULL CHECK (kind IN
                  ('customer_absent','no_cash','bad_address','unsafe_area')),
  photo_key     text,                        -- bucket privado
  geo_lat       numeric(9,6), geo_lng numeric(9,6),
  call_attempts int NOT NULL DEFAULT 0,
  waited        interval,
  resolution    text CHECK (resolution IN ('returned','discarded','delivered')),
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX delivery_incidents_order_idx ON delivery_incidents (order_id);

CREATE TABLE tickets (
  id          bigserial PRIMARY KEY,
  code        text NOT NULL UNIQUE,        -- "T-8841", o que o cliente le
  user_id     uuid REFERENCES users(id),
  order_id    bigint REFERENCES orders(id),
  category    text NOT NULL CHECK (category IN
                ('late','wrong_item','refund','pix_pending','other')),
  state       text NOT NULL DEFAULT 'open' CHECK (state IN
                ('open','waiting_customer','resolved')),
  sla_due_at  timestamptz,
  resolved_by uuid REFERENCES users(id),
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tickets_sla_idx ON tickets (sla_due_at) WHERE state <> 'resolved';

CREATE TABLE order_messages (             -- chat de tres pontas
  id          bigserial PRIMARY KEY,
  order_id    bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  sender_id   uuid REFERENCES users(id),
  sender_role text NOT NULL CHECK (sender_role IN
                ('customer','store','courier','support','system')),
  body        text NOT NULL,
  read_at     timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_messages_idx ON order_messages (order_id, created_at);
-- chat fecha 2h apos 'delivered': regra na API, historico permanece

CREATE TABLE coupons (
  id            bigserial PRIMARY KEY,
  code          text NOT NULL UNIQUE,
  kind          text NOT NULL CHECK (kind IN ('fixed','percent','free_delivery')),
  value         numeric(12,2) NOT NULL,
  min_order     numeric(12,2) NOT NULL DEFAULT 0,
  restaurant_id uuid REFERENCES restaurants(id),  -- NULL = toda a plataforma
  audience      text NOT NULL CHECK (audience IN
                  ('all','first_order','inactive_15d','inactive_30d')),
  payer         text NOT NULL CHECK (payer IN ('store','platform','shared')),
  budget_cap    numeric(12,2) NOT NULL CHECK (budget_cap > 0),
  spent         numeric(12,2) NOT NULL DEFAULT 0,
  starts_at     timestamptz NOT NULL,
  ends_at       timestamptz,
  active        boolean NOT NULL DEFAULT true,
  created_by    uuid NOT NULL REFERENCES users(id),
  CONSTRAINT within_budget CHECK (spent <= budget_cap)
);
CREATE INDEX coupons_active_idx ON coupons (active) WHERE active;

CREATE TABLE coupon_redemptions (
  id         bigserial PRIMARY KEY,
  coupon_id  bigint NOT NULL REFERENCES coupons(id),
  order_id   bigint NOT NULL REFERENCES orders(id),
  cpf        char(11) NOT NULL,            -- um uso por CPF, nao por conta
  amount     numeric(12,2) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (coupon_id, cpf)
);

COMMIT;
