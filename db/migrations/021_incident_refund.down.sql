-- 021_incident_refund.down.sql

BEGIN;

-- purge_retention() volta ao corpo da migracao 009, palavra por palavra:
-- delivery_attempts deixa de existir logo abaixo e a funcao nao pode citar
-- tabela que nao existe.
CREATE OR REPLACE FUNCTION purge_retention() RETURNS void
LANGUAGE sql AS $$
  DELETE FROM payment_proofs WHERE created_at < now() - interval '180 days';
  UPDATE delivery_incidents SET geo_lat = NULL, geo_lng = NULL, photo_key = NULL
    WHERE created_at < now() - interval '180 days';
  DELETE FROM order_messages WHERE created_at < now() - interval '1 year';
  DELETE FROM courier_positions WHERE updated_at < now() - interval '30 minutes';
$$;

ALTER TABLE refunds DROP COLUMN fee;
ALTER TABLE refunds DROP COLUMN decided_at;
ALTER TABLE refunds DROP COLUMN note;

DROP TABLE wallet_credits;
DROP TABLE delivery_attempts;

COMMIT;
