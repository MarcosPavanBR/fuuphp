-- 030_platform_mp_token.up.sql
-- Decisão do Marcos no go-live: Mercado Pago com TOKEN ÚNICO DA PLATAFORMA.
-- Cartão, Pix automático, gorjeta e estorno passam pela conta da plataforma
-- (credenciais só no .env do servidor), e a loja recebe pelo repasse semanal
-- do livro-razão -- não há token por loja nem split.
--
-- A 002 tinha deixado em `restaurant_credentials` o lugar do token por loja
-- (mp_public_key, mp_access_token criptografado, mp_user_id). Nenhum código
-- lê essas colunas; deixá-las é deixar um lugar pronto pra guardar segredo
-- de loja que ninguém rotaciona nem audita. Saem. Fica a chave Pix da loja
-- (`pix_key`), que é o Pix manual: cai direto na conta dela.

BEGIN;

ALTER TABLE restaurant_credentials
  DROP COLUMN mp_public_key,
  DROP COLUMN mp_access_token,
  DROP COLUMN mp_user_id;

COMMENT ON TABLE restaurant_credentials IS
  'Dado de recebimento da loja fora do Mercado Pago: a chave Pix do Pix manual. O Mercado Pago usa o token único da plataforma (migração 030).';

COMMIT;
