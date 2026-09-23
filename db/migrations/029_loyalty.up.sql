-- 029_loyalty.up.sql
-- Tela 2.3 — Fidelidade: "Seus pontos 1.240 de 1.500 · Faltam 260 pontos para
-- o cupom de R$ 20 · R$ 10 de desconto 800 pontos · Entrega grátis 1.500
-- pontos · Histórico: Pedido #A38F2C +128 · Cupom R$ 10 −800".
-- "Saldo calculado no banco (soma dos lançamentos), nunca no cliente."
--
-- Mesmo desenho do livro financeiro (006): pontos são LANÇAMENTOS, só de
-- inserção, e o saldo é a soma. Ganha-se na entrega, perde-se no estorno,
-- gasta-se no resgate -- cada um uma linha, com origem única (idempotente).
--
-- O resgate vira um cupom PESSOAL (coupons.owner_user_id): passa por toda a
-- máquina de cupom que já existe (desconto, orçamento, livro contábil com a
-- plataforma pagando, um uso por CPF), e só o dono consegue usar.
--
-- Suposição declarada: o mock não diz quantos pontos vale cada real. Fica
-- 1 ponto por real de subtotal (`platform_policies.loyalty_points_per_brl`),
-- ajustável pela política; o "+128" do mock é um pedido de R$ 128.

BEGIN;

CREATE TABLE loyalty_entries (
  id         bigserial PRIMARY KEY,
  user_id    uuid NOT NULL REFERENCES users(id),
  points     integer NOT NULL CHECK (points <> 0),   -- + ganha, - gasta/estorna
  origin     text NOT NULL CHECK (origin IN ('order','refund','redeem','adjustment')),
  origin_id  text NOT NULL,
  order_id   bigint REFERENCES orders(id),
  memo       text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (origin, origin_id)
);
CREATE INDEX loyalty_entries_user_idx ON loyalty_entries (user_id, created_at DESC);
REVOKE UPDATE, DELETE ON loyalty_entries FROM app_rw;   -- só de inserção, como o livro

-- O catálogo de trocas (o que a tela oferece), gerido pela plataforma.
CREATE TABLE loyalty_rewards (
  id         bigserial PRIMARY KEY,
  label      text NOT NULL,
  kind       text NOT NULL CHECK (kind IN ('fixed','free_delivery')),
  value      numeric(12,2) NOT NULL DEFAULT 0,
  cost       integer NOT NULL CHECK (cost > 0),
  valid_days integer NOT NULL DEFAULT 30 CHECK (valid_days > 0),
  active     boolean NOT NULL DEFAULT true
);
INSERT INTO loyalty_rewards (label, kind, value, cost) VALUES
  ('R$ 10 de desconto', 'fixed', 10.00, 800),
  ('R$ 20 de desconto', 'fixed', 20.00, 1500),
  ('Entrega grátis', 'free_delivery', 0.00, 1500);

ALTER TABLE coupons ADD COLUMN owner_user_id uuid REFERENCES users(id);
ALTER TABLE platform_policies ADD COLUMN loyalty_points_per_brl numeric(6,2) NOT NULL DEFAULT 1
  CHECK (loyalty_points_per_brl >= 0);

COMMIT;
