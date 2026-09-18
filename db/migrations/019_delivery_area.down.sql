-- 019_delivery_area.down.sql
BEGIN;

ALTER TABLE platform_policies
  DROP COLUMN IF EXISTS delivery_max_km,
  DROP COLUMN IF EXISTS delivery_per_km,
  DROP COLUMN IF EXISTS delivery_base_fee;

ALTER TABLE addresses
  DROP COLUMN IF EXISTS reference;

COMMIT;
