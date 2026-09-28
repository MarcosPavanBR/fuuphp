-- 047_courier_review_note.up.sql
-- O motivo da decisão sobre uma candidatura de entregador (decisão 51).
--
-- admin/couriers.php exigia o motivo pra recusar ou pedir correção ("é o
-- que a pessoa vai ler"), mas não o gravava em lugar nenhum: a pessoa via
-- só "precisa de correção", sem saber do quê. Agora o motivo fica aqui, e o
-- app do candidato mostra.
BEGIN;
ALTER TABLE courier_applications ADD COLUMN review_note text
  CHECK (review_note IS NULL OR length(review_note) <= 1000);
ALTER TABLE courier_applications ADD COLUMN reviewed_at timestamptz;
COMMIT;
