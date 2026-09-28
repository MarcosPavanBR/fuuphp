-- 012_saved_cards.down.sql
BEGIN;

DROP TABLE IF EXISTS saved_cards;

ALTER TABLE users
  DROP COLUMN IF EXISTS mp_customer_id;

COMMIT;
