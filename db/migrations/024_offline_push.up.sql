-- 024_offline_push.up.sql
-- Telas 7.1 (fila de upload offline) e 7.2 (notificações push).
--
--  1. "Pagamento nunca é enfileirado offline — só o upload do comprovante,
--     que é idempotente por UUID." (tela 7.1). O UUID precisa morar em algum
--     lugar do banco pra o reenvio da fila devolver o MESMO comprovante em
--     vez de tentar criar outro (e bater no 409 de pedido já em análise).
--
--  2. "Três tipos que importam: aprovação, saiu para entrega e prazo
--     acabando. Origem é a outbox, então nada se perde." (tela 7.2). A
--     `outbox` existe desde a migração 004; faltava onde guardar a
--     assinatura de push de cada aparelho e a notificação a entregar.

BEGIN;

ALTER TABLE payment_proofs ADD COLUMN upload_key uuid;
CREATE UNIQUE INDEX payment_proofs_upload_key_idx
  ON payment_proofs (upload_key) WHERE upload_key IS NOT NULL;

-- Uma linha por aparelho que aceitou notificação. O `endpoint` é o endereço
-- do serviço de push do navegador (único por aparelho+app) e funciona como
-- credencial pro service worker buscar o que tem pra mostrar -- ver
-- api/v1/push/pending.php.
CREATE TABLE push_subscriptions (
  id          bigserial PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  endpoint    text NOT NULL UNIQUE,
  p256dh      text NOT NULL,
  auth        text NOT NULL,
  -- Tela 6.3: "Push separado por tipo (status × promoção) para o usuário
  -- não desligar tudo e perder o aviso da aprovação."
  want_status    boolean NOT NULL DEFAULT true,
  want_payment   boolean NOT NULL DEFAULT true,
  want_promotion boolean NOT NULL DEFAULT false,
  failures    int NOT NULL DEFAULT 0,        -- 404/410 do serviço = aparelho sumiu
  created_at  timestamptz NOT NULL DEFAULT now(),
  last_push_at timestamptz
);
CREATE INDEX push_subscriptions_user_idx ON push_subscriptions (user_id);

-- A notificação em si. O push que sai é só um "acorda" sem conteúdo; o
-- service worker busca daqui o texto. Assim o texto não precisa atravessar
-- o serviço de push (nem ser cifrado à mão pra isso).
CREATE TABLE notifications (
  id           bigserial PRIMARY KEY,
  user_id      uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind         text NOT NULL CHECK (kind IN
                 ('payment_approved','out_for_delivery','proof_deadline','order_status','promotion')),
  title        text NOT NULL,
  body         text NOT NULL,
  order_id     bigint REFERENCES orders(id) ON DELETE CASCADE,
  outbox_id    bigint REFERENCES outbox(id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  delivered_at timestamptz
);
CREATE INDEX notifications_pending_idx ON notifications (user_id, created_at)
  WHERE delivered_at IS NULL;
-- Uma notificação por evento da outbox e por tipo: o worker pode rodar duas
-- vezes sem avisar duas vezes.
CREATE UNIQUE INDEX notifications_outbox_idx ON notifications (outbox_id, kind)
  WHERE outbox_id IS NOT NULL;
-- "Faltam 5 min" é UMA vez por pedido, não a cada volta do worker.
CREATE UNIQUE INDEX notifications_deadline_idx ON notifications (order_id)
  WHERE kind = 'proof_deadline';

COMMIT;
