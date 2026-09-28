-- 032_partner_login_failures.up.sql
-- Limite de tentativas no login de parceiro (loja: CNPJ + senha; entregador:
-- CPF + código de acesso de 6 dígitos).
--
-- O login do cliente já tinha limite (OTP: 5 tentativas por código, 3
-- pedidos por janela). O de parceiro não tinha nenhum: o código de acesso do
-- entregador tem 6 dígitos -- um milhão de combinações, o que um script
-- testa inteiro. Agora cada erro vira uma linha aqui, e o login recusa
-- (429) com 5 erros no mesmo login ou 30 no mesmo IP em 15 minutos, ANTES
-- de conferir a senha (senão o bloqueio ainda deixaria descobrir a certa).
--
-- As linhas só servem pra janela: o pg_cron apaga as de mais de um dia.

BEGIN;

CREATE TABLE partner_login_failures (
  id          bigserial PRIMARY KEY,
  kind        text NOT NULL CHECK (kind IN ('restaurant','courier')),
  login_code  text NOT NULL,
  ip          inet,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX partner_login_failures_login_idx ON partner_login_failures (kind, login_code, created_at);
CREATE INDEX partner_login_failures_ip_idx ON partner_login_failures (ip, created_at);

SELECT cron.schedule('partner-login-failures-purge', '15 4 * * *',
  $$DELETE FROM partner_login_failures WHERE created_at < now() - interval '1 day'$$);

COMMIT;
