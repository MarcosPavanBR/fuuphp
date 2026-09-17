-- 005_payments.up.sql
-- payments, payment_proofs, idempotency_keys, refunds, card_transactions.
-- Libera: os 6 metodos de pagamento + reembolso (Fases 4 e 13 das telas).

BEGIN;

CREATE TABLE payments (
  id            bigserial PRIMARY KEY,
  order_id      bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  provider      text NOT NULL CHECK (provider IN ('mercadopago','offline')),
  provider_ref  text,
  amount        numeric(12,2) NOT NULL CHECK (amount > 0),
  status        text NOT NULL CHECK (status IN
                  ('created','in_process','approved','rejected','refunded','charged_back')),
  status_detail text,
  raw_response  jsonb,                  -- prova em disputa
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (provider, provider_ref)
);
CREATE UNIQUE INDEX payments_one_approved ON payments (order_id)
  WHERE status = 'approved';             -- a regra de ouro

-- Comprovante de Pix manual, com validacao dupla (loja confirma o codigo).
CREATE TABLE payment_proofs (
  id             bigserial PRIMARY KEY,
  payment_id     bigint NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
  order_id       bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  restaurant_id  uuid NOT NULL REFERENCES restaurants(id),
  storage_key    text NOT NULL,          -- bucket privado, URL assinada
  sha256         char(64) NOT NULL,      -- pega arquivo repetido
  phash          char(16),               -- pega print reeditado
  uploaded_by    uuid REFERENCES users(id),
  state          text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','approved','rejected')),
  counted_amount numeric(12,2),
  reviewed_by    uuid REFERENCES users(id),
  reviewed_at    timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX payment_proofs_sha_idx ON payment_proofs (sha256);
CREATE INDEX payment_proofs_pending_idx ON payment_proofs (restaurant_id) WHERE state = 'pending';

-- Retry com a mesma chave devolve a MESMA resposta gravada (contrato de API,
-- secao I.3): 100 requisicoes simultaneas => 1 cobranca, 99 respostas identicas.
CREATE TABLE idempotency_keys (
  key           uuid PRIMARY KEY,
  route         text NOT NULL,
  request_hash  char(64) NOT NULL,
  status_code   int,
  response_body jsonb,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idempotency_keys_cleanup_idx ON idempotency_keys (created_at);

CREATE TABLE refunds (
  id         bigserial PRIMARY KEY,
  order_id   bigint NOT NULL REFERENCES orders(id),
  payment_id bigint REFERENCES payments(id),
  refund_key uuid NOT NULL UNIQUE,         -- idempotencia ponta a ponta
  amount     numeric(12,2) NOT NULL CHECK (amount > 0),
  channel    text NOT NULL CHECK (channel IN
               ('gateway','pix_return','acquirer_void','wallet_credit','none')),
  payer      text NOT NULL CHECK (payer IN ('store','platform','shared')),
  cause      text NOT NULL CHECK (cause IN
               ('customer_cancel','store_reject','no_courier','not_delivered',
                'wrong_item','platform_failure','fraud')),
  state      text NOT NULL DEFAULT 'pending'
               CHECK (state IN ('pending','sent','done','failed')),
  decided_by uuid REFERENCES users(id),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX refunds_order_idx ON refunds (order_id);

-- device_id ganha FK na migracao 006, quando pos_devices existir.
CREATE TABLE card_transactions (
  id               bigserial PRIMARY KEY,
  order_id         bigint REFERENCES orders(id),
  device_id        uuid,
  acquirer         text NOT NULL,
  nsu              text,
  amount_app       numeric(12,2) NOT NULL,  -- informado pelo entregador
  amount_statement numeric(12,2),           -- veio do extrato
  brand            text,
  kind             text CHECK (kind IN ('debit','credit')),
  state            text NOT NULL DEFAULT 'pending'
                     CHECK (state IN ('pending','reconciled','divergent')),
  created_at       timestamptz NOT NULL DEFAULT now(),
  UNIQUE (acquirer, nsu)                   -- NSU nao repete na adquirente
);

COMMIT;
