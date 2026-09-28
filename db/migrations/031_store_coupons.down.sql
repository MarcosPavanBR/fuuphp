-- 031_store_coupons.down.sql

BEGIN;

DROP INDEX IF EXISTS coupons_store_created_idx;
ALTER TABLE coupons
  DROP CONSTRAINT store_coupon_is_store_paid,
  DROP COLUMN created_by_store;
ALTER TABLE restaurants DROP COLUMN coupon_budget_limit;

COMMIT;
