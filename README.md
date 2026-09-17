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

## O que este primeiro commit contém

Só a **fundação de banco** — as nove migrações SQL do esquema completo,
porque é dela que tudo o resto depende (checkout, pagamento, caixa,
dispatch, disputa, cupom). PHP, Svelte e as telas ainda não foram portados;
essa é a próxima etapa, na ordem sugerida pela especificação (12 semanas,
Parte I §10).

```
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
docker-compose.yml          banco local para desenvolvimento
.github/workflows/migrations.yml   CI: aplica as 9, reverte tudo, aplica de novo
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

## Como rodar localmente

```bash
docker compose up -d
export DATABASE_URL=postgres://postgres:postgres@localhost:5432/fuudelivery
bash db/migrate.sh up          # aplica as 9, em ordem
bash db/migrate.sh down 3      # reverte as 3 últimas
bash db/migrate.sh down 9      # reverte tudo
```

As nove migrações foram validadas de ponta a ponta (`up` completo, `down`
completo em ordem reversa, `up` de novo) contra um PostgreSQL 16 real com
`pg_cron` instalado, incluindo um teste funcional de `advance_order()`
confirmando que transições legais avançam o pedido e transições ilegais
levantam exceção (a defesa de concorrência descrita na Parte I §4 e na
Parte II §9).

## Próximos passos (ordem sugerida pela especificação, Parte I §10)

1. **Módulo de identidade em PHP** sobre `001` — OTP, sessão com rotação de
   refresh token, login de parceiro (loja CNPJ+senha, entregador CPF+código).
2. **Módulo de pagamentos em PHP** — rotas com PDO, idempotência, webhooks
   do Mercado Pago, reembolso, os seis testes de concorrência da Parte I §9.
3. **Portar as telas** de `FUUDelivery - 64 Telas (offline).html` para
   Svelte + Bootstrap, uma fase por vez, seguindo a mesma ordem de risco
   (dinheiro primeiro, conveniência depois).

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
