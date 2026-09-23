# Baixa de espécie por Pix (tela 9.5, migração 027)

"Reaproveita a validação humana já existente. Prazo e bloqueio automático
evitam que o dinheiro 'durma' com o entregador."

- **O que faltava.** `cash_settlement_intents.method = 'pix'` existia desde a
  migração 006, mas nada usava: não havia onde guardar o comprovante, nem
  como a loja conferir. A 9.1 do app oferecia só "Entregar na loja".
- **Fluxo.** O entregador escolhe "Pix para a loja" (9.1) e recebe o
  copia-e-cola da **chave Pix da loja** com o valor exato e o identificador
  da baixa (`BX<id>`, o que a loja procura no extrato). Sem código de balcão.
  Transfere, manda o comprovante (câmera ou galeria) e a tela fica em
  "aguardando a loja". A loja confere no Caixa (9.3), na fila "Baixas por Pix
  para conferir", e aprova ou recusa com motivo.
- **Aprovar é a mesma baixa do balcão.** Os dois lançamentos
  (`courier_cash −` / `store_receivable +`) saem de uma função só,
  `ledger_cash_settled()`, que o balcão (`confirm_settlement.php`) também
  passou a usar -- as duas formas de baixa não podem divergir no livro.
- **Mesmo tratamento do comprovante do cliente.** sha256, aHash e marca
  d'água ("BAIXA #BX…") -- as funções saíram de `payments/upload_proof.php`
  para `lib/payments/proof_images.php`, e os dois uploads usam. A loja é
  avisada quando o arquivo (ou um print parecido) já apareceu antes, em
  qualquer uma das duas filas.
- **Prazo: "até amanhã, 23:59"** (fuso de São Paulo), como o mock. Uma baixa
  com comprovante **esperando a loja não expira** (`expire_cash_settlements()`
  mudou na 027): o entregador já pagou; expirar puniria a demora da loja.
- **Recusa.** Comum: o entregador vê o motivo e manda outro (um comprovante
  pendente por vez, índice `settlement_proofs_one_pending`). Marcada como
  falsa: "bloqueia a conta e gera ocorrência" -- disputa `fake_proof` pro time
  da plataforma, `couriers.cash_blocked` e a baixa em `disputed`. Nada é
  lançado no livro em nenhuma recusa.
- **Idempotência.** O envio aceita `X-Idempotency-Key` (UUID): reenvio da mesma
  foto devolve o mesmo comprovante.
- Loja sem chave Pix cadastrada: a baixa por Pix é recusada (`409
  store_has_no_pix_key`) e o app manda dar baixa no balcão.
- Testado em `tests/smoke_courier.sh` (as duas pontas, recusa, reenvio,
  aprovação com os dois lançamentos, fraude com bloqueio, não-expiração) e no
  navegador (entregador paga e envia; loja vê e aprova).
