-- 046_missing_indexes.up.sql
-- Índices que faltavam, achados medindo, não adivinhando (decisão 49):
-- a suíte inteira rodou com pg_stat_statements ligado, e cada consulta real
-- foi explicada com enable_seqscan = off. Sobrou varredura da tabela inteira
-- nestas, todas em tabela que cresce com o número de pedidos. Nenhuma muda
-- resultado; só o caminho até ele.
BEGIN;
-- Trava de idempotência do livro (refund_already_booked, acerto semanal,
-- baixa de espécie): roda em todo estorno e baixa, e lia o livro inteiro.
CREATE INDEX ledger_origin_idx ON ledger_entries (origin, origin_id);
-- Cardápio inteiro da loja no painel, inclusive o item pausado: o índice
-- da migração 002 é parcial (só item disponível, pro app do cliente).
CREATE INDEX menu_items_store_idx ON menu_items (restaurant_id);
-- Cobranças de um pedido: pagar, trocar método, cancelar, webhook. O índice
-- parcial payments_one_approved só serve pra achar a aprovada.
CREATE INDEX payments_order_idx ON payments (order_id);
-- Rodadas de despacho de um pedido (contadas a cada rodada e na tela 15.1).
CREATE INDEX dispatch_attempts_order_idx ON dispatch_attempts (order_id);
-- Venda na maquininha de um pedido (conciliação, estorno de venda).
CREATE INDEX card_transactions_order_idx ON card_transactions (order_id);
-- "Endereço em uso?" antes de editar ou apagar (migração 043).
CREATE INDEX orders_address_idx ON orders (address_id);
-- Corridas do entregador por status (app do entregador, a cada consulta).
CREATE INDEX orders_courier_idx ON orders (courier_id, status) WHERE courier_id IS NOT NULL;
-- Pedidos da loja por status fora da janela da cozinha (vitrine: "lojas
-- lotadas", pausa, horário). orders_kds_idx só cobre paid/preparing/ready.
CREATE INDEX orders_restaurant_status_idx ON orders (restaurant_id, status);
-- Limite de pedidos de código de login por pessoa (3 a cada 10 min): conta
-- também os já usados, que o índice parcial de pendentes não cobre.
CREATE INDEX otp_codes_rate_idx ON otp_codes (user_id, purpose, created_at);
-- Disputa de um pedido (decidir, abrir, tela da loja).
CREATE INDEX disputes_order_idx ON disputes (order_id);
-- Chamados de uma pessoa (central de ajuda).
CREATE INDEX tickets_user_idx ON tickets (user_id, created_at DESC);
-- Foto de entrega reaproveitada em outro pedido (alerta de fraude).
CREATE INDEX delivery_proofs_sha256_idx ON delivery_proofs (sha256);
-- Conta de parceiro de um usuário (toda renovação de sessão de loja e
-- entregador confere de novo).
CREATE INDEX partner_accounts_user_idx ON partner_accounts (user_id);
-- Histórico de retirada de uma maquininha (painel da loja).
CREATE INDEX pos_custody_device_idx ON pos_custody (device_id);
-- Baixa de espécie pelo código, no balcão da loja.
CREATE INDEX cash_intents_store_code_idx ON cash_settlement_intents (restaurant_id, code_hash);
COMMIT;
