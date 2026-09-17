-- 004_ordering.up.sql
-- orders, order_items, order_events, outbox, advance_order(), delivery_slots.
-- Libera: pedido, KDS, agendamento.

BEGIN;

CREATE TABLE orders (
  id             bigserial PRIMARY KEY,
  public_code    text NOT NULL UNIQUE
                   DEFAULT upper(substr(encode(gen_random_bytes(4),'hex'),1,8)),
  user_id        uuid NOT NULL REFERENCES users(id),
  restaurant_id  uuid NOT NULL REFERENCES restaurants(id),
  courier_id     uuid,          -- FK adicionada na migracao 007 (couriers ainda nao existe)
  address_id     bigint REFERENCES addresses(id),
  status         text NOT NULL DEFAULT 'cart' CHECK (status IN (
                   'cart','pending_payment','pending_verification','paid',
                   'preparing','ready','delivering','delivered',
                   'rejected','cancelled','refunded')),
  subtotal       numeric(12,2) NOT NULL CHECK (subtotal >= 0),
  delivery_fee   numeric(12,2) NOT NULL DEFAULT 0,
  surge_fee      numeric(12,2) NOT NULL DEFAULT 0,   -- frete turbinado
  discount       numeric(12,2) NOT NULL DEFAULT 0,
  tip            numeric(12,2) NOT NULL DEFAULT 0,
  total          numeric(12,2) GENERATED ALWAYS AS
                   (subtotal + delivery_fee + surge_fee + tip - discount) STORED,
  commission     numeric(12,2) NOT NULL DEFAULT 0,   -- nosso credito
  payment_method payment_method,
  change_for     numeric(12,2),
  machine_kind   text CHECK (machine_kind IN ('debit','credit')),
  scheduled_for  tstzrange,                          -- pedido agendado
  policy_snapshot jsonb NOT NULL DEFAULT '{}',        -- politica no ato
  verification_deadline timestamptz,
  cancel_reason  text, reject_reason text,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT cash_change_valid CHECK (
    payment_method <> 'cash' OR change_for IS NULL OR change_for >= subtotal),
  CONSTRAINT machine_needs_kind CHECK (
    payment_method <> 'pos_machine' OR machine_kind IS NOT NULL)
);
CREATE INDEX orders_kds_idx ON orders (restaurant_id, created_at DESC)
  WHERE status IN ('paid','preparing','ready');
CREATE INDEX orders_awaiting_idx ON orders (verification_deadline)
  WHERE status = 'pending_verification';
CREATE INDEX orders_user_idx ON orders (user_id, created_at DESC);

CREATE TABLE order_items (
  id                bigserial PRIMARY KEY,
  order_id          bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  menu_item_id      bigint NOT NULL REFERENCES menu_items(id),
  name_snapshot     text NOT NULL,        -- nome no momento do pedido
  unit_price        numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  quantity          int NOT NULL CHECK (quantity > 0),
  variants_snapshot jsonb NOT NULL DEFAULT '[]',
  notes             text,
  line_total        numeric(12,2) GENERATED ALWAYS AS (unit_price * quantity) STORED
);
CREATE INDEX order_items_order_idx ON order_items (order_id);

CREATE TABLE order_events (
  id          bigserial PRIMARY KEY,
  order_id    bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  from_status text,
  to_status   text NOT NULL,
  actor_id    uuid REFERENCES users(id),
  actor_kind  text NOT NULL CHECK (actor_kind IN ('customer','store','courier','admin','system')),
  meta        jsonb NOT NULL DEFAULT '{}',
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_events_order_idx ON order_events (order_id, created_at);

-- Entrega garantida para quem le em tempo real (KDS, apps): todo avanco de
-- pedido grava aqui, um worker le e publica, mesmo se ninguem estava
-- ouvindo pg_notify no instante exato.
CREATE TABLE outbox (
  id           bigserial PRIMARY KEY,
  topic        text NOT NULL,
  payload      jsonb NOT NULL,
  published_at timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX outbox_pending_idx ON outbox (created_at) WHERE published_at IS NULL;

CREATE TABLE delivery_slots (              -- pedido agendado
  restaurant_id uuid NOT NULL REFERENCES restaurants(id),
  "window"      tstzrange NOT NULL,        -- "window" e palavra reservada no PG
  capacity      int NOT NULL CHECK (capacity > 0),
  taken         int NOT NULL DEFAULT 0 CHECK (taken <= capacity),
  PRIMARY KEY (restaurant_id, "window")
);

-- A unica porta para mudar status (secao II.9). Nenhum "UPDATE orders SET
-- status" deve existir fora desta funcao -- e regra de revisao de codigo,
-- nao so de banco.
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

COMMIT;
