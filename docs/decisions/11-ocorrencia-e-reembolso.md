# Ocorrência na entrega e console de reembolso (Fase 13.3 e 13.4)

As duas metades que faltavam do caminho do erro: o que o entregador faz
quando ninguém atende a porta, e quem decide o dinheiro depois. Antes disto,
`delivery_incidents` existia desde a migração `008` e nunca recebia uma
linha, e `refunds` nascia em `pending` sem ninguém pra decidir — a tela 13.4
era literalmente a fila que não existia.

### 13.3 — o entregador registra em vez de sumir

- **A tela existe por um motivo econômico, não estético.** "Sem isso,
  entregador abandona pedido difícil em vez de registrar." Então tudo foi
  construído a serviço de fazer registrar ser melhor que sumir: a garantia da
  corrida aparece **antes** de escolher o motivo, com o valor real do frete
  do pedido, não como promessa genérica depois de confirmar.
- **Os quatro motivos são o `CHECK` de `delivery_incidents.kind`.** Não há um
  quinto: a tela e o banco dizem a mesma coisa (`customer_absent`, `no_cash`,
  `bad_address`, `unsafe_area`).
- **`call_attempts` é um `int` — guarda quantas, nunca quando.** A tela
  promete "2 ligações às 20:33 e 20:36 · 6 min no local", e prova que se
  discute em disputa precisa de hora. Daí a tabela `delivery_attempts`
  (migração `021`): uma linha por toque — chegou, ligou, campainha — com
  hora, GPS e IP. É o chip "geo + inet" da tela virando coluna.
- **A chegada é única por corrida** (índice único parcial): "6 min no local"
  conta do primeiro pé no endereço, e um retry de rede não pode zerar esse
  relógio.
- **"Espere 10 min no local" é regra do servidor, não texto.** Vale só pra
  cliente ausente — endereço que não existe não melhora esperando, e local
  sem segurança piora —, e ainda exige ao menos uma ligação registrada: "não
  atende" é uma afirmação sobre uma ligação que aconteceu. Sem os dois, o
  servidor devolve `wait_not_satisfied` / `call_required`.
- **A prova é obrigatória de verdade.** Sem foto não abre (422
  `photo_required`). Isso obrigou a construir o upload que faltava:
  `couriers/incident_photo.php`, com MIME real por `finfo` e `sha256` do
  conteúdo, guardado em disco privado (bucket privado com URL assinada em
  produção — é foto da porta da casa de alguém). O mesmo upload serve à prova
  de entrega da 8.6, que até aqui sabia falar em `photo_storage_key` e não
  tinha por onde subir a foto.
- **A foto repetida em outra corrida vira sinal, não bloqueio.** Pode ser o
  mesmo prédio duas vezes na mesma noite; então grava-se `fraud_signals`
  (`proof_reuse`, score 70) e quem decide é a fila de disputas com o resto do
  contexto. `couriers/deliver.php` passou a gravar o `sha256` da prova — sem
  isso a coluna da migração `015` ficava vazia e a checagem não checava nada.
- **Abrir ocorrência não muda o status do pedido.** A comida continua com o
  entregador; quem decide o destino é o suporte ("passado o prazo, o suporte
  libera"). A ocorrência entra na mesma fila de `disputes` que o admin já
  olha (`not_delivered`, risco alto em dinheiro e área sem segurança) — é o
  chip "→ disputes".
- **A corrida garantida é paga na RESOLUÇÃO, não na abertura.** "Você recebe
  a corrida integral nas duas saídas": devolver à loja e descartar creditam
  `courier_payable` com origem `compensation`. Creditar ao abrir pagaria a
  mesma corrida duas vezes quando o suporte mandasse entregar assim mesmo (aí
  quem paga é o caminho normal do `deliver.php`). A tela do entregador diz
  isso em vez de deixar implícito.

### 13.4 — o console de reembolso do admin

- **`refunds.payer` deixou de ser rótulo.** Até aqui a coluna existia e o
  dinheiro não andava. `refund_ledger()` lança o custo pelas mesmas contas do
  cupom, pelo mesmo motivo — é o mesmo tipo de custo: dinheiro que ia pra
  loja e voltou pro cliente. `store` → `store_receivable`; `platform` →
  `platform_expense`; `shared` → metade de cada, com o centavo ímpar na
  plataforma.
- **"Idempotente por `refund_key`" é verdade no livro, não só na tabela.**
  `origin_id` de cada lançamento é o `refund_key`, e a decisão só roda em
  reembolso `pending`. Dois cliques, um lançamento.
- **O ajuste da taxa (Perdoar / Metade / Manter) precisou de coluna.**
  `refunds.fee` guarda a taxa ao lado do estorno: sem o valor original não dá
  pra recalcular nem pra explicar por que o estorno passou de R$ 63,40 pra
  R$ 78,40 sem ninguém ter mexido no pedido. A tela mostra a prévia; o valor
  que vale é o que o servidor recalcula ao decidir.
- **O estorno fica em `sent`, não em `done`.** O dinheiro saiu daqui, mas
  quem confirma que chegou é o gateway; marcar `done` na hora diria que a
  fatura do cliente já mudou, e ela não mudou. (Não há chamada real à API de
  estorno do Mercado Pago: o módulo roda em modo `fake` sem credencial, e o
  chip "MP refund API" da tela é a rota, não uma integração construída.)
- **`orders.status = 'refunded'` só quando a função do banco permite** (de
  `paid`, `delivered` ou `cancelled`). Pedido ainda em preparo com estorno
  decidido existe, e nesse caso o status continua sendo o que ele é — quem
  manda no status é `advance_order()`, não o console.
- **Crédito em carteira: a tabela `wallet_credits` não existia.**
  `refunds.channel` já aceitava `'wallet_credit'` desde a migração `005`, mas
  não havia carteira nenhuma — era um rótulo sem saldo. Migração `021` cria a
  tabela, e a oferta nasce em `offered`: **"nunca pode ser imposto"** virou
  máquina de estados, não frase na tela.
  - Enquanto está `offered` não há saldo e **não há lançamento**: é proposta,
    não dinheiro. Aceitar é o que lança o custo (o estorno pela conta de quem
    paga; o bônus sempre como `platform_expense`, porque ninguém mais
    concordou em pagar o "+ R$ 10").
  - **Recusar devolve o estorno ao caminho do pagamento** e o reembolso volta
    pra fila do admin — o "nunca imposto" valendo nos dois sentidos.
  - Aceitar **devolve o pagamento pra `approved`**. `record_refund()` marca
    `payments.status = 'refunded'` na CRIAÇÃO do reembolso (é a promessa
    feita no cancelamento); aceitar crédito troca essa promessa por outra, e
    a cobrança original continua de pé. É exatamente por isso que crédito
    custa menos que estorno — e deixar `refunded` ali diria que a fatura
    mudou.
  - **O saldo é gasto sozinho no próximo pedido** (`checkout.php`, depois do
    cupom: o cupom é campanha com orçamento, o crédito é dívida nossa com
    esta pessoa). Não há lançamento novo ao gastar — o custo já foi lançado
    no aceite, e gastar só consome o passivo. Crédito maior que o pedido não
    evapora: a linha é consumida e o troco vira uma linha nova, aceita, com a
    mesma validade — saldo parcial sem uma coluna de saldo parcial.
  - Validade de **90 dias**, dita na oferta. Não há job: o vencimento é
    aplicado na leitura da carteira, porque crédito vencido que ainda aparece
    como saldo é pior que um cron a menos.
- **Ocorrência e reembolso ficam na mesma aba do admin, mas são duas
  decisões.** Liberar a sacola é logística; devolver dinheiro é a 13.4.
  Quando a ocorrência gera devolução, quem paga é escolha de **gente**: a
  lista "quem paga a conta, por causa" da tela não cobre cliente ausente, e
  chutar ali seria inventar política.
- **Um defeito real que só o navegador achou:** `getCurrentPosition` tem
  opção `timeout` e mesmo assim existe aparelho que não chama nenhum dos dois
  callbacks (permissão negada sem diálogo, GPS sem provedor). Com isso o
  botão ficava desabilitado **para sempre** — e travar é o que esta tela não
  pode fazer. O corte de tempo passou a ser nosso (`Promise.race`), nas duas
  telas do entregador que pedem posição. Junto foi um desperdício silencioso:
  o app refaz o objeto do pedido a cada leitura de `couriers/me.php`, e o
  efeito da tela dependia do OBJETO — relia a ocorrência a cada 4 s em vez de
  a cada 30 s.
- **O que não foi construído, dito na tela:** não há aviso por WhatsApp da
  oferta de crédito (a pessoa vê no perfil), não há chamada real de estorno
  ao gateway, e a foto da ocorrência não é exibida no painel — o admin lê a
  chave de armazenamento, porque servir imagem de bucket privado com URL
  assinada é integração que este ambiente não tem.
- **Validado com Postgres e navegador reais.** `tests/smoke_incident.sh` (19ª
  suíte) cobre: prova obrigatória, ligação obrigatória em "não atende", os 10
  min como regra do servidor, rastro com GPS e `inet`, a ocorrência virando
  disputa, o status intocado, ocorrência duplicada (409), pedido de outro
  entregador (403), a corrida garantida no livro com origem `compensation`,
  resolução repetida (409), foto reaproveitada virando `fraud_signals`, a
  fila por método com "como devolver / prazo / quem paga", perdoar e metade
  da taxa, o estorno debitando o repasse da loja, idempotência por
  `refund_key`, a oferta que não vira saldo nem lançamento, recusar
  devolvendo o canal, aceitar lançando estorno + bônus separados, o saldo
  entrando no pedido seguinte com troco, e dinheiro que não estorna nada. No
  Playwright: o entregador abre a ocorrência pelo botão "Problema na
  entrega", marca chegada e ligação, sobe uma foto de verdade, é barrado
  pelos 10 min e passa depois deles; o admin vê a ocorrência com o rastro e a
  fila com os cinco métodos, perdoa a taxa (R$ 63,40 → R$ 78,40, os números
  do mock) e oferece o crédito; o cliente aceita no perfil e fica com
  R$ 88,40 de saldo. Zero erros de console.
