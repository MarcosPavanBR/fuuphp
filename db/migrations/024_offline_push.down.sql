-- 024_offline_push.down.sql

BEGIN;

DROP TABLE notifications;
DROP TABLE push_subscriptions;
DROP INDEX payment_proofs_upload_key_idx;
ALTER TABLE payment_proofs DROP COLUMN upload_key;

COMMIT;
