-- 004_ordering.down.sql
BEGIN;

DROP FUNCTION IF EXISTS advance_order(bigint, text, uuid, text, jsonb);
DROP TABLE IF EXISTS delivery_slots;
DROP TABLE IF EXISTS outbox;
DROP TABLE IF EXISTS order_events;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;

COMMIT;
