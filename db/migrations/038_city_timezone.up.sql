-- 038_city_timezone.up.sql
-- Fuso por cidade. O Brasil tem quatro: Brasília (-3, a maioria), -4 (MS,
-- MT, AM, RO, RR), -5 (AC) e -2 (Fernando de Noronha). Até aqui tudo rodava
-- no de Brasília (migração 018 e decisão 38) -- e uma loja de Campo Grande
-- ou de Ribas do Rio Pardo (MS) que abre às 18h abriria às 17h da cidade
-- dela, e as faixas de agendamento sairiam uma hora erradas.
--
--   service_cities.timezone   o fuso da cidade (o admin escolhe; o padrão
--                             vem da UF);
--   restaurant_timezone(id)   o fuso de uma loja = o da cidade dela, ou o de
--                             Brasília se a cidade não estiver cadastrada.
--
-- apply_business_hours() passa a comparar o horário de cada loja com a hora
-- de parede DA CIDADE dela. O que é da plataforma (relatórios, acerto de
-- terça, sessão do banco) continua no de Brasília.

BEGIN;

ALTER TABLE service_cities
  ADD COLUMN timezone text NOT NULL DEFAULT 'America/Sao_Paulo'
  CHECK (timezone IN (
    'America/Noronha', 'America/Sao_Paulo', 'America/Campo_Grande', 'America/Cuiaba',
    'America/Manaus', 'America/Porto_Velho', 'America/Boa_Vista', 'America/Rio_Branco'
  ));

-- Cidades já cadastradas: o fuso pela UF.
UPDATE service_cities SET timezone = CASE uf
  WHEN 'MS' THEN 'America/Campo_Grande' WHEN 'MT' THEN 'America/Cuiaba'
  WHEN 'AM' THEN 'America/Manaus'       WHEN 'RO' THEN 'America/Porto_Velho'
  WHEN 'RR' THEN 'America/Boa_Vista'    WHEN 'AC' THEN 'America/Rio_Branco'
  ELSE 'America/Sao_Paulo' END;

CREATE FUNCTION restaurant_timezone(p_restaurant uuid) RETURNS text
LANGUAGE sql STABLE AS $$
  SELECT COALESCE(
    (SELECT c.timezone FROM restaurants r JOIN service_cities c ON c.ibge_code = r.city_ibge_code
      WHERE r.id = p_restaurant),
    'America/Sao_Paulo')
$$;

CREATE OR REPLACE FUNCTION apply_business_hours() RETURNS void
LANGUAGE plpgsql AS $$
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
                 AND lt.t >= h.opens
                 AND lt.t < COALESCE(h.last_order, h.closes)
               ELSE EXISTS (
                 SELECT 1 FROM business_hours bh
                  WHERE bh.restaurant_id = r2.id AND bh.active
                    AND (
                      -- turno que comeca e termina no mesmo dia
                      (bh.dow = lt.dow AND COALESCE(bh.last_order, bh.closes) > bh.opens
                        AND lt.t >= bh.opens AND lt.t < COALESCE(bh.last_order, bh.closes))
                      -- turno que atravessa a meia-noite, antes dela
                      OR (bh.dow = lt.dow AND COALESCE(bh.last_order, bh.closes) < bh.opens
                        AND lt.t >= bh.opens)
                      -- ...e depois dela, que ainda e o turno de ONTEM
                      OR (bh.dow = lt.prev AND COALESCE(bh.last_order, bh.closes) < bh.opens
                        AND lt.t < COALESCE(bh.last_order, bh.closes))
                    )
               )
             END AS should_open
        FROM restaurants r2
        -- A hora de parede DA LOJA: o fuso da cidade dela (migração 038).
        CROSS JOIN LATERAL (
          SELECT l::time AS t, EXTRACT(dow FROM l)::int AS dow,
                 EXTRACT(dow FROM l - interval '1 day')::int AS prev, l::date AS d
            FROM (SELECT timezone(restaurant_timezone(r2.id), now()) AS l) x
        ) lt
        LEFT JOIN holiday_overrides h
               ON h.restaurant_id = r2.id AND h.day = lt.d
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

COMMIT;
