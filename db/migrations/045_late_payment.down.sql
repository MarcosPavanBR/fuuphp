-- 045_late_payment.down.sql
-- Falha de propósito se já existir estorno 'late_payment': apagar estorno é
-- apagar dinheiro devido. Só serve pra banco de desenvolvimento.
BEGIN;
DROP INDEX refunds_payment_idx;
ALTER TABLE refunds DROP CONSTRAINT refunds_cause_check;
ALTER TABLE refunds ADD CONSTRAINT refunds_cause_check CHECK (cause IN
  ('customer_cancel','store_reject','no_courier','not_delivered',
   'wrong_item','platform_failure','fraud'));
COMMIT;
