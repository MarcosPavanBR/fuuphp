# Arquitetura do FUUdelivery

Este documento explica **como o sistema é montado e por quê**: as camadas, o
caminho de uma requisição, as regras que o banco garante sozinho e como o
dinheiro circula. Para *o que existe*, veja os catálogos gerados:
[API.md](API.md) (todas as rotas) e [DATABASE.md](DATABASE.md) (todas as
tabelas). Para *como se escreve código aqui*, [CONVENTIONS.md](CONVENTIONS.md).
Para *como se opera*, [OPERATIONS.md](OPERATIONS.md). Pro que protege o
sistema, [SECURITY.md](SECURITY.md); pra como cada público o usa,
[MANUAL.md](MANUAL.md). As decisões módulo a módulo, com o contexto de cada
uma, estão em [decisions/](decisions/).

## A stack é fixa (cláusula zero)

Svelte 5 (runes) + Bootstrap 5 + Bootstrap Icons no front; PHP 8.4 com PDO,
sem framework, na API; PostgreSQL 16 com pg_cron no banco; Mercado Pago como
**único** gateway de pagamento. Nada entra na stack sem autorização -- por
isso JWT, `.env`, push VAPID e o parser de CSV são escritos aqui, pequenos e
testados, em vez de virem de biblioteca. As exceções de front são as que o
próprio mock cita nos chips das telas: SweetAlert e Leaflet.

## Visão geral

```
 ┌──────────────── web/ (Vite, 4 apps, 1 build) ────────────────┐
 │ index.html      cliente        (PWA: offline, push)          │
 │ painel.html     loja           (KDS, validação de Pix)       │
 │ entregador.html entregador     (ofertas, rota, caixa)        │
 │ admin.html      plataforma     (políticas, netting, disputas)│
 └───────────────┬──────────────────────────────────────────────┘
                 │ HTTPS + JSON (+ SSE no acompanhamento)
 ┌───────────────▼──── api/v1/<área>/<ação>.php ────────────────┐
 │ um arquivo por rota; cada um: bootstrap → guarda → valida →   │
 │ transação → resposta. Nenhuma regra de negócio mora aqui     │
 │ se ela for usada por mais de uma rota: essa vai pra lib/     │
 └───────────────┬──────────────────────────────────────────────┘
 ┌───────────────▼──── lib/ ────────────────────────────────────┐
 │ core/       env, banco, HTTP, JWT, sessão, OTP, idempotência │
 │ catalog/    política comercial, operação da loja, card       │
 │ ordering/   pedido, carrinho, cupom, agendamento, frete      │
 │ payments/   Mercado Pago, Pix, estorno, carteira             │
 │ ledger/     livro contábil, maquininha, netting semanal      │
 │ dispatch/   ofertas em rodadas, ocorrências                  │
 │ messaging/  push, notificações, suporte                      │
 │ printing/   ESC/POS: comanda e recibo de baixa               │
 │ account/    LGPD (exportar, excluir), fidelidade (pontos)    │
 └───────────────┬──────────────────────────────────────────────┘
 ┌───────────────▼──── PostgreSQL 16 ───────────────────────────┐
 │ advance_order()  ·  ledger append-only  ·  outbox  ·  pg_cron │
 └───────────────▲──────────────────────────────────────────────┘
                 │
 ┌───────────────┴──── bin/ (cron do servidor, a cada minuto) ──┐
 │ push_worker · dispatch_rounds · execute_refunds ·            │
 │ auto_cancel_no_courier · apply_financial_blocks              │
 └──────────────────────────────────────────────────────────────┘
```

## O caminho de uma requisição

Toda rota é um arquivo PHP em `api/v1/<área>/<ação>.php` (a URL é o caminho
do arquivo -- contrato público, não muda). O arquivo segue sempre a mesma
ordem, e é por ela que se lê qualquer um:

1. `require_once .../lib/bootstrap.php` -- carrega `.env`, todas as libs,
   CORS de desenvolvimento e o tratador de erro que transforma exceção em
   `500 {code, message, trace_id}` sem vazar stack trace. Por último, a
   **trava de produção**: em `staging`/`production` com integração simulada
   ou sem segredo, a requisição para aqui com 503 ([GO_LIVE.md](GO_LIVE.md)).
2. **Comentário de cabeçalho** -- a tela do mock que a rota serve e as
   decisões dela. O primeiro parágrafo vira o resumo em [API.md](API.md).
3. `require_method('POST')` e o **guarda**: `require_auth()` (token),
   depois `require_admin` / `require_store_staff` / `require_courier` ou a
   checagem de papel do cliente. O token é JWT HS256 de 15 min; o refresh
   gira a cada uso (`lib/core/sessions.php`).
4. **Validação** do corpo, sempre devolvendo `422` com `fields` quando é
   erro de preenchimento (`error_response()` em `lib/core/response.php`).
5. **Transação** quando há mais de uma escrita. Chamada de rede (Mercado
   Pago, push) fica FORA da transação: rede não segura lock de banco.
6. `json_response()`.

Escrita que não pode rodar duas vezes por um retry de rede exige
`X-Idempotency-Key` (`lib/core/idempotency.php` guarda a resposta e devolve
a mesma no replay). Upload de comprovante usa a mesma ideia pelo UUID da
fila offline.

## Regras que o banco garante sozinho

O código não precisa repetir estas regras, e não deve tentar contorná-las:

**Máquina de estados do pedido.** `orders.status` só muda pela função
`advance_order()` (migração 004, versão atual na 017). Ela trava a linha,
recusa transição ilegal, grava `order_events` (a linha do tempo que cliente,
loja e suporte leem), escreve na `outbox` e dispara `pg_notify` pro SSE.

```
cart → pending_payment ─┬→ paid ───────→ preparing → ready → delivering → delivered
                        ├→ pending_verification ─┘ (Pix manual: loja confere)
                        └→ rejected / cancelled          (qualquer etapa até delivering
                                                           pode ir pra cancelled)
paid / delivered / cancelled → refunded
```

**Livro contábil só de inserção.** `ledger_entries` não aceita UPDATE nem
DELETE (REVOKE na migração 006). Corrigir é lançar a contrapartida.

**Um pagamento aprovado por pedido** (índice `payments_one_approved`). Por
isso a gorjeta da avaliação mora em `reviews`, não em `payments`.

**Idempotência por chave única**: comprovante (`upload_key`), notificação
(`outbox_id, kind`), resgate de cupom (`coupon_id, cpf`), lançamentos por
origem.

**Uma loja não enxerga a outra (RLS).** Em produção a API conecta como
`app_rw`, que não é dona das tabelas. Em `orders`, `payments`,
`payment_proofs` e `order_messages` (migração 009), `app_rw` só vê as linhas
de acordo com o que a conexão diz ser:

- `db()` abre toda conexão como `app.role = platform`, porque a autorização
  fina é do PHP;
- `require_store_staff()` troca pra `store` + o `restaurant_id` do token. Se
  uma rota de loja esquecer o filtro, o banco ainda esconde o pedido da
  vizinha.

`app_rw` também não altera o livro nem os pontos e não faz DDL
(`tests/smoke_db_roles.sh`).

## Dinheiro: quem deve o quê

Todo valor que muda de mão vira linha em `ledger_entries`, numa de cinco
contas (`lib/ledger/order_ledger.php` explica o modelo inteiro):

| Conta | Positivo significa |
|---|---|
| `store_receivable` | a loja deve à plataforma |
| `courier_cash` | o entregador está com dinheiro da plataforma (espécie) |
| `courier_payable` | a plataforma deve ao entregador (frete, bônus, gorjeta) |
| `platform_revenue` | receita (comissão, taxas) |
| `platform_expense` | despesa (cupom bancado, surge, compensação) |

Na entrega, a parte da loja (subtotal − comissão) é lançada conforme onde o
dinheiro do cliente caiu: na conta da plataforma (cartão, Pix automático),
direto na loja (Pix manual, maquininha da loja) ou na mão do entregador
(dinheiro, maquininha dele). O **netting semanal** (`lib/ledger/netting.php`)
compensa tudo toda terça: cada parte recebe ou paga só a diferença. Loja com
repasse atrasado cai pra "somente online" -- aplicado em `resolve_policy()`,
então checkout, troca de método e a tela 4.1 obedecem juntos.

## Tempo real e trabalho em segundo plano

- **SSE** (`orders/track.php`) empurra cada evento do pedido pro cliente.
- **Outbox → push**: `advance_order()` escreve em `outbox`; o
  `bin/push_worker.php` lê, cria `notifications` e acorda os aparelhos com um
  push VAPID sem conteúdo; o service worker busca o texto em
  `push/pending.php`. Nada se perde se o worker estiver parado: a outbox
  espera.
- **pg_cron** (dentro do banco, migrações 009 e 018): recusa Pix não
  validado no prazo (`pix-verification-timeout`), expira intenção de baixa
  de espécie, gera os repasses da semana toda terça às 3h
  (`weekly-payouts`), abre e fecha a loja pelo horário (`business-hours`) e
  purga dado com retenção vencida.
- **cron do servidor** (`bin/*.php`): o que depende de política lida em PHP
  ou de rede -- rodadas do despacho, estornos no gateway, bloqueios
  financeiros, cancelamento sem entregador, push.

## Front-end

Um projeto Vite com quatro entradas (`web/src/entries/`), cada uma montando
a raiz do seu app (`web/src/apps/`). O que é compartilhado fica em `lib/`:

```
web/src/
  entries/            customer.js · store-panel.js · courier.js · admin.js
  apps/               CustomerApp · StorePanelApp · CourierApp · AdminApp
  lib/services/       api.js (fetch + token + erro), push.js, uploadQueue
  lib/state/          sessões de cada app, carrinho, PWA  (*.svelte.js = runes)
  lib/utils/          datas do Postgres, toasts
  lib/components/     peças usadas por mais de uma tela (mapa, foto, chat…)
  lib/screens/<app>/  as telas do mock, uma por arquivo, com o número da tela
  lib/data/           dados estáticos (UFs e cidades)
  styles/tokens.css   cores, fontes e medidas do mock (variáveis --fuu-*)
```

O app do cliente é PWA: `web/public/sw.js` guarda o shell e as rotas
públicas de catálogo (nunca dado de conta), enfileira comprovantes offline
(IndexedDB, reenviados pela página) e recebe push.

## Testes

Cada módulo tem um `tests/smoke_<área>.sh` que sobe o servidor embutido do
PHP, semeia dados próprios e exercita as rotas de verdade contra um banco
migrado -- incluindo os caminhos de erro e as regras de dinheiro. O CI
(`.github/workflows/ci.yml`) aplica todas as migrações, prova o round-trip
`down`/`up`, roda todas as suítes na ordem, confere os catálogos gerados e
compila o front.
