-- 017_no_courier.up.sql
-- Tela 15.1 — "Sem entregador disponível".
--
-- orders.pickup_by_customer: a saída que SALVA o pedido quando ninguém
-- aceita a corrida. Não dá pra representar isso só apagando o endereço:
-- a cozinha precisa saber que o cliente vem buscar (some da fila de
-- entrega, aparece como retirada), e o endereço continua sendo histórico
-- de para onde o pedido ia.
--
-- orders.no_courier_since: desde quando o pedido está pronto sem ninguém
-- designado. Podia ser derivado de order_events toda vez, mas esse carimbo
-- é lido por um processo que varre pedidos vencidos a cada minuto -- e uma
-- coluna indexável vale mais que um subselect por linha.

BEGIN;

ALTER TABLE orders
  ADD COLUMN pickup_by_customer boolean NOT NULL DEFAULT false,
  ADD COLUMN no_courier_since   timestamptz;

CREATE INDEX orders_no_courier_idx ON orders (no_courier_since)
  WHERE courier_id IS NULL AND no_courier_since IS NOT NULL;

-- ('ready','cancelled') nao existia na lista da 004, e sem ela a tela 15.1 e
-- uma promessa que o banco recusa: tanto "Cancelar e receber tudo de volta"
-- quanto "passados 15 min cancelamos sozinhos" acontecem com o pedido em
-- 'ready'. A lista da 004 fechava 'ready' de proposito -- "a comida esta na
-- bancada esperando o entregador" -- e o caso que faltava e exatamente esse:
-- a comida na bancada e NINGUEM vindo buscar. So essa transicao entra; o
-- resto da lista fica identico.
CREATE OR REPLACE FUNCTION advance_order(
  p_order bigint, p_to text, p_actor uuid, p_kind text, p_meta jsonb DEFAULT '{}'
) RETURNS text LANGUAGE plpgsql AS $$
DECLARE v_from text;
BEGIN
  SELECT status INTO v_from FROM orders WHERE id = p_order FOR UPDATE;
  IF v_from IS NULL THEN RAISE EXCEPTION 'pedido % inexistente', p_order; END IF;

  IF NOT (v_from, p_to) IN (
      ('cart','pending_payment'),
      ('pending_payment','pending_verification'), ('pending_payment','paid'),
      ('pending_payment','rejected'),  ('pending_payment','cancelled'),
      ('pending_verification','paid'), ('pending_verification','rejected'),
      ('pending_verification','cancelled'),
      ('paid','preparing'),  ('paid','cancelled'),  ('paid','rejected'),
      ('preparing','ready'), ('preparing','cancelled'),
      ('ready','delivering'), ('ready','cancelled'),
      ('delivering','delivered'), ('delivering','cancelled'),
      ('paid','refunded'), ('delivered','refunded'), ('cancelled','refunded')
  ) THEN
    RAISE EXCEPTION 'transicao ilegal: % -> %', v_from, p_to;
  END IF;

  UPDATE orders SET status = p_to, updated_at = now() WHERE id = p_order;
  INSERT INTO order_events(order_id, from_status, to_status, actor_id, actor_kind, meta)
    VALUES (p_order, v_from, p_to, p_actor, p_kind, p_meta);
  INSERT INTO outbox(topic, payload) VALUES (
    'order.' || p_to,
    jsonb_build_object('order_id', p_order, 'from', v_from, 'to', p_to));
  PERFORM pg_notify('order_changed', p_order::text);
  RETURN v_from;
END $$;

COMMIT;
