-- 009_operations.down.sql
BEGIN;

SELECT cron.unschedule('pix-verification-timeout');
SELECT cron.unschedule('cash-settlement-expiry');
SELECT cron.unschedule('retention-purge');
SELECT cron.unschedule('weekly-payouts');

DROP VIEW IF EXISTS report_fraud_loss_pct;
DROP VIEW IF EXISTS report_delivery_cost_per_order;
DROP VIEW IF EXISTS report_payment_method_mix;

DROP FUNCTION IF EXISTS generate_weekly_payouts(date, date);
DROP FUNCTION IF EXISTS purge_retention();
DROP FUNCTION IF EXISTS expire_cash_settlements();
DROP FUNCTION IF EXISTS expire_pending_verifications();

DROP TABLE IF EXISTS courier_positions;

DROP POLICY IF EXISTS store_scope ON order_messages;
DROP POLICY IF EXISTS store_scope ON payment_proofs;
DROP POLICY IF EXISTS store_scope ON payments;
DROP POLICY IF EXISTS store_scope ON orders;

ALTER TABLE order_messages  DISABLE ROW LEVEL SECURITY;
ALTER TABLE payment_proofs  DISABLE ROW LEVEL SECURITY;
ALTER TABLE payments        DISABLE ROW LEVEL SECURITY;
ALTER TABLE orders          DISABLE ROW LEVEL SECURITY;

-- Grants nao sao revertidos: derrubar acesso de app_rw/app_ro em producao
-- por um rollback de migracao e mais perigoso que deixar a concessao ampla
-- no lugar. Se for preciso mesmo desfazer, faca manualmente e com atencao.

COMMIT;
