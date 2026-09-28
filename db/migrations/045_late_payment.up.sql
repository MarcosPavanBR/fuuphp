-- 045_late_payment.up.sql
-- Nova causa de estorno: 'late_payment', o dinheiro que chega e não paga
-- pedido nenhum (decisão 48).
--
-- Achado na revisão do webhook do Mercado Pago (26/09/2026), reproduzido
-- antes de corrigir: cartão em análise no antifraude, cliente cancela o
-- pedido, a análise aprova depois -- o webhook marcava o pagamento como
-- aprovado e parava ali. Pedido cancelado, cliente cobrado, nenhum estorno.
-- O mesmo com o QR do Pix automático pago depois do cancelamento, e com a
-- cobrança em dobro (o MP cobrou, a nossa requisição caiu antes de gravar,
-- e o cliente pagou de novo).
--
-- Nenhuma causa existente servia: todas dizem quem arca com um pedido que
-- deu errado, e o custo vai pro livro (refund_ledger). Aqui o dinheiro
-- nunca foi de ninguém: volta inteiro, sem taxa, e nada entra no livro.
BEGIN;
ALTER TABLE refunds DROP CONSTRAINT refunds_cause_check;
ALTER TABLE refunds ADD CONSTRAINT refunds_cause_check CHECK (cause IN
  ('customer_cancel','store_reject','no_courier','not_delivered',
   'wrong_item','platform_failure','fraud','late_payment'));
-- O estorno tardio é idempotente por cobrança, não por pedido: é por aqui
-- que record_late_payment_refund() procura se já existe.
CREATE INDEX refunds_payment_idx ON refunds (payment_id);
COMMIT;
