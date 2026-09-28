# 40 — Fuso por cidade

**O que foi achado:** o Brasil tem quatro fusos, e o sistema rodava inteiro no
de Brasília (migração 018 e decisão [38](38-revisao-do-codigo.md)). A cópia da
Home do concorrente que o Marcos mandou é de Ribas do Rio Pardo, Mato Grosso
do Sul -- UTC−4, uma hora atrás de Brasília. Numa cidade assim, loja que abre
às 18h abriria às 17h da cidade, as faixas de agendamento sairiam uma hora
erradas, e "fechar por hoje" reabriria às 23h. A própria migração 018 já
dizia que loja fora de Brasília "pede uma coluna nova".

**O que foi feito** (migração 038):

- `service_cities.timezone`: o fuso da cidade, entre os oito do país que o
  sistema aceita. O admin escolhe na aba Cidades; vazio, vale o da UF (MS,
  MT, AM, RO e RR em UTC−4; AC em UTC−5; o resto em UTC−3). As cidades já
  cadastradas ganharam o fuso pela UF.
- `restaurant_timezone(id)` no banco e `store_timezone()` no PHP: o relógio
  de uma loja é o da cidade dela.
- Passaram a usar o relógio da loja: o abrir/fechar automático
  (`apply_business_hours`), as faixas de agendamento e o "hoje" delas, o
  "fechar por hoje", o dia da conciliação da maquininha, as telas de horário e
  de pausa do painel, a hora carimbada no comprovante de Pix e na comanda, a
  hora prevista no push "saiu para entrega", o prazo da baixa por Pix, e o
  gráfico de pedidos por hora dos Relatórios.
- Ficam no de Brasília, de propósito: o que é da plataforma (acerto de
  terça, exportação, a sessão do banco) -- é um calendário só pra todo mundo.
- Teste: `tests/smoke_timezone.sh`. Duas lojas, uma em MS e outra em SP, com
  o mesmo horário montado pra estar aberto agora no relógio de MS: só a de MS
  abre. As faixas saem com `-04:00`, o "hoje" é o de MS e "fechar por hoje"
  vai até a meia-noite de MS.

**Simplificado / fora:** ~~banners usam o dia de Brasília~~ **resolvido na
[41](41-conferencia-final.md):** o banner de uma cidade começa e termina na
meia-noite dela; o de todas as cidades segue Brasília. Cupom de loja não tem
virada de dia: dura N dias a partir do instante em que foi criado.
