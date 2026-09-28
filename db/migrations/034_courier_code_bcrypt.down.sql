-- 034_courier_code_bcrypt.down.sql
-- Volta a char(64). Só funciona se nenhum código já estiver em bcrypt (60
-- caracteres cabem em char(64), mas o login antigo não saberia conferir);
-- nesse caso, gere códigos novos pelo admin depois de reverter.

BEGIN;

ALTER TABLE partner_accounts ALTER COLUMN access_code_hash TYPE char(64);

COMMIT;
