-- 001_identity.down.sql
BEGIN;

DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS consents;
DROP TABLE IF EXISTS otp_codes;
DROP TABLE IF EXISTS partner_accounts;
DROP TABLE IF EXISTS users;

-- Roles e extensoes ficam: sao compartilhadas por todo o esquema e outras
-- migracoes ainda dependem delas enquanto o down for aplicado fora de ordem.

COMMIT;
