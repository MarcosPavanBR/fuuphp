-- 029_loyalty.down.sql

BEGIN;

ALTER TABLE platform_policies DROP COLUMN loyalty_points_per_brl;
ALTER TABLE coupons DROP COLUMN owner_user_id;
DROP TABLE loyalty_rewards;
DROP TABLE loyalty_entries;

COMMIT;
