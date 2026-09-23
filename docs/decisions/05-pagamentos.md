# Módulo de pagamentos

Fase 4 (seleção de método, cartão, Pix, dinheiro, maquininha) e Fase 7.3
(painel da loja, validação humana do Pix) do mock, em cima das tabelas da
migração `005`. `orders/checkout.php` foi criado nesta passada porque
faltava a ponte entre o carrinho incremental (Fase 3, `status='cart'`) e
o pagamento — ele não existia antes deste módulo.

- **Limite honesto: não há conta sandbox real do Mercado Pago neste
  ambiente.** `lib/payments/mercadopago.php` implementa o cliente HTTP contra o
  contrato documentado da Payments API de verdade (`POST /v1/payments`,
  cartão tokenizado + Pix), mas sem `MERCADOPAGO_ACCESS_TOKEN` configurado
  ele cai em `MERCADOPAGO_MODE=fake`: simula aprovação/recusa de cartão
  pela mesma convenção de prefixo que os cartões de teste do próprio
  Mercado Pago usam (`OTHE`/`CONT`/`FUND` recusam, qualquer outro token
  aprova) e devolve um Pix automático simulado no mesmo formato de
  resposta. Os smoke tests e o front rodam de ponta a ponta nesse modo;
  trocar pra produção é só preencher a variável de ambiente, nenhuma
  linha de chamada muda.
- **`payment_method` é lido do pedido, nunca do corpo da requisição.**
  `payments/pay.php` despacha pelo método que `orders/checkout.php` já
  gravou em `orders.payment_method` — o cliente não consegue pagar um
  pedido de cartão como se fosse dinheiro só trocando o JSON.
- **Idempotência exigida nos 5 métodos, não só cartão.** A especificação
  pede `X-Idempotency-Key` explicitamente para cartão ("sempre com
  X-Idempotency-Key"); este projeto amplia a exigência pros cinco —
  nenhum método pode rodar duas vezes por um retry de rede, nem os de
  validação humana (dois comprovantes pro mesmo clique, por exemplo).
  `lib/core/idempotency.php` grava a chave com o hash da rota+corpo antes de
  chamar o handler e devolve a MESMA resposta HTTP em replay; reusar a
  chave numa requisição diferente dá 409. Um `register_shutdown_function`
  libera a reserva se o handler terminar a requisição por dentro (ex.:
  `advance_order()` batendo numa transição ilegal) sem nunca gravar a
  resposta — sem isso, esse caso deixaria a chave "em processamento" pra
  sempre, travando qualquer retry legítimo.
- **Dinheiro e maquininha vão direto pra `paid`, sem etapa de validação
  humana antes da cozinha.** Nenhum dinheiro trocou de mãos ainda nesse
  momento — quem confere é o entregador na entrega (Fase 8/9,
  `courier_cash_ledger`/`card_transactions`, ainda não construídos). Pix é
  diferente: o dinheiro já saiu da conta do cliente antes da cozinha
  começar (é transferência bancária, não reversível como um cartão), por
  isso precisa da barreira humana antes.
- **Pix manual não passa pelo Mercado Pago.** "Dinheiro cai direto na
  conta do dono (white-label)" — o QR/copia-e-cola usa a
  `restaurant_credentials.pix_key` da própria loja. É exatamente por isso
  que precisa de comprovante + revisão humana: não existe webhook de
  confirmação de quem não processou o pagamento. Pix automático
  (`pix_auto`) é diferente — passa pelo Mercado Pago e é confirmado pelo
  webhook, sem revisão humana; o enum já previa os dois métodos
  (`payment_method`), mas o mock só desenha telas para o manual — o
  automático ficou sem tela nesta passada (registrado em "Próximos
  passos").
- **Pix copia-e-cola é um gerador real de BR Code (EMV/Pix estático)**,
  não uma string decorativa: `lib/payments/pix.php` monta os campos TLV do Banco
  Central e fecha com CRC16. Como é dinheiro de verdade saindo da conta de
  alguém (o código embarcado num QR real teria que ser aceito por
  qualquer banco), o CRC16 foi conferido contra o vetor de teste padrão do
  algoritmo (`"123456789"` → `0x29B1`, CRC-16/CCITT-FALSE) antes de entrar
  em uso — não é um "parece certo", é o valor exato que a especificação
  pública do algoritmo define.
- **Prazo único de 15 minutos, não reiniciado no upload.**
  `orders.verification_deadline` é gravado quando o Pix (manual ou
  automático) é criado em `payments/pay.php`, cobrindo pagar + enviar
  comprovante + a loja validar — é o mesmo campo que a Fase 4.3 (QR) e a
  Fase 5.2 (tela "Analisando") leem, e o `pg_cron` (`expire_pending_verifications`,
  migração `009`) só age quando o pedido já está em `pending_verification`.
- **Comprovante: MIME real por `finfo`, não o `Content-Type` do
  navegador**, sha256 exato e um "average hash" (aHash) de 64 bits como
  phash simplificado — documentado como simplificação: um pHash de
  verdade usa DCT; este usa a média de luminância de um grid 8×8, sem
  dependência nova (`ext-gd`, já disponível), e já cobre o caso descrito
  no mock ("imagem inédita" vs. reenviada). Marca d'água aplicada com a
  fonte embutida do GD (`imagestring`), sem exigir um arquivo `.ttf` que
  este ambiente não tem.
- **Guardado em disco local (`PROOF_STORAGE_DIR`), não um bucket.** Em
  produção isto é Cloudflare R2/S3 com URL assinada — este ambiente não
  tem um bucket real configurado, e inventar uma integração sem poder
  testá-la contra o serviço de verdade seria pior que ser explícito sobre
  a lacuna.
- **`FOR UPDATE` trava aprovação dupla do Pix (Fase 7.3), de verdade.**
  `restaurants/approve_pix.php` tranca a linha de `payment_proofs` antes
  de decidir; a segunda chamada (duas abas clicando "Aprovar" ao mesmo
  tempo) vê `state != 'pending'` e recebe 409 — testado no smoke test
  literalmente chamando o endpoint duas vezes com o mesmo `proof_id`.
- **Aprovar/recusar chama `advance_order()` no mesmo commit da revisão** —
  imprimir a comanda (ESC/POS) e notificar o cliente (push/outbox) ficam
  para quando a fila de impressão e o worker de push existirem (Fase
  7.2/11), fora do escopo deste módulo; hoje só o evento em
  `order_events` e a mudança de status acontecem.
- **`refunds` e `card_transactions` existem no esquema (migração `005`),
  mas não têm endpoint ainda.** Reembolso é Fase 13 (cancelamento/
  disputa) e reconciliação de maquininha é Fase 9 (fechamento de caixa) —
  os dois dependem de fluxos que ainda não foram portados (entregador
  confirmando NSU, painel de disputa), então construir os endpoints agora
  seria adivinhar o contrato sem a tela que o usa.
- **Validado contra Postgres e PHP reais, não só `php -l`.**
  `tests/smoke_payments.sh` roda os cinco métodos ponta a ponta (incluindo
  o caminho de recusa de cartão e o webhook confirmando um Pix
  automático), a idempotência (replay idêntico, reuso de chave barrado,
  chave ausente barrada), o upload de um JPEG real gerado via GD, e o
  ciclo completo de aprovação/recusa humana do Pix — contra o mesmo banco
  migrado que os outros módulos, em sequência, sem colisão.

**Decidido pelo Marcos (go-live): token único da plataforma.** Cartão, Pix
automático, gorjeta e estorno usam as credenciais da plataforma, que ficam no
`.env` do servidor. A loja recebe pelo repasse semanal do livro-razão, sem
split. As colunas de Mercado Pago por loja em `restaurant_credentials` ficam
sem uso. Veja a [33](33-go-live.md).

**Atualização (go-live).** O que mudou desde este registro:

- A conferência na entrega foi construída: caixa do entregador e baixa de
  espécie ([27](27-app-do-entregador-e-caixa.md), [29](29-baixa-por-pix.md)),
  e maquininha e conciliação ([12](12-maquininha-conciliacao-netting.md)).
- Aprovar ou recusar Pix continua passando por `advance_order()`, que escreve
  na `outbox`. Dali o `bin/push_worker.php` avisa o cliente
  ([19](19-push.md)), e o pedido aprovado (`paid`) entra na fila de impressão
  do balcão ([31](31-impressao-escpos-e-recibo-de-baixa.md)).
- `refunds` e `card_transactions` já têm endpoint: o reembolso está na Fase
  13 ([10](10-caminho-do-erro.md)) e é executado por
  `bin/execute_refunds.php`; a maquininha está na Fase 9
  ([12](12-maquininha-conciliacao-netting.md)).
