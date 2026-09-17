# fuuphp

Plataforma de delivery com quatro superfícies (cliente, entregador, loja em
tablet/KDS, painel admin), especificada em `Especificação FUUDelivery —
Documento Único` (64 telas, 15 fases, esquema PostgreSQL de 42 tabelas).

## Relação com o `fuudelivery-backend` (Go) — não usado neste projeto

Existe um backend em produção — `MarcosPavanBR/fuudelivery-backend` — em Go
(microsserviços `auth_api`/`orders_api`/`delivery_api`, GORM, três bancos
Postgres separados no Supabase, RabbitMQ, gateway AbacatePay). **Decisão do
dono do produto: `fuuphp` não usa Go nem AbacatePay, em nenhuma hipótese.**
Este repositório é a implementação completa da especificação — PHP +
Svelte + PostgreSQL único + Mercado Pago — sem depender de nada do
backend Go e sem integrar com AbacatePay. O repositório Go é citado aqui só
como contexto histórico (existe, roda em produção, tem nome parecido), não
como stack, dependência ou fallback deste projeto.

## Cláusula zero — a stack é fixa

Svelte · Bootstrap · Bootstrap Icons · SweetAlert · toastr · PHP (PDO,
prepared statements) · **PostgreSQL 16, banco único do sistema** · Cloudflare
· Mercado Pago (gateway único, token do próprio lojista) · core-js · jQuery
(congelado, só onde já existe) · ESC/POS.

Nada entra, sai ou é "melhorado" sem autorização do dono do produto — nem
por versão mais nova, nem por biblioteca que pareça melhor. Ver a
especificação completa para o texto integral da cláusula e o raciocínio por
trás de cada item.

## O que este repositório contém

A **fundação de banco** (onze migrações SQL), os módulos **identity**,
**catálogo + pedido + checkout**, **descoberta** (busca de loja e
produto), **carrinho incremental**, **pagamentos** (cartão via Mercado
Pago, Pix automático e manual, dinheiro, maquininha, validação humana do
Pix) e **acompanhamento pós-pedido** (linha do tempo, tracking em tempo
real por SSE, avaliação) em PHP sobre ela, e o **front-end em Svelte**
(`web/`) cobrindo a Fase 1 (onboarding), a Fase 2 (home, busca, fidelidade,
pedidos, perfil), a Fase 3 (loja, item, carrinho), a Fase 4 (pagamento) e a
Fase 5 (pós-pedido: linha do tempo, tracking ao vivo, avaliação) — 5 das
15 fases / 64 telas. Ledger, dispatch e o resto ainda não foram portados.
Segue a ordem sugerida pela especificação (12 semanas, Parte I §10) —
catálogo/pedido vem antes de
pagamentos porque `POST /v1/orders/:id/pay` pressupõe que o pedido já
existe.

```
api/v1/auth/                módulo identity (endpoints, um arquivo por rota)
  otp_request.php             POST — pede código (login/signup/phone_verify)
  otp_verify.php               POST — confirma código, emite access+refresh
  refresh.php                  POST — rotaciona refresh, detecta reuso
  partner_login.php            POST — loja (CNPJ+senha) / entregador (CPF+código)
  consent.php                  POST — registra aceite de termo (LGPD), autenticado
api/v1/restaurants/         catálogo, descoberta (público, exceto orders.php
                             e approve_pix.php) e painel da loja
  show.php                     GET  ?id= — dados da loja + horário de funcionamento
  menu.php                     GET  ?id= — cardápio completo, disponível ou não
                                (item esgotado vem marcado, não escondido — Fase 3)
  list.php                     GET  ?city_ibge_code=&category=&lat=&lng= — lojas
                                da cidade, distância real por Haversine se lat/lng vierem
  search_products.php          GET  ?city_ibge_code=&q= — busca de produto por
                                nome (índice GIN trigram), min. 2 caracteres
  orders.php                   GET  ?id= — fila do KDS (só restaurant_staff da própria loja)
  approve_pix.php               POST — Fase 7.3: validação humana do Pix
                                 manual, SELECT...FOR UPDATE trava aprovação
                                 dupla, aprova/recusa chama advance_order()
api/v1/addresses/           endereços do cliente autenticado
  create.php                   POST — cadastra endereço
  list.php                     GET  — lista os do usuário logado
api/v1/cart/                 carrinho incremental (Fase 3) — item por item, não
                              tudo de uma vez como orders/create.php
  add_item.php                  POST — acha ou cria o carrinho (status='cart') da
                                 loja, precifica a linha no servidor, insere
  show.php                      GET  ?restaurant_id= — carrinho atual ou order:null
  update_quantity.php           POST — muda quantidade, recalcula subtotal
  remove_item.php               POST — tira a linha, recalcula subtotal
api/v1/orders/               pedido e checkout
  create.php                   POST — checkout de um passo só: valida
                                política/preço/loja aberta, cria o pedido,
                                avança cart → pending_payment
  checkout.php                  POST — checkout do carrinho incremental
                                 (Fase 3 → Fase 4): valida endereço/método/
                                 política, avança cart → pending_payment
                                 SEM criar um segundo pedido
  show.php                     GET  ?id= — detalhe (dono ou loja do pedido, só),
                                agora com a linha do tempo (order_events)
  list.php                     GET  — pedidos do cliente autenticado, com nome
                                da loja e contagem de itens
  status.php                   POST — única porta pra mudar status, por cima de
                                advance_order(); autorização por papel aqui,
                                legalidade da transição só no banco
  track.php                     GET  ?id= — SSE (Fase 5.3): snapshot na
                                 hora + evento ao vivo por LISTEN/NOTIFY,
                                 ver seção própria abaixo
api/v1/payments/              módulo de pagamentos (Fase 4 + validação humana
                               do Pix, Fase 7.3) — ver seção própria abaixo
  pay.php                        POST — cobra o método já escolhido no
                                  checkout; X-Idempotency-Key obrigatório
                                  pros 5 métodos, não só cartão
  upload_proof.php               POST multipart — comprovante de Pix manual:
                                  MIME real (finfo), sha256, aHash, marca
                                  d'água; avança pending_payment →
                                  pending_verification
  webhook_mercadopago.php        POST — webhook assíncrono (cartão em
                                  reanálise, Pix automático) — fonte da
                                  verdade, não a resposta síncrona de pay.php
api/v1/reviews/
  create.php                     POST — Fase 5.5: só com status='delivered',
                                  uma nota por pedido (UNIQUE em reviews)
api/v1/profile/
  show.php                      GET  — usuário + estatísticas (pedidos, cupons;
                                 pontos de fidelidade fica null, ver README)
lib/                          código compartilhado entre módulos
  bootstrap.php                 carrega .env, CORS (dev), registra handler de erro, requires
  db.php                          PDO (DATABASE_URL → pgsql DSN) + pg_bool() +
                                   raw_pg_connect() (ext-pgsql cru, só pro
                                   LISTEN/NOTIFY de orders/track.php)
  response.php                    envelope de erro/sucesso com trace_id (contrato de API, Parte I §3)
  jwt.php                          JWT HS256 escrito à mão (sem dependência nova)
  sessions.php                    emissão e rotação de sessão (Parte I §7)
  otp.php                          geração/hash de código, limite de pedidos
  auth_guard.php                  exige access token válido + variante por
                                   query string só pro SSE (EventSource não
                                   manda header customizado)
  policy.php                      resolve política loja → plataforma, gera o policy_snapshot
  orders.php                      chama advance_order(), autorização de acesso a
                                   pedido, fetch_order_events() (linha do tempo, Fase 5.3)
  cart.php                        price_line() (preço de uma linha, usado por
                                   cart/add_item.php E orders/create.php),
                                   find_or_create_cart(), recompute_cart_subtotal()
  validation.php                  CPF/CNPJ com dígito verificador, e-mail, telefone
  uuid.php                        UUIDv4 sem dependência
  idempotency.php                 idempotent_response(): X-Idempotency-Key
                                   grava a resposta e devolve a MESMA em
                                   replay, pros 5 métodos de pagamento
  pix.php                         gera o Pix "copia e cola" (BR Code/EMV) —
                                   CRC16 conferido contra o vetor de teste
                                   padrão do algoritmo antes de entrar em uso
  mercadopago.php                 cliente HTTP da Payments API do Mercado
                                   Pago (cartão, Pix), com modo "fake" pra
                                   rodar sem conta sandbox real (ver README)
tests/
  smoke_identity.sh             fluxo completo de identity (signup, código errado,
                                 refresh, detecção de reuso) contra um banco já migrado
  smoke_ordering.sh             fluxo completo de checkout (política, preço com
                                 variação, transição ilegal barrada, papel sem
                                 permissão barrado, KDS, paid→preparing→ready)
  smoke_discovery.sh            lista por distância, filtro de categoria, busca
                                 por trigram, perfil com estatísticas reais
  smoke_cart.sh                 variação obrigatória, indisponível barrado, troca
                                 de loja bloqueada com carrinho cheio e permitida
                                 vazio, recálculo em update/remove
  smoke_payments.sh             checkout do carrinho, os 5 métodos de
                                 pagamento, idempotência (replay e reuso
                                 barrado), upload+aprovação/recusa de
                                 comprovante Pix, aprovação dupla barrada,
                                 webhook do Pix automático
  smoke_tracking.sh             orders/show.php com linha do tempo, SSE de
                                 orders/track.php (snapshot + evento ao vivo
                                 via LISTEN/NOTIFY em outro processo),
                                 reviews/create.php (gate por delivered,
                                 uma avaliação por pedido)
  support/random_cnpj.php       CNPJ aleatório com dígito verificador válido,
                                 pra seed de teste não colidir entre scripts
db/
  migrations/
    001_identity.up.sql / .down.sql      users, partner_accounts, otp_codes,
                                          consents, sessions
    002_catalog.up.sql  / .down.sql      restaurants, restaurant_credentials,
                                          menu_items, item_variants,
                                          business_hours, addresses
    003_policy.up.sql   / .down.sql      platform_policies, policy_overrides,
                                          restaurant_payment_settings, audit_log
    004_ordering.up.sql / .down.sql      orders, order_items, order_events,
                                          outbox, advance_order(), delivery_slots
    005_payments.up.sql / .down.sql      payments, payment_proofs,
                                          idempotency_keys, refunds,
                                          card_transactions
    006_ledger.up.sql   / .down.sql      ledger_entries (append-only) + views,
                                          cash_settlement_intents, payouts,
                                          pos_devices, pos_custody
    007_dispatch.up.sql / .down.sql      courier_applications, couriers,
                                          courier_shifts, offers,
                                          dispatch_attempts
    008_support.up.sql  / .down.sql      disputes, fraud_signals,
                                          delivery_incidents, tickets,
                                          order_messages, coupons
    009_operations.up.sql / .down.sql    RLS, courier_positions (UNLOGGED),
                                          jobs do pg_cron, views de relatório,
                                          grants de produção
    010_catalog_discovery.up.sql / .down.sql   restaurants ganha category,
                                          logo_key, lat, lng + índice trigram
                                          em menu_items.name (fora das 42
                                          tabelas originais — ver seção própria)
    011_reviews.up.sql  / .down.sql      reviews (Fase 5.5 -- também fora
                                          das 42 tabelas originais, sem
                                          coluna de nota agregada em
                                          restaurants, ver seção própria)
  migrate.sh              runner simples (up / down N) via DATABASE_URL
  Dockerfile               postgres:16 + pg_cron
docker-compose.yml          banco (Postgres) + app (PHP embutido) para desenvolvimento
.github/workflows/ci.yml    CI: migrações (up/down/up) + lint PHP + smoke tests dos módulos
                             + build do front-end
```

Cada arquivo de migração segue exatamente a Parte II da especificação
(seção 11 — "Ordem das migrações"). Onde a tabela referenciava outra que só
nasce numa migração posterior (ex.: `orders.courier_id` → `couriers`, que só
existe na 007), a coluna é criada sem `FOREIGN KEY` e a constraint é
adicionada por `ALTER TABLE` na migração em que a tabela alvo passa a
existir — sem isso a ordem descrita no documento não fecha.

Duas correções feitas durante a implementação (a especificação descreve a
intenção, não é SQL testado linha a linha):

- **`window` é palavra reservada no PostgreSQL.** A coluna de
  `delivery_slots` precisou virar `"window"` (com aspas) — o `CREATE TABLE`
  como estava escrito no documento não roda.
- **`order_messages` é chat de três pontas** (cliente, loja, entregador,
  suporte), não só a loja. A especificação só detalha isolamento por
  `restaurant_id` (o mesmo padrão das outras tabelas com RLS); o acesso do
  próprio cliente/entregador às suas mensagens depende de variáveis de
  sessão que o documento não define (`app.user_id` / algo equivalente).
  A política em `009_operations.up.sql` cobre o lado loja/plataforma e
  deixa isso sinalizado em comentário — é uma decisão de produto pendente,
  não algo que dava para inventar na migração.

Os jobs de `pg_cron` (timeout de verificação de Pix, expurgo por retenção,
payout semanal) e as views de relatório em `009` são a mecânica descrita na
seção II.11 da especificação, no nível de detalhe que o documento de origem
especificou. O algoritmo fino de acerto semanal (netting, retry de payout
falho, valor mínimo) pertence ao módulo de pagamentos em PHP — fora do
escopo desta migração de esquema.

## Módulo identity — decisões de implementação

A especificação descreve o *quê* (OTP, refresh com rotação e detecção de
reuso, login de parceiro) mas não o *como* em código. Decisões tomadas para
fechar essa lacuna:

- **JWT escrito à mão** (`lib/jwt.php`, HS256), em vez de uma biblioteca via
  Composer — a cláusula zero não autoriza nenhuma, e o formato é simples o
  bastante para não precisar. Se algum dia crescer (RS256, JWKS, revogação
  por `jti`), isso é proposta de mudança de stack, não decisão de código.
- **Endpoints são arquivos diretos** (`api/v1/auth/otp_request.php` etc.),
  sem framework de rotas — casa com o diagrama da especificação
  (`api/*.php ─► PG`) e com a cláusula zero (PHP puro, PDO). URL bonita
  (`/v1/auth/otp/request`) viraria reescrita de servidor (nginx/.htaccess),
  ainda não configurada porque a hospedagem real não foi decidida aqui.
- **Cadastro (`purpose=signup`) exige `full_name` na primeira chamada**,
  porque `users.full_name` é `NOT NULL` no esquema — não dá para criar um
  usuário "rascunho" só com telefone. Login (`purpose=login`) exige que o
  usuário já exista; se não existir, a resposta aponta para `signup` (regra
  "erro sempre com saída", Parte I §6).
- **2FA por aparelho em `partner_login`, na forma mais simples que o
  esquema permite:** confiança no primeiro uso — o primeiro `device_id`
  enviado fica gravado em `partner_accounts.device_id`; login de outro
  aparelho dá `device_mismatch` até o suporte liberar a troca manualmente.
  A especificação menciona "2FA por aparelho" sem detalhar o fluxo de troca
  (reenvio de código, aprovação em outro dispositivo já logado etc.) —
  fica como decisão de produto em aberto.
- **`dev_code` na resposta de `otp_request` só fora de produção**
  (`APP_ENV != production`). Sem um provedor de SMS/e-mail configurado
  ainda, é assim que o fluxo é testável; o código real nunca é logado nem
  devolvido quando `APP_ENV=production`.

## Módulo catalog+ordering+checkout — decisões de implementação

- **Frete (`delivery_fee`) é informado pelo cliente do checkout, não
  calculado aqui.** O esquema não modela uma tarifa base por loja/distância
  (só `surge_fee`, o frete turbinado do cenário sem entregador — Fase 15) e
  a especificação não detalha de onde vem o valor normal. Fica validado
  como número ≥ 0 e nada mais; o cálculo por distância/geolocalização é
  responsabilidade de um módulo futuro (dispatch ou um serviço de tarifação
  à parte).
- **`policy_overrides` com `scope='city'` não entra no merge de
  `lib/policy.php`.** Só `scope='restaurant'` é aplicado — um override por
  praça exigiria cruzar `city_ibge_code` do endereço de entrega contra a
  praça da loja, e essa resolução geográfica não existe neste módulo ainda.
  Fica comentado no código.
- **`POST /v1/orders/status` é uma porta só, por cima de `advance_order()`,**
  em vez de um endpoint por transição (`/accept`, `/reject`, `/ready`...).
  A legalidade de uma transição (de/para) é decidida inteiramente pelo
  banco; o PHP só decide **quem tem permissão de pedir** cada transição
  (cliente só cancela o próprio pedido; loja avança/recusa/cancela o seu).
  Isso significa dois níveis de erro diferentes por design: 403 quando o
  papel não pode pedir aquilo, 409 quando o banco recusa a transição em si.
- **RLS por restaurante (migração 009) ainda não está em uso real.** As
  policies existem no banco, mas a conexão PHP de `lib/db.php` usa o que
  `DATABASE_URL` apontar — em dev/CI isso é o superusuário `postgres`, que
  ignora RLS por padrão (é dono das tabelas). A autorização de acesso a
  pedido hoje é feita inteiramente em `lib/orders.php`
  (`authorize_order_access()`), comparando `user_id`/`restaurant_id` do
  token com os do pedido. Rodar como o role `app_rw` (já com `GRANT`
  configurado em `009`) e emitir `SET LOCAL app.role` / `app.restaurant_id`
  por requisição é o próximo passo para RLS virar defesa em profundidade de
  verdade, não só desenho.

## Módulo de descoberta — decisões de implementação

A migração `010` e os endpoints `restaurants/list.php` e
`restaurants/search_products.php` existem porque a tela 2.1 (Home/Explorar)
do mock mostra categoria, distância e logo por loja, e nenhum dos três tinha
coluna no esquema — a Parte II original fixa 42 tabelas e nenhuma delas tem
`category`/`lat`/`lng` em `restaurants`.

- **O que foi adicionado (`restaurants.category`, `logo_key`, `lat`, `lng`)
  é extensão justificada, não invenção livre.** São fatos básicos de
  catálogo — "que tipo de comida" e "onde fica" — sem os quais a tela que
  o próprio dono do produto encomendou não tem como funcionar de verdade.
- **O que ficou de fora por ser escopo grande demais pra uma migração de
  suporte: nota da loja (rating).** O mock mostra "4,8 (812)" por loja —
  isso pede uma tabela de avaliações inteira (nota + comentário +
  moderação + agregação), que não está em nenhum lugar da especificação.
  Inventar esse sistema sem decisão do dono do produto seria escopo novo
  demais; fica de fora, documentado, até vir decisão.
- **Distância é Haversine de verdade, calculado no PostgreSQL** — não
  estimativa nem mock. Só fica `NULL` quando falta lat/lng de um dos dois
  lados (cliente não mandou, ou a loja não tem coordenada cadastrada).
- **Busca de produto usa `ILIKE` sobre o índice GIN trigram**
  (`gin_trgm_ops`, extensão `pg_trgm` já criada na migração `001`) — é
  exatamente o que a tela 2.2 pede no chip "PostgreSQL trigram". Os
  filtros do mock (Entrega grátis / Até 30 min / 4,5+) dependem de taxa de
  entrega, ETA e nota por loja — nenhum dos três é real ainda (mesma
  lacuna do parágrafo acima); só o filtro "Tudo" filtra de verdade no
  front, os outros avisam em vez de fingir.

## Módulo de carrinho — decisões de implementação

A Fase 3 revelou que `orders/create.php` (checkout de um passo só, feito
para o módulo catalog+ordering) não é como a tela realmente funciona: o
mock é item por item, com `toastr` confirmando cada adição, e "o carrinho
vive num store Svelte e é espelhado no PostgreSQL como pedido em
status='cart'". Os dois modelos convivem — nenhum substituiu o outro.

- **`price_line()` foi extraída pra `lib/cart.php` e reaproveitada em
  `orders/create.php`.** Precificar uma linha (validar item+variação
  contra o cardápio atual, nunca confiar no preço que o cliente mandou) é
  a mesma regra nos dois fluxos; duplicar essa validação seria o tipo de
  coisa que diverge silenciosamente com o tempo.
- **Um usuário só pode ter carrinho aberto numa loja por vez** — o próprio
  esquema força isso (`orders.restaurant_id` é fixo por pedido). Trocar de
  loja com o carrinho **vazio** troca sem perguntar (é o caso comum:
  passou pela loja e não pediu nada); com **item** dentro, dá 409 — a
  decisão de esvaziar e trocar fica com quem está usando o app, o backend
  não assume por conta própria.
- **Item esgotado aparece na lista, desabilitado, não escondido** — bug
  real encontrado nesta passada: `restaurants/menu.php` filtrava
  `available = true` desde a Fase 2, o que contradizia a própria
  especificação da tela 3.1 ("item esgotado desabilitado no servidor, não
  escondido"). Corrigido: o endpoint devolve todo o cardápio, com
  `available` no payload, e o front decide a aparência.
- **Cupom (`Cupom BEMVINDO10 −R$10,00` no mock) é só campo de UI.** A
  tabela `coupons` existe desde a migração `008`, mas não há endpoint de
  resgate/validação — construir isso é escopo de um cupom de verdade
  (regra de quem paga o desconto, teto, um uso por CPF), não deste
  módulo. O campo avisa que ainda não foi implementado em vez de aceitar
  qualquer código e fingir um desconto.
- **"Ir para pagamento" é onde a Fase 3 do front para.** O botão existe,
  mas ainda leva a um aviso — o backend de checkout+pagamento já existe
  (`orders/checkout.php`, `payments/pay.php`, ver seção própria abaixo),
  só a tela da Fase 4 que ainda não foi construída em Svelte.
- **Modal x classe reservada do Bootstrap: bug real, corrigido.** O
  primeiro `ItemModal.svelte` usava a classe `.modal` — que é exatamente o
  nome que o Bootstrap usa pro componente dele (`display: none` por
  padrão). O CSS com escopo do Svelte deveria vencer por especificidade,
  mas depender disso é frágil; o certo é nunca usar nomes reservados do
  framework de UI. Renomeado para `.item-modal-panel` / `.item-modal-backdrop`.
  Achado com Playwright checando `boundingBox()` do modal (`null` = não
  estava renderizando, apesar de estar no DOM) — não teria aparecido só
  olhando o código.

## Módulo de pagamentos — decisões de implementação

Fase 4 (seleção de método, cartão, Pix, dinheiro, maquininha) e Fase 7.3
(painel da loja, validação humana do Pix) do mock, em cima das tabelas da
migração `005`. `orders/checkout.php` foi criado nesta passada porque
faltava a ponte entre o carrinho incremental (Fase 3, `status='cart'`) e
o pagamento — ele não existia antes deste módulo.

- **Limite honesto: não há conta sandbox real do Mercado Pago neste
  ambiente.** `lib/mercadopago.php` implementa o cliente HTTP contra o
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
  `lib/idempotency.php` grava a chave com o hash da rota+corpo antes de
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
  não uma string decorativa: `lib/pix.php` monta os campos TLV do Banco
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

## Módulo de acompanhamento pós-pedido — decisões de implementação

Fase 5 do mock (aprovado, em análise, tracking em tempo real, rejeitado,
avaliação), na parte que é backend puro: linha do tempo, SSE e avaliação.
A Fase 5 no front (as 5 telas de verdade) ainda não foi portada — ver
"Próximos passos".

- **`reviews` é tabela nova (migração 011), fora das 42 originais —
  decisão consciente, não um esquecimento.** A migração `010` já tinha
  deixado registrado que "agregação, moderação" (nota média da loja
  exibida em Home/Search, moderação de comentário) ficava de fora por
  exigir decisão de produto. Esta migração respeita esse limite: nenhuma
  coluna de nota agregada entra em `restaurants`, nenhuma tela lista ou
  pondera reviews de outros usuários. O que entra é só o registro do
  feedback em si — rating, tags, comentário, gorjeta pro entregador — os
  campos que a tela 5.5 descreve, um por pedido (`UNIQUE (order_id)`), sem
  inventar além disso.
- **Gorjeta da avaliação (`courier_tip`) é REGISTRADA, não cobrada de
  novo.** O mock diz "cobrada no mesmo cartão do pedido" — fazer isso de
  verdade exigiria uma segunda transação no Mercado Pago associada ao
  pagamento original, que este módulo não implementa. Mesmo padrão de
  dinheiro/maquininha no módulo de pagamentos: intenção registrada,
  captura de valor de verdade fica pra outro módulo.
- **Avaliação só é aceita com `status = 'delivered'`** — e nenhum pedido
  chega lá sozinho ainda: a transição `delivering → delivered` só existe
  pra quem tem o app do entregador (Fase 8), que não foi construído nesta
  passada. `tests/smoke_tracking.sh` avança o pedido manualmente via
  `advance_order()` por `psql`, o mesmo artifício que os outros smoke
  tests já usavam pra simular etapas de um módulo futuro (`smoke_ordering.sh`
  já fazia isso pra simular pagamento aprovado antes deste módulo existir).
- **SSE de verdade, não polling disfarçado — com um limite documentado.**
  `orders/track.php` abre uma conexão `LISTEN order_changed` numa conexão
  pgsql crua (`raw_pg_connect()`, PDO não tem LISTEN/NOTIFY assíncrono) e
  manda um evento a cada `pg_notify` que `advance_order()` já dispara
  (migração 004). A conexão dura só 25 segundos de propósito: o servidor
  embutido do PHP (`php -S`), usado neste ambiente de desenvolvimento,
  processa uma requisição por vez — segurar a conexão pra sempre travaria
  o resto da API. O `EventSource` do navegador reconecta sozinho quando a
  conexão cai (é o próprio protocolo SSE), e cada reconexão manda um
  snapshot completo primeiro — então nenhum evento se perde, funciona como
  long-poll encadeado. Atrás de PHP-FPM com múltiplos workers (produção),
  o mesmo código aguentaria uma janela bem maior sem esse limite ser
  necessário.
- **`EventSource` não manda header customizado — o token vai por query
  string nesta rota, como exceção documentada.** `require_auth_header_or_query()`
  (`lib/auth_guard.php`) aceita `?token=` só aqui; toda outra rota continua
  exigindo `Authorization: Bearer` normalmente. É `GET`, então o token na
  URL não é mais exposto do que qualquer outro parâmetro de leitura —
  ainda assim, um access token de 15 minutos de vida, não um refresh.
- **`orders/show.php` ganhou `events`** (a mesma linha do tempo que o SSE
  manda) — pra quem abre o pedido sem já estar ouvindo o SSE (ex.: entrou
  direto pela lista de pedidos) ver o histórico sem esperar o próximo
  evento.
- **Validado contra Postgres e PHP reais, com o SSE testado de verdade —
  não só a forma do JSON.** `tests/smoke_tracking.sh` abre a conexão SSE
  de um processo, dispara `advance_order()` de OUTRO processo (`psql`) 2
  segundos depois, e confere que o evento `preparing` chegou no stream
  antes da conexão fechar — prova que o `LISTEN/NOTIFY` está entregando de
  verdade entre processos, não só que a rota responde 200.

## Front-end (`web/`) — Fase 1 a Fase 5, decisões de implementação

Svelte 5 + Vite, Bootstrap 5, Bootstrap Icons, `sweetalert` (não
`sweetalert2` — o pacote `sweetalert` na versão 2.x do npm *é* a
biblioteca clássica, a mesma API `swal()` que o mock usa). Fase 1 (splash,
seleção de estado, cidade+bairro), Fase 2 (home, busca, fidelidade,
pedidos, perfil), Fase 3 (loja, item, carrinho), Fase 4 (pagamento) e Fase
5 (pós-pedido) estão portadas; as outras 10 fases ainda não têm componente.

```
web/
  src/
    styles/tokens.css         paleta, tipografia e forma extraídos do HTML
                               de origem (grep de #hex por frequência —
                               ver comentário no topo do arquivo)
    lib/
      api.js                      cliente fetch fino (base URL, token, erros,
                                   headers extra, multipart -- ver Fase 4)
      session.svelte.js            estado de sessão reativo (login/logout real)
      cart.svelte.js                estado do carrinho, espelha a resposta da API
                                     a cada ação (não um store que finge sincronizar)
      toastr.js                    toastr sem jQuery (ver abaixo)
      datetime.js                   parsePgTimestamp() -- normaliza timestamptz
                                     do PDO (offset de 2 dígitos, sem T) pra
                                     algo que Date() sempre entende
      data/states.js                UFs, cidades e coordenadas de exemplo (estático)
      components/
        PhoneStatusBar.svelte          barra "9:41" que aparece em toda tela
        PhoneScreen.svelte              moldura de largura de celular
        BottomNav.svelte                5 abas (house/search/cart/star/person)
        QuickLogin.svelte               login mínimo real (ver abaixo)
        QuickAddress.svelte             endereço mínimo real (ver Fase 4 abaixo)
        ItemModal.svelte                3.2 — variações, observação, preço ao vivo
      screens/
        Splash.svelte                   1.1 — fade, avança sozinho
        StateSelector.svelte            1.2 — busca + lista com contagem de lojas
        CityPicker.svelte               1.3 — busca de cidade, bairro, SweetAlert
        Home.svelte                     2.1 — categorias, lojas por distância real
        Search.svelte                   2.2 — busca de produto por trigram
        Loyalty.svelte                  2.3 — só desenho, dado de exemplo (ver abaixo)
        Orders.svelte                   2.4 — pedidos do cliente, tabs em andamento/histórico
        Profile.svelte                  2.5 — perfil, estatísticas, endereços
        RestaurantPage.svelte           3.1 — cardápio por categoria, item esgotado visível
        CartDrawer.svelte               3.3 — itens, cupom (só UI), totais reais
        PaymentFlow.svelte              orquestra a Fase 4 inteira (endereço ->
                                         seleção -> método -> resultado)
        PaymentSelector.svelte          4.1 — grid de 5 métodos + BitPay (BETA, desabilitado)
        CardForm.svelte                 4.2 — campos do cartão (tokenização real pendente)
        PixPayment.svelte               4.3 — QR + copia-e-cola real, contador do banco
        ProofUploader.svelte            4.4 — compressão via canvas, progresso real (XHR)
        CashPayment.svelte              4.5 — troco, valida contra o total
        MachinePayment.svelte           4.6 — débito/crédito, bandeiras aceitas
        OrderTracking.svelte            5.1/5.2/5.3/5.4 numa tela só, reagindo
                                         ao status ao vivo por SSE (ver abaixo)
        ReviewScreen.svelte             5.5 — nota, tags, gorjeta, comentário
    App.svelte                  orquestra Fase 1 -> Fase 2 (abas) -> Fase 3/4/5
                                 (tela cheia por cima das abas, com volta)
```

- **`toastr` sem jQuery.** O pacote npm `toastr` declara "jQuery is
  required" no próprio `package.json` — e a cláusula zero proíbe jQuery em
  código novo. As duas regras da mesma cláusula se contradizem para
  front-end escrito do zero. Resolvido do mesmo jeito que o JWT do módulo
  identity: `web/src/lib/toastr.js` reimplementa a mesma interface
  (`toastr.success/error/warning/info`) em ~60 linhas de DOM puro, sem
  puxar jQuery. Trocar por versão nova ou biblioteca "melhor" seria
  proposta de mudança de stack, não decisão de quem está escrevendo tela.
- **Vite como bundler não é item da cláusula zero.** A cláusula lista
  frameworks e bibliotecas de runtime, não ferramenta de build; não existe
  jeito de compilar `.svelte` pra produção sem alguma. Fica registrado
  aqui pela mesma razão que o Composer não apareceu no módulo PHP: é
  plumbing, não stack.
- **UFs/cidades/bairros são dado estático local** (`web/src/lib/data/states.js`),
  não um endpoint do backend. O esquema do banco só guarda
  `city_ibge_code` por endereço — não existe tabela de UFs/municípios — e
  a especificação diz que essa lista "vem do edge cache da Cloudflare",
  que é exatamente o que um JSON estático bem cacheado seria em produção.
  As 4 contagens de "lojas ativas" (SP 1.284, MG 612, PR 348, BA 297) são
  as mesmas do mock original — não vêm de `COUNT(*)` real.
- **Geolocalização e SweetAlert são reais, não simulados.** A tela 1.3
  chama de verdade `navigator.geolocation.getCurrentPosition` depois do
  SweetAlert confirmar — testado via Playwright com permissão de
  localização concedida e coordenadas fixas. O que é simplificado é achar
  o bairro a partir de lat/lng: geocodificação reversa pede um provedor
  externo (Google/Mapbox/Nominatim) que não foi decidido ainda, então o
  fluxo usa o primeiro bairro conhecido da cidade como resultado.
- **Validado visualmente, não só compilado.** `npm run build` limpo não
  prova que a tela se parece com o mock. As 3 telas da Fase 1 foram
  conferidas numa janela de 430px com Playwright + Chromium: splash com o
  fade automático, seleção de estado com destaque e botão desabilitado até
  escolher, o modal do SweetAlert dentro do fluxo de cidade, o toast de
  sucesso depois da geolocalização, e a Fase 1 fechando com o
  `city_ibge_code` certo.

**Fase 2 — decisões adicionais:**

- **A barra inferior tem 5 abas** (house/search/cart/star/person), **não
  6** — "Pedidos" (2.4) não é uma delas. No mock, ela é alcançada tocando
  a estatística "PEDIDOS" dentro do Perfil (2.5); é assim que
  `App.svelte` liga as duas (`Profile` → `onOpenOrders` → aba `orders`,
  com botão de voltar em `Orders.svelte`, já que ela não é uma aba
  própria da navegação inferior).
- **A aba Carrinho existe mas não abre nada** — avisa que é a Fase 3
  (cardápio/item/carrinho), ainda não portada, em vez de levar a uma tela
  vazia fingindo que funciona.
- **Login é `QuickLogin.svelte`, um formulário mínimo de verdade** (chama
  `/auth/otp_request.php` e `/otp_verify.php` reais), não a tela completa
  da Fase 10 — que ainda não foi desenhada em componente. Ele gate as três
  abas que precisam de usuário autenticado (fidelidade, pedidos, perfil) e
  deixa isso visível na tela com um selo "login provisório".
- **CORS entrou em `lib/bootstrap.php`** porque Fase 2 é a primeira vez
  que o front chama a API de verdade — Vite (porta 5173 em dev) e PHP
  (porta 8080) são origens diferentes pro navegador. `ALLOWED_ORIGIN` no
  `.env` controla isso; vazio em produção assume que PWA e API dividem
  domínio via Cloudflare (a especificação nunca fala em domínios
  separados), então nenhum header `Access-Control-*` é enviado.
- **Fidelidade (2.3) é a única tela que não chama a API.** Sem tabela de
  pontos no banco (ver "Módulo de descoberta" acima — mesma lacuna, outra
  tela), os números são fixos, os mesmos do mock, com um selo visível
  avisando que é dado de exemplo. Os botões "Resgatar"/"Trocar" mostram um
  aviso em vez de fingir uma transação.
- **`orders/list.php` foi enriquecido** (nome da loja, contagem de itens)
  depois que a tela de Pedidos (2.4) mostrou que a versão anterior (só
  `status`/`total`/`payment_method`) não bastava pra uma lista útil — o
  endpoint mudou junto com a tela que o usa, não antes.
- **Validado com Playwright de ponta a ponta, incluindo login real:**
  onboarding → Home com lojas ordenadas por distância Haversine de
  verdade → filtro de categoria refazendo a consulta → busca de produto
  por trigram → aba Fidelidade sem login → aba Perfil pedindo login →
  cadastro por OTP dentro do `QuickLogin` → Perfil com estatísticas reais
  → toque em "PEDIDOS" → sub-tela de pedidos → volta pro Perfil. Zero
  erros de console em todo o percurso (um 404 apareceu no meio do teste e
  não era bug: é a própria API respondendo `user_not_found` de propósito
  para um telefone sem cadastro — o Chromium loga qualquer `fetch` não-2xx
  como "erro" no console, mesmo quando a aplicação trata a resposta
  corretamente, como este caso trata).

**Fase 3 — decisões adicionais:**

- **`RestaurantPage`/`CartDrawer` são tela cheia por cima das abas, sem a
  barra inferior** — é assim que o mock desenha 3.1/3.3 (sem os 5 ícones
  visíveis), diferente das telas da Fase 2. `App.svelte` trata isso como
  uma pilha própria (`restaurantId`/`cartOpen`), não como mais uma aba.
- **Adicionar item sem estar logado abre o `QuickLogin` *dentro* do
  modal**, sem fechar ou perder as variações já marcadas — testado de
  ponta a ponta: seleciona variação, tenta adicionar, loga pelo formulário
  embutido, volta pro item com tudo como estava. Depois do login, ainda é
  preciso tocar "Adicionar" de novo (não reenvia sozinho) — é uma escolha
  deliberada de não disparar uma ação de carrinho sem toque explícito
  depois de uma tela nova aparecer, não uma limitação técnica.
- **Grupo de variação vira rádio ou checkbox pela própria coluna do
  banco**: `max_selections === 1` é escolha única (rádio); qualquer outro
  valor (ou `null`, sem limite) é múltipla escolha, capada nesse número
  quando ele existir. Não tem coluna dizendo "isto é rádio" — é inferido
  do mesmo jeito que o cardápio já descreve os grupos.
- **Preço do item é recalculado a cada seleção, no cliente, só pra
  mostrar** — o preço que de fato vira `order_items.unit_price` é
  recalculado de novo no servidor (`price_line()`) quando `Adicionar` é
  clicado. O número que o cliente vê antes de confirmar é preview, nunca
  a fonte da verdade — exatamente o que o chip da tela 3.2 pede
  ("preço recalculado no servidor antes de virar item do pedido").
- **Validado com Playwright, incluindo o bug do `.modal` acima**: abrir
  loja anônimo (o 401 de `cart/show.php` pra usuário deslogado é esperado
  e já tratado, não erro), trocar de categoria, abrir item indisponível
  (não abre modal — é `disabled`), selecionar variação obrigatória +
  adicional, ver o preço somar ao vivo (R$32,90 → R$38,90), tentar
  adicionar sem login → `QuickLogin` embutido → login real por OTP →
  variações preservadas → adicionar de verdade → toast "Item adicionado
  ✓" → barra de carrinho fixa → abrir carrinho → aumentar quantidade →
  subtotal recalculado no servidor (R$38,90 → R$77,80). Zero erros de
  console do início ao fim.

**Fase 4 — decisões adicionais:**

- **`PaymentFlow.svelte` orquestra a fase inteira**, mas quem chama a API
  de verdade é cada tela filha via callback (`onSubmit`/`onContinue`) — o
  mesmo padrão de props+callback já usado em `ItemModal`/`CartDrawer`,
  não um store novo só pra isto.
- **Endereço mínimo (`QuickAddress.svelte`), no mesmo espírito do
  `QuickLogin.svelte`**: lista os endereços salvos ou cadastra um novo
  (chama `/addresses/list.php` e `/addresses/create.php` de verdade) — não
  é a tela de CRUD completo da Fase 6 (editar, apagar, rótulo, padrão),
  que ainda não foi portada. Sem isto, não haveria como testar o checkout
  ponta a ponta nesta passada.
- **`orders/checkout.php` só é chamado uma vez por fluxo.** Ele transiciona
  `cart → pending_payment`; não existe "carrinho" pra achar numa segunda
  chamada. Retry de pagamento (CVV errado, etc.) chama só `payments/pay.php`
  de novo em cima do mesmo `order.id` — `PaymentFlow` guarda esse estado
  (`order !== null` vira o sinal de "checkout já aconteceu"). Trocar de
  método DEPOIS que o checkout já rodou (ex.: Pix sem chave cadastrada,
  volta e escolhe cartão) não reabre um carrinho novo automaticamente —
  isso exigiria um endpoint de abandono que não existe ainda; registrado
  como simplificação no código.
- **Recusa de cartão (HTTP 402) não é um "erro" pro fluxo — é uma decisão
  de domínio.** `checkoutAndPay()` distingue os dois: um 402 cujo corpo já
  traz `order`/`payment` (a forma que `payments/pay.php` sempre devolve
  numa recusa) é tratado como resultado normal, não repassado como
  exceção — entrega pro `OrderTracking.svelte` (Fase 5) igual a um
  sucesso, que mostra o hero de "Pagamento recusado" (5.4) porque um
  pedido `rejected` é estado terminal no banco (`advance_order()` não tem
  transição saindo dele) — não dá pra "tentar de novo" no mesmo pedido.
- **Tokenização real do MercadoPago.js não está integrada** — este
  ambiente não tem uma Public Key de sandbox do Mercado Pago. `CardForm.svelte`
  tem os mesmos campos do mock (4.2), mas manda um token placeholder (os
  dígitos do cartão) em vez de um token de verdade gerado pelo SDK no
  navegador; funciona porque o backend também está em
  `MERCADOPAGO_MODE=fake` neste ambiente (ver "Módulo de pagamentos"
  acima). Um selo amarelo avisa isso na própria tela, mesmo padrão do
  "login provisório" do `QuickLogin`.
- **Progresso de upload é real, não decorativo.** `fetch()` não expõe
  progresso de envio de forma confiável entre navegadores, então
  `ProofUploader.svelte` usa `XMLHttpRequest` só pra esta chamada
  (`xhr.upload.onprogress`) — é por isso que este componente não usa
  `lib/api.js` como os outros. A compressão antes do envio também é real:
  redesenha a imagem num `<canvas>` (máximo 1280px no lado maior, JPEG
  80%) e mostra o tamanho antes/depois, exatamente como o chip da tela
  4.4 descreve.
- **Pix copia-e-cola (BR Code) veio de `lib/pix.php` de verdade** — não é
  texto decorativo. `PixPayment.svelte` exibe o payload EMV completo
  (testado visualmente: começa com `000201`, contém `br.gov.bcb.pix`).
  O CNPJ mostrado na tela exigiu adicionar a coluna `cnpj` na resposta de
  `restaurants/show.php` (não vazava antes — é dado público, mesmo que já
  sai em `partner_login.php`).
- **Contador da tela 4.3 lê `orders.verification_deadline` do servidor**,
  não um timer local — atualizado a cada segundo (`setInterval`) só pra
  formatar `mm:ss`, nunca pra decidir quando expira; quem decide isso é o
  `pg_cron` (`expire_pending_verifications`, migração `009`).
- **Troco (`CashPayment`) valida contra o TOTAL do pedido no cliente**,
  mais rigoroso que o mínimo que o próprio banco exige (`CHECK
  cash_change_valid` só pede `change_for >= subtotal`, sem contar o
  frete) — o valor mandado pro backend nunca fica abaixo do que o cliente
  realmente deve, então a validação mais frouxa do banco nunca chega a
  ser testada pelo caminho feliz desta tela.
- **Validado com Playwright, os cinco métodos, ponta a ponta e contra o
  backend real** (não só compilado): dinheiro com troco → "Pagamento
  aprovado"; maquininha com bandeira obrigatória → "Pagamento aprovado";
  cartão aprovado (modo fake) → "Pagamento aprovado" com bandeira/final
  registrados; cartão recusado (interceptado com a resposta 402 exata que
  o backend manda, já que o formulário real só aceita dígitos e a
  convenção de recusa do modo fake do backend é alfanumérica) →
  hero "Pagamento recusado"; Pix manual → QR real exibido → upload de um
  JPEG de teste gerado on-the-fly → "Comprovante em análise" → aprovado
  pela loja via `restaurants/approve_pix.php` (login de loja real) →
  pedido confirmado `paid`. Dois bugs reais apareceram e foram corrigidos
  nesta validação: `payments/pay.php` devolve `pix_copy_paste` na raiz do
  corpo, não dentro de `payment` — o primeiro código guardava só
  `payment`, então o código Pix aparecia em branco na tela; achado
  comparando o texto renderizado com o esperado via Playwright, não só
  lendo o código. E: limpar o carrinho (`clearCartState()`) acontecia
  ANTES da tela de resultado assumir, então por uma fração de segundo o
  que estava por trás (ex.: "Total do pedido") mostrava R$0,00 — corrigido
  movendo a limpeza pra depois.

**Fase 5 — decisões adicionais:**

- **Uma tela só (`OrderTracking.svelte`) cobre 5.1, 5.2, 5.3 e 5.4** — no
  mock as quatro já são a mesma ideia ("o aviso chega por SSE"), só o
  conteúdo do "hero" muda com `order.status`; separar em 4 arquivos
  duplicaria a conexão SSE e a busca do pedido sem ganhar nada. 5.5
  (`ReviewScreen.svelte`) é tela própria de verdade, porque é a única que
  tem uma ação distinta (enviar formulário) em vez de só refletir status.
- **SSE de verdade no navegador**: `EventSource` nativo (sem lib) contra
  `orders/track.php`; reconecta sozinho quando a conexão de 25s do
  backend fecha (comportamento padrão do protocolo, documentado na seção
  "Módulo de acompanhamento pós-pedido" acima) — o front não tem nenhuma
  lógica de retry escrita à mão.
- **Token na URL, não no header, só nesta chamada** — `EventSource` não
  deixa configurar headers customizados, então a Authorization normal não
  serve aqui. `require_auth_header_or_query()` no backend é o que torna
  isso seguro sem abrir a exceção pra mais nenhuma rota.
- **Mapa e localização do entregador são um placeholder explícito**, não
  Leaflet nem coordenadas fingidas — a Fase 8 (app do entregador, que é
  quem geraria posição de verdade) não foi construída. Mesma decisão do
  `courier_positions` (migração 009): existe no banco, não tem quem
  escreva nele ainda.
- **Previsão de entrega é uma janela fixa a partir de `created_at`** (25 a
  45 min depois), igual à mesma simplificação já assumida em
  `PaymentSelector.svelte` (Fase 4.1) — sem motor de logística real
  (Fase 8/9), não tem outra fonte pra esse número.
- **Bug real de datas, achado nesta fase e corrigido em três lugares.**
  `new Date(timestamp.replace(' ', 'T'))` parece inofensivo, mas quebra
  silenciosamente ("Invalid Date", sem lançar exceção) quando o
  `timestamptz` do Postgres termina em offset de 2 dígitos sem os
  dois-pontos (`+00`, não `+00:00`) — a combinação exata que
  `ATTR_EMULATE_PREPARES` e o driver `pgsql` produzem. Achado com
  Playwright comparando o texto renderizado ("Invalid Date" na tela, não
  só no console). `web/src/lib/datetime.js` (`parsePgTimestamp()`) resolve
  isso normalizando pra ISO 8601 de verdade antes de entregar pro `Date`;
  usado em `OrderTracking.svelte` (novo) e em `Orders.svelte` (Fase 2.4,
  que já tinha o mesmo bug desde antes desta fase — `minutesLeft()` também
  corrigido).
- **`orders/show.php` ganhou `review`** (a avaliação já feita, ou `null`)
  pro botão "Avaliar pedido" não aparecer de novo pra quem já avaliou —
  em vez de deixar o clique acontecer e só então devolver 409.
- **Gorjeta da avaliação some no "R$ Outro"** se o campo numérico for
  preenchido, e os chips fixos (R$2/R$5/R$10) se desmarcam sozinhos —
  são mutuamente exclusivos por design (`effectiveTip`), igual ao rádio
  de parcelas do cartão.
- **Validado com Playwright de ponta a ponta, incluindo o SSE de
  verdade entre processos diferentes** (não só a forma da resposta):
  pedido pago → hero "Pagamento aprovado" → `advance_order()` disparado
  via `psql` num processo separado, 1,5s depois → a tela reage sozinha
  pra "Em preparo na cozinha" sem nenhum reload, comprovando o
  `LISTEN/NOTIFY` entregando entre processos de verdade → avança até
  `delivered` → "Avaliar pedido" → 5 estrelas + 2 tags + gorjeta de R$5 +
  comentário → envia → volta pra lista de pedidos → reabre o mesmo pedido
  pela aba "Histórico" → mostra "Você já avaliou esse pedido com 5
  estrelas" em vez do botão de novo. Zero erros de console do início ao
  fim, nas duas passadas completas.

## Como rodar localmente

```bash
docker compose up -d
export DATABASE_URL=postgres://postgres:postgres@localhost:5432/fuudelivery
bash db/migrate.sh up           # aplica as 11, em ordem
bash db/migrate.sh down 3       # reverte as 3 últimas
bash db/migrate.sh down 11      # reverte tudo

cp .env.example .env            # ajuste DATABASE_URL/JWT_SECRET/ALLOWED_ORIGIN se precisar
JWT_SECRET=dev-secret bash tests/smoke_identity.sh    # fluxo completo de identity
JWT_SECRET=dev-secret bash tests/smoke_ordering.sh    # fluxo completo de checkout (semeia loja/cardápio sozinho)
JWT_SECRET=dev-secret bash tests/smoke_discovery.sh   # lista por distância, busca, perfil (semeia sozinho)
JWT_SECRET=dev-secret bash tests/smoke_cart.sh        # carrinho incremental (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_payments.sh   # pagamentos (semeia sozinho)
JWT_SECRET=dev-secret bash tests/smoke_tracking.sh    # timeline, SSE, avaliação (semeia sozinho)

php -S localhost:8080                  # API, num terminal
cd web && npm install && npm run dev   # front-end Svelte, noutro terminal — http://localhost:5173
```

`MERCADOPAGO_MODE=fake` é o padrão quando `MERCADOPAGO_ACCESS_TOKEN` não
está configurado (ver seção "Módulo de pagamentos" abaixo) — não precisa
de conta sandbox pra rodar nada disto localmente.

As onze migrações foram validadas de ponta a ponta (`up` completo, `down`
completo em ordem reversa, `up` de novo) contra um PostgreSQL 16 real com
`pg_cron` instalado, incluindo um teste funcional de `advance_order()`
confirmando que transições legais avançam o pedido e transições ilegais
levantam exceção (a defesa de concorrência descrita na Parte I §4 e na
Parte II §9).

O módulo identity foi validado do mesmo jeito, ponta a ponta contra um
Postgres real: cadastro por OTP, rejeição de código errado com contador de
tentativas, emissão de tokens, `consent` autenticado x sem token, rotação
de refresh e — o caso que mais importa — reuso de um refresh já rotacionado
derrubando a família de sessão inteira (a defesa contra token roubado da
Parte I §7). `tests/smoke_identity.sh` é exatamente essa sequência,
automatizada, e roda no CI a cada push em `api/`, `lib/` ou `tests/`.

O módulo catalog+ordering+checkout também: cardápio com variação de preço
somando certo, pedido abaixo do mínimo barrado, checkout gerando
`policy_snapshot` e total corretos, transição ilegal (`pending_payment` →
`ready` direto) barrada com 409, papel sem permissão barrado com 403, fila
do KDS mostrando só o que a loja pode ver, e o caminho feliz completo
`paid → preparing → ready`. `tests/smoke_ordering.sh` semeia sua própria
loja/cardápio via `psql` e roda tudo isso a cada push, no mesmo CI.

Um bug real apareceu e foi corrigido durante essa validação: com
`PDO::ATTR_EMULATE_PREPARES` desligado (necessário pra prepared statement
de verdade, não só client-side), `PDO::execute()` manda um `false` do PHP
como string vazia `''` — e o PostgreSQL rejeita `''` como `boolean`
("invalid input syntax for type boolean"). `lib/db.php` ganhou `pg_bool()`
pra isso; qualquer parâmetro booleano futuro deve passar por ela.

O módulo de descoberta também: lista por distância real (Haversine)
ordenando a loja mais perto primeiro, filtro de categoria devolvendo só a
categoria pedida, busca por trigram achando o produto pelo nome parcial
mesmo na loja certa, busca curta demais barrada, e perfil autenticado
devolvendo estatísticas reais sem vazar CPF. `tests/smoke_discovery.sh`
semeia sua própria loja com CNPJ aleatório válido (`tests/support/random_cnpj.php`)
— um bug real de teste apareceu aqui: os primeiros CNPJs fixos colidiam
com os que `smoke_ordering.sh` já tinha semeado no mesmo banco, porque os
dois scripts rodam em sequência no mesmo CI sem recriar o banco entre um e
outro.

O módulo de carrinho também: variação obrigatória exigida antes de
precificar, item indisponível barrado, preço com variação somando certo
(R$30 + R$5 = R$35), troca de loja com carrinho vazio permitida e com
carrinho cheio barrada (409), acúmulo de subtotal em dois itens,
recálculo em `update_quantity`/`remove_item`. `tests/smoke_cart.sh` roda
tudo isso a cada push, no mesmo CI.

O módulo de pagamentos também: checkout do carrinho barrado sem endereço,
os cinco métodos pagando de ponta a ponta (dinheiro e maquininha indo
direto pra `paid`, cartão aprovado e recusado pelo modo fake do Mercado
Pago, Pix automático confirmado por webhook, Pix manual gerando um BR
Code de verdade), idempotência com replay idêntico/reuso barrado/chave
ausente barrada, upload de comprovante real (JPEG gerado via GD) avançando
o pedido, aprovação e recusa humana do Pix com aprovação dupla barrada por
`FOR UPDATE`. `tests/smoke_payments.sh` roda tudo isso a cada push, no
mesmo CI, em `MERCADOPAGO_MODE=fake` (ver seção "Módulo de pagamentos"
acima pro porquê).

O módulo de acompanhamento pós-pedido também: `orders/show.php` com a
linha do tempo, o SSE de `orders/track.php` recebendo evento ao vivo
disparado por outro processo (não só a forma da resposta), e
`reviews/create.php` barrando avaliação antes de `delivered` e avaliação
duplicada. `tests/smoke_tracking.sh` roda tudo isso a cada push, no mesmo
CI.

O front-end validou o mesmo jeito, não só compilado, nas cinco fases com
Playwright + Chromium numa janela de 430px: Fase 1 (fade do splash,
seleção de estado com destaque, SweetAlert real antes da Geolocation API,
toast sem jQuery), Fase 2 (lojas ordenadas por distância de verdade,
filtro de categoria refazendo a consulta, busca de produto, login real por
OTP destravando as abas autenticadas, perfil com estatísticas reais,
navegação Perfil → Pedidos → volta), Fase 3 (cardápio com item esgotado
visível, modal de variação com preço ao vivo, login embutido no modal
preservando seleção, carrinho persistente com recálculo real de
quantidade), Fase 4 (os cinco métodos de pagamento contra o backend real,
incluindo o ciclo completo de Pix manual com aprovação humana pelo painel
da loja — ver seção "Fase 4 — decisões adicionais" acima pros dois bugs
reais que apareceram e foram corrigidos nesta validação) e Fase 5
(acompanhamento ao vivo por SSE entre processos diferentes, avaliação
completa, reabertura pela lista de pedidos mostrando "já avaliado" — ver
seção "Fase 5 — decisões adicionais" acima pro bug real de datas achado e
corrigido em três lugares nesta validação). Um bug real de CSS apareceu na
Fase 3 e está documentado na seção "Módulo de carrinho" acima — a classe
`.modal` colidindo com o Bootstrap, achada checando `boundingBox()` via
Playwright, não só lendo o código.

## Próximos passos (ordem sugerida pela especificação, Parte I §10)

1. **Fase 6 no front (endereços, cartões salvos, configurações).**
   `QuickAddress.svelte` cobre o mínimo pra Fase 4 funcionar (listar/criar
   endereço); falta o CRUD completo (editar, apagar, rótulo, padrão) e
   cartões salvos via Mercado Pago (nunca guardar PAN, só bandeira/4
   últimos dígitos/id do cartão salvo).
2. **Portar o resto das telas** de `FUUDelivery - 64 Telas (offline).html`
   para Svelte + Bootstrap — Fases 1 a 5 prontas, faltam as outras 9.
3. **Fase 7.2 (push) e impressão ESC/POS** — `restaurants/approve_pix.php`
   já grava o evento e avança o pedido, mas notificar o cliente e imprimir
   a comanda dependem de uma fila de push (outbox + worker) e de conexão
   com impressora térmica que ainda não existem neste repositório.
4. **Mapa e posição do entregador (Fase 5.3 e Fase 8).**
   `OrderTracking.svelte` já mostra a linha do tempo real, mas o mapa é um
   placeholder explícito — depende do app do entregador (Fase 8) existir
   pra ter posição de verdade pra mostrar.
5. **Reembolso (Fase 13) e reconciliação de maquininha (Fase 9).**
   `refunds` e `card_transactions` existem no esquema (migração `005`) sem
   endpoint — os dois dependem de telas/fluxos (disputa, entregador
   confirmando NSU) que ainda não foram portados.
6. **Decisão de produto pendente: fidelidade/pontos.** A tela 2.3 existe
   só como desenho (dado de exemplo, sem tabela no banco). Se for pra
   valer, precisa de um ledger de pontos — mesmo padrão append-only do
   `ledger_entries` financeiro — e isso é decisão de escopo, não algo pra
   inventar numa migração de suporte a tela.
7. **Decisão de produto pendente: cupons.** `coupons`/`coupon_redemptions`
   existem desde a migração `008`, mas não há endpoint de resgate. O
   `CartDrawer` (3.3) já tem o campo de UI, esperando o backend.
8. **Decisão de produto pendente: Pix automático sem tela.** O enum
   `payment_method` já tem `pix_auto` e o backend já processa (webhook
   incluído), mas o mock de 64 telas só desenha o fluxo manual (Fase 4.3);
   não há uma tela própria pra "Pix instantâneo" — fica pra quando/se essa
   tela for desenhada.
9. **Trocar de método de pagamento depois do checkout já ter acontecido
   não reabre um carrinho novo** (ex.: Pix manual sem chave cadastrada,
   volta e escolhe cartão) — precisaria de um endpoint de abandono de
   `pending_payment` que não existe ainda. Registrado como simplificação
   em `PaymentFlow.svelte`; não é o caminho comum (a maioria das voltas
   acontece antes do checkout, quando o retry já funciona certo).
10. **Gorjeta da avaliação (Fase 5.5) é registrada, não cobrada.** O mock
    diz "cobrada no mesmo cartão do pedido" — exigiria uma segunda
    transação no Mercado Pago associada ao pagamento original, que este
    módulo não implementa (mesma simplificação de dinheiro/maquininha no
    módulo de pagamentos).

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
