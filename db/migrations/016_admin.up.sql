-- 016_admin.up.sql
-- Ajustes em `disputes` pro painel da plataforma (telas 12.1 e 12.2).
--
-- 1) order_id deixa de ser obrigatório. A divergência de caixa
--    ('cash_unsettled') é entre um entregador e uma loja num FECHAMENTO, não
--    num pedido: o dinheiro veio de várias corridas. Exigir um pedido ali
--    obrigaria a escolher um arbitrariamente, e um número escolhido no chute
--    é pior que um campo vazio.
-- 2) courier_id e restaurant_id passam a existir, porque sem pedido eles são
--    a única forma de saber de quem é a ocorrência.
-- 3) `restaurants.rejected_at` e `rejection_reason`: a tela 12.1 aprova OU
--    recusa o cadastro, e até agora só existia `approved_at` -- uma loja
--    recusada ficava indistinguível de uma loja que nunca foi analisada.

BEGIN;

ALTER TABLE disputes
  ALTER COLUMN order_id DROP NOT NULL,
  ADD COLUMN courier_id    uuid REFERENCES couriers(id),
  ADD COLUMN restaurant_id uuid REFERENCES restaurants(id),
  ADD CONSTRAINT dispute_has_subject CHECK (
    order_id IS NOT NULL OR courier_id IS NOT NULL OR restaurant_id IS NOT NULL);

ALTER TABLE restaurants
  ADD COLUMN rejected_at      timestamptz,
  ADD COLUMN rejection_reason text,
  ADD CONSTRAINT restaurant_not_both_decisions CHECK (
    approved_at IS NULL OR rejected_at IS NULL);

COMMIT;
