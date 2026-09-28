-- 042_admin_totp.up.sql
-- Segundo fator do admin (auditoria SEG-04). O admin entra com o código por
-- SMS, e quem clonasse o chip do dono virava admin. Com isto ligado, além do
-- SMS vai o código de 6 dígitos de um app autenticador (Google Authenticator,
-- Microsoft Authenticator, 2FAS...), padrão TOTP (RFC 6238) -- calculado no
-- PHP, sem serviço nem biblioteca.
--
--   admin_totp   um por admin: o segredo (base32), quando foi confirmado
--                (antes disso não é exigido) e o último intervalo usado (o
--                mesmo código não vale duas vezes).
BEGIN;

CREATE TABLE admin_totp (
  user_id       uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  secret        text NOT NULL CHECK (secret ~ '^[A-Z2-7]{32}$'),
  confirmed_at  timestamptz,
  last_step     bigint,
  created_at    timestamptz NOT NULL DEFAULT now()
);
-- Relatório (app_ro) não enxerga segredo, como em restaurant_credentials.
REVOKE SELECT ON admin_totp FROM app_ro;

COMMIT;
