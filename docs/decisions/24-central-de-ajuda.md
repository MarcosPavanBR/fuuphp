# Central de ajuda (Fase 14.1)

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
