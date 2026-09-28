-- 026_account_deletion.up.sql
-- Tela 6.3 — "LGPD: exportar/excluir".
--
-- Excluir conta aqui é ANONIMIZAR, não apagar a linha: pedidos, pagamentos,
-- estornos e o livro contábil apontam pra `users(id)` e precisam continuar
-- existindo pela obrigação fiscal e contábil (LGPD art. 16, I). O que some
-- é tudo que identifica a pessoa: nome, CPF, telefone, e-mail, data de
-- nascimento, endereços, cartões salvos, sessões e assinaturas de push.
-- `deleted_at` marca a conta como encerrada e é o que o login confere.

BEGIN;

ALTER TABLE users ADD COLUMN deleted_at timestamptz;

COMMIT;
