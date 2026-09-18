-- 017_no_courier.down.sql
BEGIN;

-- Volta a lista de transicoes exatamente como a 004 a escreveu, sem
-- ('ready','cancelled').
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
      ('ready','delivering'),
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

DROP INDEX IF EXISTS orders_no_courier_idx;

ALTER TABLE orders
  DROP COLUMN IF EXISTS no_courier_since,
  DROP COLUMN IF EXISTS pickup_by_customer;

COMMIT;
