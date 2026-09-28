-- 020_scheduling.up.sql
-- Tela 14.4 — "Pedido agendado": faixa com vaga limitada pela capacidade
-- real da cozinha, nao pelo relogio.
--
-- As duas pecas de banco ja existiam desde a 004 e estavam sem uso:
-- `orders.scheduled_for` (tstzrange) e `delivery_slots` (capacity/taken, com
-- CHECK (taken <= capacity) -- e esse CHECK que faz "3 vagas" ser verdade
-- mesmo com dois clientes apertando ao mesmo tempo).
--
-- O que faltava era de onde sai a capacidade. Nao da pra inventar: quantos
-- pedidos cabem numa faixa de 30 min e um numero que so a loja sabe. Entao a
-- loja declara, na tela de horario (11.4), e zero -- o padrao -- significa
-- "essa loja nao aceita agendamento", nao "cabe zero pedido".

BEGIN;

ALTER TABLE restaurants
  ADD COLUMN slot_capacity int NOT NULL DEFAULT 0
    CHECK (slot_capacity >= 0);

-- Achar rapido os pedidos agendados de uma loja: a fila da cozinha (11.1)
-- passa a esconder o que ainda nao chegou perto da faixa, e essa consulta
-- roda a cada atualizacao do KDS.
CREATE INDEX orders_scheduled_idx ON orders (restaurant_id, scheduled_for)
  WHERE scheduled_for IS NOT NULL;

COMMIT;
