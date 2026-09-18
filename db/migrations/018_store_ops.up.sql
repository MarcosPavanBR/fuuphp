-- 018_store_ops.up.sql
-- Telas 11.2 (pausar loja, motivo e tempo de preparo), 11.3 (cardapio) e
-- 11.4 (horario, feriados e ultimo pedido).
--
-- As duas tabelas aqui sao citadas nominalmente pelo mock -- `store_pauses`
-- ("motivo, autor, duracao") e `holiday_overrides` -- mas nao estavam na
-- Parte II da especificacao, que so tem restaurants.is_open/pause_until e
-- business_hours. Sem elas, pausar seria um campo sobrescrito sem historico
-- (ninguem saberia quem pausou, por que, nem por quanto tempo) e feriado
-- seria editar o horario semanal na mao e lembrar de desfazer depois.

BEGIN;

-- Historico de pausas. `pause_until` em restaurants continua sendo a
-- verdade operacional (e o que o checkout le); esta tabela e o registro de
-- QUEM pausou, POR QUE e por quanto -- o que o mock chama de "o motivo
-- aparece para o cliente" e o que permite medir as 2 h por dia que derrubam
-- o selo de "Confiavel".
CREATE TABLE store_pauses (
  id            bigserial PRIMARY KEY,
  restaurant_id uuid NOT NULL REFERENCES restaurants(id) ON DELETE CASCADE,
  kind          text NOT NULL CHECK (kind IN ('short','rest_of_day')),
  reason        text NOT NULL CHECK (reason IN ('busy_kitchen','out_of_stock','no_courier','technical')),
  started_at    timestamptz NOT NULL DEFAULT now(),
  -- NULL = "fechar por hoje": quem reabre e o horario de amanha, nao um relogio.
  until         timestamptz,
  ended_at      timestamptz,
  created_by    uuid REFERENCES users(id)
);
CREATE INDEX store_pauses_open_idx ON store_pauses (restaurant_id, started_at DESC)
  WHERE ended_at IS NULL;

-- Feriado e EXCECAO, nao edicao do horario semanal: editar business_hours
-- para um dia especifico obrigaria a lembrar de desfazer na quarta-feira
-- seguinte. `closed = false` com faixa preenchida e o "so jantar" do mock.
CREATE TABLE holiday_overrides (
  id            bigserial PRIMARY KEY,
  restaurant_id uuid NOT NULL REFERENCES restaurants(id) ON DELETE CASCADE,
  day           date NOT NULL,
  closed        boolean NOT NULL DEFAULT true,
  opens         time,
  closes        time,
  last_order    time,
  note          text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (restaurant_id, day),
  CHECK (closed OR (opens IS NOT NULL AND closes IS NOT NULL))
);
CREATE INDEX holiday_overrides_day_idx ON holiday_overrides (day);

-- "Tempo de preparo informado ao cliente" (11.2). Ate aqui a previsao de
-- entrega do app do cliente era uma janela fixa de 25-45 min escrita no
-- front -- numero que ninguem da loja tinha como corrigir.
ALTER TABLE restaurants
  ADD COLUMN prep_minutes   int NOT NULL DEFAULT 30 CHECK (prep_minutes BETWEEN 5 AND 180),
  ADD COLUMN prep_auto_bump boolean NOT NULL DEFAULT false;

-- ── Abrir e fechar e tarefa do pg_cron, nao do atendente (11.4) ─────────
--
-- Fuso fixo em America/Sao_Paulo: `business_hours.opens/closes` sao `time`
-- sem fuso, e a especificacao nao tem coluna de fuso por loja. Comparar com
-- now() cru (UTC) abriria toda loja tres horas cedo. Uma loja fora desse
-- fuso pede uma coluna nova -- decisao de produto, nao de migracao.
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

SELECT cron.schedule('business-hours', '* * * * *',
  $$SELECT apply_business_hours()$$);

COMMIT;
