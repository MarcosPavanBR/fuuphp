# Troca de método, gorjeta cobrada e Pix automático (migração 025)

- **Trocar a forma de pagamento troca o método do MESMO pedido**
  (`payments/change_method.php`), não abandona e recria. Itens, frete,
  cupom resgatado, crédito de carteira e vaga agendada já estão decididos e
  não dependem do método; desfazer cada um pra refazer em seguida seria
  mais código e mais chance de errar dinheiro. `orders.status` não muda
  (continua `pending_payment`); a troca fica na trilha (`order_events`,
  `meta.event = payment_method_changed`).
- **Só se troca o que ainda não é dinheiro.** O único pagamento descartável
  é o Pix manual sem comprovante (vira `rejected / method_changed`). Cartão
  ou Pix automático já no Mercado Pago travam o método (409
  `payment_method_locked`): o dinheiro ainda pode cair, e um pedido com
  duas cobranças vivas é o que `payments_one_approved` existe pra impedir.
  Comprovante de um QR descartado é recusado no upload.
- **Um Pix por pedido.** Voltar da tela do QR e escolher Pix de novo
  devolvia um QR NOVO (o cliente podia pagar os dois). Agora `pay.php`
  reaproveita a cobrança viva (`reused: true`); o copia-e-cola do Pix manual
  é determinístico e é recalculado igual.
- **Status do Mercado Pago traduzido** (`mp_normalize_status`). O CHECK de
  `payments.status` não conhece `pending` (todo Pix recém-emitido no MP),
  `cancelled` (Pix expirado), `authorized` nem `in_mediation`: o primeiro Pix
  automático em produção quebraria o INSERT. Achado ao construir a tela.
- **Gorjeta da tela 5.5 cobrada** ("Cobrada no mesmo cartão do pedido").
  Não mora em `payments` -- o índice de um aprovado por pedido é a regra de
  ouro -- e sim na própria avaliação (`reviews.tip_state` =
  none/charged/failed, `tip_provider_ref`, `tip_error`). A nota é gravada
  antes e vale mesmo se o cartão recusar; a cobrança roda fora da transação
  (rede não segura lock) com idempotência por pedido; aprovada, vira
  `courier_payable` (`review_tip:<pedido>`). Só existe em pedido de cartão
  no app com entregador; teto de R$ 200. Gorjetas registradas antes da 025
  ficam `failed` ("registrada antes da cobrança existir"), pra não parecer
  dinheiro que entrou. **Não validado no MP real:** exige que o pagamento
  original tenha usado cliente + cartão salvos (token novo a partir do
  `card_id`); sem isso a cobrança falha com mensagem clara, nunca cobra
  outro cartão.
- **Pix automático tem tela** (`PixAutoPaymentScreen.svelte`). O mock não a
  desenha: o Pix automático aparece como forma que a LOJA liga (10.5,
  "RECOMENDADO · nada de conferir comprovante") e no mix da 12.3. A tela é a
  4.3 sem o que não se aplica -- sem comprovante, sem "a loja confirma" --
  e sai sozinha quando o webhook aprova (consulta o pedido a cada 4 s).
  O tile só aparece na 4.1 quando a loja aceita.
- **A 4.1 mostra o que a loja aceita** (`restaurants/show.php` devolve
  `payment_methods`). Antes os quatro tiles apareciam sempre e o checkout
  recusava depois com 422; agora o que a loja não aceita fica desabilitado
  com "A loja não aceita agora" -- visível, pra o cliente entender.
- **"Somente online" passou a valer de verdade.** `restaurants.online_only_until`
  (loja em atraso de repasse) era ligado por `bin/apply_financial_blocks.php`
  e mostrado na tela de pagamentos da loja, mas nenhum checkout o lia -- o
  comentário do script dizia o contrário. Agora `resolve_policy()` corta pra
  `ONLINE_PAYMENT_METHODS`, e checkout, troca de método e 4.1 obedecem juntos.
- **Aba "Carrinho" da barra inferior** abre o carrinho com item mais recente
  (`cart/show.php` sem `restaurant_id`); antes avisava "ainda não portada".
