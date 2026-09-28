-- 021_incident_refund.up.sql
-- Telas 13.3 (ocorrencia na entrega) e 13.4 (console de reembolso do admin).
--
-- Duas tabelas e duas colunas, cada uma por uma frase da tela que hoje nao
-- tinha onde morar:
--
--  1. "Tentativas registradas: 2 ligacoes as 20:33 e 20:36 - 6 min no local."
--     `delivery_incidents.call_attempts` e um int: guarda QUANTAS, nunca
--     QUANDO. Prova que se discute em disputa precisa de hora, e "6 min no
--     local" precisa de uma marca de chegada. Dai `delivery_attempts`.
--
--  2. "Oferecer credito + R$ 10 [...] nunca pode ser imposto."
--     `refunds.channel` ja aceitava 'wallet_credit', mas nao havia carteira
--     nenhuma -- era um rotulo sem saldo. E "oferta" exige um estado entre
--     propor e valer, que a linha de refund sozinha nao expressa.

BEGIN;

-- Rastro do que o entregador fez ANTES de abrir a ocorrencia. Uma linha por
-- toque: chegou, ligou, tocou a campainha. Com hora, GPS e IP -- e o chip da
-- tela 13.3 diz exatamente "geo + inet".
CREATE TABLE delivery_attempts (
  id         bigserial PRIMARY KEY,
  order_id   bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  courier_id uuid NOT NULL REFERENCES couriers(id),
  kind       text NOT NULL CHECK (kind IN ('arrival','call','bell')),
  lat        numeric(9,6), lng numeric(9,6),
  ip         inet,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX delivery_attempts_order_idx ON delivery_attempts (order_id, created_at);

-- Chegada e uma so por corrida: "6 min no local" conta do primeiro pe no
-- endereco, nao do ultimo retry de rede.
CREATE UNIQUE INDEX delivery_attempts_arrival_idx
  ON delivery_attempts (order_id) WHERE kind = 'arrival';

-- Credito em carteira: oferta, nunca imposicao. Por isso nasce em 'offered'
-- e so vira saldo quando a pessoa aceita.
--
--   offered  -> o admin propos no lugar do estorno
--   accepted -> a pessoa aceitou; o saldo existe e pode ser gasto
--   declined -> a pessoa recusou; o estorno original volta a valer
--   spent    -> consumido num pedido (order_id_spent aponta qual)
--   expired  -> passou de expires_at sem ser gasto
CREATE TABLE wallet_credits (
  id             bigserial PRIMARY KEY,
  user_id        uuid NOT NULL REFERENCES users(id),
  refund_id      bigint REFERENCES refunds(id),
  order_id       bigint REFERENCES orders(id),     -- pedido que originou
  amount         numeric(12,2) NOT NULL CHECK (amount > 0),
  bonus          numeric(12,2) NOT NULL DEFAULT 0 CHECK (bonus >= 0),
  state          text NOT NULL DEFAULT 'offered' CHECK (state IN
                   ('offered','accepted','declined','spent','expired')),
  expires_at     timestamptz,
  order_id_spent bigint REFERENCES orders(id),
  decided_by     uuid REFERENCES users(id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  -- Gasto exige o pedido onde foi gasto, e so pedido gasto tem essa marca.
  CONSTRAINT wallet_spent_needs_order CHECK (
    (state = 'spent') = (order_id_spent IS NOT NULL))
);
CREATE INDEX wallet_credits_user_idx ON wallet_credits (user_id, state);
-- Uma oferta aberta por reembolso: reoferecer nao empilha saldo.
CREATE UNIQUE INDEX wallet_credits_refund_idx
  ON wallet_credits (refund_id) WHERE state IN ('offered','accepted');

-- A decisao do admin (tela 13.4) tem motivo escrito e hora. `decided_by` ja
-- existia; faltava o que a pessoa leu e quando.
ALTER TABLE refunds ADD COLUMN note text;
ALTER TABLE refunds ADD COLUMN decided_at timestamptz;
-- A taxa de cancelamento ajustada ("Perdoar / Metade / Manter"): guardar o
-- valor da taxa DEPOIS do ajuste e o que explica por que o estorno mudou de
-- R$ 63,40 para R$ 78,40 sem ninguem ter mexido no pedido.
ALTER TABLE refunds ADD COLUMN fee numeric(12,2) NOT NULL DEFAULT 0 CHECK (fee >= 0);

-- Retencao (secao I.7): o rastro da ocorrencia tem o mesmo prazo da propria
-- ocorrencia -- 180 dias pra geo, porque e dado de localizacao de pessoa.
CREATE OR REPLACE FUNCTION purge_retention() RETURNS void
LANGUAGE sql AS $$
  DELETE FROM payment_proofs WHERE created_at < now() - interval '180 days';
  UPDATE delivery_incidents SET geo_lat = NULL, geo_lng = NULL, photo_key = NULL
    WHERE created_at < now() - interval '180 days';
  UPDATE delivery_attempts SET lat = NULL, lng = NULL, ip = NULL
    WHERE created_at < now() - interval '180 days';
  DELETE FROM order_messages WHERE created_at < now() - interval '1 year';
  DELETE FROM courier_positions WHERE updated_at < now() - interval '30 minutes';
$$;

COMMIT;
