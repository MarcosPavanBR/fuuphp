-- 044_cart_coupon.down.sql
BEGIN;
ALTER TABLE orders DROP COLUMN coupon_id;
COMMIT;
