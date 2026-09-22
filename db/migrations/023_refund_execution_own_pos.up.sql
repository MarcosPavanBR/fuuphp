-- 023_refund_execution_own_pos.up.sql
-- Duas pontas de dinheiro que ficaram pra depois:
--
--  1. EXECUTAR o reembolso. A tela 13.4 decide e grava `refunds.state =
--     'sent'`; faltava quem mandasse o dinheiro de verdade e registrasse o
--     resultado. O executor (bin/execute_refunds.php) precisa de onde gravar
--     a resposta do gateway, quantas vezes tentou e por que falhou.
--
--  2. MAQUININHA DO PRÓPRIO ENTREGADOR (tela 9.6). A política ja tinha
--     `allow_courier_own_pos` desde a migração 003, mas `pos_devices` só
--     podia pertencer a uma loja. "Maquininha do próprio entregador: o valor
--     cai na conta dele, então vira dívida com a loja e segue o mesmo fluxo
--     da espécie." Aqui a máquina passa a poder ter um dos dois donos.

BEGIN;

ALTER TABLE refunds ADD COLUMN provider_ref text;         -- id do estorno no gateway
ALTER TABLE refunds ADD COLUMN attempts int NOT NULL DEFAULT 0;
ALTER TABLE refunds ADD COLUMN last_error text;
ALTER TABLE refunds ADD COLUMN executed_at timestamptz;

-- O executor varre so os 'sent'; o indice parcial deixa a varredura barata
-- mesmo com anos de reembolsos 'done' na tabela.
CREATE INDEX refunds_to_execute_idx ON refunds (created_at) WHERE state = 'sent';

-- A maquina tem UM dono: a loja OU o entregador. Nunca os dois, nunca
-- nenhum.
ALTER TABLE pos_devices ALTER COLUMN restaurant_id DROP NOT NULL;
ALTER TABLE pos_devices ADD COLUMN courier_id uuid REFERENCES couriers(id);
ALTER TABLE pos_devices ADD CONSTRAINT pos_device_one_owner CHECK (
  (restaurant_id IS NOT NULL) <> (courier_id IS NOT NULL));
CREATE UNIQUE INDEX pos_devices_courier_label_idx
  ON pos_devices (courier_id, label) WHERE courier_id IS NOT NULL;

COMMIT;
