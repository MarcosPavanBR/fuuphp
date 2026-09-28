-- 043_address_archive.down.sql
-- Os arquivados voltam a aparecer na lista do cliente (não há como saber
-- qual "versão" ele preferia): é o preço de desfazer.
BEGIN;
DROP INDEX addresses_user_live_idx;
ALTER TABLE addresses DROP COLUMN archived_at;
COMMIT;
