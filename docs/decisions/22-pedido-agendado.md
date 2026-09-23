# Pedido agendado (Fase 14.4)

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
