-- 038_city_timezone.down.sql
-- Volta apply_business_hours() ao fuso fixo de Brasília (migração 018).

BEGIN;

CREATE OR REPLACE FUNCTION apply_business_hours() RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
  v_local timestamp := timezone('America/Sao_Paulo', now());
  v_time  time      := v_local::time;
  v_dow   int       := EXTRACT(dow FROM v_local)::int;
  v_prev  int       := EXTRACT(dow FROM v_local - interval '1 day')::int;
  v_day   date      := v_local::date;
BEGIN
  -- Pausa curta vencida deixa de valer sozinha: "volta sozinha no tempo
  -- escolhido" so e verdade se alguem apagar o relogio.
  UPDATE store_pauses SET ended_at = until
   WHERE ended_at IS NULL AND until IS NOT NULL AND until <= now();
  UPDATE restaurants SET pause_until = NULL
   WHERE pause_until IS NOT NULL AND pause_until <= now();

  UPDATE restaurants r SET is_open = calc.should_open
    FROM (
      SELECT r2.id,
             CASE
               -- Feriado manda no dia inteiro, aberto ou fechado.
               WHEN h.day IS NOT NULL THEN
                 NOT h.closed
                 AND v_time >= h.opens
                 AND v_time < COALESCE(h.last_order, h.closes)
               ELSE EXISTS (
                 SELECT 1 FROM business_hours bh
                  WHERE bh.restaurant_id = r2.id AND bh.active
                    AND (
                      -- turno que comeca e termina no mesmo dia
                      (bh.dow = v_dow AND COALESCE(bh.last_order, bh.closes) > bh.opens
                        AND v_time >= bh.opens AND v_time < COALESCE(bh.last_order, bh.closes))
                      -- turno que atravessa a meia-noite, antes dela
                      OR (bh.dow = v_dow AND COALESCE(bh.last_order, bh.closes) < bh.opens
                        AND v_time >= bh.opens)
                      -- ...e depois dela, que ainda e o turno de ONTEM
                      OR (bh.dow = v_prev AND COALESCE(bh.last_order, bh.closes) < bh.opens
                        AND v_time < COALESCE(bh.last_order, bh.closes))
                    )
               )
             END AS should_open
        FROM restaurants r2
        LEFT JOIN holiday_overrides h
               ON h.restaurant_id = r2.id AND h.day = v_day
       WHERE r2.approved_at IS NOT NULL
         -- Loja sem horario cadastrado nao entra: abrir sozinha uma loja que
         -- nunca declarou horario seria pior que deixar como esta.
         AND EXISTS (SELECT 1 FROM business_hours bh2 WHERE bh2.restaurant_id = r2.id)
         -- Pausa em curso manda mais que o horario: sem isto, "fechar por
         -- hoje" duraria um minuto, ate o proximo tique reabrir a loja. As
         -- pausas ja vencidas foram apagadas no UPDATE acima, entao quem
         -- sobra aqui esta pausado de verdade agora.
         AND r2.pause_until IS NULL
    ) calc
   WHERE calc.id = r.id AND r.is_open IS DISTINCT FROM calc.should_open;
END $$;

DROP FUNCTION IF EXISTS restaurant_timezone(uuid);
ALTER TABLE service_cities DROP COLUMN timezone;

COMMIT;
