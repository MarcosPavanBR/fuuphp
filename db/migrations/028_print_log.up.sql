-- 028_print_log.up.sql
-- Impressão ESC/POS na loja (telas 4.5, 7.3, 9.3, 11.1).
--
-- A fila de impressão NÃO é uma tabela de trabalhos: é derivada dos dados.
-- Pedido pago sem comanda impressa, baixa confirmada sem recibo impresso --
-- isso é o que falta imprimir. Esta tabela registra só o que JÁ saiu no papel
-- (e as reimpressões), pra a fila saber o que tirar. Assim não existe
-- trabalho "perdido": se o tablet estava desligado, a comanda continua
-- pendente até ele voltar.
--
-- Quem imprime é o tablet do balcão (o servidor não alcança a impressora na
-- rede da loja): ele puxa a fila, manda os bytes por USB e confirma aqui.

BEGIN;

CREATE TABLE print_log (
  id             bigserial PRIMARY KEY,
  restaurant_id  uuid NOT NULL REFERENCES restaurants(id),
  kind           text NOT NULL CHECK (kind IN ('order_ticket','settlement_receipt')),
  ref_id         bigint NOT NULL,              -- orders.id ou cash_settlement_intents.id
  reprint        boolean NOT NULL DEFAULT false,
  printed_by     uuid REFERENCES users(id),
  printed_at     timestamptz NOT NULL DEFAULT now()
);
-- A primeira via é uma só (duas abas do painel confirmando juntas não viram
-- duas "primeiras"); reimpressão pode quantas quiser.
CREATE UNIQUE INDEX print_log_first_copy ON print_log (kind, ref_id) WHERE NOT reprint;
CREATE INDEX print_log_store_idx ON print_log (restaurant_id, printed_at DESC);

COMMIT;
