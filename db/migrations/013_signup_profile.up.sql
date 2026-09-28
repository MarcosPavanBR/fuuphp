-- 013_signup_profile.up.sql
-- users.birth_date: o único campo da tela 10.3 que ainda não existia.
--
-- A tela separa o cadastro em três blocos por BASE LEGAL, não por gosto:
-- obrigatório para operar (nome, telefone, e-mail), exigido por lei (CPF na
-- nota fiscal) e opcional com consentimento próprio -- onde entram marketing
-- e data de nascimento ("libera promoções de aniversário"). Marketing já
-- tinha onde ser gravado (consents.kind = 'marketing'); a data não tinha
-- coluna nenhuma.
--
-- Fica anulável de propósito: quem não consentir não preenche, e a ausência
-- é a resposta certa, não um valor padrão.

BEGIN;

ALTER TABLE users
  ADD COLUMN birth_date date;

COMMIT;
