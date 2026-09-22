-- 025_review_tip_charge.down.sql

BEGIN;

ALTER TABLE reviews
  DROP COLUMN tip_charged_at,
  DROP COLUMN tip_error,
  DROP COLUMN tip_provider_ref,
  DROP COLUMN tip_state;

COMMIT;
