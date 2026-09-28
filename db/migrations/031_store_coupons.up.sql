-- 031_store_coupons.up.sql
-- Tela 15.3 — "Cupom de loja ela cria sozinha no painel, dentro do teto que
-- você liberar aqui."
--
-- Até aqui só a plataforma criava cupom, inclusive o pago pela loja: deixar
-- a loja criar pedia um teto por loja, e ele não existia (decisão 21). Agora:
--
--   restaurants.coupon_budget_limit  o teto que a plataforma libera pra loja
--                                    (0 = a loja não cria cupom, o padrão);
--   coupons.created_by_store         o cupom foi criado pela própria loja.
--
-- O teto vale sobre o ORÇAMENTO COMPROMETIDO: a soma dos `budget_cap` dos
-- cupons vivos (ativos e no prazo) que a loja criou. Desativar ou vencer um
-- cupom devolve o que ele não gastou. Cupom criado pela loja é sempre pago
-- por ela (payer = 'store'), e só vale na própria loja -- o CHECK garante.

BEGIN;

ALTER TABLE restaurants
  ADD COLUMN coupon_budget_limit numeric(12,2) NOT NULL DEFAULT 0
    CHECK (coupon_budget_limit >= 0);

ALTER TABLE coupons
  ADD COLUMN created_by_store boolean NOT NULL DEFAULT false,
  ADD CONSTRAINT store_coupon_is_store_paid
    CHECK (NOT created_by_store OR (payer = 'store' AND restaurant_id IS NOT NULL));

CREATE INDEX coupons_store_created_idx ON coupons (restaurant_id)
  WHERE created_by_store AND active;

COMMIT;
