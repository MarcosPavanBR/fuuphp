-- 025_review_tip_charge.up.sql
-- Tela 5.5 — a gorjeta da avaliação passa a ser COBRADA.
--
-- "Vai 100% para o entregador, no repasse da terça. Cobrada no mesmo
-- cartão do pedido." Até a migração 024 ela era só registrada em
-- reviews.courier_tip. A cobrança não pode morar em `payments`: o índice
-- `payments_one_approved` (migração 005, "a regra de ouro") garante UM
-- pagamento aprovado por pedido, e a gorjeta é uma segunda cobrança do
-- mesmo pedido. Por isso o estado dela fica na própria avaliação.
--
--   tip_state: 'none'     sem gorjeta
--              'charged'  cobrada no cartão, lançada em courier_payable
--              'failed'   o cartão recusou; nada foi lançado

BEGIN;

ALTER TABLE reviews
  ADD COLUMN tip_state        text NOT NULL DEFAULT 'none'
                              CHECK (tip_state IN ('none','charged','failed')),
  ADD COLUMN tip_provider_ref text,
  ADD COLUMN tip_error        text,
  ADD COLUMN tip_charged_at   timestamptz;

-- Gorjeta registrada antes desta migração nunca foi cobrada: fica marcada
-- como falha pra não parecer dinheiro que entrou.
UPDATE reviews SET tip_state = 'failed', tip_error = 'registrada antes da cobrança existir'
 WHERE courier_tip > 0;

COMMIT;
