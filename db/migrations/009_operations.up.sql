-- 009_operations.up.sql
-- RLS por restaurante, courier_positions (UNLOGGED), jobs do pg_cron
-- (timeout de Pix, expurgo, payout), views de relatorio, grants de producao.
-- Libera: producao.

BEGIN;

-- Isolamento por loja: um WHERE esquecido deixa de virar vazamento entre lojas.
ALTER TABLE orders          ENABLE ROW LEVEL SECURITY;
ALTER TABLE payments        ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_proofs  ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_messages  ENABLE ROW LEVEL SECURITY;

CREATE POLICY store_scope ON orders FOR ALL TO app_rw
  USING (restaurant_id = current_setting('app.restaurant_id', true)::uuid
         OR current_setting('app.role', true) = 'platform');

CREATE POLICY store_scope ON payments FOR ALL TO app_rw
  USING (
    current_setting('app.role', true) = 'platform'
    OR order_id IN (
      SELECT id FROM orders
      WHERE restaurant_id = current_setting('app.restaurant_id', true)::uuid
    )
  );

CREATE POLICY store_scope ON payment_proofs FOR ALL TO app_rw
  USING (restaurant_id = current_setting('app.restaurant_id', true)::uuid
         OR current_setting('app.role', true) = 'platform');

-- order_messages e chat de tres pontas (cliente, loja, entregador, suporte),
-- nao so a loja. O documento fonte so especifica o isolamento por
-- restaurant_id (o mesmo padrao das outras tres tabelas); acesso do
-- proprio cliente/entregador as SUAS mensagens depende de variaveis de
-- sessao adicionais (app.user_id / app.courier_id) que nao foram definidas
-- na especificacao -- decisao de produto pendente antes de habilitar RLS
-- completo aqui. A policy abaixo cobre o lado da loja e da plataforma;
-- o lado cliente/entregador precisa ser resolvido no modulo de identidade.
CREATE POLICY store_scope ON order_messages FOR ALL TO app_rw
  USING (
    current_setting('app.role', true) = 'platform'
    OR order_id IN (
      SELECT id FROM orders
      WHERE restaurant_id = current_setting('app.restaurant_id', true)::uuid
    )
  );

-- Posicao do entregador: escrita a cada 15s, uma linha por entregador
-- (UPSERT, sem historico). UNLOGGED = sem WAL, equivalente a cache dentro
-- do proprio PostgreSQL, sem adicionar tecnologia a stack.
CREATE UNLOGGED TABLE courier_positions (
  courier_id uuid PRIMARY KEY REFERENCES couriers(id) ON DELETE CASCADE,
  lat        numeric(9,6) NOT NULL,
  lng        numeric(9,6) NOT NULL,
  heading    numeric(5,1),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- ── Jobs do pg_cron ──────────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION expire_pending_verifications() RETURNS void
LANGUAGE plpgsql AS $$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT id FROM orders
    WHERE status = 'pending_verification' AND verification_deadline < now()
    FOR UPDATE SKIP LOCKED
  LOOP
    PERFORM advance_order(r.id, 'rejected', NULL, 'system',
      jsonb_build_object('reason', 'verification_timeout'));
  END LOOP;
END $$;

CREATE OR REPLACE FUNCTION expire_cash_settlements() RETURNS void
LANGUAGE sql AS $$
  UPDATE cash_settlement_intents
  SET state = 'expired'
  WHERE state = 'open' AND expires_at < now();
$$;

-- Retencao declarada (secao I.7 / II.10): comprovante e IP 180 dias,
-- mensagens de pedido 1 ano, posicao de entregador expurgada se estiver
-- parada ha mais de 30 min. orders e ledger_entries NUNCA sao apagados
-- antes de 5 anos (exigencia fiscal) -- por isso nao aparecem aqui.
CREATE OR REPLACE FUNCTION purge_retention() RETURNS void
LANGUAGE sql AS $$
  DELETE FROM payment_proofs WHERE created_at < now() - interval '180 days';
  UPDATE delivery_incidents SET geo_lat = NULL, geo_lng = NULL, photo_key = NULL
    WHERE created_at < now() - interval '180 days';
  DELETE FROM order_messages WHERE created_at < now() - interval '1 year';
  DELETE FROM courier_positions WHERE updated_at < now() - interval '30 minutes';
$$;

-- Rascunho mecanico do payout semanal: soma o que o ledger ja lancou no
-- periodo e retem a especie ainda nao baixada do entregador. Fica em
-- estado 'draft' -- a revisao e o envio (sent/paid) sao acao humana no
-- painel admin, nao deste job. Regra fina de netting/retry pertence ao
-- modulo de pagamentos (fora do escopo desta migracao).
CREATE OR REPLACE FUNCTION generate_weekly_payouts(p_start date, p_end date)
RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO payouts (party_kind, party_id, period_start, period_end, gross, withheld, net)
  SELECT
    'courier', c.courier_id, p_start, p_end,
    c.gross,
    GREATEST(COALESCE(b.balance, 0), 0) AS withheld,
    c.gross - GREATEST(COALESCE(b.balance, 0), 0) AS net
  FROM (
    SELECT party_id AS courier_id, SUM(amount) AS gross
    FROM ledger_entries
    WHERE account = 'courier_payable'
      AND created_at >= p_start AND created_at < p_end + 1
    GROUP BY party_id
  ) c
  LEFT JOIN courier_cash_balance b ON b.courier_id = c.courier_id
  ON CONFLICT (party_kind, party_id, period_start, period_end) DO NOTHING;

  INSERT INTO payouts (party_kind, party_id, period_start, period_end, gross, withheld, net)
  SELECT 'restaurant', s.restaurant_id, p_start, p_end, s.owed, 0, s.owed
  FROM (
    SELECT party_id AS restaurant_id, SUM(amount) AS owed
    FROM ledger_entries
    WHERE account = 'store_receivable'
      AND created_at >= p_start AND created_at < p_end + 1
    GROUP BY party_id
  ) s
  ON CONFLICT (party_kind, party_id, period_start, period_end) DO NOTHING;
END $$;

SELECT cron.schedule('pix-verification-timeout', '* * * * *',
  $$SELECT expire_pending_verifications()$$);
SELECT cron.schedule('cash-settlement-expiry', '*/5 * * * *',
  $$SELECT expire_cash_settlements()$$);
SELECT cron.schedule('retention-purge', '0 4 * * *',
  $$SELECT purge_retention()$$);
SELECT cron.schedule('weekly-payouts', '0 3 * * 2',
  $$SELECT generate_weekly_payouts((now()::date - 7), (now()::date - 1))$$);

-- ── Views de relatorio (painel da plataforma, Fase 12) ──────────────────

CREATE VIEW report_payment_method_mix AS
  SELECT restaurant_id, payment_method,
         count(*) AS orders_count, sum(total) AS gross_amount
  FROM orders
  WHERE status NOT IN ('cart','pending_payment')
  GROUP BY restaurant_id, payment_method;

CREATE VIEW report_delivery_cost_per_order AS
  SELECT restaurant_id,
         avg(delivery_fee + surge_fee) AS avg_delivery_cost,
         count(*) AS orders_count
  FROM orders
  WHERE status = 'delivered'
  GROUP BY restaurant_id;

CREATE VIEW report_fraud_loss_pct AS
  SELECT o.restaurant_id,
         sum(d.amount) FILTER (WHERE d.kind IN ('fake_proof','nsu_divergent')) AS fraud_amount,
         sum(o.total) AS gmv,
         round(100 * sum(d.amount) FILTER (WHERE d.kind IN ('fake_proof','nsu_divergent'))
               / NULLIF(sum(o.total), 0), 2) AS fraud_pct_of_gmv
  FROM orders o
  LEFT JOIN disputes d ON d.order_id = o.id
  WHERE o.status NOT IN ('cart','pending_payment')
  GROUP BY o.restaurant_id;

-- ── Producao: acesso das tres roles ─────────────────────────────────────

GRANT USAGE ON SCHEMA public TO app_rw, app_ro, migrator;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_rw;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_ro;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_rw;

GRANT CREATE ON SCHEMA public TO migrator;
GRANT ALL ON ALL TABLES IN SCHEMA public TO migrator;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO migrator;

ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO app_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO migrator;

-- O GRANT ALL TABLES acima re-concede o que 002 e 006 revogaram
-- explicitamente -- reafirma as duas restricoes de novo, por cima.
REVOKE SELECT ON restaurant_credentials FROM app_ro;
REVOKE UPDATE, DELETE ON ledger_entries FROM app_rw;

COMMIT;
