-- 033_store_signup.up.sql
-- Cadastro de loja pela própria loja ("Quero vender no FUU").
--
-- Até aqui, loja só nascia por INSERT no banco: a fila "esperando análise"
-- do admin (12.1) existia, mas nada a enchia. Sem cadastro, não entra loja
-- -- e sem loja a plataforma não fatura. O cadastro (restaurants/signup.php)
-- cria a loja NÃO aprovada, a conta do balcão (CNPJ + senha) e a chave Pix;
-- a plataforma confere e aprova na fila que já existe.
--
-- Colunas novas, todas pro admin conferir antes de aprovar (e ligar pra loja):
--   contact_name / contact_phone  quem responde pela loja;
--   address_text                  o endereço como a loja escreveu;
--   signup_ip                     de onde veio o cadastro (limite por IP e
--                                 rastro de fraude; apagado na aprovação).

BEGIN;

ALTER TABLE restaurants
  ADD COLUMN contact_name  text,
  ADD COLUMN contact_phone text,
  ADD COLUMN address_text  text,
  ADD COLUMN signup_ip     inet;

CREATE INDEX restaurants_signup_ip_idx ON restaurants (signup_ip, created_at)
  WHERE signup_ip IS NOT NULL;

COMMIT;
