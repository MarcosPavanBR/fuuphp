# Glossário

Os termos do FUUdelivery, na língua do negócio e no nome que têm no código.

| Termo | O que é | No código |
|---|---|---|
| **Admin fundador** | o primeiro admin da plataforma, criado uma vez no servidor | `bin/bootstrap_admin.php` |
| **Aparelho confiável** | o primeiro tablet/celular em que a loja ou o entregador entra; outro aparelho só com liberação do suporte | `partner_accounts.device_id`, `admin/partner_devices.php` |
| **Banner** | a imagem do carrossel da Home, criada pela plataforma; pode levar a uma loja | `promo_banners`, `admin/banners.php`, `banners/list.php` |
| **Baixa (de espécie)** | o entregador acerta com a loja o dinheiro que recebeu em mãos: presencial ou por Pix com comprovante | `cash_settlement_intents`, `settlement_proofs`, `ledger_cash_settled()` |
| **Caixa do entregador** | quanto dinheiro em espécie o entregador tem em mãos (a devolver) | `courier_cash_balance()` |
| **Cadastro de loja / loja em análise** | a loja se cadastra sozinha pelo painel; até a plataforma aprovar, monta o cardápio mas não vende | `restaurants/signup.php`, `restaurants.approved_at`, `require_store_accepting_orders()` |
| **Canal (de OTP)** | por onde o código de login chega: SMS, WhatsApp ou e-mail, conforme o provedor | `otp_sender_channels()`, `auth/channels.php` |
| **Chave Pix da loja** | onde cai o Pix direto do cliente: aleatória, e-mail, telefone ou o CNPJ da loja, nunca CPF | `restaurant_credentials.pix_key`, `store_pix_key_check()` |
| **Código de acesso** | os 6 dígitos que o entregador usa com o CPF pra entrar; gerado na aprovação, guardado em bcrypt | `partner_accounts.access_code_hash` |
| **Código de entrega** | os 4 dígitos que o cliente dá ao entregador na porta pra fechar o pedido | `orders.delivery_code` |
| **Comanda** | o papel que sai na impressora da cozinha quando o pedido entra | `print_order_ticket()` |
| **Comissão** | o percentual da plataforma sobre o pedido (8% na política inicial) | `platform_policies.commission_bps`, `orders.commission` |
| **Comprovante (Pix)** | a foto do Pix que o cliente envia no Pix direto pra loja | `payment_proofs`, `lib/payments/proof_images.php` |
| **Conciliação** | casar o extrato da maquininha (CSV) com as vendas informadas | `restaurants/reconciliation.php` |
| **Cidade atendida (praça)** | cidade em que a plataforma opera; só ela aparece no onboarding e aceita cadastro de loja | `service_cities`, `cities/list.php`, `admin/cities.php` |
| **Cupom pessoal** | cupom que só uma conta usa: a troca de pontos da fidelidade | `coupons.owner_user_id` |
| **Despacho / rodadas** | oferecer a corrida aos entregadores em rodadas, com raio e valor crescendo | `lib/dispatch/`, `bin/dispatch_rounds.php`, `dispatch_attempts` |
| **Espécie** | dinheiro vivo (meio de pagamento `cash`) | `payment_method = 'cash'` |
| **Estorno / reembolso** | devolver dinheiro ao cliente, pelo caminho do meio de pagamento | `refunds`, `refund_plan()`, `bin/execute_refunds.php` |
| **Fake (modo)** | Mercado Pago e push simulados, pra rodar sem conta real; proibido em produção | `mp_mode()`, `push_mode()` |
| **Favorita (loja)** | loja marcada com o coração pelo cliente; vira atalho na Home | `favorite_restaurants`, `profile/favorites.php` |
| **Fidelidade / pontos** | 1 ponto por real de subtotal, trocado por cupom pessoal | `loyalty_entries`, `loyalty_rewards` |
| **Fuso da cidade** | o relógio das lojas de uma cidade (MS, MT, AM, RO, RR: UTC−4; AC: UTC−5); o da plataforma é o de Brasília | `service_cities.timezone`, `restaurant_timezone()`, `store_timezone()` |
| **Homologação (staging)** | o ambiente igual à produção, mas com o Mercado Pago de teste | `APP_ENV=staging` |
| **KDS** | a tela da cozinha: a fila de pedidos pra aceitar, preparar e marcar pronto | `KdsBoard.svelte` |
| **Livro (livro-razão)** | todo movimento de dinheiro, só de inserção: quem deve o quê a quem | `ledger_entries`, `lib/ledger/` |
| **Loja só-online** | loja que só aceita cartão e Pix: nova (30 dias) ou em atraso | `restaurants.online_only_until` |
| **Maquininha (POS)** | a máquina de cartão levada pelo entregador | `pos_devices`, `pos_custody`, `card_transactions` |
| **Netting (acerto semanal)** | toda terça: o que a plataforma deve e o que cobra de cada loja e entregador, compensado num valor só | `generate_weekly_payouts()`, `admin/netting.php` |
| **OTP** | o código de uso único do login (6 dígitos, 5 minutos) | `otp_codes`, `lib/core/otp.php` |
| **Outbox** | a fila de eventos que o banco grava a cada mudança de pedido; o worker de push lê dela | `outbox`, `bin/push_worker.php` |
| **Pix automático** | Pix gerado pelo Mercado Pago, confirmado sozinho pelo webhook | `pix_auto` |
| **Pix direto pra loja (manual)** | Pix pra chave da própria loja, conferido por uma pessoa pelo comprovante | `pix_manual`, `restaurants/approve_pix.php` |
| **Política** | as regras de dinheiro da plataforma, versionadas: mudar cria versão nova | `platform_policies`, `resolve_policy()` |
| **Recibo de baixa** | o papel assinado (HMAC) que comprova a baixa de espécie | `print_settlement_receipt()` |
| **Repasse** | o pagamento semanal da plataforma à loja ou ao entregador | `payouts` |
| **Queridinhos** | os itens mais pedidos (entregues) da cidade nos últimos 30 dias, de lojas abertas, até 2 por loja | `restaurants/popular_items.php` |
| **RLS** | a regra do banco que esconde de uma loja os pedidos das outras | migração 009, `db_scope_to_restaurant()` |
| **Saúde (health)** | a rota que o monitor externo consulta: banco e pg_cron respondendo | `api/v1/system/health.php`, `cron_healthy()` |
| **Ticket médio** | o que o cliente paga, em média, por pedido pago no período (itens, frete e gorjeta, já com desconto) | `admin/reports.php` (`totals.average_ticket`) |
| **Surge** | o valor a mais na corrida quando falta entregador | rodadas de despacho |
| **Teto de cupom (da loja)** | quanto a loja pode ter comprometido em cupons criados por ela | `restaurants.coupon_budget_limit` |
| **Teto de espécie** | quanto dinheiro vivo o entregador pode ter em mãos (R$ 300) | `platform_policies.cash_ceiling` |
| **Trava de produção** | a checagem que impede a API de subir com algo simulado ou sem segredo | `lib/core/production_guard.php` |
| **Turbinar o frete** | o cliente oferece mais pelo frete quando ninguém aceita a corrida | tela 15.1 |
| **`app_rw`** | o papel do banco com que a API conecta em produção (sem ser dona das tabelas) | migração 001, `deploy/postgres/` |
| **`advance_order()`** | a única porta pra mudar o status de um pedido; recusa transição ilegal | migração 004 (atual: 017) |
| **`trace_id`** | o identificador de cada requisição, que aparece no erro e no log | `trace_id()` |
