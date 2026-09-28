# Fidelidade (tela 2.3, migração 029)

Era "decisão de produto pendente", mas o mock desenha a tela inteira -- "Seus
pontos 1.240 de 1.500 · Faltam 260 pontos para o cupom de R$ 20 · R$ 10 de
desconto 800 pontos · Entrega grátis 1.500 pontos · Histórico" -- e diz como
calcular: "saldo calculado no banco (soma dos lançamentos), nunca no
cliente". Foi construída seguindo isso à risca.

- **Livro de pontos só de inserção** (`loyalty_entries`, REVOKE UPDATE/DELETE),
  mesmo desenho do livro financeiro. Saldo = `SUM(points)`. Cada lançamento
  tem origem única: rodar de novo não dá ponto de novo.
- **Ganha na entrega**, não no pagamento (pedido cancelado não dá ponto), junto
  do acerto da entrega (`ledger_order_delivered`). **Suposição declarada:** o
  mock não diz a taxa; ficou 1 ponto por real de subtotal (itens -- frete,
  gorjeta e desconto não contam), ajustável na política
  (`platform_policies.loyalty_points_per_brl`). O "+128" do mock é um pedido de
  R$ 128.
- **Estorno devolve os pontos** na proporção do valor estornado
  (`record_refund` → `loyalty_reverse_for_refund`). O saldo pode ficar negativo
  se a pessoa já gastou; o livro registra o fato.
- **Catálogo de trocas em tabela** (`loyalty_rewards`), com os valores do mock:
  R$ 10 (800), R$ 20 (1.500), entrega grátis (1.500). A meta mostrada é a troca
  mais barata que o saldo ainda não paga -- com 1.240 pontos, exatamente o
  "Faltam 260 pontos para o cupom de R$ 20" do mock (o teste confere).
- **Trocar gera um cupom pessoal** (`coupons.owner_user_id`) pago pela
  plataforma e válido por 30 dias. Ele passa pela máquina de cupom que já
  existe -- desconto, orçamento, livro contábil, um uso por CPF -- e só o dono
  consegue usar (`409 coupon_audience`, "cupom pessoal"). A troca trava a conta
  (`FOR UPDATE`) pra dois resgates simultâneos não gastarem o mesmo saldo.
- A tela mostra saldo, meta com barra, "Resgatar agora" (a melhor troca que já
  dá), as trocas com quanto falta, os cupons de pontos ainda não usados (toque
  copia o código) e o histórico. O perfil (2.5) mostra o saldo real.

**Decidido pelo Marcos: entra no lançamento.** Ela está no roteiro de
validação do go-live ([GO_LIVE.md](../GO_LIVE.md#8-validação-antes-de-abrir)).
