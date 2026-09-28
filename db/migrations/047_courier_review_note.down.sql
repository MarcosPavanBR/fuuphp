-- 047_courier_review_note.down.sql
BEGIN;
ALTER TABLE courier_applications DROP COLUMN reviewed_at;
ALTER TABLE courier_applications DROP COLUMN review_note;
COMMIT;
