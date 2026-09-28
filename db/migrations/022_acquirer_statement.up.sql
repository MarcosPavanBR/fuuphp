-- 022_acquirer_statement.up.sql
-- Tela 9.6 (conciliacao da maquininha fisica).
--
-- Uma tabela so, e por uma linha da tela: "Ultimo extrato: 15/09 23:58 - 17
-- transacoes - importado automaticamente."
--
-- `card_transactions` (migracao 005) ja guarda o par NSU/valor e o estado da
-- conciliacao. O que nao existia era a memoria do IMPORT: quando o extrato
-- entrou, quem mandou, quantas linhas vieram e quantas casaram. Isso nao e
-- enfeite de tela -- e importacao de dado financeiro, e sem registro do
-- arquivo ninguem consegue responder "de onde veio esse valor".
--
-- O resto da tela 9.6 ja tem onde morar: divergencia vira `disputes` com
-- kind 'nsu_divergent' (migracao 008), e a regra da maquininha do proprio
-- entregador e `platform_policies.allow_courier_own_pos` (migracao 003).

BEGIN;

CREATE TABLE acquirer_statements (
  id            bigserial PRIMARY KEY,
  restaurant_id uuid NOT NULL REFERENCES restaurants(id) ON DELETE CASCADE,
  acquirer      text NOT NULL,
  reference_day date NOT NULL,             -- o dia que o extrato cobre
  filename      text,
  rows_total    int NOT NULL DEFAULT 0,
  rows_matched  int NOT NULL DEFAULT 0,    -- casaram com uma venda informada
  rows_orphan   int NOT NULL DEFAULT 0,    -- no extrato e sem par no app
  sha256        char(64),                  -- o mesmo arquivo nao entra duas vezes
  imported_by   uuid REFERENCES users(id),
  imported_at   timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT statement_rows_sane CHECK (
    rows_matched >= 0 AND rows_orphan >= 0 AND rows_matched + rows_orphan <= rows_total)
);
CREATE INDEX acquirer_statements_store_idx
  ON acquirer_statements (restaurant_id, reference_day DESC);

-- Reimportar o MESMO arquivo e erro de operacao, nao correcao: o extrato ja
-- foi conciliado e as linhas ja mudaram de estado. Arquivo diferente do
-- mesmo dia continua podendo entrar (extrato parcial, reenvio da adquirente).
CREATE UNIQUE INDEX acquirer_statements_file_idx
  ON acquirer_statements (restaurant_id, sha256) WHERE sha256 IS NOT NULL;

COMMIT;
