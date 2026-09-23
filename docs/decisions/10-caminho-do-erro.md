# Caminho do erro (Fase 13.1 e 13.2)

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
