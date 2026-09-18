-- 016_admin.down.sql
BEGIN;

ALTER TABLE restaurants
  DROP CONSTRAINT IF EXISTS restaurant_not_both_decisions,
  DROP COLUMN IF EXISTS rejection_reason,
  DROP COLUMN IF EXISTS rejected_at;

-- Ocorrência sem pedido não cabe no formato antigo: some com ela antes de
-- voltar a coluna a NOT NULL, senão a migração trava num banco com dados.
DELETE FROM disputes WHERE order_id IS NULL;

ALTER TABLE disputes
  DROP CONSTRAINT IF EXISTS dispute_has_subject,
  DROP COLUMN IF EXISTS restaurant_id,
  DROP COLUMN IF EXISTS courier_id,
  ALTER COLUMN order_id SET NOT NULL;

COMMIT;
