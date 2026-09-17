# fuuphp

Plataforma de delivery com quatro superfícies (cliente, entregador, loja em
tablet/KDS, painel admin), especificada em `Especificação FUUDelivery —
Documento Único` (64 telas, 15 fases, esquema PostgreSQL de 42 tabelas).

## Relação com o `fuudelivery-backend` (Go)

Existe um backend em produção — `MarcosPavanBR/fuudelivery-backend` — em Go
(microsserviços `auth_api`/`orders_api`/`delivery_api`, GORM, três bancos
Postgres separados no Supabase, RabbitMQ, gateway AbacatePay). Este
repositório **não é uma extensão dele**: é a implementação da especificação
PHP + Svelte + PostgreSQL único, decidida como o caminho daqui pra frente. O
backend Go fica em produção enquanto a migração não estiver pronta, sem
prazo definido para desligar — mas a stack fixada pela cláusula zero abaixo
vale só para este repositório.

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

A **fundação de banco** (nove migrações SQL), o **módulo de identidade** e o
**módulo de catálogo + pedido + checkout** em PHP sobre ela, e o começo do
**front-end em Svelte** (`web/`) — só a Fase 1 (onboarding) por enquanto,
das 15 fases / 64 telas. Pagamentos, ledger, dispatch e o resto do backend
ainda não foram portados; as outras 14 fases do front também não. Segue a
ordem sugerida pela especificação (12 semanas, Parte I §10) —
catálogo/pedido vem antes de pagamentos porque `POST /v1/orders/:id/pay`
pressupõe que o pedido já existe.

```
api/v1/auth/                módulo identity (endpoints, um arquivo por rota)
  otp_request.php             POST — pede código (login/signup/phone_verify)
  otp_verify.php               POST — confirma código, emite access+refresh
  refresh.php                  POST — rotaciona refresh, detecta reuso
  partner_login.php            POST — loja (CNPJ+senha) / entregador (CPF+código)
  consent.php                  POST — registra aceite de termo (LGPD), autenticado
api/v1/restaurants/         catálogo (público, exceto orders.php)
  show.php                     GET  ?id= — dados da loja + horário de funcionamento
  menu.php                     GET  ?id= — cardápio disponível, com variações
  orders.php                   GET  ?id= — fila do KDS (só restaurant_staff da própria loja)
api/v1/addresses/           endereços do cliente autenticado
  create.php                   POST — cadastra endereço
  list.php                     GET  — lista os do usuário logado
api/v1/orders/               pedido e checkout
  create.php                   POST — checkout: valida política/preço/loja aberta,
                                cria o pedido, avança cart → pending_payment
  show.php                     GET  ?id= — detalhe (dono ou loja do pedido, só)
  list.php                     GET  — pedidos do cliente autenticado
  status.php                   POST — única porta pra mudar status, por cima de
                                advance_order(); autorização por papel aqui,
                                legalidade da transição só no banco
lib/                          código compartilhado entre módulos
  bootstrap.php                 carrega .env, registra handler de erro, requires
  db.php                          PDO (DATABASE_URL → pgsql DSN) + pg_bool()
  response.php                    envelope de erro/sucesso com trace_id (contrato de API, Parte I §3)
  jwt.php                          JWT HS256 escrito à mão (sem dependência nova)
  sessions.php                    emissão e rotação de sessão (Parte I §7)
  otp.php                          geração/hash de código, limite de pedidos
  auth_guard.php                  exige access token válido
  policy.php                      resolve política loja → plataforma, gera o policy_snapshot
  orders.php                      chama advance_order(), autorização de acesso a pedido
  validation.php                  CPF/CNPJ com dígito verificador, e-mail, telefone
  uuid.php                        UUIDv4 sem dependência
tests/
  smoke_identity.sh             fluxo completo de identity (signup, código errado,
                                 refresh, detecção de reuso) contra um banco já migrado
  smoke_ordering.sh             fluxo completo de checkout (política, preço com
                                 variação, transição ilegal barrada, papel sem
                                 permissão barrado, KDS, paid→preparing→ready)
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
  migrate.sh              runner simples (up / down N) via DATABASE_URL
  Dockerfile               postgres:16 + pg_cron
docker-compose.yml          banco (Postgres) + app (PHP embutido) para desenvolvimento
.github/workflows/ci.yml    CI: migrações (up/down/up) + lint PHP + smoke tests dos módulos
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

## Front-end (`web/`) — Fase 1, decisões de implementação

Svelte 5 + Vite, Bootstrap 5, Bootstrap Icons, `sweetalert` (não
`sweetalert2` — o pacote `sweetalert` na versão 2.x do npm *é* a
biblioteca clássica, a mesma API `swal()` que o mock usa). Só a Fase 1
(splash, seleção de estado, cidade+bairro) está portada; as outras 14
fases ainda não têm componente.

```
web/
  src/
    styles/tokens.css         paleta, tipografia e forma extraídos do HTML
                               de origem (grep de #hex por frequência —
                               ver comentário no topo do arquivo)
    lib/
      toastr.js                  toastr sem jQuery (ver abaixo)
      data/states.js              UFs e cidades/bairros de exemplo (estático)
      components/
        PhoneStatusBar.svelte        barra "9:41" que aparece em toda tela
        PhoneScreen.svelte            moldura de largura de celular
      screens/
        Splash.svelte                 1.1 — fade, avança sozinho
        StateSelector.svelte          1.2 — busca + lista com contagem de lojas
        CityPicker.svelte             1.3 — busca de cidade, bairro, SweetAlert
    App.svelte                  orquestra as 3 telas da Fase 1
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
  prova que a tela se parece com o mock. As 3 telas foram conferidas numa
  janela de 430px com Playwright + Chromium: splash com o fade automático,
  seleção de estado com destaque e botão desabilitado até escolher, o
  modal do SweetAlert dentro do fluxo de cidade, o toast de sucesso depois
  da geolocalização, e a Fase 1 fechando com o `city_ibge_code` certo.

## Como rodar localmente

```bash
docker compose up -d
export DATABASE_URL=postgres://postgres:postgres@localhost:5432/fuudelivery
bash db/migrate.sh up          # aplica as 9, em ordem
bash db/migrate.sh down 3      # reverte as 3 últimas
bash db/migrate.sh down 9      # reverte tudo

cp .env.example .env           # ajuste DATABASE_URL/JWT_SECRET se precisar
JWT_SECRET=dev-secret bash tests/smoke_identity.sh   # fluxo completo de identity
JWT_SECRET=dev-secret bash tests/smoke_ordering.sh   # fluxo completo de checkout (semeia loja/cardápio sozinho)

cd web && npm install && npm run dev   # front-end Svelte, http://localhost:5173
```

As nove migrações foram validadas de ponta a ponta (`up` completo, `down`
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

O front-end validou o mesmo jeito, não só compilado: as 3 telas da Fase 1
conferidas com Playwright + Chromium numa janela de 430px — fade do splash,
seleção de estado com destaque e "Continuar" desabilitado até escolher, o
SweetAlert real perguntando antes da Geolocation API, o toast (sem jQuery)
confirmando o bairro achado, e o fluxo fechando com o `city_ibge_code`
certo passado adiante.

## Próximos passos (ordem sugerida pela especificação, Parte I §10)

1. **Módulo de pagamentos em PHP** — rotas com PDO, idempotência, webhooks
   do Mercado Pago, reembolso, os seis testes de concorrência da Parte I §9.
   (Nota: a cláusula zero fixa Mercado Pago, mas o gateway real em produção
   hoje — no `fuudelivery-backend` em Go — é AbacatePay; vale confirmar
   antes de integrar de verdade.)
2. **Portar o resto das telas** de `FUUDelivery - 64 Telas (offline).html`
   para Svelte + Bootstrap — só a Fase 1 (onboarding) está pronta, faltam
   14 fases — seguindo a mesma ordem de risco (dinheiro primeiro,
   conveniência depois), e ligando cada tela nova às rotas de API que já
   existem (identity, catalog, ordering).

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
