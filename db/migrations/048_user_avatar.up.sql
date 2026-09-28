-- 048: foto de perfil do cliente (api/v1/profile/avatar.php).
-- Só a chave do arquivo (<id>/<sha256>.jpg); o arquivo mora no Supabase
-- Storage (bucket "avatars") ou no disco, conforme lib/storage/avatars.php.
BEGIN;
ALTER TABLE users ADD COLUMN avatar_key text
  CHECK (avatar_key IS NULL OR avatar_key ~ '^[0-9a-f-]{36}/[0-9a-f]{64}\.jpg$');
COMMIT;
