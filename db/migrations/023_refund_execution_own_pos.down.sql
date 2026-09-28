-- 023_refund_execution_own_pos.down.sql

BEGIN;

-- Maquina de entregador nao cabe no esquema anterior (restaurant_id NOT
-- NULL): some junto com a coluna que permitia existir.
DELETE FROM pos_custody WHERE device_id IN (SELECT id FROM pos_devices WHERE courier_id IS NOT NULL);
UPDATE card_transactions SET device_id = NULL
 WHERE device_id IN (SELECT id FROM pos_devices WHERE courier_id IS NOT NULL);
DELETE FROM pos_devices WHERE courier_id IS NOT NULL;

DROP INDEX pos_devices_courier_label_idx;
ALTER TABLE pos_devices DROP CONSTRAINT pos_device_one_owner;
ALTER TABLE pos_devices DROP COLUMN courier_id;
ALTER TABLE pos_devices ALTER COLUMN restaurant_id SET NOT NULL;

DROP INDEX refunds_to_execute_idx;
ALTER TABLE refunds DROP COLUMN executed_at;
ALTER TABLE refunds DROP COLUMN last_error;
ALTER TABLE refunds DROP COLUMN attempts;
ALTER TABLE refunds DROP COLUMN provider_ref;

COMMIT;
