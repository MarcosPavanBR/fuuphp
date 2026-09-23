# Mapa do banco

> Gerado por `php bin/generate_db_map.php` a partir do banco com todas as migrações
> aplicadas. Não edite à mão: o CI confere (`--check`).

58 tabelas em PostgreSQL 16. Regras que o esquema garante sozinho (e que o
código não precisa repetir): `orders.status` só muda por `advance_order()`; `ledger_entries`
é só de inserção (sem UPDATE/DELETE); um pagamento aprovado por pedido
(`payments_one_approved`). Contexto de cada regra: `docs/ARCHITECTURE.md`.

## Tipos enumerados

- `ledger_account`: courier_cash, courier_payable, store_receivable, platform_revenue, platform_expense
- `payment_method`: mp_card, pix_auto, pix_manual, cash, pos_machine

## Índice

[`acquirer_statements`](#acquirer_statements) [`addresses`](#addresses) [`audit_log`](#audit_log) [`business_hours`](#business_hours) [`card_transactions`](#card_transactions) [`cash_settlement_intents`](#cash_settlement_intents) [`consents`](#consents) [`coupon_redemptions`](#coupon_redemptions) [`coupons`](#coupons) [`courier_applications`](#courier_applications) [`courier_documents`](#courier_documents) [`courier_positions`](#courier_positions) [`courier_shifts`](#courier_shifts) [`couriers`](#couriers) [`delivery_attempts`](#delivery_attempts) [`delivery_incidents`](#delivery_incidents) [`delivery_proofs`](#delivery_proofs) [`delivery_slots`](#delivery_slots) [`dispatch_attempts`](#dispatch_attempts) [`disputes`](#disputes) [`fraud_signals`](#fraud_signals) [`holiday_overrides`](#holiday_overrides) [`idempotency_keys`](#idempotency_keys) [`item_variants`](#item_variants) [`ledger_entries`](#ledger_entries) [`loyalty_entries`](#loyalty_entries) [`loyalty_rewards`](#loyalty_rewards) [`menu_items`](#menu_items) [`notifications`](#notifications) [`offers`](#offers) [`order_events`](#order_events) [`order_items`](#order_items) [`order_messages`](#order_messages) [`orders`](#orders) [`otp_codes`](#otp_codes) [`outbox`](#outbox) [`partner_accounts`](#partner_accounts) [`payment_proofs`](#payment_proofs) [`payments`](#payments) [`payouts`](#payouts) [`platform_policies`](#platform_policies) [`policy_overrides`](#policy_overrides) [`pos_custody`](#pos_custody) [`pos_devices`](#pos_devices) [`print_log`](#print_log) [`push_subscriptions`](#push_subscriptions) [`refunds`](#refunds) [`restaurant_credentials`](#restaurant_credentials) [`restaurant_payment_settings`](#restaurant_payment_settings) [`restaurants`](#restaurants) [`reviews`](#reviews) [`saved_cards`](#saved_cards) [`sessions`](#sessions) [`settlement_proofs`](#settlement_proofs) [`store_pauses`](#store_pauses) [`tickets`](#tickets) [`users`](#users) [`wallet_credits`](#wallet_credits) 

## acquirer_statements

Criada em `db/migrations/022_acquirer_statement.up.sql`. Da migração: Tela 9.6 (conciliacao da maquininha fisica).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `acquirer` | text |  |  |
| `reference_day` | date |  |  |
| `filename` | text | sim |  |
| `rows_total` | integer (padrão) |  |  |
| `rows_matched` | integer (padrão) |  |  |
| `rows_orphan` | integer (padrão) |  |  |
| `sha256` | character | sim |  |
| `imported_by` | uuid | sim | `users.id` |
| `imported_at` | timestamp with time zone (padrão) |  |  |

## addresses

Criada em `db/migrations/002_catalog.up.sql`. Da migração: restaurants, restaurant_credentials, cardapio (menu_items, item_variants), business_hours, addresses. Libera: catalogo e cardapio (Fases 2, 3, 11 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `label` | text | sim |  |
| `street` | text |  |  |
| `number` | text | sim |  |
| `complement` | text | sim |  |
| `neighborhood` | text | sim |  |
| `city` | text |  |  |
| `city_ibge_code` | character |  |  |
| `state` | character |  |  |
| `postal_code` | character |  |  |
| `lat` | numeric |  |  |
| `lng` | numeric |  |  |
| `is_default` | boolean (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `reference` | text | sim |  |

## audit_log

Criada em `db/migrations/003_policy.up.sql`. Da migração: platform_policies, policy_overrides, restaurant_payment_settings, audit_log. Libera: checkout consciente de politica (teto, prazo, comissao, metodos).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `actor_id` | uuid |  | `users.id` |
| `action` | text |  |  |
| `target` | text |  |  |
| `before` | jsonb | sim |  |
| `after` | jsonb | sim |  |
| `ip` | inet | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## business_hours

Criada em `db/migrations/002_catalog.up.sql`. Da migração: restaurants, restaurant_credentials, cardapio (menu_items, item_variants), business_hours, addresses. Libera: catalogo e cardapio (Fases 2, 3, 11 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `restaurant_id` | uuid |  | `restaurants.id` |
| `dow` | integer |  |  |
| `shift` | text |  |  |
| `opens` | time without time zone |  |  |
| `closes` | time without time zone |  |  |
| `last_order` | time without time zone |  |  |
| `active` | boolean (padrão) |  |  |

## card_transactions

Criada em `db/migrations/005_payments.up.sql`. device_id ganha FK na migracao 006, quando pos_devices existir.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint | sim | `orders.id` |
| `device_id` | uuid | sim | `pos_devices.id` |
| `acquirer` | text |  |  |
| `nsu` | text | sim |  |
| `amount_app` | numeric |  |  |
| `amount_statement` | numeric | sim |  |
| `brand` | text | sim |  |
| `kind` | text | sim |  |
| `state` | text (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## cash_settlement_intents

Criada em `db/migrations/006_ledger.up.sql`. Baixa de especie: intencao do entregador + confirmacao da loja. courier_id ganha FK na migracao 007, quando couriers existir.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `courier_id` | uuid |  | `couriers.id` |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `amount` | numeric |  |  |
| `method` | text |  |  |
| `code_hash` | character | sim |  |
| `expires_at` | timestamp with time zone |  |  |
| `state` | text (padrão) |  |  |
| `counted_amount` | numeric | sim |  |
| `confirmed_by` | uuid | sim | `users.id` |
| `confirmed_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## consents

Criada em `db/migrations/001_identity.up.sql`. LGPD: termo aceito, versao e IP de cada consentimento.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `kind` | text |  |  |
| `version` | text |  |  |
| `accepted_at` | timestamp with time zone (padrão) |  |  |
| `ip` | inet | sim |  |

## coupon_redemptions

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `coupon_id` | bigint |  | `coupons.id` |
| `order_id` | bigint |  | `orders.id` |
| `cpf` | character |  |  |
| `amount` | numeric |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## coupons

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `code` | text |  |  |
| `kind` | text |  |  |
| `value` | numeric |  |  |
| `min_order` | numeric (padrão) |  |  |
| `restaurant_id` | uuid | sim | `restaurants.id` |
| `audience` | text |  |  |
| `payer` | text |  |  |
| `budget_cap` | numeric |  |  |
| `spent` | numeric (padrão) |  |  |
| `starts_at` | timestamp with time zone |  |  |
| `ends_at` | timestamp with time zone | sim |  |
| `active` | boolean (padrão) |  |  |
| `created_by` | uuid |  | `users.id` |
| `owner_user_id` | uuid | sim | `users.id` |
| `created_by_store` | boolean (padrão) |  |  |

## courier_applications

Criada em `db/migrations/007_dispatch.up.sql`. Da migração: courier_applications, courier_documents, couriers, courier_shifts, offers, dispatch_attempts. Libera: app do entregador (Fases 8 e 15 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid (padrão) |  |  |
| `full_name` | text |  |  |
| `cpf` | character |  |  |
| `phone` | text |  |  |
| `email` | citext | sim |  |
| `vehicle` | text |  |  |
| `plate` | text | sim |  |
| `pix_key` | text |  |  |
| `face_match` | numeric | sim |  |
| `state` | text (padrão) |  |  |
| `reviewed_by` | uuid | sim | `users.id` |
| `contract_version` | integer | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## courier_documents

Criada em `db/migrations/007_dispatch.up.sql`. Da migração: courier_applications, courier_documents, couriers, courier_shifts, offers, dispatch_attempts. Libera: app do entregador (Fases 8 e 15 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `application_id` | uuid |  | `courier_applications.id` |
| `kind` | text |  |  |
| `storage_key` | text |  |  |
| `sha256` | character |  |  |
| `expires_on` | date | sim |  |
| `state` | text (padrão) |  |  |

## courier_positions

Criada em `db/migrations/009_operations.up.sql`. Posicao do entregador: escrita a cada 15s, uma linha por entregador (UPSERT, sem historico). UNLOGGED = sem WAL, equivalente a cache dentro do proprio PostgreSQL, sem adicionar tecnologia a stack.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `courier_id` | uuid |  | `couriers.id` |
| `lat` | numeric |  |  |
| `lng` | numeric |  |  |
| `heading` | numeric | sim |  |
| `updated_at` | timestamp with time zone (padrão) |  |  |

## courier_shifts

Criada em `db/migrations/007_dispatch.up.sql`. Da migração: courier_applications, courier_documents, couriers, courier_shifts, offers, dispatch_attempts. Libera: app do entregador (Fases 8 e 15 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `courier_id` | uuid |  | `couriers.id` |
| `started_at` | timestamp with time zone (padrão) |  |  |
| `ended_at` | timestamp with time zone | sim |  |

## couriers

Criada em `db/migrations/007_dispatch.up.sql`. Da migração: courier_applications, courier_documents, couriers, courier_shifts, offers, dispatch_attempts. Libera: app do entregador (Fases 8 e 15 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid |  |  |
| `user_id` | uuid |  | `users.id` |
| `city_ibge_code` | character |  |  |
| `rating` | numeric | sim |  |
| `cash_blocked` | boolean (padrão) |  |  |
| `active` | boolean (padrão) |  |  |

## delivery_attempts

Criada em `db/migrations/021_incident_refund.up.sql`. Rastro do que o entregador fez ANTES de abrir a ocorrencia. Uma linha por toque: chegou, ligou, tocou a campainha. Com hora, GPS e IP -- e o chip da tela 13.3 diz exatamente "geo + inet".

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `courier_id` | uuid |  | `couriers.id` |
| `kind` | text |  |  |
| `lat` | numeric | sim |  |
| `lng` | numeric | sim |  |
| `ip` | inet | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## delivery_incidents

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `courier_id` | uuid |  | `couriers.id` |
| `kind` | text |  |  |
| `photo_key` | text | sim |  |
| `geo_lat` | numeric | sim |  |
| `geo_lng` | numeric | sim |  |
| `call_attempts` | integer (padrão) |  |  |
| `waited` | interval | sim |  |
| `resolution` | text | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## delivery_proofs

Criada em `db/migrations/015_delivery_proof.up.sql`. Da migração: Prova de entrega da tela 8.6: "Código de 4 dígitos ou foto com GPS — prova de entrega para disputa."

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `courier_id` | uuid |  | `couriers.id` |
| `kind` | text |  |  |
| `storage_key` | text | sim |  |
| `sha256` | character | sim |  |
| `lat` | numeric | sim |  |
| `lng` | numeric | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## delivery_slots

Criada em `db/migrations/004_ordering.up.sql`. Da migração: orders, order_items, order_events, outbox, advance_order(), delivery_slots. Libera: pedido, KDS, agendamento.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `restaurant_id` | uuid |  | `restaurants.id` |
| `window` | tstzrange |  |  |
| `capacity` | integer |  |  |
| `taken` | integer (padrão) |  |  |

## dispatch_attempts

Criada em `db/migrations/007_dispatch.up.sql`. Da migração: courier_applications, courier_documents, couriers, courier_shifts, offers, dispatch_attempts. Libera: app do entregador (Fases 8 e 15 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `round` | integer |  |  |
| `radius_km` | numeric |  |  |
| `candidates` | integer |  |  |
| `surge` | numeric (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## disputes

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint | sim | `orders.id` |
| `kind` | text |  |  |
| `risk` | text (padrão) |  |  |
| `amount` | numeric | sim |  |
| `state` | text (padrão) |  |  |
| `resolution` | text | sim |  |
| `decided_by` | uuid | sim | `users.id` |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `courier_id` | uuid | sim | `couriers.id` |
| `restaurant_id` | uuid | sim | `restaurants.id` |

## fraud_signals

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `kind` | text |  |  |
| `subject` | text |  |  |
| `user_id` | uuid | sim | `users.id` |
| `courier_id` | uuid | sim | `couriers.id` |
| `order_id` | bigint | sim | `orders.id` |
| `score` | integer |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## holiday_overrides

Criada em `db/migrations/018_store_ops.up.sql`. Feriado e EXCECAO, nao edicao do horario semanal: editar business_hours para um dia especifico obrigaria a lembrar de desfazer na quarta-feira seguinte. `closed = false` com faixa preenchida e o "so jantar" do mock.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `day` | date |  |  |
| `closed` | boolean (padrão) |  |  |
| `opens` | time without time zone | sim |  |
| `closes` | time without time zone | sim |  |
| `last_order` | time without time zone | sim |  |
| `note` | text | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## idempotency_keys

Criada em `db/migrations/005_payments.up.sql`. Retry com a mesma chave devolve a MESMA resposta gravada (contrato de API, secao I.3): 100 requisicoes simultaneas => 1 cobranca, 99 respostas identicas.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `key` | uuid |  |  |
| `route` | text |  |  |
| `request_hash` | character |  |  |
| `status_code` | integer | sim |  |
| `response_body` | jsonb | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## item_variants

Criada em `db/migrations/002_catalog.up.sql`. Da migração: restaurants, restaurant_credentials, cardapio (menu_items, item_variants), business_hours, addresses. Libera: catalogo e cardapio (Fases 2, 3, 11 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `menu_item_id` | bigint |  | `menu_items.id` |
| `group_name` | text |  |  |
| `name` | text |  |  |
| `price_delta` | numeric (padrão) |  |  |
| `max_selections` | integer | sim |  |
| `required` | boolean (padrão) |  |  |
| `position` | integer (padrão) |  |  |

## ledger_entries

Criada em `db/migrations/006_ledger.up.sql`. Da migração: ledger_entries (append-only) + views, cash_settlement_intents, payouts, pos_devices, pos_custody. Libera: caixa e repasses (Fase 9 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `account` | ledger_account |  |  |
| `party_id` | uuid |  |  |
| `amount` | numeric |  |  |
| `origin` | text |  |  |
| `origin_id` | text |  |  |
| `order_id` | bigint | sim | `orders.id` |
| `actor_id` | uuid | sim | `users.id` |
| `memo` | text | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## loyalty_entries

Criada em `db/migrations/029_loyalty.up.sql`. Da migração: Tela 2.3 — Fidelidade: "Seus pontos 1.240 de 1.500 · Faltam 260 pontos para o cupom de R$ 20 · R$ 10 de desconto 800 pontos · Entrega grátis 1.500 pontos · Histórico: Pedido #A38F2C +128 · Cupom R$ 10 −800". "Saldo ca…

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `points` | integer |  |  |
| `origin` | text |  |  |
| `origin_id` | text |  |  |
| `order_id` | bigint | sim | `orders.id` |
| `memo` | text |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## loyalty_rewards

Criada em `db/migrations/029_loyalty.up.sql`. O catálogo de trocas (o que a tela oferece), gerido pela plataforma.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `label` | text |  |  |
| `kind` | text |  |  |
| `value` | numeric (padrão) |  |  |
| `cost` | integer |  |  |
| `valid_days` | integer (padrão) |  |  |
| `active` | boolean (padrão) |  |  |

## menu_items

Criada em `db/migrations/002_catalog.up.sql`. Da migração: restaurants, restaurant_credentials, cardapio (menu_items, item_variants), business_hours, addresses. Libera: catalogo e cardapio (Fases 2, 3, 11 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `name` | text |  |  |
| `description` | text | sim |  |
| `price` | numeric |  |  |
| `category` | text | sim |  |
| `photo_key` | text | sim |  |
| `available` | boolean (padrão) |  |  |
| `sold_out_at` | timestamp with time zone | sim |  |
| `position` | integer (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## notifications

Criada em `db/migrations/024_offline_push.up.sql`. A notificação em si. O push que sai é só um "acorda" sem conteúdo; o service worker busca daqui o texto. Assim o texto não precisa atravessar o serviço de push (nem ser cifrado à mão pra isso).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `kind` | text |  |  |
| `title` | text |  |  |
| `body` | text |  |  |
| `order_id` | bigint | sim | `orders.id` |
| `outbox_id` | bigint | sim | `outbox.id` |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `delivered_at` | timestamp with time zone | sim |  |

## offers

Criada em `db/migrations/007_dispatch.up.sql`. Da migração: courier_applications, courier_documents, couriers, courier_shifts, offers, dispatch_attempts. Libera: app do entregador (Fases 8 e 15 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `courier_id` | uuid | sim | `couriers.id` |
| `fee` | numeric |  |  |
| `bonus` | numeric (padrão) |  |  |
| `expires_at` | timestamp with time zone |  |  |
| `state` | text (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## order_events

Criada em `db/migrations/004_ordering.up.sql`. Da migração: orders, order_items, order_events, outbox, advance_order(), delivery_slots. Libera: pedido, KDS, agendamento.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `from_status` | text | sim |  |
| `to_status` | text |  |  |
| `actor_id` | uuid | sim | `users.id` |
| `actor_kind` | text |  |  |
| `meta` | jsonb (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## order_items

Criada em `db/migrations/004_ordering.up.sql`. Da migração: orders, order_items, order_events, outbox, advance_order(), delivery_slots. Libera: pedido, KDS, agendamento.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `menu_item_id` | bigint |  | `menu_items.id` |
| `name_snapshot` | text |  |  |
| `unit_price` | numeric |  |  |
| `quantity` | integer |  |  |
| `variants_snapshot` | jsonb (padrão) |  |  |
| `notes` | text | sim |  |
| `line_total` | numeric | sim |  |

## order_messages

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `sender_id` | uuid | sim | `users.id` |
| `sender_role` | text |  |  |
| `body` | text |  |  |
| `read_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## orders

Criada em `db/migrations/004_ordering.up.sql`. Da migração: orders, order_items, order_events, outbox, advance_order(), delivery_slots. Libera: pedido, KDS, agendamento.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `public_code` | text (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `courier_id` | uuid | sim | `couriers.id` |
| `address_id` | bigint | sim | `addresses.id` |
| `status` | text (padrão) |  |  |
| `subtotal` | numeric |  |  |
| `delivery_fee` | numeric (padrão) |  |  |
| `surge_fee` | numeric (padrão) |  |  |
| `discount` | numeric (padrão) |  |  |
| `tip` | numeric (padrão) |  |  |
| `total` | numeric | sim |  |
| `commission` | numeric (padrão) |  |  |
| `payment_method` | payment_method | sim |  |
| `change_for` | numeric | sim |  |
| `machine_kind` | text | sim |  |
| `scheduled_for` | tstzrange | sim |  |
| `policy_snapshot` | jsonb (padrão) |  |  |
| `verification_deadline` | timestamp with time zone | sim |  |
| `cancel_reason` | text | sim |  |
| `reject_reason` | text | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `updated_at` | timestamp with time zone (padrão) |  |  |
| `delivery_code` | character (padrão) |  |  |
| `pickup_by_customer` | boolean (padrão) |  |  |
| `no_courier_since` | timestamp with time zone | sim |  |

## otp_codes

Criada em `db/migrations/001_identity.up.sql`. Da migração: Extensoes, roles de acesso e o modulo identity: users, partner_accounts, otp_codes, consents, sessions. Libera: login e cadastro (Fase 10 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `channel` | text |  |  |
| `code_hash` | character |  |  |
| `purpose` | text |  |  |
| `attempts` | integer (padrão) |  |  |
| `expires_at` | timestamp with time zone |  |  |
| `consumed_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## outbox

Criada em `db/migrations/004_ordering.up.sql`. Entrega garantida para quem le em tempo real (KDS, apps): todo avanco de pedido grava aqui, um worker le e publica, mesmo se ninguem estava ouvindo pg_notify no instante exato.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `topic` | text |  |  |
| `payload` | jsonb |  |  |
| `published_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## partner_accounts

Criada em `db/migrations/001_identity.up.sql`. Login de parceiros: loja com CNPJ+senha no tablet, entregador com CPF+codigo, 2FA por aparelho. restaurant_id/courier_id ganham FK quando essas tabelas existirem (002 e 007) -- nao existem ainda nesta migracao.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `kind` | text |  |  |
| `restaurant_id` | uuid | sim | `restaurants.id` |
| `courier_id` | uuid | sim | `couriers.id` |
| `login_code` | text |  |  |
| `password_hash` | text | sim |  |
| `access_code_hash` | character | sim |  |
| `device_id` | text | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## payment_proofs

Criada em `db/migrations/005_payments.up.sql`. Comprovante de Pix manual, com validacao dupla (loja confirma o codigo).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `payment_id` | bigint |  | `payments.id` |
| `order_id` | bigint |  | `orders.id` |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `storage_key` | text |  |  |
| `sha256` | character |  |  |
| `phash` | character | sim |  |
| `uploaded_by` | uuid | sim | `users.id` |
| `state` | text (padrão) |  |  |
| `counted_amount` | numeric | sim |  |
| `reviewed_by` | uuid | sim | `users.id` |
| `reviewed_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `upload_key` | uuid | sim |  |

## payments

Criada em `db/migrations/005_payments.up.sql`. Da migração: payments, payment_proofs, idempotency_keys, refunds, card_transactions. Libera: os 6 metodos de pagamento + reembolso (Fases 4 e 13 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `provider` | text |  |  |
| `provider_ref` | text | sim |  |
| `amount` | numeric |  |  |
| `status` | text |  |  |
| `status_detail` | text | sim |  |
| `raw_response` | jsonb | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## payouts

Criada em `db/migrations/006_ledger.up.sql`. Da migração: ledger_entries (append-only) + views, cash_settlement_intents, payouts, pos_devices, pos_custody. Libera: caixa e repasses (Fase 9 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `party_kind` | text |  |  |
| `party_id` | uuid |  |  |
| `period_start` | date |  |  |
| `period_end` | date |  |  |
| `gross` | numeric |  |  |
| `withheld` | numeric (padrão) |  |  |
| `net` | numeric |  |  |
| `provider_ref` | text | sim |  |
| `state` | text (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## platform_policies

Criada em `db/migrations/003_policy.up.sql`. Da migração: platform_policies, policy_overrides, restaurant_payment_settings, audit_log. Libera: checkout consciente de politica (teto, prazo, comissao, metodos).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `version` | integer |  |  |
| `cash_ceiling` | numeric (padrão) |  |  |
| `cash_settle_deadline` | interval (padrão) |  |  |
| `allow_partial_settle` | boolean (padrão) |  |  |
| `withhold_unsettled` | boolean (padrão) |  |  |
| `require_cash_photo` | boolean (padrão) |  |  |
| `commission_bps` | integer (padrão) |  |  |
| `courier_payout_dow` | integer (padrão) |  |  |
| `store_debit_dow` | integer (padrão) |  |  |
| `pos_return_deadline` | text (padrão) |  |  |
| `allow_courier_own_pos` | boolean (padrão) |  |  |
| `enabled_methods` | payment_method[] |  |  |
| `new_store_online_only_days` | integer (padrão) |  |  |
| `no_courier_timeout` | interval (padrão) |  |  |
| `created_by` | uuid |  | `users.id` |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `cancel_fee` | numeric (padrão) |  |  |
| `delivery_base_fee` | numeric (padrão) |  |  |
| `delivery_per_km` | numeric (padrão) |  |  |
| `delivery_max_km` | numeric | sim |  |
| `loyalty_points_per_brl` | numeric (padrão) |  |  |

## policy_overrides

Criada em `db/migrations/003_policy.up.sql`. Da migração: platform_policies, policy_overrides, restaurant_payment_settings, audit_log. Libera: checkout consciente de politica (teto, prazo, comissao, metodos).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `scope` | text |  |  |
| `scope_id` | text |  |  |
| `patch` | jsonb |  |  |
| `reason` | text |  |  |
| `created_by` | uuid |  | `users.id` |
| `expires_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## pos_custody

Criada em `db/migrations/006_ledger.up.sql`. courier_id ganha FK na migracao 007, quando couriers existir.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `device_id` | uuid |  | `pos_devices.id` |
| `courier_id` | uuid |  | `couriers.id` |
| `taken_at` | timestamp with time zone (padrão) |  |  |
| `due_at` | timestamp with time zone |  |  |
| `returned_at` | timestamp with time zone | sim |  |
| `confirmed_by` | uuid | sim | `users.id` |

## pos_devices

Criada em `db/migrations/006_ledger.up.sql`. Da migração: ledger_entries (append-only) + views, cash_settlement_intents, payouts, pos_devices, pos_custody. Libera: caixa e repasses (Fase 9 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid (padrão) |  |  |
| `restaurant_id` | uuid | sim | `restaurants.id` |
| `label` | text |  |  |
| `acquirer` | text |  |  |
| `serial` | text | sim |  |
| `active` | boolean (padrão) |  |  |
| `courier_id` | uuid | sim | `couriers.id` |

## print_log

Criada em `db/migrations/028_print_log.up.sql`. Da migração: Impressão ESC/POS na loja (telas 4.5, 7.3, 9.3, 11.1).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `kind` | text |  |  |
| `ref_id` | bigint |  |  |
| `reprint` | boolean (padrão) |  |  |
| `printed_by` | uuid | sim | `users.id` |
| `printed_at` | timestamp with time zone (padrão) |  |  |

## push_subscriptions

Criada em `db/migrations/024_offline_push.up.sql`. Uma linha por aparelho que aceitou notificação. O `endpoint` é o endereço do serviço de push do navegador (único por aparelho+app) e funciona como credencial pro service worker buscar o que tem pra mostrar -- ver api/v1/push/pending.php.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `endpoint` | text |  |  |
| `p256dh` | text |  |  |
| `auth` | text |  |  |
| `want_status` | boolean (padrão) |  |  |
| `want_payment` | boolean (padrão) |  |  |
| `want_promotion` | boolean (padrão) |  |  |
| `failures` | integer (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `last_push_at` | timestamp with time zone | sim |  |

## refunds

Criada em `db/migrations/005_payments.up.sql`. Da migração: payments, payment_proofs, idempotency_keys, refunds, card_transactions. Libera: os 6 metodos de pagamento + reembolso (Fases 4 e 13 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `payment_id` | bigint | sim | `payments.id` |
| `refund_key` | uuid |  |  |
| `amount` | numeric |  |  |
| `channel` | text |  |  |
| `payer` | text |  |  |
| `cause` | text |  |  |
| `state` | text (padrão) |  |  |
| `decided_by` | uuid | sim | `users.id` |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `note` | text | sim |  |
| `decided_at` | timestamp with time zone | sim |  |
| `fee` | numeric (padrão) |  |  |
| `provider_ref` | text | sim |  |
| `attempts` | integer (padrão) |  |  |
| `last_error` | text | sim |  |
| `executed_at` | timestamp with time zone | sim |  |

## restaurant_credentials

Criada em `db/migrations/002_catalog.up.sql`. O ativo mais sensivel do sistema: o token que move o dinheiro DELE.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `restaurant_id` | uuid |  | `restaurants.id` |
| `pix_key` | text | sim |  |
| `rotated_at` | timestamp with time zone (padrão) |  |  |

## restaurant_payment_settings

Criada em `db/migrations/003_policy.up.sql`. Da migração: platform_policies, policy_overrides, restaurant_payment_settings, audit_log. Libera: checkout consciente de politica (teto, prazo, comissao, metodos).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `restaurant_id` | uuid |  | `restaurants.id` |
| `methods` | payment_method[] |  |  |
| `max_cash` | numeric | sim |  |
| `max_card_machine` | numeric | sim |  |
| `max_change` | numeric (padrão) |  |  |
| `min_order` | numeric (padrão) |  |  |
| `updated_by` | uuid | sim | `users.id` |
| `updated_at` | timestamp with time zone (padrão) |  |  |

## restaurants

Criada em `db/migrations/002_catalog.up.sql`. Da migração: restaurants, restaurant_credentials, cardapio (menu_items, item_variants), business_hours, addresses. Libera: catalogo e cardapio (Fases 2, 3, 11 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid (padrão) |  |  |
| `name` | text |  |  |
| `cnpj` | character |  |  |
| `city_ibge_code` | character |  |  |
| `commission_bps` | integer (padrão) |  |  |
| `credit_limit` | numeric (padrão) |  |  |
| `online_only_until` | date | sim |  |
| `is_open` | boolean (padrão) |  |  |
| `pause_until` | timestamp with time zone | sim |  |
| `approved_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `category` | text | sim |  |
| `logo_key` | text | sim |  |
| `lat` | numeric | sim |  |
| `lng` | numeric | sim |  |
| `rejected_at` | timestamp with time zone | sim |  |
| `rejection_reason` | text | sim |  |
| `prep_minutes` | integer (padrão) |  |  |
| `prep_auto_bump` | boolean (padrão) |  |  |
| `slot_capacity` | integer (padrão) |  |  |
| `coupon_budget_limit` | numeric (padrão) |  |  |

## reviews

Criada em `db/migrations/011_reviews.up.sql`. Da migração: reviews: avaliação do pedido (Fase 5.5 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `order_id` | bigint |  | `orders.id` |
| `user_id` | uuid |  | `users.id` |
| `rating` | smallint |  |  |
| `tags` | text[] (padrão) |  |  |
| `comment` | text | sim |  |
| `courier_tip` | numeric (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `tip_state` | text (padrão) |  |  |
| `tip_provider_ref` | text | sim |  |
| `tip_error` | text | sim |  |
| `tip_charged_at` | timestamp with time zone | sim |  |

## saved_cards

Criada em `db/migrations/012_saved_cards.up.sql`. Da migração: saved_cards: cartões salvos via Mercado Pago (Fase 6.2 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `mp_card_id` | text |  |  |
| `brand` | text |  |  |
| `last4` | character |  |  |
| `kind` | text | sim |  |
| `exp_month` | smallint |  |  |
| `exp_year` | smallint |  |  |
| `is_default` | boolean (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## sessions

Criada em `db/migrations/001_identity.up.sql`. Access token de 15 min (fora do banco); refresh de 30 dias com rotacao. family_id agrupa a cadeia de rotacao: um refresh_hash reaparecendo depois de ja ter sido rotacionado (rotated_from apontado por outra sessao) e a assinatura de token roubado -- a aplicacao revoga a familia inteira.

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `family_id` | uuid |  |  |
| `refresh_hash` | character |  |  |
| `device_label` | text | sim |  |
| `ip` | inet | sim |  |
| `rotated_from` | uuid | sim | `sessions.id` |
| `revoked_at` | timestamp with time zone | sim |  |
| `expires_at` | timestamp with time zone |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## settlement_proofs

Criada em `db/migrations/027_settlement_pix.up.sql`. Da migração: Tela 9.5 — "Entregador — baixa por Pix (loja fechada)".

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `intent_id` | bigint |  | `cash_settlement_intents.id` |
| `courier_id` | uuid |  |  |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `storage_key` | text |  |  |
| `sha256` | character |  |  |
| `phash` | character | sim |  |
| `upload_key` | uuid | sim |  |
| `state` | text (padrão) |  |  |
| `reject_reason` | text | sim |  |
| `reviewed_by` | uuid | sim | `users.id` |
| `reviewed_at` | timestamp with time zone | sim |  |
| `created_at` | timestamp with time zone (padrão) |  |  |

## store_pauses

Criada em `db/migrations/018_store_ops.up.sql`. Historico de pausas. `pause_until` em restaurants continua sendo a verdade operacional (e o que o checkout le); esta tabela e o registro de QUEM pausou, POR QUE e por quanto -- o que o mock chama de "o motivo aparece para o cliente" e o que permite medir as 2 h por dia que derrubam o selo de "Confiavel".

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `restaurant_id` | uuid |  | `restaurants.id` |
| `kind` | text |  |  |
| `reason` | text |  |  |
| `started_at` | timestamp with time zone (padrão) |  |  |
| `until` | timestamp with time zone | sim |  |
| `ended_at` | timestamp with time zone | sim |  |
| `created_by` | uuid | sim | `users.id` |

## tickets

Criada em `db/migrations/008_support.up.sql`. Da migração: disputes, fraud_signals, delivery_incidents, tickets, order_messages, coupons, coupon_redemptions. Libera: suporte, antifraude, promocoes (Fase 13 e 14 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `code` | text |  |  |
| `user_id` | uuid | sim | `users.id` |
| `order_id` | bigint | sim | `orders.id` |
| `category` | text |  |  |
| `state` | text (padrão) |  |  |
| `sla_due_at` | timestamp with time zone | sim |  |
| `resolved_by` | uuid | sim | `users.id` |
| `created_at` | timestamp with time zone (padrão) |  |  |

## users

Criada em `db/migrations/001_identity.up.sql`. Da migração: Extensoes, roles de acesso e o modulo identity: users, partner_accounts, otp_codes, consents, sessions. Libera: login e cadastro (Fase 10 das telas).

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | uuid (padrão) |  |  |
| `role` | text |  |  |
| `full_name` | text |  |  |
| `cpf` | character | sim |  |
| `phone` | text | sim |  |
| `email` | citext | sim |  |
| `password_hash` | text | sim |  |
| `lgpd_accepted_at` | timestamp with time zone | sim |  |
| `blocked` | boolean (padrão) |  |  |
| `created_at` | timestamp with time zone (padrão) |  |  |
| `mp_customer_id` | text | sim |  |
| `birth_date` | date | sim |  |
| `deleted_at` | timestamp with time zone | sim |  |

## wallet_credits

Criada em `db/migrations/021_incident_refund.up.sql`. Credito em carteira: oferta, nunca imposicao. Por isso nasce em 'offered' e so vira saldo quando a pessoa aceita. offered -> o admin propos no lugar do estorno accepted -> a pessoa aceitou; o saldo existe e pode ser gasto declined -> a pessoa recusou; o estorno original volta a valer spent -> consumido num pedido (order_id_spent aponta qual) expired -> passou de expires_at sem ser gasto

| Coluna | Tipo | Nulo | Referência |
|---|---|---|---|
| `id` | bigint (padrão) |  |  |
| `user_id` | uuid |  | `users.id` |
| `refund_id` | bigint | sim | `refunds.id` |
| `order_id` | bigint | sim | `orders.id` |
| `amount` | numeric |  |  |
| `bonus` | numeric (padrão) |  |  |
| `state` | text (padrão) |  |  |
| `expires_at` | timestamp with time zone | sim |  |
| `order_id_spent` | bigint | sim | `orders.id` |
| `decided_by` | uuid | sim | `users.id` |
| `created_at` | timestamp with time zone (padrão) |  |  |
