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

A **fundação de banco** (dez migrações SQL), os módulos **identity**,
**catálogo + pedido + checkout** e **descoberta** (busca de loja e
produto) em PHP sobre ela, e o **front-end em Svelte** (`web/`) cobrindo a
Fase 1 (onboarding) e a Fase 2 (home, busca, fidelidade, pedidos, perfil)
— 2 das 15 fases / 64 telas. Pagamentos, ledger, dispatch, cardápio+carrinho
(Fase 3) e o resto ainda não foram portados. Segue a ordem sugerida pela
especificação (12 semanas, Parte I §10) — catálogo/pedido vem antes de
pagamentos porque `POST /v1/orders/:id/pay` pressupõe que o pedido já
existe.

```
api/v1/auth/                módulo identity (endpoints, um arquivo por rota)
  otp_request.php             POST — pede código (login/signup/phone_verify)
  otp_verify.php               POST — confirma código, emite access+refresh
  refresh.php                  POST — rotaciona refresh, detecta reuso
  partner_login.php            POST — loja (CNPJ+senha) / entregador (CPF+código)
  consent.php                  POST — registra aceite de termo (LGPD), autenticado
api/v1/restaurants/         catálogo e descoberta (público, exceto orders.php)
  show.php                     GET  ?id= — dados da loja + horário de funcionamento
  menu.php                     GET  ?id= — cardápio disponível, com variações
  list.php                     GET  ?city_ibge_code=&category=&lat=&lng= — lojas
                                da cidade, distância real por Haversine se lat/lng vierem
  search_products.php          GET  ?city_ibge_code=&q= — busca de produto por
                                nome (índice GIN trigram), min. 2 caracteres
  orders.php                   GET  ?id= — fila do KDS (só restaurant_staff da própria loja)
api/v1/addresses/           endereços do cliente autenticado
  create.php                   POST — cadastra endereço
  list.php                     GET  — lista os do usuário logado
api/v1/orders/               pedido e checkout
  create.php                   POST — checkout: valida política/preço/loja aberta,
                                cria o pedido, avança cart → pending_payment
  show.php                     GET  ?id= — detalhe (dono ou loja do pedido, só)
  list.php                     GET  — pedidos do cliente autenticado, com nome
                                da loja e contagem de itens
  status.php                   POST — única porta pra mudar status, por cima de
                                advance_order(); autorização por papel aqui,
                                legalidade da transição só no banco
api/v1/profile/
  show.php                      GET  — usuário + estatísticas (pedidos, cupons;
                                 pontos de fidelidade fica null, ver README)
lib/                          código compartilhado entre módulos
  bootstrap.php                 carrega .env, CORS (dev), registra handler de erro, requires
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
  smoke_discovery.sh            lista por distância, filtro de categoria, busca
                                 por trigram, perfil com estatísticas reais
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

## Front-end (`web/`) — Fase 1 e Fase 2, decisões de implementação

Svelte 5 + Vite, Bootstrap 5, Bootstrap Icons, `sweetalert` (não
`sweetalert2` — o pacote `sweetalert` na versão 2.x do npm *é* a
biblioteca clássica, a mesma API `swal()` que o mock usa). Fase 1 (splash,
seleção de estado, cidade+bairro) e Fase 2 (home, busca, fidelidade,
pedidos, perfil) estão portadas; as outras 13 fases ainda não têm
componente.

```
web/
  src/
    styles/tokens.css         paleta, tipografia e forma extraídos do HTML
                               de origem (grep de #hex por frequência —
                               ver comentário no topo do arquivo)
    lib/
      api.js                      cliente fetch fino (base URL, token, erros)
      session.svelte.js            estado de sessão reativo (login/logout real)
      toastr.js                    toastr sem jQuery (ver abaixo)
      data/states.js                UFs, cidades e coordenadas de exemplo (estático)
      components/
        PhoneStatusBar.svelte          barra "9:41" que aparece em toda tela
        PhoneScreen.svelte              moldura de largura de celular
        BottomNav.svelte                5 abas (house/search/cart/star/person)
        QuickLogin.svelte               login mínimo real (ver abaixo)
      screens/
        Splash.svelte                   1.1 — fade, avança sozinho
        StateSelector.svelte            1.2 — busca + lista com contagem de lojas
        CityPicker.svelte               1.3 — busca de cidade, bairro, SweetAlert
        Home.svelte                     2.1 — categorias, lojas por distância real
        Search.svelte                   2.2 — busca de produto por trigram
        Loyalty.svelte                  2.3 — só desenho, dado de exemplo (ver abaixo)
        Orders.svelte                   2.4 — pedidos do cliente, tabs em andamento/histórico
        Profile.svelte                  2.5 — perfil, estatísticas, endereços
    App.svelte                  orquestra Fase 1 -> Fase 2 (abas + sub-telas)
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

## Como rodar localmente

```bash
docker compose up -d
export DATABASE_URL=postgres://postgres:postgres@localhost:5432/fuudelivery
bash db/migrate.sh up           # aplica as 10, em ordem
bash db/migrate.sh down 3       # reverte as 3 últimas
bash db/migrate.sh down 10      # reverte tudo

cp .env.example .env            # ajuste DATABASE_URL/JWT_SECRET/ALLOWED_ORIGIN se precisar
JWT_SECRET=dev-secret bash tests/smoke_identity.sh    # fluxo completo de identity
JWT_SECRET=dev-secret bash tests/smoke_ordering.sh    # fluxo completo de checkout (semeia loja/cardápio sozinho)
JWT_SECRET=dev-secret bash tests/smoke_discovery.sh   # lista por distância, busca, perfil (semeia sozinho)

php -S localhost:8080                  # API, num terminal
cd web && npm install && npm run dev   # front-end Svelte, noutro terminal — http://localhost:5173
```

As dez migrações foram validadas de ponta a ponta (`up` completo, `down`
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

O front-end validou o mesmo jeito, não só compilado, nas duas fases com
Playwright + Chromium numa janela de 430px: Fase 1 (fade do splash,
seleção de estado com destaque, SweetAlert real antes da Geolocation API,
toast sem jQuery) e Fase 2 (lojas ordenadas por distância de verdade,
filtro de categoria refazendo a consulta, busca de produto, login real por
OTP destravando as abas autenticadas, perfil com estatísticas reais,
navegação Perfil → Pedidos → volta).

## Próximos passos (ordem sugerida pela especificação, Parte I §10)

1. **Módulo de pagamentos em PHP** — rotas com PDO, idempotência, webhooks
   do Mercado Pago, reembolso, os seis testes de concorrência da Parte I §9.
   Gateway é Mercado Pago, decidido — o AbacatePay do `fuudelivery-backend`
   (Go) não entra neste projeto, em nenhuma hipótese (ver seção acima).
2. **Fase 3 (cardápio, item, carrinho)** — é o próximo passo natural do
   front-end: a Home (2.1) já abre um restaurante, só que hoje isso é um
   aviso ("ainda não portada") em vez de uma tela de verdade.
3. **Portar o resto das telas** de `FUUDelivery - 64 Telas (offline).html`
   para Svelte + Bootstrap — Fases 1 e 2 prontas, faltam 13 — seguindo a
   mesma ordem de risco (dinheiro primeiro, conveniência depois).
4. **Decisão de produto pendente: fidelidade/pontos.** A tela 2.3 existe
   só como desenho (dado de exemplo, sem tabela no banco). Se for pra
   valer, precisa de um ledger de pontos — mesmo padrão append-only do
   `ledger_entries` financeiro — e isso é decisão de escopo, não algo pra
   inventar numa migração de suporte a tela.

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
