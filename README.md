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

A **fundação de banco** (vinte e seis migrações SQL), a **API em PHP** sobre
ela e os **quatro apps em Svelte** (`web/`): cliente, painel da loja, app do
entregador e painel da plataforma. As 15 fases do mock estão construídas --
onboarding, descoberta, cardápio e carrinho, os cinco meios de pagamento
(Mercado Pago em modo fake), acompanhamento com mapa, conta e LGPD, PWA
offline e push, painel da loja, entregador com caixa e maquininha, livro
contábil com netting semanal, login, painel da plataforma, caminho do erro
com reembolso executado, ajuda, chat, agendamento e despacho em rodadas.
O que ficou de fora, e por quê, está em "Próximos passos" no fim deste
arquivo; cada módulo tem sua seção de decisões abaixo, na ordem em que foi
construído (as seções mais antigas registram o estado da época e apontam
pra seção que as atualizou).

```
api/v1/auth/                módulo identity (endpoints, um arquivo por rota)
  otp_request.php             POST — pede código (login/signup/phone_verify);
                                aceita channel=sms|whatsapp quando é telefone
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
  orders.php                   GET  ?id=&scope=kds|recent — fila do KDS (comanda,
                                cronômetro do status, entregador) ou "pedidos
                                recentes"; só restaurant_staff da própria loja
  approve_pix.php               POST — Fase 7.3: validação humana do Pix
                                 manual, SELECT...FOR UPDATE trava aprovação
                                 dupla, aprova/recusa chama advance_order()
  pending_proofs.php            GET  — fila de validação: comprovantes pendentes
                                 da loja com itens, endereço, nº de pedidos do
                                 cliente e se a imagem já apareceu antes
  proof_image.php               GET  ?id= — transmite o arquivo do comprovante
                                 (MIME real, private/no-store, só a dona dele)
  stats.php                     GET  — "visão geral de hoje": faturado, pedidos,
                                 Pix validados e recusados, calculados no banco
  settlements.php               GET  — baixas de espécie esperando no caixa e
                                 as do dia (tela 9.3); nunca devolve o código
  confirm_settlement.php        POST — a loja conta, digita o código e confirma;
                                 os dois lançamentos nascem na mesma transação,
                                 divergência abre ocorrência e não lança nada
  pause_status.php              GET  — tela 11.2 inteira: estado da loja, custo
                                 estimado da pausa (pedidos e faturamento por
                                 hora DESTA loja nesta faixa), horário de hoje
                                 e quanto tempo já ficou pausada
  pause.php                     POST — pausa curta (15/30/60 min, volta
                                 sozinha), "fechar por hoje" ou voltar. Motivo
                                 obrigatório; nenhum pedido muda de status
  prep_time.php                 POST — tempo de preparo informado ao cliente e
                                 o acréscimo automático por fila (11.2)
  menu_admin.php                GET  — cardápio pelos olhos da loja: inclui o
                                 indisponível e quanto cada item vendeu na
                                 semana (de order_items, não de contador)
  menu_availability.php         POST — "esgotar é um toque": só disponibilidade,
                                 com o carimbo de quando esgotou
  menu_item.php                 POST — publica item novo ou editado com suas
                                 variações (substituídas em bloco, numa
                                 transação); preço continua sendo do servidor
  hours.php                     GET  — horário da semana, feriados futuros e o
                                 histograma de pedidos por hora (11.4)
  hours_save.php                POST — salva os dois turnos para os dias do
                                 escopo escolhido numa transação só, e reavalia
                                 se a loja abre agora
  holiday.php                   POST — feriado/data especial como exceção de um
                                 dia (fechado ou faixa própria), ou remoção
api/v1/support/             central de ajuda do cliente (Fase 14.1)
  home.php                      GET  — a tela inteira: pedido em andamento
                                 como assunto, os quatro atalhos com seu SLA,
                                 os chamados de quem perguntou e o tempo médio
                                 de resposta medido das conversas reais
  answer.php                    GET  ?topic= — o "fluxo automático antes de
                                 chamar gente": lê o pedido e responde do
                                 estado dele (preparo real, comprovante na
                                 fila, estorno já registrado, 15.1 em curso)
  ticket.php                    POST — abre o chamado com prazo por categoria
                                 e deixa a primeira mensagem na conversa do
                                 pedido; apertar o mesmo atalho de novo não
                                 cria um segundo chamado
api/v1/admin/               painel da plataforma (Fase 12), role 'admin'
  guard.php                     require_admin(): a porta única do painel
  restaurants.php               GET fila de análise / POST aprova ou recusa
                                 (aprovar liga a trava de só-online por N dias)
  disputes.php                  GET fila por risco e a galeria antifraude /
                                 POST resolve com contrapartida no livro
  reports.php                   GET ?days= — GMV, mix de pagamento, custo de
                                 entrega por pedido, perda por fraude
  couriers.php                  GET fila de análise de entregador (15.2) /
                                 POST aprova (cria couriers + o login de CPF e
                                 código de acesso), recusa ou pede correção
  campaigns.php                 GET campanhas com gasto, teto e quem paga /
                                 POST cria com teto obrigatório, e com
                                 dry_run devolve só a projeção (15.3)
  policy.php                    GET política atual + histórico / POST publica
                                 uma VERSÃO NOVA (nunca edita a anterior)
api/v1/couriers/            app do entregador (Fase 8, 9 e 15.2)
  apply.php                     POST — candidatura (15.2). "A chave Pix tem
                                 que ser sua" é regra aqui: chave que é CPF ou
                                 telefone é conferida com os dados da pessoa
  application.php               GET  — a tela 15.2: documentos enviados, o que
                                 falta e o que a gente consegue (e não
                                 consegue) conferir sozinho
  apply_document.php            POST multipart — documento da candidatura, com
                                 MIME real e sha256; arquivo repetido em outra
                                 candidatura é barrado
  submit_application.php        POST — manda pra análise; exige documentos
                                 completos e grava o aceite do contrato
                                 versionado em `consents`
  me.php                        GET  — turno, saldo em espécie, teto da política
                                 e a corrida em andamento, numa chamada só
  shift.php                     POST — abre/fecha turno (um aberto por vez)
  offers.php                    GET  — corridas abertas da praça dele; some a
                                 corrida em dinheiro se o caixa está bloqueado
  accept_offer.php              POST — aceite atômico (UPDATE condicional)
  pickup.php                    POST — "cheguei"/"peguei" com GPS; devolve a
                                 comanda e o troco calculado no servidor
  deliver.php                   POST — entrega com prova (código ou foto),
                                 lança espécie e frete no livro; idempotente
  earnings.php                  GET  — o livro de lançamentos, não um resumo
  settle_intent.php             POST — intenção de baixa: código de 6 dígitos
                                 (só o hash fica), valor congelado, 10 min
api/v1/addresses/           endereços do cliente autenticado (Fase 6.1 e 14.3)
  quote.php                     GET  ?lat=&lng=|?address_id= (+restaurant_id) —
                                 área e taxa ANTES de salvar: com loja, o frete
                                 exato que o checkout vai refazer; sem loja,
                                 quantas lojas da praça alcançam aquele ponto
  create.php                   POST — cadastra endereço
  list.php                     GET  — lista os do usuário logado
  update.php                    POST — edita campos e/ou troca o padrão
                                 (desmarca os outros na mesma transação)
  delete.php                    POST — apaga; bloqueado com 409 se o
                                 endereço já foi usado num pedido (FK)
api/v1/cards/                cartões salvos via Mercado Pago (Fase 6.2)
  create.php                    POST — cria o Customer no Mercado Pago na
                                 primeira vez (users.mp_customer_id fica
                                 guardado), salva o cartão; primeiro
                                 cartão vira padrão sozinho
  list.php                      GET  — cartões do usuário logado
  update.php                     POST — só troca o padrão (o resto do
                                  cartão é imutável depois de salvo)
  delete.php                     POST — remove no Mercado Pago e no banco
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
                                legalidade da transição só no banco. Cancelar
                                ou recusar exige motivo e grava o reembolso na
                                mesma transação (Fase 13)
  messages.php                 GET  ?id= / POST — chat de três pontas (14.2);
                                mistura mensagens e eventos do pedido na mesma
                                linha do tempo, e fecha 2 h depois da entrega
  cancel_quote.php             GET  ?id= — o que acontece se desfizer agora:
                                taxa, valor de volta, canal, prazo e quem paga
                                (telas 13.1 e 13.2); só lê
  slots.php                    GET  ?restaurant_id= — as faixas de entrega dos
                                próximos dias, com vaga real; diz também
                                quando a loja não aceita agendamento (14.4)
  dispatch_status.php          GET  ?id= — a tela 15.1 inteira numa chamada:
                                há quanto tempo procura entregador, quando o
                                cancelamento automático entra, e quais saídas
                                existem PARA ESTE pedido (com o motivo escrito
                                quando alguma não está disponível); só lê
  dispatch_action.php          POST — as saídas da 15.1: 'boost' (surge no
                                pedido + bônus na oferta, só em pedido que
                                paga na entrega) e 'pickup' (cliente retira,
                                frete zera e volta). Cancelar continua em
                                status.php, a porta única
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
  update.php                    POST — completa o cadastro (tela 10.3): nome,
                                 e-mail, CPF validado e data de nascimento;
                                 409 se CPF/e-mail já for de outra conta
bin/                          processos de linha de comando (cron), não rotas
  auto_cancel_no_courier.php    varre pedidos prontos há mais tempo que o
                                 prazo da política sem entregador, cancela e
                                 devolve integral (a promessa escrita da tela
                                 15.1). Uma linha no cron, a cada minuto
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
  store.php                     tempo de preparo que o cliente vê, com o
                                 acréscimo da fila calculado na leitura (11.2)
  coupons.php                   cupons (15.3): quem paga o desconto no livro,
                                 tamanho do público e projeção com ticket real
  scheduling.php                faixas de entrega (14.4): geradas do horário
                                 da loja, reserva de vaga em delivery_slots e
                                 o horizonte de agendamento
  delivery.php                  frete e área de entrega (14.3): Haversine,
                                 tarifa da política e a cotação que o checkout
                                 refaz -- o frete deixou de vir do cliente
  support.php                   central de ajuda (14.1): SLA por categoria e
                                 os quatro fluxos automáticos, que respondem
                                 do estado real do pedido de quem perguntou
  refunds.php                   rotas de estorno por método, quem paga por
                                 causa e gravação idempotente (Fase 13)
  dispatch.php                  despacho mínimo (uma oferta por pedido pronto),
                                 o relógio de "pronto e sem ninguém pra levar"
                                 (tela 15.1), saldos do entregador e escrita
                                 no livro
  pix.php                         gera o Pix "copia e cola" (BR Code/EMV) —
                                   CRC16 conferido contra o vetor de teste
                                   padrão do algoritmo antes de entrar em uso
  mercadopago.php                 cliente HTTP da Payments API do Mercado
                                   Pago (cartão, Pix, Customer/Cards pra
                                   cartão salvo), com modo "fake" pra rodar
                                   sem conta sandbox real (ver README)
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
  smoke_account.sh              CRUD de endereços (troca de padrão, bloqueio
                                 de apagar endereço em uso) e cartões salvos
                                 (mp_customer_id reaproveitado entre
                                 cartões, troca de padrão, remoção)
  smoke_admin.sh                painel da plataforma: aprovação com trava de
                                 só-online, ocorrências com contrapartida no
                                 livro, relatórios e política versionada
  smoke_support.sh              chat de três pontas (quem entra, o fechamento
                                 2 h depois da entrega) e cupom: mínimo,
                                 orçamento e um uso por CPF
  smoke_courier.sh              entregador e caixa: turno, aceite disputado,
                                 troco do servidor, entrega com prova, livro
                                 de lançamentos e baixa de espécie na loja
  smoke_cancel.sh               caminho do erro: cancelamento com e sem taxa,
                                 recusa da loja, reembolso por método (canal,
                                 valor e quem paga) e isolamento por dono
  smoke_panel.sh                painel da loja: fila de validação de Pix,
                                 imagem do comprovante com autorização,
                                 resumo do dia, KDS e as transições da loja
                                 (aceitar, pronto, entregue ao motoboy),
                                 mais o isolamento entre lojas
  smoke_rounds.sh               despacho em rodadas (Fase 15): raio que
                                 cresce, quem vê a corrida pela posição, GPS
                                 desligado sem prioridade, surge somado só
                                 pela diferença e pago ao entregador
  smoke_money.sh                executor de estornos (automático, falha,
                                 retentativa, Pix manual com referência),
                                 CSV contábil, foto privada da ocorrência,
                                 o livro do pedido (espécie e cartão) e a
                                 maquininha do próprio entregador
  smoke_machine.sh              maquininha e fechamento (9.6, 9.7, 10.4,
                                 10.6): custódia com duas pontas, NSU x
                                 extrato, divergência virando ocorrência,
                                 netting pelo livro, baixa como contrapartida
                                 e os bloqueios automáticos
  smoke_incident.sh             ocorrência na entrega (13.3) e console de
                                 reembolso (13.4): prova obrigatória, os 10
                                 min como regra do servidor, corrida garantida
                                 no livro, fila por método, ajuste da taxa e
                                 crédito em carteira que ninguém impõe
  smoke_growth.sh               entrada de entregador (15.2) e campanhas
                                 (15.3): chave Pix de outro recusada, fila de
                                 análise, aprovação criando entregador e
                                 login, teto que desativa o cupom sozinho e o
                                 livro com quem pagou o desconto
  smoke_schedule.sh             pedido agendado (14.4): faixas nascidas do
                                 horário, vaga limitada pelo CHECK do banco,
                                 horizonte de 4 dias no servidor e a cozinha
                                 sem ver o pedido antes da hora
  smoke_address.sh              endereço com área e taxa (14.3): referência,
                                 cobertura no servidor, frete calculado (e o
                                 cliente não conseguindo forjar frete grátis),
                                 tarifa versionada e snapshot congelado
  smoke_help.sh                 central de ajuda (14.1): os quatro atalhos
                                 respondidos do estado real do pedido, chamado
                                 com prazo por categoria, sem duplicar e sem
                                 vazar pedido alheio
  smoke_store.sh                a loja operando a si mesma (11.2 a 11.4):
                                 pausa com motivo e volta automática, tempo
                                 de preparo, esgotar/publicar item, horário
                                 por escopo, feriado e o job de abrir/fechar
  smoke_dispatch.sh             pedido pronto sem entregador (15.1): relógio,
                                 turbo chegando na oferta, turbo negado em
                                 pedido pago, retirada com devolução parcial,
                                 cancelamento integral por falha de despacho e
                                 a varredura do auto-cancel
  support/random_cnpj.php       CNPJ aleatório com dígito verificador válido,
                                 pra seed de teste não colidir entre scripts
  support/random_cpf.php        idem pra CPF (users.cpf é UNIQUE)
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
    023_refund_execution_own_pos.up.sql / .down.sql  execução de estorno
                                           (provider_ref, tentativas, erro) e
                                           maquininha com dono entregador
    022_acquirer_statement.up.sql / .down.sql  acquirer_statements, o registro
                                           de cada extrato importado (tela 9.6)
    021_incident_refund.up.sql / .down.sql  delivery_attempts, wallet_credits
                                           e refunds.fee/note/decided_at
                                           (telas 13.3 e 13.4)
    020_scheduling.up.sql / .down.sql     restaurants.slot_capacity e o índice
                                           dos pedidos agendados (14.4)
    019_delivery_area.up.sql / .down.sql  addresses.reference e a tarifa de
                                           entrega na política (14.3)
    018_store_ops.up.sql / .down.sql      store_pauses, holiday_overrides,
                                           restaurants.prep_minutes e o job
                                           que abre/fecha a loja (11.2 a 11.4)
    017_no_courier.up.sql / .down.sql     orders.pickup_by_customer +
                                           no_courier_since e a transição
                                           ('ready','cancelled') (tela 15.1)
    016_admin.up.sql / .down.sql          disputes sem pedido obrigatório,
                                           restaurants.rejected_at (tela 12.1)
    015_delivery_proof.up.sql / .down.sql orders.delivery_code + delivery_proofs
    014_cancellation.up.sql / .down.sql   platform_policies.cancel_fee (tela 13.1)
    013_signup_profile.up.sql / .down.sql users.birth_date (opcional da tela 10.3)
    012_saved_cards.up.sql / .down.sql   saved_cards (Fase 6.2) +
                                          users.mp_customer_id -- também
                                          fora das 42 originais
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

- **JWT escrito à mão** (`lib/core/jwt.php`, HS256), em vez de uma biblioteca via
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
  `lib/catalog/policy.php`.** Só `scope='restaurant'` é aplicado — um override por
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
  policies existem no banco, mas a conexão PHP de `lib/core/db.php` usa o que
  `DATABASE_URL` apontar — em dev/CI isso é o superusuário `postgres`, que
  ignora RLS por padrão (é dono das tabelas). A autorização de acesso a
  pedido hoje é feita inteiramente em `lib/ordering/orders.php`
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
  filtros do mock (Entrega grátis / Até 30 min / 4,5+) passaram a filtrar
  de verdade -- ver "Lacunas do app do cliente fechadas".

## Módulo de carrinho — decisões de implementação

A Fase 3 revelou que `orders/create.php` (checkout de um passo só, feito
para o módulo catalog+ordering) não é como a tela realmente funciona: o
mock é item por item, com `toastr` confirmando cada adição, e "o carrinho
vive num store Svelte e é espelhado no PostgreSQL como pedido em
status='cart'". Os dois modelos convivem — nenhum substituiu o outro.

- **`price_line()` foi extraída pra `lib/ordering/cart.php` e reaproveitada em
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
  (`lib/core/auth_guard.php`) aceita `?token=` só aqui; toda outra rota continua
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

## Módulo de conta — decisões de implementação

Fase 6 do mock (endereços, cartões, configurações), a parte que é backend
puro: CRUD de endereços completo e cartão salvo via Mercado Pago.

- **Endereços ganharam `update.php`/`delete.php`** — só existiam
  `create`/`list` desde o módulo catalog+ordering. Trocar de padrão
  desmarca os outros do mesmo usuário na mesma transação (não existe
  `UNIQUE` parcial no banco garantindo "só um padrão"; é regra de
  aplicação, testada no smoke test). Apagar um endereço já usado num
  pedido é bloqueado pela própria FK (`orders.address_id` não tem `ON
  DELETE`) — capturado e devolvido como 409, não como 500 cru.
- **Taxa de entrega por endereço não é mostrada em lugar nenhum desta
  tela** — mesmo achado documentado em `PaymentSelector.svelte`
  (Fase 4.1): não existe cálculo de frete por bairro/distância neste
  backend (`orders/checkout.php` recebe `delivery_fee` do corpo da
  requisição, não calcula). O mock mostra "Taxa R$ 6,90 · 25–35 min" por
  endereço; inventar esse número aqui seria fabricar um dado que o
  sistema não sustenta. Registrado em "Próximos passos".
- **Cartão salvo usa o modelo real do Mercado Pago: Customer → Cards.**
  Um Customer por usuário (`users.mp_customer_id`, criado na primeira vez
  que alguém salva um cartão), N cartões por Customer — sem guardar esse
  id, cada cartão novo criaria um Customer à toa. `lib/payments/mercadopago.php`
  ganhou `mp_create_customer()`/`mp_create_card()`/`mp_delete_card()`,
  seguindo o mesmo contrato documentado da API real, com o mesmo modo
  fake já usado pelo módulo de pagamentos (sem conta sandbox neste
  ambiente).
- **Bandeira em modo fake é um palpite, nunca usado pra cobrança.** Sem
  base de BIN neste ambiente, `mp_fake_create_card()` só olha o primeiro
  dígito do token-placeholder (5→Mastercard, 4→Visa, resto→Elo) —
  cosmético, documentado no código; em produção quem decide a bandeira de
  verdade é o Mercado Pago, a partir do token real do SDK.
- **`cards/update.php` só aceita `is_default`.** Os outros campos de um
  cartão salvo (bandeira, últimos 4 dígitos, validade) vêm do Mercado Pago
  no momento da criação — não existe "editar cartão" de verdade, e deixar
  o endpoint aceitar isso silenciosamente criaria um cartão salvo com
  dados que não batem com o que o Mercado Pago realmente tem.
- **Validado contra Postgres e PHP reais.** `tests/smoke_account.sh` cobre
  os dois endereços com troca de padrão, edição, e o bloqueio de apagar
  endereço em uso; dois cartões com o mesmo `mp_customer_id` reaproveitado
  (confirmado por query direta no banco, não só pela resposta da API),
  troca de padrão e remoção.

## Front-end (`web/`) — Fases 1 a 6, 7.1, 8, 9, 10, 12, 13, 14, 15.1, decisões

Svelte 5 + Vite, Bootstrap 5, Bootstrap Icons, `sweetalert` (não
`sweetalert2` — o pacote `sweetalert` na versão 2.x do npm *é* a
biblioteca clássica, a mesma API `swal()` que o mock usa). Fase 1 (splash,
seleção de estado, cidade+bairro), Fase 2 (home, busca, fidelidade,
pedidos, perfil), Fase 3 (loja, item, carrinho), Fase 4 (pagamento), Fase 5
(pós-pedido), Fase 6 (conta, endereços, cartões, configurações) e Fase 10.1
a 10.3 (login e cadastro) e Fase 13.1/13.2 (cancelar e recusar) estão
portadas, mais o PWA com modo offline (7.1), o chat do pedido (14.2), o
painel da loja (Fase 7.3, 9.3 e 11.1, em `painel.html`), o app do entregador
(Fase 8 e 9, em `entregador.html`) e o painel da plataforma (Fase 12 e tela
10.5, em `admin.html`); todos têm seção própria adiante. As outras fases
ainda não têm componente.

```
web/
  public/
    manifest.webmanifest          7.1 — standalone, tema #CC2B1D, ícones 192/512
    sw.js                         7.1 — cache do shell e das rotas públicas;
                                   nada autenticado, nada que não seja GET
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
      pwa.svelte.js                 7.1 — registra o service worker, guarda o
                                     convite de instalação, sabe se está online
      components/
        PhoneStatusBar.svelte          barra "9:41" que aparece em toda tela
        PhoneScreen.svelte              moldura de largura de celular
        BottomNav.svelte                5 abas (house/search/cart/star/person)
        QuickAddress.svelte             endereço mínimo real (ver Fase 4 abaixo)
        ItemModal.svelte                3.2 — variações, observação, preço ao vivo
        OrderChat.svelte                14.2 — chat de três pontas (cliente e loja)
      screens/
        AuthFlow.svelte                 orquestra a Fase 10: 10.1 -> 10.2 -> 10.3
        ScheduleScreen.svelte           14.4 — "quando você quer receber?":
                                         faixa com vaga real, antes da escolha
                                         do pagamento
        HelpScreen.svelte               14.1 — central de ajuda: assunto é o
                                         pedido de agora, e cada atalho
                                         responde antes de abrir chamado
        CancelDialog.svelte             13.1 — taxa e estorno antes de confirmar
                                         (e a devolução integral da 15.1)
        NoCourierPanel.svelte           15.1 — pronto e sem entregador: relógio,
                                         turbo, retirada e cancelar com tudo de
                                         volta; vive dentro de OrderTracking
        LoginScreen.svelte              10.1 — telefone ou e-mail, código de uso único
        OtpScreen.svelte                10.2 — seis caixas, reenvio, WhatsApp
        SignupScreen.svelte             10.3 — cadastro com base legal por bloco
        Splash.svelte                   1.1 — fade, avança sozinho (pulado quando
                                         a praça já está salva)
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
        AddressesScreen.svelte          6.1 — CRUD completo, CEP real (ViaCEP),
                                         padrão, bloqueio de apagar em uso
        PaymentMethods.svelte           6.2 — cartões salvos via Mercado Pago
        SettingsScreen.svelte           6.3 — notificações por tipo (localStorage)
        panel/                        painel da loja (entrada painel.html)
          StaffLogin.svelte             10.7 — CNPJ + senha, 2FA por aparelho
          ProofQueue.svelte             7.3 — fila de validação, mais urgente no topo
          ProofReviewModal.svelte       7.3 — comprovante à esquerda (zoom/rotação),
                                         pedido à direita, aprovar ou recusar
          KdsBoard.svelte               11.1 — três colunas, cronômetro por pedido,
                                         fila de Pix fixa no canto
          RejectDialog.svelte           13.2 — recusar mostrando o custo real
          CashDesk.svelte               9.3 — conferir e confirmar a baixa de espécie
        admin/                        painel da plataforma (entrada admin.html)
          AdminLogin.svelte             login por OTP (admin é pessoa, não aparelho)
          StoreQueue.svelte             12.1 — aprovar/recusar cadastro de loja
          DisputeQueue.svelte           12.2 — ocorrências e galeria antifraude
          ReportsScreen.svelte          12.3 — os números que mudam decisão
          PolicyScreen.svelte           10.5 — política versionada da plataforma
        courier/                      app do entregador (entrada entregador.html)
          CourierLogin.svelte           10.7 — CPF + código, 2FA por aparelho
          CourierHome.svelte            8.1 — turno, saldo em espécie, teto
          OfferList.svelte              8.2 — oferta com ganho, distância e troco
          RideScreen.svelte             8.3 a 8.6 — coleta, entrega e prova
          EarningsScreen.svelte         8.7 — livro de lançamentos
          SettleScreen.svelte           9.1/9.2/9.4 — baixa de espécie
          PanelOverview.svelte          7.3 — visão geral de hoje + pedidos recentes
    App.svelte                  orquestra Fase 1 -> Fase 2 (abas, e Fase 6
                                 como pseudo-abas dentro do mesmo shell) ->
                                 Fase 3/4/5 (tela cheia por cima das abas)
    Panel.svelte                raiz do painel da loja: login, abas
                                 Cozinha/Visão geral/Caixa, atualização periódica
    Courier.svelte              raiz do app do entregador: login, corrida em
                                 andamento, abas Corridas/Ganhos
    Admin.svelte                raiz do painel da plataforma: login por OTP e
                                 abas Lojas/Ocorrências/Relatórios/Políticas
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
- **Login é o `AuthFlow` da Fase 10** (telas 10.1, 10.2 e 10.3, seção
  própria abaixo). Ele fecha as três abas que precisam de usuário
  autenticado (fidelidade, pedidos, perfil) e também é o que aparece dentro
  do modal de item (3.2) quando alguém tenta montar um carrinho sem conta.
  O `QuickLogin.svelte` provisório foi removido no mesmo commit.
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
- **Pix copia-e-cola (BR Code) veio de `lib/payments/pix.php` de verdade** — não é
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
- **Mapa e localização do entregador** eram um placeholder enquanto não
  havia quem escrevesse em `courier_positions`; hoje são o `DeliveryMap`
  com Leaflet -- ver "Lacunas do app do cliente fechadas".
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

**Fase 6 — decisões adicionais:**

- **Endereços/cartões/configurações entram como pseudo-abas dentro do
  mesmo `app-shell`**, não como uma pilha própria tipo `RestaurantPage`/
  `PaymentFlow` — mesmo padrão que `Orders.svelte` (Fase 2.4) já usava
  (`tab = 'orders'` sem estar na barra inferior). Simples, e consistente
  com o que já existia.
- **Busca por CEP é uma chamada real pro ViaCEP** (API pública,
  gratuita, sem chave) — não um mock. Limite honesto: este ambiente de
  desenvolvimento bloqueia tráfego de saída pra hosts fora da allowlist
  do proxy, então só o caminho de FALHA foi testável aqui (a chamada
  falha, cai de volta pro preenchimento manual, sem travar a tela). O
  caminho de sucesso não pôde ser validado neste ambiente especificamente
  — o contrato da API é público e estável, não é algo inventado, mas fica
  registrado que não foi visto funcionando de ponta a ponta nesta sessão.
- **Endereço padrão e cartão padrão usam o mesmo padrão de UI**: card
  com badge "PADRÃO" pros outros, botão "Tornar padrão" pro resto — o
  botão chama `addresses/update.php`/`cards/update.php` com só
  `is_default: true`, o backend cuida de desmarcar o resto.
- **Apagar endereço em uso mostra a mensagem certa, não um erro genérico**
  — `AddressesScreen.svelte` reconhece especificamente o código
  `address_in_use` (409) e troca a mensagem por algo que explica o motivo
  (endereço já usado num pedido), em vez de deixar o texto cru do backend
  ou um "erro desconhecido".
- **Configurações são preferência real de aparelho (localStorage), não
  decorativas** — os três toggles de notificação persistem entre reloads
  deste navegador. Não viram push de verdade ainda (Fase 7.2 não
  construída), mas o formato já é o que o worker de push vai precisar
  checar quando existir.
- **Validado com Playwright de ponta a ponta, contra o backend real, zero
  erros de console**: dois endereços criados → trocar padrão → editar
  complemento → apagar bloqueado (coberto pelo smoke test, não repetido
  aqui) → dois cartões salvos (Mastercard e Visa pelo heurístico de
  bandeira) → trocar padrão → configurações com os três toggles reais,
  incluindo o de promoções ligado manualmente e persistido.

## Login e cadastro (Fase 10.1 a 10.3) — decisões de implementação

O acesso do cliente saiu do provisório. Até aqui havia um `QuickLogin` de
três campos com um selo "login provisório"; agora são as três telas
desenhadas, com o mesmo backend de sempre (o módulo identity nunca foi
mock).

- **Um formulário só serve pra entrar e pra criar conta.** Quem digita um
  telefone sem conta recebe `user_not_found` do servidor, e é aí que o
  campo de nome aparece. Ninguém precisa escolher "entrar ou cadastrar"
  antes de digitar nada: quem sabe a resposta é o banco, não a tela.
- **O cadastro (10.3) é um passo do fluxo, não uma tela solta.** Ele só
  existe depois do OTP -- a conta já foi criada com nome e telefone
  verificados, e o que falta é CPF, e-mail e consentimentos. Quem já tinha
  conta entra direto; pedir CPF a cada login seria pedir o mesmo dado duas
  vezes, o oposto do "mínimo necessário" que a tela promete.
- **"Conta recém-criada" é estado de sessão, não de tela.** Essa foi a
  correção de um bug real que o Playwright pegou: com a condição escrita
  como "não autenticado", a aba trocava o fluxo pela tela dela no instante
  em que o token chegava, e o cadastro nunca aparecia. Agora
  `session.svelte.js` guarda `pendingSignup`, ligado no `verifyOtp` com
  `purpose=signup` e desligado quando o cadastro termina -- a aba e o modal
  de item leem o mesmo sinal.
- **Os três blocos de 10.3 são bases legais diferentes, e isso muda onde o
  dado é gravado.** CPF vai pra `users` (obrigação legal, nota fiscal);
  marketing e data de nascimento são consentimento, então viram registros
  próprios em `consents` -- versão e IP em cada um. Aceitar os termos não
  liga marketing junto: são três chamadas distintas a `consent.php`, que é
  exatamente o que a tela promete ("sem consentimento embutido em aceite de
  termos").
- **`users.birth_date` é a única coluna nova** (migração `013`). Anulável
  de propósito: quem não consente não preenche, e a ausência é a resposta
  certa, não um valor padrão.
- **CPF é validado no servidor, com dígito verificador** (`is_valid_cpf`,
  que já existia) e é `UNIQUE` em `users` -- CPF de outra conta devolve 409
  com a saída possível ("entre com ela"), não 500 cru.
- **"Receber por WhatsApp" é um canal de verdade**, não um link decorativo:
  `otp_codes.channel` já previa `whatsapp` no enum desde a migração `001`,
  e `otp_request.php` passou a aceitar `channel`. O envio em si continua
  sendo integração externa (hoje um `error_log`) em qualquer canal -- o que
  o teste prova é que o canal pedido é o canal gravado. A "ligação
  automática" que o mock também cita ficou de fora: o enum não prevê esse
  canal, e inventar valor de enum pra caber numa tela é a ordem errada.
- **Google e Apple aparecem desabilitados, com "em breve".** Não existe
  OAuth neste backend nem tabela de identidade federada. Mesma escolha já
  feita com o BitPay na tela 4.1: melhor um botão que diz o que é do que um
  botão que não faz nada.
- **As seis caixas do código são um input só.** Um campo por dígito quebra
  colar o código, o preenchimento automático do SMS e o apagar pra trás. O
  que se vê são seis caixas desenhadas sobre um input transparente com
  `autocomplete="one-time-code"` -- o navegador continua tratando como um
  campo de 6 dígitos.
- **A tela de código não inventa o prazo.** O texto diz o que o servidor
  realmente faz (5 minutos, 5 tentativas) e o contador de reenvio é local,
  mas quem bloqueia é o backend: código errado zera as caixas e mostra a
  mensagem que veio de lá, incluindo quantas tentativas restam.
- **Validado com Postgres e navegador reais.** `tests/smoke_identity.sh`
  ganhou o cadastro completo (CPF gravado, conferido por query direta),
  CPF inválido barrado, data no futuro barrada, CPF de outra conta em 409 e
  o canal WhatsApp indo parar em `otp_codes.channel`. No Playwright, numa
  janela de 430px: alternar telefone/e-mail, máscara de telefone, o pivô
  pra cadastro vindo do servidor, código errado rejeitado, cadastro com os
  dois opcionais marcados, e depois sair e entrar de novo caindo direto no
  perfil -- sem repetir o cadastro. As únicas respostas não-200 no console
  são os três erros que o próprio teste provoca.

## Caminho do erro (Fase 13.1 e 13.2) — decisões de implementação

"O buraco mais comum em app de delivery." Até aqui um pedido só avançava; o
que acontece quando alguém desiste — e para onde vai o dinheiro — não existia
em lugar nenhum além da tabela `refunds`, vazia desde a migração `005`.

- **`lib/payments/refunds.php` é a tabela da tela 13.4 escrita em código.** Por onde o
  dinheiro volta em cada forma de pagamento (cartão → `gateway`, Pix →
  `pix_return`, maquininha → `acquirer_void`, dinheiro → `none`), em quanto
  tempo, e quem arca. Os canais são os do `CHECK` de `refunds.channel`, não
  uma lista nova inventada pra caber na tela.
- **A taxa de cancelamento virou política, não número mágico.** Migração
  `014` adiciona `platform_policies.cancel_fee`, junto de teto de espécie,
  comissão e prazo de repasse — versionada e auditável igual ao resto. O
  padrão é **0**: o mock mostra R$ 15,00 num pedido de R$ 78,40, mas isso é
  exemplo de desenho, não regra aprovada, e cobrar do cliente um valor que
  ninguém autorizou seria pior que não cobrar.
- **A taxa vale a do dia do pedido.** `cancel_fee` entra em
  `orders.policy_snapshot` no checkout e é de lá que o cancelamento lê
  (`policy_for_order()`): mudar a taxa hoje não encarece o cancelamento de um
  pedido feito ontem. Pedido anterior à migração cai na política corrente.
- **Quando a taxa existe é regra de código, não de configuração:** livre
  antes de a cozinha começar (`pending_payment`, `pending_verification`,
  `paid`), cobrada de `preparing` em diante — e só quando quem desiste é o
  cliente. Loja recusando e falha nossa nunca cobram taxa de ninguém.
- **A cotação e a execução usam a mesma função.** `orders/cancel_quote.php`
  só lê e devolve o que a tela mostra antes do botão vermelho; `status.php`
  recalcula com a mesma `refund_plan()` na hora de gravar. Se a tela
  calculasse por conta própria, o cliente veria um valor e receberia outro --
  que é exatamente o que a tela 13.1 existe pra evitar.
- **Desfazer pedido e decidir o dinheiro acontecem na mesma transação.** Um
  pedido cancelado sem o reembolso registrado junto é o estado que trava
  reembolso por dias (tela 13.4). `status.php` abre transação, grava o
  motivo, chama `advance_order()` e insere em `refunds` — tudo ou nada.
- **Motivo passou a ser obrigatório pra cancelar ou recusar** (422
  `reason_required`). Não é burocracia: é ele que alimenta o ranking da loja
  e, do lado da loja, é o texto que o cliente lê.
- **Estorno de zero não é linha na tabela.** `refunds.amount` tem
  `CHECK (amount > 0)`, então pedido em dinheiro (nada foi cobrado) cancela
  sem criar reembolso nenhum. A compensação do entregador que já se deslocou
  é lançamento de `ledger_entries` — Fase 9, não construída.
- **A taxa de recusa da loja conta o ATO, não o status final.** Pedido já em
  preparo não pode ir pra `rejected` (a função do banco não permite essa
  transição), vai pra `cancelled`. Contar por status deixaria a taxa presa em
  zero justamente nas recusas que mais doem — então ela sai de
  `order_events.actor_kind = 'store'`.
- **O que o mock tem e a tela 13.2 não tem: "sugerir substituição".** Depende
  de um canal pro cliente responder, que é a Fase 14 (suporte) e não existe.
  Uma caixa de texto que não chega em ninguém seria pior que a ausência.
- **Dois defeitos reais que o navegador achou aqui:**
  1. **Colisão de classe com o Bootstrap, de novo.** `.row` do Bootstrap
     força `width: 100%` nos filhos, então toda linha "rótulo à esquerda,
     valor à direita" empilhava. Estava assim desde a Fase 5 no cartão de
     resumo do acompanhamento, sem ninguém notar. As quatro telas que usavam
     `.row` passaram a usar `.kv`. (O primeiro caso dessa família foi
     `.modal`, documentado em "Módulo de carrinho".)
  2. **O SSE do acompanhamento travava a própria tela.** Abrir o
     cancelamento com o stream aberto fazia a cotação demorar **23,5 s**
     medidos: `php -S` atende uma requisição por vez e o stream segura o
     processo pela janela inteira. Duas correções, as duas boas por si só —
     o modal fecha o `EventSource` enquanto está aberto (não há o que
     atualizar atrás dele), e o heartbeat do stream caiu de 8 s pra 2 s,
     porque é a escrita dele que faz o `connection_aborted()` do PHP
     perceber que o cliente foi embora. Ficou em **1,3 s**.
- **Validado com Postgres e navegador reais.** `tests/smoke_cancel.sh` cobre
  os quatro métodos de pagamento, cancelamento com e sem taxa, recusa da
  loja, o pagamento virando `refunded`, reembolso não duplicado e pedido de
  outra pessoa (404, que não conta nem que existe). No Playwright: o cliente
  abre o acompanhamento, cancela escolhendo motivo e vê o pedido virar
  "Cancelado"; a loja recusa pelo KDS com o custo na tela (estorno,
  entregador, taxa de recusa real). Zero erros de console.

## Ocorrência na entrega e console de reembolso (Fase 13.3 e 13.4) — decisões

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

## Maquininha, conciliação e netting semanal (9.6, 9.7, 10.4, 10.6) — decisões

Servidor e telas construídos. Painel da loja: abas **Pagamentos** (10.4) e
**Conciliação** (9.6); app do entregador: **Maquininha** (10.6) e o campo de
NSU na entrega de pedido de maquininha; painel da plataforma: **Financeiro**
(9.7). O servidor roda contra Postgres real em `tests/smoke_machine.sh`; as
telas foram percorridas no Playwright de ponta a ponta (retirar, vender sem
NSU, completar NSU, devolver, a loja confirmar, importar extrato com
divergência, gerar lote e dar baixa), sem erro de console.

- **Custódia de dois lados de verdade.** A primeira versão fechava a
  custódia no "devolvi" do entregador — e o navegador mostrou o efeito: ela
  sumia das duas telas e a loja nunca via o botão de confirmar. Agora a
  custódia fica aberta até `confirmed_by`; a máquina só sai de novo depois da
  conferência no balcão (`device_taken`), e o entregador que já devolveu pode
  retirar outra enquanto isso.
- **Completar o NSU atualiza a mesma venda**, não cria uma segunda; NSU de
  outra venda é recusado (`nsu_already_used`, a `UNIQUE (acquirer, nsu)`).

- **"A máquina é do estabelecimento" (10.4) é a regra de contabilidade.**
  Venda na maquininha NÃO lança `courier_cash`: o dinheiro cai na adquirente
  da loja, e o entregador "não deve nada por essas vendas. Só o equipamento
  e os NSUs." Espécie vira dívida; maquininha vira conferência.
- **O esquema já existia quase todo** (`card_transactions`, `pos_devices`,
  `pos_custody`, `payouts`, `restaurant_payment_settings`, e na política
  `cash_ceiling`, `cash_settle_deadline`, `store_debit_dow`,
  `pos_return_deadline`, `allow_courier_own_pos`). A migração `022` cria só
  `acquirer_statements`: a memória de cada extrato importado ("Último
  extrato: 15/09 23:58 · 17 transações"), com `sha256` pra o mesmo arquivo
  não entrar duas vezes.
- **10.4 — formas de pagamento da loja** (`restaurants/payment_settings.php`):
  cada método vem com a consequência operacional escrita pelo servidor; o
  teto de dinheiro da loja não passa do teto da plataforma; loja com
  `online_only_until` vigente só enxerga e só consegue ligar as formas
  online. `restaurants/pos_devices.php` cadastra, desativa (nunca máquina na
  rua) e **confirma a devolução** — a segunda ponta da custódia.
- **10.6 — custódia** (`couriers/pos.php`): retirar grava posse com prazo da
  política; `pos_one_holder` impede a mesma máquina com duas pessoas, e quem
  já está com uma não pega outra. A venda é conferida contra o total do
  pedido na hora (`amount_mismatch`), e venda sem NSU entra mesmo assim —
  ela existiu, e é justamente ela que trava o fechamento do dia. Devolver
  não fecha sozinho: a loja confirma.
- **9.6 — conciliação** (`restaurants/reconciliation.php`): importa o CSV da
  adquirente e casa por NSU, com fallback valor+horário (janela de 30 min, só
  em linha ainda pendente). Divergência vira `disputes` com kind
  `nsu_divergent` — a fila do admin que já existe — e o dia só fecha sem
  nenhuma linha aberta. Linha do extrato sem venda informada fica como órfã:
  não se inventa venda. **Não há API de adquirente** ("conecte a API" da
  tela); o CSV é o caminho real.
- **9.7 — netting** (`admin/netting.php`): a coluna "a cobrar" é
  `store_receivable` da semana, direto do livro; "taxa já split" (cartão e Pix
  automático) é separada de "taxa em aberto" pra não cobrar duas vezes. Gerar
  usa a mesma `generate_weekly_payouts()` do pg_cron (idempotente). "Gerar
  lote de Pix" marca `sent` — **não há Pix em lote integrado**, a
  transferência é feita no banco com a lista. Baixa é **lançamento negativo
  de contrapartida** com origem `payout`, nunca edição, e quitar o débito
  tira a loja da trava de só-online.
- **Bloqueios automáticos** (`bin/apply_financial_blocks.php`, cron de hora
  em hora): `couriers.cash_blocked` e `restaurants.online_only_until` eram
  lidos por todo mundo e **escritos por ninguém**. A varredura liga e desliga
  os dois a partir da política — espécie acima do teto ou mais velha que o
  prazo de baixa; débito semanal vencido. Em PHP pelo mesmo motivo do
  auto-cancel da 15.1: a interpretação da política mora aqui.
- **Não modelado:** maquininha do próprio entregador. A política tem
  `allow_courier_own_pos`, mas `pos_devices` só pertence a loja; cadastrar
  máquina de entregador pede decisão de esquema, não um campo improvisado.

## Despacho em rodadas (Fase 15) — decisões de implementação

- **O despacho era uma rodada só**, sem raio: todo entregador da praça via
  toda corrida. Agora a oferta sobe de rodada enquanto ninguém aceita
  (`DISPATCH_ROUNDS` em `lib/dispatch/dispatch.php`): 2 km → 4 km → 7 km com R$ 2 de
  surge → praça inteira com R$ 4. **Os números são parâmetros de operação,
  não do mock** — a especificação pede rodada, raio e surge sem fixar
  valores; ficam num lugar só pra ajustar.
- **`dispatch_attempts` finalmente recebe linha** (existia desde a migração
  007): uma por rodada, com raio, quantos candidatos havia dentro dele e o
  surge. Rodada pulada (ninguém perguntou durante uma janela) entra também —
  o registro é "por que não achou", e apagar o raio intermediário apagaria
  parte da resposta.
- **Posição do entregador** — `couriers/position.php`, UPSERT em
  `courier_positions` (UNLOGGED, migração 009) a cada 15 s, como a
  especificação manda; só com turno aberto. O app manda sem travar nada: se o
  GPS não responde, é fogo-e-esquece.
- **Quem vê a corrida** é quem está dentro do raio da rodada, medido da loja
  até a última posição. **Sem posição recente, só na última rodada** —
  desligar o GPS não pode dar prioridade sobre quem está perto.
- **Surge soma só a diferença** entre rodadas, por cima do turbo que o
  cliente pagou (tela 15.1), que não é tocado.
- **O bônus agora é pago.** Até aqui o turbo entrava na oferta e no total,
  mas a entrega só lançava o frete: o bônus ia pra lugar nenhum. Na entrega,
  `courier_payable` recebe o bônus inteiro, e a parte que o cliente não
  pagou (o surge) é `platform_expense`.
- **Quem move as rodadas:** a vitrine de ofertas avança na passagem (o app
  pergunta a cada 4 s), a tela do cliente também, e `bin/dispatch_rounds.php`
  no cron de minuto cobre a hora em que ninguém está perguntando.
- **A tela 15.1 do cliente** passou a dizer em que raio a busca está e se
  já há bônus pago por nós — o que é verdade, em vez de "aguarde".

## Pontas de dinheiro: livro do pedido, estornos executados, CSV — decisões

- **O livro não tinha a linha principal.** `store_receivable` se mexia em
  cupom, estorno, ocorrência e baixa, mas nenhum pedido entregue dizia quanto
  a loja tinha a receber ou a pagar — a coluna "a cobrar" da 9.7 somava um
  livro incompleto. `lib/ledger/order_ledger.php` lança, na mesma transação da
  entrega (e da retirada no balcão), o acerto do pedido: **a plataforma passa
  a dever à loja a parte dela (subtotal − comissão); quem entregar o dinheiro
  à loja abate essa dívida.** Cartão e Pix automático: −parte (repasse).
  Pix manual e maquininha da loja: +(total − parte), porque o dinheiro caiu na
  conta dela. Dinheiro e maquininha do entregador: −parte na entrega, e a
  baixa no balcão lança +total. Depois da baixa sobra exatamente comissão +
  frete + gorjeta — o teste confere essa igualdade.
- **Correção de sinal na baixa de espécie (9.3).** A versão anterior lançava
  `store_receivable −total` na baixa e nada na entrega: o saldo da loja
  ficava negativo pra sempre. Pela frase da tela ("o dinheiro do pedido em
  espécie é seu — o entregador é apenas portador"), a baixa PAGA a parte da
  loja: `+total`.
- **A gorjeta agora vai pro entregador** (`courier_payable`, origem
  `tip:<pedido>`). Antes ela entrava no total e não saía pra ninguém.
- **Estorno de pedido nunca entregue não cobra a loja.** `refund_ledger`
  cobrava a loja pelo valor inteiro do estorno mesmo quando ela nunca tinha
  recebido nada. Agora depende de duas perguntas — o pedido foi entregue? o
  dinheiro está com quem? Antes da entrega, o custo real é a taxa ("fica com
  a loja") e a comida já feita ("FUUDelivery paga tudo, inclusive a comida
  produzida", quando o pagador inclui a plataforma e a cozinha tinha
  começado). O teste da 13.4 que esperava a loja pagando R$ 66 de um pedido
  cancelado em preparo foi corrigido com a explicação.
- **Executor de estornos** (`lib/payments/refund_executor.php` +
  `bin/execute_refunds.php`, cron a cada minuto): cartão e Pix automático vão
  pra `POST /v1/payments/{id}/refunds` do Mercado Pago com o `refund_key`
  como chave de idempotência. Falha vira `last_error`; três falhas, `failed`,
  que aparece na fila "em execução" do console com "Tentar de novo". Pix
  manual e maquininha não passam pela nossa conta: ficam esperando
  confirmação humana **com referência obrigatória** (E2E do Pix, protocolo da
  adquirente). `FOR UPDATE SKIP LOCKED` deixa duas instâncias rodarem juntas.
- **Exportação contábil (12.3)** — `admin/export.php`: livro, pedidos e
  acertos, CSV com `;`, vírgula decimal e BOM UTF-8 (abre certo no Excel em
  português). O front baixa com o token no cabeçalho, nunca na URL.
- **Foto da ocorrência no painel** — `admin/incident_photo.php`, mesmo
  desenho do comprovante de Pix: disco privado, rota autenticada, blob no
  navegador, `Cache-Control: private, no-store`. Foto apagada pela retenção
  de 180 dias aparece como tal, não como erro.
- **Maquininha do próprio entregador** (migração 023): `pos_devices` passa a
  ter UM dono — loja ou entregador (`CHECK`). Só com
  `allow_courier_own_pos` na política. A venda nela lança `courier_cash`
  (dinheiro na conta dele, dívida com a loja) uma vez só — completar o NSU
  depois não cobra de novo — e não aparece na conciliação da loja, que confere
  o extrato da adquirente DELA.
- **O que continua sem integração real:** a chamada ao Mercado Pago roda em
  modo `fake` neste ambiente (sem credencial); em produção, com
  `MERCADOPAGO_ACCESS_TOKEN`, o mesmo código chama a API. O split
  (`application_fee`) não é usado: sem ele o dinheiro online cai na conta da
  plataforma, e é exatamente isso que o livro do pedido registra.

## Troca de método, gorjeta cobrada e Pix automático (migração 025) — decisões

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
- **Pix automático tem tela** (`PixAutoPayment.svelte`). O mock não a
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

## Lacunas do app do cliente fechadas (migração 026) — decisões

O que o app ainda avisava como "não construído" (toasts, notas, placeholders)
virou funcionalidade. Uma varredura por "ainda não" no front achou a lista.

- **LGPD de verdade (tela 6.3).** "Baixar meus dados" entrega um JSON com
  tudo que o sistema guarda sobre a pessoa (`profile/export.php`,
  `lib/account/account_privacy.php`) e nada que seja segredo (hash de sessão, código
  OTP, token do cartão no MP). "Excluir conta" é **anonimização**: nome,
  CPF, telefone, e-mail, nascimento, cartões, push e sessões somem; pedidos e
  pagamentos ficam, sem identificar ninguém, porque a lei fiscal obriga
  (LGPD art. 16, I). Endereço usado em pedido fica só com cidade, CEP de 5
  dígitos e coordenada arredondada. Barrada com pedido em andamento ou
  reembolso vivo; saldo de carteira exige aceite explícito de perda;
  confirmação digitando EXCLUIR. O telefone fica livre pra conta nova.
  Limite conhecido: o access token (15 min) de quem excluiu continua
  assinado até vencer -- o app faz logout na hora, e o refresh já é recusado.
- **"Alterar senha"** explica que conta de cliente não tem senha (entra por
  código no celular) em vez de abrir um formulário que não faria nada.
- **"Cardápios offline"** lista o que o service worker guardou de verdade
  (cache `*-data`) e deixa apagar; o rodapé mostra a versão real do SW.
- **Perfil (2.5) completo**: "Editar perfil" (nome, e-mail, CPF só
  mascarado na volta -- `cpf_masked` --, nascimento), "Notas e comprovantes"
  (recibo por pedido em `orders/receipt.php`: loja com CNPJ, itens, taxas,
  como pagou, estornos, gorjeta cobrada à parte; imprimível; diz que não é
  nota fiscal -- quem emite é a loja) e "Privacidade e dados (LGPD)".
- **"Repetir" pedido (2.4)** (`orders/reorder.php`): os mesmos itens,
  variações e observações voltam pro carrinho com o **preço de hoje**; o
  que saiu do cardápio é pulado e listado, em vez de falhar tudo.
- **Mapa da entrega (5.3)**: `DeliveryMap.svelte` com Leaflet (o chip do
  mock), pinos de loja, destino e entregador, "0,8 km · 3 min" e "Jonas está
  levando · Moto · placa". A posição só é liberada enquanto o pedido está
  em rota (`orders/courier_location.php`) -- antes e depois, o entregador
  não é rastreável por cliente. Sinal velho aparece como "última posição".
  A candidatura guarda o tipo de veículo, não o modelo ("Moto", não "Honda
  Biz").
- **Foto do item (11.1 → 3.1/3.2/2.2)** (`restaurants/menu_photo.php`):
  recodificada com GD pra JPEG de até 900 px (tira EXIF/GPS, neutraliza
  arquivo disfarçado), chave = hash do conteúdo, servida pública com cache
  imutável e guardada pelo service worker pro cardápio offline. Sobe na hora,
  fora do rascunho do editor (foto não muda preço nem regra).
- **Nota, tempo e frete no card da loja e nos filtros da busca (2.1/2.2)**
  (`lib/catalog/restaurant_facts.php`): nota das avaliações (só com 3 ou mais),
  frete pelo MESMO `delivery_quote()` que o checkout cobra (o teste confere
  que card e cobrança batem), tempo = preparo informado pela loja com a
  fila + viagem a `DELIVERY_AVG_KMH`. "Entrega grátis", "Até 30 min" e
  "4,5+" filtram de verdade; resultado sem o dado não passa no filtro.
  Custo conhecido: calcula por loja a cada listagem (algumas consultas por
  loja) -- aceitável no tamanho de uma praça; cache vira assunto se a lista
  crescer.
- **Pontos de fidelidade continuam sem tabela** (decisão de produto,
  "Próximos passos" 7) -- o perfil segue dizendo isso.

## Painel da plataforma (Fase 12 + tela 10.5) — decisões de implementação

O quarto público do projeto, no quarto bundle (`admin.html`): quem opera o
negócio. Fecha três coisas que estavam em aberto -- loja nova não tinha como
ser aprovada, ocorrência de caixa não aparecia pra ninguém, e política só
mudava por `INSERT` na mão.

- **Admin não tem conta de parceiro: entra pelo mesmo OTP do cliente.** Loja e
  entregador logam por aparelho (CNPJ+senha, CPF+código, 2FA por device);
  admin é uma pessoa com conta, e o que muda é o papel no token. O front
  confere o papel só pra não deixar alguém preso numa tela que daria 403 em
  tudo -- quem barra de verdade é `require_admin()` a cada chamada.
- **Aprovar loja não é carimbo: é a trava de só-online sendo ligada.** "Loja
  nova nasce só-online por 30 dias -- a liberação de dinheiro e maquininha é
  consequência do histórico, não de negociação." Aprovar grava
  `online_only_until` com o prazo da política E limita
  `restaurant_payment_settings.methods` aos métodos online. É o que o
  checkout vai ler depois; não é conselho na tela.
- **Recusar exige motivo**, porque a loja precisa saber o que corrigir --
  `rejected_at`/`rejection_reason` entraram na migração `016`: antes, uma
  loja recusada era indistinguível de uma que ninguém tinha olhado ainda.
- **O alerta de sócio virou o sinal que este backend consegue dar de
  verdade:** CNPJ de mesma raiz (8 primeiros dígitos) já cadastrado. O mock
  fala em "sócio com histórico", mas não há base de sócios aqui, e um alerta
  de histórico feito a partir de nada seria pior que nenhum alerta.
- **Ocorrência agora existe como registro.** A divergência de caixa (tela 9.3)
  marcava a intenção como `disputed` e parava aí -- ninguém via. Agora abre
  uma linha em `disputes`, e o valor gravado é a DIFERENÇA, não o total: é
  ela que está em disputa.
- **`disputes.order_id` deixou de ser obrigatório** (migração `016`). Uma
  divergência de fechamento é entre um entregador e uma loja num conjunto de
  corridas, não num pedido -- exigir um pedido obrigaria a escolher um no
  chute, e número escolhido no chute é pior que campo vazio. Entraram
  `courier_id` e `restaurant_id`, com CHECK exigindo pelo menos um sujeito.
- **Resolver ocorrência é contrapartida no livro, nunca edição de saldo.** A
  tela pergunta de qual bolso sai o valor, e "ninguém — sem cobrança" é opção
  explícita, não o padrão escondido. Sem valor, resolver só fecha a
  ocorrência.
- **Política é versionada, não editada.** Salvar faz `INSERT` de uma versão
  nova em `platform_policies` (a PK é a versão), copiando o que não mudou da
  anterior. A versão velha continua existindo, e pedido já feito segue a
  política que ele congelou em `policy_snapshot`. O teste prova as duas
  coisas: a versão nova nasce e a anterior continua com os valores antigos.
- **Os relatórios mostram os quatro números que o mock escolheu**, e a
  escolha é o conteúdo: GMV, quanto do GMV depende de gente conferindo
  (Pix manual + dinheiro + maquininha), custo de entrega por pedido e perda
  por fraude como percentual. Onde o dado não sustenta o número, aparece "—".
- **Honestidade sobre os saldos:** só metade do livro existe. Baixa de
  espécie e ocorrência são lançadas; o crédito por pedido (comissão + frete
  que a loja devolve) é o netting semanal da tela 9.7, que não foi
  construído. A tela diz isso em vez de chamar um saldo pela metade de "a
  cobrar na terça" -- foi um achado de olhar o número renderizado, que
  aparecia negativo.
- **Exportação CSV e fechamento contábil (12.3) não foram construídos**, nem
  a aprovação de entregador (15.2) ou o painel de campanhas (15.3).
- **Validado com Postgres e navegador reais.** `tests/smoke_admin.sh` cobre o
  403 pra quem não é admin, a fila de análise, recusa sem motivo barrada,
  aprovação ligando só-online (conferido em
  `restaurant_payment_settings.methods`), decisão dupla barrada, fila de
  ocorrências por risco, a contrapartida caindo no livro com o valor certo,
  resolução dupla barrada, os relatórios e a política versionada com a
  anterior intacta. No Playwright: login por OTP, aprovar, resolver
  ocorrência cobrando do entregador e publicar uma versão nova de política.

## PWA e modo offline (Fase 7.1) — decisões de implementação

O app do cliente instala e abre sem rede. Até aqui o rodapé do perfil dizia
"PWA v2.0.0 (parcial)" porque não havia manifest nem service worker; agora
há, e o que ele faz é limitado de propósito.

- **A regra que organiza o cache: navegação pode vir do disco, dinheiro
  nunca.** Só `GET` entra em cache, e entre os GETs só as rotas públicas --
  `restaurants/list`, `show`, `menu` e `search_products`. Perfil, pedidos,
  carrinho, pagamento, painel e entregador passam direto pra rede. Servir
  dado de conta de outra pessoa que usou o mesmo aparelho seria pior que
  ficar sem dado.
- **Requisição com `Authorization` não entra em cache nem em rota pública**,
  porque o header muda o que o servidor devolve e o cache do service worker
  não varia por header.
- **Duas estratégias, por motivo diferente.** App shell (HTML/JS/CSS/ícones):
  cache primeiro -- é o que faz abrir rápido e abrir offline. Cardápio e
  listas: rede primeiro com cópia no cache -- preço velho é pior que espera,
  então a rede ganha sempre que existe, e o cache é o plano B.
- **A praça escolhida passou a ser salva.** Não era: todo reload mandava o
  cliente refazer a Fase 1. Offline isso seria fatal -- quem reabre o app no
  metrô quer o cardápio salvo, não a tela "onde você está". Foi um achado do
  teste de PWA, não do plano.
- **O convite de instalar só aparece quando o navegador diz que dá.** O
  banner é desenhado pela tela (`beforeinstallprompt` com `preventDefault`),
  mas nunca é mostrado sem o evento -- um botão "Instalar" que não instala
  seria pior que nenhum. "Depois" fica salvo.
- **A fila de upload offline existe (tela 7.1, migração 024).** Só o
  comprovante entra nela, como o mock manda ("Pagamento nunca é enfileirado
  offline"). Sem rede -- ou com a rede caindo no meio do envio -- o arquivo
  vai pro IndexedDB (`fuu-offline` / `proof-uploads`,
  `web/src/lib/uploadQueue.svelte.js`) com um UUID gerado no aparelho. Esse
  UUID viaja como `X-Idempotency-Key` e fica em `payment_proofs.upload_key`
  (índice único parcial): reenviar o mesmo item devolve `200
  {replayed: true}` e o MESMO comprovante, nunca um segundo.
- **Quem esvazia a fila é a página, não o service worker.** A página tem o
  token da sessão; o SW não. O SW só recebe o `sync` (Background Sync, onde
  existe) e pede pra página esvaziar; nos navegadores sem Background Sync,
  o evento `online` e a abertura do app cobrem. 401 deixa o item na fila até
  a pessoa entrar de novo; recusa definitiva (pedido cancelado) tira.

## Push (Fase 7.2 + migração 024) — decisões de implementação

- **O push é um "acorda" sem conteúdo, assinado com VAPID (RFC 8292).** Ao
  acordar, o service worker busca o texto em `push/pending.php`, usando o
  endpoint da própria assinatura como credencial (é segredo do navegador,
  URL longa e aleatória). Mandar o texto dentro do push exigiria cifrar o
  corpo por assinante (RFC 8291: ECDH + HKDF + AES-GCM) -- dá pra escrever
  com o openssl do PHP, mas é o tipo de erro que o navegador engole em
  silêncio. Assinatura dá pra conferir em teste (`tests/support/verify_vapid.php`
  verifica o JWT ES256 com a chave pública), cifra caseira não.
- **Origem é a outbox, "então nada se perde".** `bin/push_worker.php` é O
  publicador da `outbox` (migração 004): gera aviso para `order.paid` (só
  Pix -- "Pix confirmado 🎉 / A cozinha já começou o pedido #X"; cartão
  aprovado com a pessoa olhando a tela não precisa) e `order.delivering`
  ("Jonas saiu para entrega / Chega em torno de 20:35", estimativa a
  20 km/h, fuso de São Paulo; retirada no balcão não avisa) e marca toda
  linha como publicada, com aviso ou sem. O terceiro tipo, "Faltam 5 min
  para expirar", não é evento -- é relógio --, então sai de uma varredura
  dos pedidos em Pix manual perto do `verification_deadline`.
- **Uma notificação por fato.** Índices únicos em `(outbox_id, kind)` e,
  no prazo, por pedido: o worker pode rodar duas vezes sem avisar duas.
- **Preferências por aparelho (tela 6.3): status × pagamento × promoção.**
  Guardadas na assinatura (`push_subscriptions.want_*`), filtradas no envio
  e de novo no `pending.php`. Endpoint que responde 404/410 é apagado.
- **Modo `fake` por padrão (`PUSH_MODE`).** Este ambiente não alcança os
  serviços de push dos navegadores; o fake grava a notificação e conta o
  "acorda" sem sair da máquina. `PUSH_MODE=live` chama o endpoint de verdade.
  A chave fica num PEM fora da raiz servida (`VAPID_PRIVATE_KEY_FILE`,
  padrão `storage/vapid/private.pem`), gerado por
  `php bin/generate_vapid_keys.php` com permissão 0600 e que nunca
  sobrescreve uma chave existente (trocar a chave invalida todas as
  assinaturas).
- **Agendar:** `* * * * * php bin/push_worker.php` (cron do cPanel).
- **Não validado:** a entrega real por FCM/Mozilla/APNs, que depende de
  internet aberta. O teste (`tests/smoke_push.sh`) cobre assinatura,
  preferências, outbox → notificação, idempotência, o prazo, o `pending`
  e a validade criptográfica do JWT.

- **Validado com navegador real, offline de verdade.** Playwright registra o
  service worker, confere o manifest (`display: standalone`, tema `#CC2B1D`,
  três ícones), navega com rede, corta a rede com `setOffline(true)`,
  recarrega -- e o app abre, mostra a faixa "Você está offline — mostrando o
  que está salvo", lembra a praça e lista as lojas que estavam no cache.

## Chat do pedido e cupons (Fase 14.2 + migração 008) — decisões

Dois buracos que o próprio README já vinha apontando: `talkToStore()` no
acompanhamento mostrava "Fase 14 ainda não foi portada", e o campo de cupom
do carrinho (3.3) estava na tela desde a Fase 3 avisando que não tinha
backend. Os dois fecham aqui, sobre tabelas que existem desde a migração
`008`.

- **Quem entra na conversa é decidido pelo VÍNCULO com o pedido, não pelo
  papel.** Cliente dono do pedido, a loja daquele pedido, o entregador
  designado e o suporte -- nessa ordem de checagem, em `match`. Uma loja não
  entra no chat do pedido da loja vizinha, e a resposta pra quem não é parte
  é 404 (não conta nem que o pedido existe).
- **Eventos do sistema entram na mesma linha do tempo.** Mensagens e
  transições de status vêm separadas do servidor e são intercaladas por
  horário na tela. É isso que faz "Saiu para entrega às 20:29" aparecer entre
  duas falas, como a conversa aconteceu de verdade.
- **O chat fecha 2 h depois da entrega, mas só pra escrever.** O comentário
  estava na própria migração `008` ("regra na API, histórico permanece"):
  ler continua valendo pra sempre, porque prova de disputa não pode sumir. A
  hora da entrega sai do `order_events` -- não existe coluna `delivered_at`,
  e criar uma seria duplicar o que a linha do tempo já sabe.
- **Respostas rápidas dependem de quem está falando** ("evitam digitar de
  moto"): o cliente recebe "Já desço"/"Deixe na portaria", a loja recebe
  "Saindo em 5 min"/"Acabou um item, posso trocar?", o entregador recebe
  "Estou no portão". Um mesmo componente serve os dois apps -- quem está
  falando vem do servidor (`me`), não de uma prop.
- **Cupom é validado inteiro no servidor**, porque cada regra dessas é
  dinheiro: prazo, loja, pedido mínimo (sobre o SUBTOTAL -- senão o frete
  ajudaria a atingir o mínimo, o oposto do que o cupom quer), orçamento da
  campanha e um uso por CPF.
- **Um uso por CPF, não por conta** -- é a `UNIQUE (coupon_id, cpf)` da
  migração `008`, e é por isso que quem não preencheu CPF no cadastro recebe
  409 `cpf_required` com a saída na mensagem, em vez de um "cupom inválido"
  que esconde o que dava pra corrigir.
- **O desconto entra em `orders.discount` e o total se recalcula sozinho**,
  porque `total` é coluna gerada. Nada é somado no PHP nem no navegador.
- **Aplicar no carrinho e consumir orçamento são momentos diferentes.**
  `cart/apply_coupon.php` só grava o desconto; o registro em
  `coupon_redemptions` e o `spent + valor` acontecem no checkout, dentro da
  transação que avança o pedido -- carrinho abandonado não pode segurar
  dinheiro de campanha, e se o orçamento estourar entre aplicar e fechar, o
  `CHECK within_budget` derruba o checkout inteiro.
- **Código vazio remove o cupom**, no mesmo endpoint: desfazer não precisa de
  rota nova.
- **Validado com Postgres e navegador reais.** `tests/smoke_support.sh` cobre
  cupom sem CPF, inexistente, de outra loja, abaixo do mínimo, aplicado,
  removido, resgatado no checkout e recusado na segunda tentativa do mesmo
  CPF; e no chat: as duas pontas conversando, o evento de status na linha do
  tempo, a marcação de lida, a loja de fora barrada nas duas direções,
  mensagem vazia e o fechamento 2 h depois da entrega com histórico
  preservado. No Playwright: o cupom derrubando o total de R$ 78,40 pra
  R$ 68,40 no carrinho, e a conversa indo do app do cliente pro KDS da loja
  e voltando por resposta rápida, com "lida" aparecendo.

## Entrada de entregador e campanhas (Fase 15.2 e 15.3) — decisões

Os dois fecham a Fase 15. Um é como alguém ENTRA na plataforma para
entregar; o outro é de que bolso sai o desconto.

### 15.2 — Onboarding do entregador

"Documento por documento com verificação automática visível. A regra 'chave
Pix tem que ser sua' é antifraude, e dizer isso na tela evita 90% das
tentativas."

- **A regra da chave Pix é código, não texto.** Chave que é CPF tem que bater
  com o CPF da candidatura; chave que é telefone, com o telefone; e-mail, com
  o e-mail da conta. Chave aleatória não dá pra conferir aqui -- a
  titularidade é do banco, na transferência -- e a resposta diz isso em vez
  de fingir que conferiu.
- **Candidatar-se exige conta, e isso não é burocracia:** `couriers.user_id`
  é `NOT NULL`, então aprovar sem pessoa cadastrada seria impossível. A
  entrada é pelo mesmo OTP do app do cliente; o login de CPF + código de
  acesso do app do entregador é o que a APROVAÇÃO cria.
- **Aprovar é o ato que cria o entregador.** Antes disso, entrar na
  plataforma dependia de `INSERT` manual no banco (era assim que os smoke
  tests semeavam entregador). Agora a aprovação cria `couriers` (com o id da
  candidatura, como a migração 007 manda), promove o usuário a `courier` e
  gera o código de acesso -- mostrado UMA vez a quem aprovou, porque não há
  integração de WhatsApp e fingir que mandamos seria pior.
- **Candidatura incompleta não entra na fila.** Faltando documento, o envio é
  recusado com a lista do que falta: revisor abrindo e fechando candidatura
  incompleta é o que faz "análise em até 48 h" virar mentira.
- **Arquivo repetido em outra candidatura é barrado** por sha256 -- a mesma
  CNH tentando virar dois entregadores é o caso clássico, e a checagem é de
  uma linha.
- **O aceite do contrato é registro, não checkbox:** vai pra `consents` com
  IP, e a VERSÃO fica em `courier_applications.contract_version`. É isso que
  faz "contrato versionado" ser verdade.
- **O que o mock mostra e não foi construído:** o match facial e o liveness
  ("rosto confere · 96%") precisam de um provedor de visão, que não está na
  cláusula zero. `face_match` fica nulo, a conferência da selfie é humana, e
  a tela diz isso em vez de estampar uma porcentagem inventada. O aviso por
  WhatsApp também não existe -- o resultado aparece na própria tela.

### 15.3 — Cupons e campanhas

"A coluna que falta em quase todo painel: **quem paga o desconto**."

- **`coupons.payer` deixou de ser rótulo.** A tabela já tinha os três valores
  desde a migração 008, mas o resgate não mexia no livro: o desconto saía do
  total e ninguém ficava devendo a ninguém. Agora cada resgate lança em
  `ledger_entries` com `origin = 'coupon'` -- `store_receivable` quando a loja
  banca, `platform_expense` quando somos nós, metade de cada no 50/50 (o
  centavo ímpar fica com a plataforma, e está escrito por quê).
- **O teto desativa o cupom sozinho.** O `CHECK (spent <= budget_cap)` já
  impedia passar; o que faltava era desligar ao ENCOSTAR, pra ninguém
  descobrir no fechamento. O teste leva um cupom de teto R$ 10 até o limite e
  confere que o próximo cliente não consegue mais aplicar.
- **A projeção é pedida ao servidor antes de criar** (`dry_run`), porque usa
  ticket médio dos últimos 90 dias e a comissão vigente -- números que o
  navegador não tem. Quando não há pedido suficiente, a resposta diz isso em
  vez de projetar em cima de zero. O "se 3 de 10 voltarem a pedir" do mock
  fica de fora: é previsão de comportamento, e não há dado de recompra.
- **O tamanho do público é consulta, não cadastro.** "Sem pedir há 15 dias" é
  um `NOT EXISTS` em `orders`; não existe (nem precisa existir) tabela de
  segmentação.
- **O que o mock promete e ficou de fora, com o motivo:** "cupom de loja ela
  cria sozinha no painel, dentro do teto que você liberar aqui". O cupom de
  loja existe (sai do repasse dela), mas quem cria ainda é a plataforma --
  deixar a loja criar exige um teto por loja na política, que não existe; sem
  ele, "dentro do teto que você liberar" não teria o que respeitar.
- **Validado com banco e navegador reais.** `tests/smoke_growth.sh` cobre as
  duas telas ponta a ponta: chave Pix de outro CPF recusada, moto sem placa
  barrada, candidatura sem documento sem ir pra análise, README recusado como
  foto de CNH, aceite de contrato registrado, fila do admin fechada pra
  cliente, recusa sem motivo barrada, aprovação criando entregador + login
  que REALMENTE entra no app (`couriers/me.php` responde), campanha sem teto
  recusada, projeção sem gravar nada, código duplicado barrado, os dois
  lançamentos de R$ 5,00 no livro e o cupom desativado ao encostar no teto.

## Pedido agendado (Fase 14.4) — decisões de implementação

"Faixa com vaga limitada pela capacidade real da cozinha, não pelo relógio.
Cobrança só no início do preparo — agendar sem cobrar evita estorno em massa
se a loja não abrir."

Duas peças de banco existiam desde a migração `004` e estavam sem uso:
`orders.scheduled_for` (tstzrange) e `delivery_slots`, com
`CHECK (taken <= capacity)`.

- **Quem garante "3 vagas" é o CHECK do banco, não um `if`.** Dois clientes
  apertando ao mesmo tempo na última vaga é exatamente o caso em que o `if`
  perde: a reserva é um `INSERT ... ON CONFLICT DO UPDATE SET taken = taken
  + 1`, e o CHECK que já existia desde a 004 é quem devolve o erro. O PHP só
  traduz a violação em `409 slot_full`. O teste cobre os três clientes na
  faixa de duas vagas.
- **A capacidade é declarada pela loja, porque só ela sabe.** Migração `020`
  acrescenta `restaurants.slot_capacity`, editável na tela de horário (11.4).
  Zero -- o padrão -- significa "essa loja não aceita agendamento", não "cabe
  zero pedido", e a tela do cliente diz isso com essas palavras. Derivar a
  capacidade de histórico seria inventar: quantos pedidos cabem numa faixa de
  30 min depende de fogão e de gente, não do que já foi vendido.
- **As faixas nascem do horário declarado, não de um relógio fixo.** Loja que
  fecha às 15h não oferece faixa às 16h; feriado (18.3) zera o dia; turno que
  atravessa a meia-noite gera faixa depois das 00h. Faixa que já começou não
  aparece -- a cozinha não volta no tempo.
- **O horizonte de 4 dias é do SERVIDOR, não só da tela.** Achado pelo teste:
  uma loja aberta 24h tem faixa "válida" em qualquer data do calendário, e a
  primeira versão aceitou um pedido agendado para **2030** vindo direto pela
  API. A tela nunca ofereceria; a requisição passava. Agora o checkout recusa
  com `slot_too_far`.
- **A cozinha não vê o pedido antes da hora.** O KDS (11.1) filtra pedido
  agendado até faltar o preparo da loja mais dez minutos pra faixa. Sem isso,
  a cozinha faria às 15h a comida que o cliente marcou pras 21h -- que é
  justamente o oposto do que a tela promete. Quando entra na fila, entra
  marcado com a hora combinada, porque ela manda mais que a ordem de chegada.
- **"Cobrança só no início do preparo" é verdade em dinheiro e maquininha, e
  a tela não finge que é nos outros.** Nesses dois métodos nada é cobrado até
  a entrega -- a promessa do mock é literal. Em cartão e Pix, cobrar depois
  exigiria re-cobrança com cartão guardado (a Fase 6.2 guarda, mas
  `payments/pay.php` ainda não cobra com cartão salvo) ou pedir o pagamento
  na hora por push (7.2, que não existe). Então a cobrança acontece no
  checkout, e a tela escreve qual dos dois é o caso.
- **"Cancelar sem taxa até 1 h antes" cai de graça do que já existia.** Pedido
  agendado fica em `paid` até a cozinha começar, e `refund_plan()` já não
  cobra taxa antes do preparo (Fase 13). O teste confere: cotação com
  `fee: 0` e `free_cancel: true`.
- **A previsão de entrega some quando há hora combinada.** Mostrar "chega
  entre 19:10 e 19:25" num pedido marcado pras 21h seria contar uma história
  diferente da que o cliente comprou.
- **Validado com banco e navegador reais.** `tests/smoke_schedule.sh` cobre
  capacidade zero não oferecendo faixa, a loja ligando o agendamento,
  faixas só futuras, a reserva derrubando a vaga na listagem, o horizonte e a
  faixa passada recusados, a terceira pessoa vendo `slot_full` com o banco
  intacto, a cozinha sem o pedido antes da hora e com ele depois, o
  cancelamento sem taxa, e a loja desligando o agendamento.

## Endereço, área de entrega e frete no servidor (Fase 14.3) — decisões

"CEP preenche, pino corrige, ponto de referência salva a entrega. A área de
cobertura é validada no servidor e a taxa aparece antes de salvar — não na
hora de pagar."

Esta tela fecha, de quebra, o buraco que o próprio README vinha apontando em
"Próximos passos": **o frete era o único número do dinheiro que ainda vinha
do cliente**. `orders/checkout.php` e `orders/create.php` aceitavam
`delivery_fee` no corpo -- bastava mandar `0` para não pagar entrega, num
projeto onde preço de item, mínimo de pedido, comissão e total sempre foram
decididos no servidor.

- **A tarifa virou política versionada, não constante no código.** Migração
  `019` acrescenta `delivery_base_fee`, `delivery_per_km` e `delivery_max_km`
  a `platform_policies`, e a tela 10.5 (admin) passa a editá-los. Entram no
  `policy_snapshot` do pedido pelo mesmo motivo que a taxa de cancelamento:
  republicar a política amanhã não pode reescrever o frete de um pedido de
  ontem -- e o teste prova isso.
- **Os três nascem zerados, e isso é a decisão.** Inventar "R$ 5,00 +
  R$ 1,50/km" na migração seria cobrar do cliente um número que ninguém
  decidiu. Enquanto a plataforma não publicar tarifa, o frete é zero, e a
  tela do endereço diz isso com todas as letras em vez de esconder.
- **Distância é Haversine, e o README assume o que isso significa.** Linha
  reta subestima a rota real de moto. Roteamento exige provedor de mapas, que
  não está na cláusula zero; inflar o número "pra compensar" seria tarifa
  inventada. Fica a menor distância possível, documentada.
- **Loja sem coordenada não bloqueia o pedido.** `restaurants.lat/lng` é
  nullable desde a migração `010`. Bloquear o checkout por causa de um
  cadastro que não é do cliente o puniria por erro alheio; cobrar por km sem
  saber os km seria pior. Cobra-se a base, e a resposta diz que a distância é
  desconhecida.
- **Raio vazio é "sem limite", não zero.** Zero seria "não entregamos em
  lugar nenhum", e o endpoint do admin recusa zero explicitamente com essa
  frase. Fora do raio, o checkout responde `out_of_delivery_area` com o
  motivo escrito -- a distância medida e o limite, os dois no texto.
- **Ponto de referência é coluna nova, não complemento.** Complemento
  identifica a unidade (apto, bloco) e vai no cupom; referência é instrução
  pra quem entrega ("portão cinza ao lado da padaria"). Enfiar as duas no
  mesmo campo faz uma sumir.
- **O mapa do mock não existe, e a tela diz o que existe no lugar.** Não há
  provedor de mapas na cláusula zero, então não há pino pra arrastar. O que
  dá pra fazer de verdade é usar a posição do aparelho (Geolocation, a mesma
  API da 1.3) -- e a tela mostra qual das duas coordenadas está valendo, a do
  aparelho ou o centro da praça escolhida.
- **Um bug de verdade achado no navegador, não no teste de API.** O aviso
  "Buscando pelo CEP…" aparecia e sumia num `{#if}`, e a busca dispara no
  `blur` do campo. Tocar em "Salvar endereço" logo depois de digitar o CEP
  disparava o blur, o aviso entrava no fluxo do documento e empurrava o botão
  pra baixo ENTRE o mousedown e o mouseup -- o clique não completava, e o
  formulário ficava aberto sem erro nenhum. O aviso agora fica sempre no DOM
  (invisível), com o espaço reservado.
- **Validado com banco e navegador reais.** `tests/smoke_address.sh` cobre a
  referência gravada e devolvida, a taxa calculada antes de salvar, o
  endereço a 11 km recusado pelo raio de 5, a cobertura por praça (com a loja
  sem coordenada corretamente fora da conta), o checkout ignorando
  `delivery_fee: 0` mandado pelo cliente, o pedido fora do raio barrado, a
  loja sem coordenada cobrando só a base, o endereço alheio não cotável, a
  tarifa nova valendo no pedido seguinte E o snapshot do pedido antigo
  intacto, mais tarifa negativa e raio zero recusados. No Playwright: o
  painel verde "Dentro da área de entrega de 2 lojas · Taxa: R$ 4,00 +
  R$ 1,50/km · loja mais perto a 0,2 km", as pastilhas Casa/Trabalho/Outro, e
  o endereço salvo com a referência aparecendo na lista.

## Central de ajuda (Fase 14.1) — decisões de implementação

"Os quatro atalhos cobrem a maior parte dos tickets reais de delivery — cada
um abre um fluxo automático antes de chamar gente." E, antes disso: "ajuda
começa no pedido em andamento, não numa lista de perguntas."

- **O fluxo automático é a parte que importa, e não é texto fixo.** Cada
  atalho lê o estado real do pedido de quem perguntou e responde com ele:
  "meu pedido está atrasado" num pedido em preparo devolve o tempo de preparo
  que a loja informou (11.2) e avisa se a fila já subiu o número; no mesmo
  pedido pronto sem entregador, devolve as três saídas da 15.1 e manda pra
  tela; "paguei o Pix" olha `payment_proofs` e diz se o comprovante está na
  fila e quanto falta do prazo; "onde está meu estorno" lê `refunds` e
  responde com valor, canal e prazo reais. Uma FAQ genérica no lugar disso
  seria a mesma tela com metade do valor.
- **Item errado é o único que o banco não pode conferir sozinho, e a tela
  admite isso.** Só quem abriu a sacola sabe o que faltou. O "fluxo
  automático" ali é dizer o que vai acontecer em seguida, não fingir que
  conferiu.
- **O chamado nasce com prazo, e o prazo é por categoria.** `sla_due_at` sai
  de uma tabela escrita num lugar só (`lib/messaging/support.php`): 15 min pra pedido
  atrasado e Pix não confirmado, 30 min pra item errado, um dia útil pra
  estorno. Os valores não estão na especificação -- o critério registrado é o
  custo de esperar: comida esfriando e dinheiro parado são minutos; estorno
  depende de banco e adquirente, que são dias.
- **Um chamado aberto por categoria e pedido.** Apertar duas vezes o mesmo
  atalho é a mesma pessoa com o mesmo problema: a segunda mensagem entra no
  chamado que já existe, e a resposta diz isso. Sem essa regra, a fila de
  suporte enche de duplicatas justamente quando está lenta.
- **A mensagem do chamado vai pra conversa DO PEDIDO (14.2).** Suporte que
  não enxerga a conversa vira o ping-pong de "qual o número do pedido?" que a
  Fase 14 existe pra matar. Quando não há pedido ligado, a resposta devolve
  `message_delivered: false` e a tela diz isso -- não existe caixa de entrada
  avulsa na especificação, e fingir que alguém já leu seria pior.
- **O "tempo médio de resposta agora" é medido, não prometido.** Sai das
  mensagens reais dos últimos sete dias: quanto tempo, em média, a loja (ou o
  suporte, ou o entregador) levou pra responder a primeira mensagem do
  cliente. É da plataforma inteira porque é isso que a frase promete a quem
  ainda não escreveu. Sem conversa no período, a linha some. E vem em
  segundos: arredondar pra minuto transformava resposta rápida em "0 min",
  que se lê como "ninguém responde".
- **O código do chamado é sorteado, não sequencial.** "#T-8841" sequencial
  contaria pro cliente quantos chamados a plataforma inteira já teve.
- **Validado com banco e navegador reais.** `tests/smoke_help.sh` cobre a
  ajuda sem pedido nenhum (que não inventa assunto), o atalho inexistente
  recusado, o preparo real aparecendo na resposta de atraso, o pedido pronto
  sem entregador virando as saídas da 15.1, o comprovante na fila
  reconhecido, o estorno real com valor e rota, o chamado sem mensagem
  barrado, o prazo de 30 min gravado no banco, a mensagem entrando na
  conversa do pedido, o segundo toque reaproveitando o chamado, e os 403/404
  de pedido e chamado alheios. No Playwright: a central aberta pelo perfil
  com o pedido de agora no topo, o atalho de atraso respondendo com as saídas
  da 15.1, o chamado T-…-alguma-coisa aparecendo em "SEUS ATENDIMENTOS", o
  segundo toque dizendo que o chamado já existia, e o botão "falar sobre este
  pedido" caindo no acompanhamento.

## A loja operando a si mesma (Fase 11.2 a 11.4) — decisões

Até aqui o painel deixava a loja atender pedido, mas não SER uma loja: ela
não podia pausar num aperto, não podia esgotar um item, não podia mudar o
horário. `is_open` só mudava por `UPDATE` no banco, e o cardápio nascia do
script de seed. Estas três telas fecham isso.

- **Duas tabelas que o mock cita e a especificação não tinha.**
  `store_pauses` ("motivo, autor, duração") e `holiday_overrides`, ambas na
  migração `018`. Sem a primeira, pausar seria um campo sobrescrito sem
  história: ninguém saberia quem pausou nem por quê, e o aviso "acima de 2 h
  por dia a loja perde o selo" não teria como ser medido. Sem a segunda,
  feriado seria editar o horário semanal na mão — e lembrar de desfazer na
  quinta seguinte.
- **Pausar não cancela nada, e o código prova isso.** O endpoint mexe em
  `restaurants.is_open`/`pause_until` e em mais nada; nenhum pedido muda de
  status. A tela mostra ao lado quantos pedidos estão em andamento e diz que
  todos continuam, que é exatamente o que a pessoa quer saber antes de
  apertar.
- **O custo da pausa é o da PRÓPRIA loja, nesta faixa de horário.** Média das
  últimas quatro semanas na mesma hora do dia: uma média do dia inteiro diria
  que pausar às 20h custa o mesmo que às 15h, que é justamente a decisão
  errada. E quando não há histórico nessa faixa, a tela diz isso em vez de
  mostrar "≈ 0" — que seria lido como "pausar não custa nada".
- **Pausa curta não fecha a loja; "fechar por hoje" fecha.** São estados
  diferentes: pausa mantém `is_open = true` e bloqueia pelo carimbo
  (`pause_until`), fechar por hoje zera `is_open` e carimba até a virada do
  dia. A segunda parte é o que impede o job de horário de reabrir a loja no
  minuto seguinte — e o teste cobre exatamente isso.
- **A pausa passou a esconder a loja de verdade.** `restaurants/list.php` e
  `search_products.php` filtravam só por `is_open`: a loja pausada continuava
  na lista de "abertos agora" e o cliente só descobria no checkout, com o
  pedido montado. Agora saem da lista, como o mock diz.
- **Voltar não é abrir.** "Voltar a receber pedidos" apaga a pausa e chama
  `apply_business_hours()`: quem decide se a loja está aberta continua sendo
  o horário. Reabrir na marra às 3h da manhã porque alguém apertou um botão
  seria aceitar pedido que ninguém vai preparar.
- **O tempo de preparo saiu do front e virou dado da loja.** A previsão de
  entrega do app do cliente era uma janela fixa de 25–45 min escrita no
  `OrderTracking.svelte`, que ninguém na loja podia corrigir. Agora é
  `restaurants.prep_minutes` + uma margem de viagem, e o "aumentar sozinho
  quando a fila passar de 8 pedidos" é calculado na LEITURA
  (`lib/catalog/store.php`), nunca gravado por cima do valor combinado — se fosse
  gravado, a fila esvaziaria e o número normal da loja teria sumido.
- **Esgotar tem rota própria.** É a ação mais frequente do dia e a única que
  não passa por rascunho; mandá-la pelo mesmo endpoint de edição faria um
  toque na lista carregar o risco de reenviar preço e descrição junto. O
  carimbo `sold_out_at` é o que permite dizer "esgotada hoje" em vez de
  "esgotada, sem saber desde quando".
- **Variações são substituídas em bloco.** A tela manda a lista inteira como
  ficou, e o endpoint apaga e reinsere dentro de uma transação. Casar uma a
  uma exigiria ids estáveis numa tela onde a pessoa adiciona e remove linhas
  livremente; e `order_items.variants_snapshot` guarda o que foi pedido, então
  apagar uma variação não reescreve pedido nenhum do passado.
- **Abrir e fechar é tarefa do `pg_cron`, não do atendente.**
  `apply_business_hours()` roda a cada minuto: apaga pausa vencida e liga ou
  desliga `is_open` pelo horário do dia, tratando turno que atravessa a
  meia-noite (o de sexta 18:00–01:00 ainda é o turno de sexta à 00:30 de
  sábado) e feriado como exceção que manda no dia inteiro. Salvar horário ou
  cadastrar feriado chama a função na hora, senão a tela mostraria um estado
  que já mudou.
- **O fuso é fixo em `America/Sao_Paulo`, e isso está escrito na migração.**
  `business_hours.opens/closes` são `time` sem fuso e não há coluna de fuso
  por loja na especificação; comparar com `now()` cru (UTC) abriria toda loja
  três horas cedo. Uma loja fora desse fuso pede uma coluna nova — decisão de
  produto, não de migração.
- **O que o mock mostra e não foi construído, com o motivo:**
  - *"Publicar invalida o cache do cardápio no edge"* — o purge do Cloudflare
    precisa de token de API, que não existe configurado aqui; a resposta
    devolve `edge_purged: false` em vez de fingir. O service worker do PWA já
    busca cardápio pela rede primeiro (Fase 7.1), então o cliente online vê o
    preço novo assim que publica.
  - *Rascunho no servidor* ("alterações não publicadas") — vive no navegador:
    guardar rascunho pediria coluna ou tabela de versão que a especificação
    não tem, e o efeito prático é o mesmo, porque o cliente só vê o que foi
    publicado. Fechar o painel sem publicar avisa antes de descartar.
  - *Foto do item* — FEITO depois (`restaurants/menu_photo.php`, ver
    "Lacunas do app do cliente fechadas").
  - *"Nova categoria"* — categoria é texto em `menu_items`, não tabela; um
    botão próprio criaria categoria fantasma, sem item dentro. A tela explica
    que ela nasce ao publicar um item com o nome dela.
  - *"Fechar 30 min mais tarde na sexta rendeu +11 pedidos"* — é comparação
    contrafactual: exigiria histórico de MUDANÇA de horário, que ninguém
    guarda. Fica o pico real, que é medido.
- **Validado com banco e navegador reais.** `tests/smoke_store.sh` cobre
  pausa sem motivo barrada, pausa de 45 min recusada, loja pausada sumindo da
  lista e barrando o checkout, "fechar por hoje" resistindo ao job, tempo de
  preparo chegando ao cliente, esgotar/voltar com carimbo, item de outra loja
  recusado (404), publicação com variações substituídas em bloco, horário
  aplicado a cinco dias numa transação, último pedido depois do fechamento
  recusado, turno que vira a madrugada aceito, o job abrindo a loja pelo
  horário, feriado de hoje fechando e sua remoção reabrindo, e os 403 de quem
  não é a loja. No Playwright: a pausa de 30 min com motivo, o sumiço da loja
  da lista de abertos, o preparo indo de 30 a 40 min em dois cliques,
  "Esgotada hoje" na linha, o rascunho marcado em vermelho e publicado, um
  item novo com variação, o horário de seg a sex salvo de uma vez e o feriado
  virando "fechada" no cabeçalho.

## Sem entregador disponível (Fase 15.1) — decisões de implementação

"O momento que mais gera ticket e ninguém desenha: em vez de 'aguarde', três
saídas concretas e a promessa escrita de cancelamento automático com
devolução integral. A comida já feita é paga pela plataforma, não pela loja."

Até aqui, um pedido que ficava pronto e não era aceito por ninguém ficava
pronto para sempre: a oferta existia (Fase 8), mas o cliente via só "Pronto,
aguarda entregador" e não tinha saída nenhuma. Esta tela é o contrário disso.

- **A promessa da tela exigiu abrir a máquina de estados.** `('ready',
  'cancelled')` não existia na lista de transições da migração `004` -- e sem
  ela as duas promessas centrais da 15.1 ("Cancelar e receber tudo de volta"
  e "passados 15 min cancelamos sozinhos") são recusadas pelo banco, porque
  as duas acontecem com o pedido em `ready`. A 004 fechava `ready` de
  propósito ("a comida está na bancada esperando o entregador"); o caso que
  faltava era exatamente o inverso: a comida na bancada e ninguém vindo
  buscar. A migração `017` acrescenta só essa transição, e o `down` devolve a
  lista idêntica à da 004.
- **O relógio é uma coluna, não um `SELECT` derivado.**
  `orders.no_courier_since` é carimbado quando o pedido vira oferta sem
  entregador e limpo no instante em que alguém aceita (ou quando vira
  retirada). Dava pra derivar de `order_events` toda vez, mas quem lê isso é
  uma varredura que roda a cada minuto -- uma coluna indexada vale mais que
  um subselect por linha. É esse carimbo que decide o "há 6 min", a barra de
  progresso, a troca de "chamando quem está por perto" pra "está mais difícil
  que o normal" (um terço do prazo) e a hora de cancelar sozinho.
- **Turbinar o frete só existe onde o dinheiro ainda não andou.** No mock,
  quem paga os R$ 4,00 a mais é o cliente. Isso é honesto em dinheiro e
  maquininha, que pagam na entrega; em pedido já pago no cartão ou no Pix,
  cobrar a mais exigiria uma segunda transação no Mercado Pago, que este
  módulo não faz. Então a opção aparece desabilitada com o motivo escrito na
  própria tela -- não some, e não mente.
- **Turbinar mexe em DOIS lugares, na mesma transação.** `orders.surge_fee`
  (o cliente paga) e `offers.bonus` (o entregador vê). Só o primeiro seria
  cobrar sem oferecer nada; só o segundo seria prometer dinheiro que ninguém
  pagou. A oferta ainda ganha mais 5 minutos de validade, porque valor novo
  precisa de tempo pra ser visto.
- **Retirar na loja é uma devolução PARCIAL, e isso mudou `record_refund`.**
  Zerar `delivery_fee`/`surge_fee` muda o total (coluna gerada) e devolve o
  frete de quem já tinha pago -- mas o cliente continua tendo pago a comida.
  A função ganhou um parâmetro `partial` que impede o `payments.status` de
  virar `refunded`: devolver o frete não desfaz a cobrança do pedido.
- **Cancelar aqui é falha nossa, e a conta é nossa.** A causa é `no_courier`,
  que `refund_payer()` já mapeava pra `platform`, e `refund_plan()` não cobra
  taxa nenhuma -- mesmo com a cozinha já tendo terminado, que é o único caso
  em que a taxa existiria. Também não se pergunta o motivo: a tela de
  cancelamento troca os quatro motivos fechados por um só, já marcado, porque
  cobrar explicação de quem esperou quinze minutos por um entregador que não
  veio seria absurdo.
- **O cancelamento automático é PHP em cron, não `pg_cron`** --
  `bin/auto_cancel_no_courier.php`, uma linha no cron do cPanel a cada
  minuto. O timeout do Pix (migração `009`) pode viver dentro do banco porque
  lá nada foi cobrado; aqui a varredura precisa decidir dinheiro, e quem sabe
  por onde o estorno volta, quanto volta e de que bolso sai é
  `lib/payments/refunds.php`. Reescrever essa tabela em PL/pgSQL criaria uma segunda
  fonte de verdade sobre o dinheiro, e as duas iam divergir no primeiro
  ajuste. O prazo lido é o da política congelada em cada pedido, não a de
  agora: encurtar o prazo hoje não pode cancelar mais cedo o pedido de ontem.
- **A retirada precisou aparecer na cozinha e ganhar quem a feche.** O KDS
  mostra `RETIRADA — o cliente vem buscar` no lugar do nome do entregador: a
  sacola fica no balcão esperando uma pessoa, não uma moto. E como não há
  entregador pra encerrar a corrida, a loja passou a poder registrar
  `delivered` -- só em pedido de retirada. Em pedido com entrega, quem
  confirma que chegou continua sendo quem chegou.
- **O que o mock diz e não foi construído, com o motivo:**
  - *"Chuva na região e muitos pedidos ao mesmo tempo"* — não existe clima
    nem densidade de pedidos em lugar nenhum da especificação. Inventar uma
    desculpa é pior que não dar nenhuma; a tela diz o que é verdade (há
    quanto tempo procura).
  - *"Costuma achar entregador em 2 min"* — é uma estatística, e não há
    histórico de despacho pra medir. No lugar, a mecânica verdadeira: o valor
    a mais aparece na hora pra quem está com o app aberto.
  - *"Avisamos assim que alguém aceitar"* — seria push (7.2), que não existe.
    A tela se atualiza sozinha enquanto está aberta, e é isso que ela diz.
  - *`dispatch_attempts`, raio crescente e rodadas* — o despacho continua
    sendo uma rodada só (`lib/dispatch/dispatch.php`). O surge por pedido existe agora
    porque a tela precisa dele; o resto da Fase 15 não.
  - *"· 1,2 km de você"* — essa existe: `restaurants.lat/lng` (migração
    `010`) e o endereço do cliente dão a distância real, em Haversine no SQL.
    Quando a loja não tem coordenada cadastrada, a linha aparece sem a
    distância, em vez de com um número inventado.
- **O SSE dá lugar ao polling enquanto essa tela está no ar.** Aceitar uma
  corrida não passa por `advance_order`, então o stream de tempo real nem
  saberia avisar. Fechar o `EventSource` tem o efeito colateral bom de
  desbloquear o `php -S`, que atende uma requisição por vez -- o mesmo motivo
  documentado no diálogo de cancelamento.
- **Validado com banco e navegador reais.** `tests/smoke_dispatch.sh` cobre o
  relógio começando e parando, o turbo chegando na oferta do entregador, o
  turbo negado em pedido pago, a retirada com devolução parcial sem marcar o
  pagamento como estornado, o fechamento da retirada pela loja (e a recusa do
  mesmo em pedido com entrega), o cancelamento integral por `no_courier`, a
  varredura cancelando o vencido e não encostando em quem está no prazo, e os
  403/404 de pedido alheio. No Playwright: a tela da 15.1 com o contador
  andando de segundo em segundo, o turbo subindo o total de R$ 58,10 pra
  R$ 62,10, a opção desabilitada com o motivo escrito no pedido de cartão, a
  retirada devolvendo R$ 6,90 e virando "Pronto para retirada", e o KDS da
  loja fechando esse pedido no balcão.

## App do entregador e caixa (Fase 8 e 9) — decisões de implementação

O terceiro público do projeto, no terceiro bundle (`entregador.html`).
"Aplicativo separado, feito para ser usado com uma mão, no sol, de moto
parada: alvos grandes, números enormes. É ele que fecha o dinheiro do pedido
offline."

- **O dinheiro anda no livro, não num campo de saldo.** Entregar um pedido em
  dinheiro gera DOIS lançamentos em `ledger_entries` na mesma transação da
  entrega: `courier_cash += total` (o bruto que ficou na mão dele) e
  `courier_payable += frete` (o que a plataforma deve). Saldo é `SUM()`, e
  `app_rw` não tem `UPDATE`/`DELETE` nessa tabela (migração `006`) -- correção
  é contrapartida, nunca edição, e isso está no banco, não na boa vontade de
  quem escreve PHP.
- **O entregador devolve o BRUTO.** É o que a tela 9.3 diz com todas as
  letras: "sem descontar o frete dele — quem paga o frete somos nós". Por isso
  `courier_cash` recebe o total do pedido, e o frete corre por fora, em
  `courier_payable`.
- **Aceite de corrida é um `UPDATE` condicional, não um lock.** A própria
  migração `007` já sugeria em comentário:
  `UPDATE offers SET courier_id=:c WHERE id=:o AND courier_id IS NULL
  RETURNING id` -- zero linhas significa que o outro chegou primeiro. Aceitar
  de novo a MESMA corrida que já é sua devolve 200 com `already_mine`, porque
  retry de rede não é erro.
- **O timer de 15 s é de DECISÃO, não de validade.** No mock ele conta na tela
  do entregador; num app que busca corrida por polling, 15 s de validade
  significaria oferta sempre vencida. O servidor segura a oferta por 5 min, a
  tela conta 15 s e tira a corrida DESTA tela quando acaba -- ela continua
  valendo pros outros, que é o certo: ninguém perde corrida porque este
  entregador ficou olhando.
- **O troco é conta do servidor.** "É o erro mais comum do delivery em
  dinheiro", então `couriers/pickup.php` devolve `change_due` pronto
  (`change_for - total`, com o total que é coluna gerada) e a tela só mostra,
  em corpo grande.
- **Prova de entrega é obrigatória** (migração `015`): código de 4 dígitos que
  o cliente vê no app, ou foto com GPS. Sem uma das duas, `deliver.php`
  devolve 422 -- é o que sustenta disputa depois. O código nasce com o pedido
  (DEFAULT aleatório por linha) porque o cliente precisa vê-lo antes de o
  entregador chegar; não é segredo criptográfico, é prova de presença.
- **A baixa de espécie guarda só o hash do código**, como o OTP do módulo
  identity, e o valor fica congelado na intenção. `one_open_intent` (migração
  `006`) garante uma baixa em voo por entregador -- duas seria o caminho mais
  curto pra pagar duas vezes a mesma espécie.
- **Divergência não vira lançamento.** Se a loja conta valor diferente do
  declarado, a intenção fica `disputed` e NADA é lançado no livro. Não é
  rigor por rigor: como o livro é append-only, um número errado não teria
  desfazimento -- "abrir ocorrência" é justamente não registrar um valor que
  ninguém sabe se é o certo.
- **Despacho mínimo, e assumido como tal.** `lib/dispatch/dispatch.php` cria UMA oferta
  quando o pedido fica pronto, com o frete do pedido e bônus zero. A Fase 15
  desenha rodadas, raio crescente, surge e `dispatch_attempts`: dessas, só o
  surge por pedido passou a existir (a tela 15.1 precisa dele, e o bônus
  agora tem de onde vir -- o cliente que turbinou). Rodadas, raio e
  `dispatch_attempts` continuam fora. A tabela `offers` já é a da
  especificação, então o resto da Fase 15 substitui a função sem migrar nada.
- **O que o mock mostra e o app não tem:** navegação com áudio e o endereço
  escrito da loja (o esquema só tem `lat`/`lng` de restaurante -- logradouro
  só existe em `addresses`, que é do cliente); telefone da loja (não há
  coluna); upload da foto de entrega (o endpoint aceita `photo_storage_key`,
  mas a tela de câmera não foi construída); posição do entregador enviada a
  cada 15 s; e a Fase 9.6 (conciliação de maquininha por NSU). Tudo
  registrado, nada fingido na tela.
- **A loja ganhou a aba "Caixa" no painel** (tela 9.3): conta o dinheiro,
  digita o código, confirma. A ordem dos campos é a ordem do trabalho real --
  valor contado primeiro, código depois, e o valor declarado aparece ao lado
  mas nunca preenchido no campo, senão ninguém conta nada e só confirma.
- **Validado com Postgres e navegador reais.** `tests/smoke_courier.sh` cobre
  turno (inclusive o 409 de abrir dois), a oferta nascendo do `ready`, dois
  entregadores disputando a mesma corrida, o retry do próprio aceite, o troco
  do servidor, entrega barrada sem prova e com código errado, os dois
  lançamentos no livro, o replay idempotente e a baixa de caixa com
  divergência e com acerto. No Playwright, 430px com geolocalização
  concedida: login por CPF+código, abrir turno, aceitar corrida, "cheguei",
  troco R$ 14,10 calculado no servidor, entrega com código, saldo indo pra
  R$ 85,90 em espécie e R$ 7,50 a receber, código de baixa gerado e, do lado
  da loja, a confirmação com valor divergente abrindo ocorrência.

## Painel da loja (`web/painel.html`) — Fase 7.3 e 11.1 a 11.4

O tablet do balcão. Até aqui `restaurants/approve_pix.php` existia e passava
no smoke test, mas não tinha tela nenhuma: na prática, um pedido em Pix
manual ficava preso em `pending_verification` pra sempre. Este módulo fecha
esse buraco e junta o KDS, que é a outra metade do mesmo trabalho.

- **É uma página separada, não uma aba do app do cliente.** `vite.config.js`
  passou a ter duas entradas (`index.html` e `painel.html`): quem pede pizza
  não baixa a fila de validação de Pix, e o tablet da cozinha não baixa o
  carrinho. A sessão da loja também é separada — outra chave no
  `localStorage` (`fuu_staff_token`) —, então dá pra ter o app aberto numa
  aba e o painel noutra sem um login derrubar o outro.
- **Quatro endpoints novos, todos escopados pelo `restaurant_id` do
  token.** `pending_proofs.php` devolve numa consulta só tudo que o modal
  mostra (itens agregados em JSON, quantos pedidos o cliente já fez nesta
  loja, se aquela imagem já apareceu antes) — nada de N+1 numa tela que
  fica aberta o dia inteiro atualizando. `proof_image.php` transmite o
  arquivo com o MIME real e `Cache-Control: private, no-store`.
  `stats.php` é o "VISÃO GERAL DE HOJE" calculado no banco.
  `orders.php` ganhou `scope=kds|recent`. O smoke test prova o isolamento:
  a loja rival recebe fila vazia, `proof_not_found` na imagem e na
  aprovação, `restaurant_not_found` na fila de pedidos.
- **A imagem do comprovante é buscada com `fetch` + `Authorization` e virada
  em blob URL**, não posta direto num `<img src>`. Foi a forma de não abrir
  a exceção de token por query string (que só a rota de SSE tem, porque
  `EventSource` não manda header) numa rota que serve arquivo.
- **O cronômetro do KDS conta desde a entrada no status atual**, não desde a
  criação do pedido: "em preparo há 6 min" é o que a cozinha lê. Vem de
  `max(order_events.created_at)` para o status corrente, na mesma consulta.
- **`ready → delivering` virou transição pedível pela loja.** O mock 11.1
  desenha o botão "Entregue ao motoboy" na terceira coluna, e quem entrega a
  sacola em mãos é a loja. O botão só aparece quando existe entregador
  designado (`orders.courier_id`); sem ele o cartão diz "Aguardando
  entregador ser designado", como no mock. Quando a Fase 8 existir, o app do
  entregador ganha o mesmo alvo — são duas pessoas que podem registrar a
  mesma passagem de bastão.
- **O painel atualiza por polling (5 s), não por SSE — decisão consciente.**
  `advance_order()` já publica em `pg_notify` e a rota de SSE do cliente
  existe (`orders/track.php`), mas `php -S` atende uma requisição por vez:
  uma conexão SSE aberta no painel travaria as outras chamadas da própria
  tela (aprovar, avançar pedido, estatísticas). Sob `php-fpm` isso deixa de
  ser verdade e a troca é local, num `setInterval` só. O cabeçalho mostra a
  hora da última atualização e avisa quando o ciclo falha, em vez de fingir
  "conectado".
- **Desvio assumido do mock: o modal de validação é Svelte, não SweetAlert.**
  O mock diz "SweetAlert em tela cheia", mas a tela tem imagem com
  zoom/rotação e duas colunas de conteúdo — o `swal()` recebe um nó de
  conteúdo, não um componente. Mesma decisão já tomada no `ItemModal` (3.2).
  E a classe não se chama `.modal`: o Bootstrap reserva esse nome com
  `display:none` (ver "Módulo de carrinho").
- **Girar + ampliar exigiu conta, não CSS esperto.** `transform` não muda a
  caixa de layout, então uma foto em pé girada 90° desenha fora da moldura
  enquanto a rolagem continua achando que ela está em pé — o atendente rola
  e vê faixa branca (foi o que o Playwright mostrou na primeira versão). A
  moldura passou a ter um "calço" do tamanho *visual* da imagem já girada e
  ampliada, com a imagem centrada nele; a rolagem passeia pelo comprovante
  de verdade, e ampliar recentraliza em vez de jogar pro canto.
- **O que o mock mostra e esta tela não tem**: o botão de imprimir comanda
  (ESC/POS) e o indicador "impressora ok" ficaram de fora em vez de virarem
  botão morto — não existe integração com impressora neste repositório
  (item 4 de "Próximos passos"). Pausar loja, cardápio e horário (11.2 a
  11.4) também não foram construídos.
- **Validado de ponta a ponta com Postgres e navegador reais.**
  `tests/smoke_panel.sh` cobre os quatro endpoints, o isolamento entre
  lojas, a aprovação levando o pedido pra `paid` e as transições
  `paid → preparing → ready → delivering` (inclusive a ilegal, barrada pelo
  banco com 409). No Playwright, num tablet 1280×800: login da loja →
  aceitar → pronto → entregue ao motoboy → validar Pix pelo atalho do KDS →
  girar/ampliar o comprovante → recusa sem motivo barrada → aprovar com
  valor conferido → aba "Visão geral" → recusar o segundo comprovante com
  motivo → recarregar (sessão mantida) → sair. Zero erros de console
  (fora as fontes do Google, bloqueadas pelo proxy deste ambiente).
- **O 2FA por aparelho apareceu na prática durante o teste:** a segunda
  execução do Playwright, num navegador novo, levou `device_mismatch` e a
  tela mostrou "Este login está vinculado a outro aparelho" — que é
  exatamente o comportamento desenhado (`partner_accounts` faz confiança no
  primeiro uso). Liberar troca de tablet depende do suporte (Fase 14), que
  ainda não existe.

## Como rodar localmente

```bash
docker compose up -d
export DATABASE_URL=postgres://postgres:postgres@localhost:5432/fuudelivery
bash db/migrate.sh up           # aplica as 20, em ordem
bash db/migrate.sh down 3       # reverte as 3 últimas
bash db/migrate.sh down 26      # reverte tudo

cp .env.example .env            # ajuste DATABASE_URL/JWT_SECRET/ALLOWED_ORIGIN se precisar
JWT_SECRET=dev-secret bash tests/smoke_identity.sh    # fluxo completo de identity
JWT_SECRET=dev-secret bash tests/smoke_ordering.sh    # fluxo completo de checkout (semeia loja/cardápio sozinho)
JWT_SECRET=dev-secret bash tests/smoke_discovery.sh   # lista por distância, busca, perfil (semeia sozinho)
JWT_SECRET=dev-secret bash tests/smoke_cart.sh        # carrinho incremental (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_payments.sh   # pagamentos (semeia sozinho)
JWT_SECRET=dev-secret bash tests/smoke_tracking.sh    # timeline, SSE, avaliação (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_account.sh    # endereços e cartões (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_panel.sh      # painel da loja: fila de Pix e KDS (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_cancel.sh     # cancelamento, recusa e reembolso (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_courier.sh    # entregador e baixa de espécie (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_support.sh    # chat do pedido e cupons (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_admin.sh      # painel da plataforma (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_dispatch.sh   # pedido sem entregador: turbo, retirada, auto-cancel (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_store.sh      # loja operando a si mesma: pausa, cardápio, horário (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_help.sh       # central de ajuda: fluxo automático e chamado com prazo (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_address.sh    # endereço, área de entrega e frete no servidor (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_schedule.sh   # pedido agendado: faixas, vaga e fila da cozinha (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_growth.sh     # entrada de entregador e campanhas com teto (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_incident.sh   # ocorrência na entrega e console de reembolso (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_machine.sh    # maquininha, conciliação e netting semanal (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_money.sh      # estornos executados, CSV, livro do pedido (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_rounds.sh     # rodadas do despacho (semeia sozinho)

# O único processo de fundo do projeto (tela 15.1). Em produção é uma linha
# no cron do cPanel, a cada minuto; localmente, roda à mão quando quiser ver
# um pedido vencido ser cancelado:
php bin/auto_cancel_no_courier.php     # cancela pedido pronto há 15 min sem entregador

php -S localhost:8080                  # API, num terminal
cd web && npm install && npm run dev   # front-end Svelte, noutro terminal
                                       #   app do cliente:  http://localhost:5173
                                       #   painel da loja:  http://localhost:5173/painel.html
                                       #   entregador:      http://localhost:5173/entregador.html
                                       #   plataforma:      http://localhost:5173/admin.html
```

Os smoke tests semeiam dados próprios a cada execução, mas contam com um
banco recém-migrado: rodar a suíte várias vezes no mesmo banco acumula
lojas de teste e faz as asserções de contagem (ex.: "filtro de categoria
trouxe 1 loja") falharem por dado velho, não por regressão. `bash
db/migrate.sh down 26 && bash db/migrate.sh up` devolve o banco ao zero.

`MERCADOPAGO_MODE=fake` é o padrão quando `MERCADOPAGO_ACCESS_TOKEN` não
está configurado (ver seção "Módulo de pagamentos" abaixo) — não precisa
de conta sandbox pra rodar nada disto localmente.

As vinte e seis migrações foram validadas de ponta a ponta (`up` completo, `down`
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
("invalid input syntax for type boolean"). `lib/core/db.php` ganhou `pg_bool()`
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

O módulo de conta também: CRUD de endereços com troca de padrão e
bloqueio de apagar em uso (409, não 500), dois cartões salvos com o mesmo
`mp_customer_id` reaproveitado (confirmado por query direta no banco),
troca de padrão e remoção. `tests/smoke_account.sh` roda tudo isso a cada
push, no mesmo CI, em `MERCADOPAGO_MODE=fake`.

O acesso do cliente também: cadastro completo com CPF gravado (conferido
por query direta), CPF inválido barrado, data de nascimento no futuro
barrada, CPF de outra conta em 409 e o canal WhatsApp indo parar em
`otp_codes.channel`. `tests/smoke_identity.sh` roda tudo isso junto com o
que já cobria de OTP e rotação de refresh.

E o painel da loja: a fila de validação com itens, endereço e contagem de
imagem repetida; o comprovante servido com autorização (401 sem token) e
negado pra loja rival nos quatro caminhos; a aprovação levando o pedido pra
`paid`; e as transições da cozinha até `delivering`, incluindo a ilegal
barrada pelo banco. `tests/smoke_panel.sh` roda tudo isso a cada push.

E o caminho do erro: cancelamento livre antes do preparo, com taxa depois,
recusa da loja sem custo pro cliente, o canal de estorno certo pra cada
método, o pagamento virando `refunded` e reembolso que não duplica.
`tests/smoke_cancel.sh` roda tudo isso a cada push.

E o app do entregador com o caixa: turno, corrida disputada por dois
entregadores, troco calculado no servidor, entrega barrada sem prova, os dois
lançamentos no livro e a baixa de espécie com divergência e com acerto.
`tests/smoke_courier.sh` roda tudo isso a cada push.

E o chat com os cupons: quem pode entrar na conversa, o fechamento 2 h
depois da entrega com histórico preservado, e o cupom barrado por CPF
ausente, loja errada, valor mínimo, repetição e orçamento.
`tests/smoke_support.sh` roda tudo isso a cada push.

E o painel da plataforma: aprovação ligando a trava de só-online, recusa
exigindo motivo, ocorrência virando contrapartida no livro com o valor certo,
e a política publicada como versão nova com a anterior intacta.
`tests/smoke_admin.sh` roda tudo isso a cada push.

O front-end validou o mesmo jeito, não só compilado, nas seis fases com
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
reais que apareceram e foram corrigidos nesta validação), Fase 5
(acompanhamento ao vivo por SSE entre processos diferentes, avaliação
completa, reabertura pela lista de pedidos mostrando "já avaliado" — ver
seção "Fase 5 — decisões adicionais" acima pro bug real de datas achado e
corrigido em três lugares nesta validação) e Fase 6 (dois endereços com
troca de padrão e edição, dois cartões salvos com bandeira detectada pelo
heurístico, configurações persistindo em `localStorage` de verdade). Um
bug real de CSS apareceu na Fase 3 e está documentado na seção "Módulo de
carrinho" acima — a classe `.modal` colidindo com o Bootstrap, achada
checando `boundingBox()` via Playwright, não só lendo o código.

## Próximos passos (ordem sugerida pela especificação, Parte I §10)

1. **Portar o resto das telas** de `FUUDelivery - 64 Telas (offline).html`
   para Svelte + Bootstrap — Fases 1 a 6 prontas, mais o painel da loja
   (7.3, 9.3 e 11.1), o app do entregador (8.1 a 8.7 e 9.1/9.2/9.4), o acesso
   do cliente (10.1 a 10.3), o resto do app da loja (11.2 a 11.4), o caminho
   do erro inteiro (13.1 a 13.4), a Fase 14 inteira (14.1 ajuda, 14.2 chat, 14.3
   endereço com área e frete no servidor, 14.4 agendamento), a Fase 15.1 a
   15.3 e o painel da plataforma (12.1 a 12.3 e a política da 10.5);
   a fila de upload offline e o push (7.1 e 7.2) — FEITO, ver seções próprias —,
   a exportação contábil em CSV que a 12.3 promete (os
   números dela estão na tela — FEITO, ver "Pontas de dinheiro") e o que falta da Fase 15 -- rodadas, raio
   crescente e `dispatch_attempts` (15.1, 15.2 e 15.3 estão construídas, cada
   uma com seção própria acima).
2. **Cálculo de frete no servidor.** FEITO na Fase 14.3 (migração 019):
   `orders/checkout.php` e `orders/create.php` calculam o frete da tarifa da
   política e recusam endereço fora do raio; o valor que vier no corpo é
   ignorado. Ver a seção própria acima.

3. **Cartão salvo ainda não paga.** `cards/*` guarda o cartão (Fase 6.2),
   mas `payments/pay.php` só aceita um `card_token` novo a cada compra —
   pagar com cartão salvo exige o fluxo de CVV + token de uso único que o
   próprio mock descreve ("pagar com ele ainda exige CVV e gera novo token
   de uso único"), que depende do MercadoPago.js real.
4. **Impressão ESC/POS** — o push da 7.2 está FEITO (seção própria); imprimir
   a comanda depende de conexão com impressora térmica, que não existe
   neste repositório.
5. **Mapa e posição do entregador (Fase 5.3)** — FEITO (Leaflet, seção
   "Lacunas do app do cliente fechadas"). Os tiles vêm do OpenStreetMap,
   que este ambiente não alcança: os pinos, a distância e o tempo foram
   validados; a imagem do mapa por baixo, não.
6. **Reembolso — EXECUTADO no cartão e Pix automático; manual no resto.**
   O executor existe (seção "Pontas de dinheiro"). O parágrafo abaixo é o
   histórico de antes dele. A tela 13.4 agora
   existe (console do admin, com ajuste de taxa, lançamento no livro pela
   conta de quem paga e crédito em carteira que a pessoa aceita ou recusa —
   seção própria acima), e `refunds.state` passa de `'pending'` a `'sent'`
   quando o admin decide. O que continua faltando é o worker que executa: não
   se chama o `POST /v1/payments/{id}/refunds` do Mercado Pago, não se devolve
   o Pix pra chave do pagador, não se cancela na adquirente, e por isso nada
   vira `'done'` sozinho (nem `'failed'`). Junto vem a reconciliação de
   maquininha da Fase 9 (`card_transactions`, NSU).
7. **Decisão de produto pendente: fidelidade/pontos.** A tela 2.3 existe
   só como desenho (dado de exemplo, sem tabela no banco). Se for pra
   valer, precisa de um ledger de pontos — mesmo padrão append-only do
   `ledger_entries` financeiro — e isso é decisão de escopo, não algo pra
   inventar numa migração de suporte a tela.
8. **Cupons existem, campanhas não.** O resgate funciona (aplicar, validar,
   consumir orçamento), mas não há tela pra CRIAR campanha nem o painel da
   tela 15.3 com custo por pedido e retorno -- e `audience`
   (`first_order`, `inactive_15d`...) é gravado e ignorado: segmentar exige
   saber quem está inativo, que é consulta de base, não de pedido.
9. **Pix automático tem tela** — FEITO (seção "Troca de método, gorjeta
   cobrada e Pix automático").
10. **Trocar de método depois do checkout** — FEITO
    (`payments/change_method.php`, mesma seção).
11. **Gorjeta da avaliação cobrada no cartão do pedido** — FEITO (migração
    025, mesma seção). A cobrança real no Mercado Pago depende do pagamento
    original ter usado cartão salvo; não foi validada contra a API.
12. **Login social (Google e Apple) não existe no backend.** A tela 10.1
    mostra os dois botões, aqui desabilitados: não há OAuth nem tabela de
    identidade federada no esquema, e `users` não tem como guardar um
    `provider`/`subject` externo. Fazer isso direito é uma migração e um
    fluxo de callback, não um botão.
13. **Busca por CEP (Fase 6.1) não pôde ser testada neste ambiente.** A
    chamada ao ViaCEP é real, mas o ambiente de desenvolvimento bloqueia
    saída pra hosts fora da allowlist do proxy — só o caminho de falha
    (cai pro preenchimento manual) foi observado funcionando. Vale
    confirmar num ambiente com internet aberta antes de considerar pronto.

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
