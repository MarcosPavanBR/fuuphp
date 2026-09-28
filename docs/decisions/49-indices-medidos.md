# 49 — Índices que faltavam, medidos (migração 046)

**De onde veio:** a mesma varredura das decisões 47 e 48. A pergunta era se
alguma tela do dia a dia fica mais lenta conforme o número de pedidos
cresce.

## Como foi medido

Nada de adivinhar pelo nome da coluna:

1. A suíte inteira (40 suítes) rodou com `pg_stat_statements` ligado. Isso
   dá as consultas **reais** que a API faz, com quantas vezes cada uma
   rodou.
2. Cada consulta foi explicada com `enable_seqscan = off`. Se mesmo assim o
   plano varre a tabela inteira, não existe índice que sirva pro filtro.
3. Só entrou índice pra tabela que cresce com os pedidos e pra consulta que
   o código faz de verdade. Um candidato (livro por pedido) saiu, porque só
   o teste lia o livro assim.

## O que entrou

| Índice | Pra quê |
|---|---|
| `ledger_entries (origin, origin_id)` | trava de idempotência do livro: rodava em todo estorno e baixa, lendo o livro inteiro |
| `payments (order_id)` | pagar, trocar método, cancelar, webhook |
| `dispatch_attempts (order_id)` | contagem de rodadas de despacho |
| `card_transactions (order_id)` | venda na maquininha do pedido |
| `orders (address_id)` | "endereço em uso?" (migração 043) |
| `orders (courier_id, status)` | corridas do entregador |
| `orders (restaurant_id, status)` | lojas lotadas na vitrine, pausa, horário |
| `otp_codes (user_id, purpose, created_at)` | limite de 3 códigos a cada 10 minutos |
| `disputes (order_id)` | disputa do pedido |
| `tickets (user_id, created_at)` | central de ajuda |
| `delivery_proofs (sha256)` | foto de entrega reaproveitada (fraude) |
| `partner_accounts (user_id)` | toda renovação de sessão de parceiro |
| `pos_custody (device_id)` | histórico da maquininha |
| `cash_settlement_intents (restaurant_id, code_hash)` | baixa pelo código no balcão |
| `menu_items (restaurant_id)` | cardápio inteiro no painel (o índice antigo só cobre item disponível) |

Depois da migração, a mesma medição não achou mais varredura nessas
consultas. O que sobrou são telas do admin com poucas linhas (relatório,
fraude) e tabelas pequenas.

## Consulta dentro de laço (N+1)

Contadas as consultas por requisição nas telas que ficam consultando o
servidor: cozinha, fila de Pix, caixa da loja, app do entregador,
acompanhamento, pedidos do cliente, ajuda e as listas do admin. A contagem
foi feita com 1 pedido na fila e depois com 40.

**O número não mudou em nenhuma.** A maior é a de ofertas do entregador,
com 17 consultas, mas fixa. O acompanhamento ao vivo usa LISTEN/NOTIFY do
PostgreSQL e só relê o pedido quando ele muda.
