-- 043_address_archive.up.sql
-- O pedido não guarda cópia do endereço: orders.address_id aponta pra linha
-- de addresses, e é essa linha que o entregador, a comanda e o recibo leem.
-- Editar o endereço reescrevia, sem ninguém ver, o destino de um pedido a
-- caminho e o histórico dos antigos (achado no fuzz profundo de 25/09/2026).
--
-- Agora endereço usado em pedido é imutável:
--   * editar cria uma linha nova com as mudanças e arquiva a antiga;
--   * apagar arquiva (antes dava 409 address_in_use e o cliente ficava
--     com o endereço velho na lista pra sempre).
-- Arquivado some da lista e do checkout; os pedidos seguem apontando pra
-- ele. Endereço nunca usado continua sendo editado e apagado de verdade.
BEGIN;
ALTER TABLE addresses ADD COLUMN archived_at timestamptz;
CREATE INDEX addresses_user_live_idx ON addresses (user_id) WHERE archived_at IS NULL;
COMMIT;
