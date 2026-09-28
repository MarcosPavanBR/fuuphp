-- 010_catalog_discovery.down.sql
BEGIN;

DROP INDEX IF EXISTS menu_items_name_trgm_idx;
DROP INDEX IF EXISTS restaurants_city_category_idx;

ALTER TABLE restaurants
  DROP COLUMN IF EXISTS lng,
  DROP COLUMN IF EXISTS lat,
  DROP COLUMN IF EXISTS logo_key,
  DROP COLUMN IF EXISTS category;

COMMIT;
