# A loja operando a si mesma (Fase 11.2 a 11.4)

Até aqui o painel deixava a loja atender pedido, mas não SER uma loja: ela
não podia pausar num aperto, não podia esgotar um item, não podia mudar o
horário. `is_open` só mudava por `UPDATE` no banco, e o cardápio nascia do
script de seed. Estas três telas fecham isso.

- **Duas tabelas que o mock cita e a especificação não tinha.**
  `store_pauses` ("motivo, autor, duração") e `holiday_overrides`, ambas na
  migração `018`. Sem a primeira, pausar seria um campo sobrescrito sem
  história: ninguém saberia quem pausou nem por quê, e o aviso "acima de 2 h
  por dia a loja perde o selo" não teria como ser medido. Sem a segunda,
  feriado seria editar o horário semanal na mão — e lembrar de desfazer na
  quinta seguinte.
- **Pausar não cancela nada, e o código prova isso.** O endpoint mexe em
  `restaurants.is_open`/`pause_until` e em mais nada; nenhum pedido muda de
  status. A tela mostra ao lado quantos pedidos estão em andamento e diz que
  todos continuam, que é exatamente o que a pessoa quer saber antes de
  apertar.
- **O custo da pausa é o da PRÓPRIA loja, nesta faixa de horário.** Média das
  últimas quatro semanas na mesma hora do dia: uma média do dia inteiro diria
  que pausar às 20h custa o mesmo que às 15h, que é justamente a decisão
  errada. E quando não há histórico nessa faixa, a tela diz isso em vez de
  mostrar "≈ 0" — que seria lido como "pausar não custa nada".
- **Pausa curta não fecha a loja; "fechar por hoje" fecha.** São estados
  diferentes: pausa mantém `is_open = true` e bloqueia pelo carimbo
  (`pause_until`), fechar por hoje zera `is_open` e carimba até a virada do
  dia. A segunda parte é o que impede o job de horário de reabrir a loja no
  minuto seguinte — e o teste cobre exatamente isso.
- **A pausa passou a esconder a loja de verdade.** `restaurants/list.php` e
  `search_products.php` filtravam só por `is_open`: a loja pausada continuava
  na lista de "abertos agora" e o cliente só descobria no checkout, com o
  pedido montado. Agora saem da lista, como o mock diz.
- **Voltar não é abrir.** "Voltar a receber pedidos" apaga a pausa e chama
  `apply_business_hours()`: quem decide se a loja está aberta continua sendo
  o horário. Reabrir na marra às 3h da manhã porque alguém apertou um botão
  seria aceitar pedido que ninguém vai preparar.
- **O tempo de preparo saiu do front e virou dado da loja.** A previsão de
  entrega do app do cliente era uma janela fixa de 25–45 min escrita no
  `OrderTrackingScreen.svelte`, que ninguém na loja podia corrigir. Agora é
  `restaurants.prep_minutes` + uma margem de viagem, e o "aumentar sozinho
  quando a fila passar de 8 pedidos" é calculado na LEITURA
  (`lib/catalog/store.php`), nunca gravado por cima do valor combinado — se fosse
  gravado, a fila esvaziaria e o número normal da loja teria sumido.
- **Esgotar tem rota própria.** É a ação mais frequente do dia e a única que
  não passa por rascunho; mandá-la pelo mesmo endpoint de edição faria um
  toque na lista carregar o risco de reenviar preço e descrição junto. O
  carimbo `sold_out_at` é o que permite dizer "esgotada hoje" em vez de
  "esgotada, sem saber desde quando".
- **Variações são substituídas em bloco.** A tela manda a lista inteira como
  ficou, e o endpoint apaga e reinsere dentro de uma transação. Casar uma a
  uma exigiria ids estáveis numa tela onde a pessoa adiciona e remove linhas
  livremente; e `order_items.variants_snapshot` guarda o que foi pedido, então
  apagar uma variação não reescreve pedido nenhum do passado.
- **Abrir e fechar é tarefa do `pg_cron`, não do atendente.**
  `apply_business_hours()` roda a cada minuto: apaga pausa vencida e liga ou
  desliga `is_open` pelo horário do dia, tratando turno que atravessa a
  meia-noite (o de sexta 18:00–01:00 ainda é o turno de sexta à 00:30 de
  sábado) e feriado como exceção que manda no dia inteiro. Salvar horário ou
  cadastrar feriado chama a função na hora, senão a tela mostraria um estado
  que já mudou.
- **O fuso é fixo em `America/Sao_Paulo`, e isso está escrito na migração.**
  `business_hours.opens/closes` são `time` sem fuso e não há coluna de fuso
  por loja na especificação; comparar com `now()` cru (UTC) abriria toda loja
  três horas cedo. Uma loja fora desse fuso pede uma coluna nova — decisão de
  produto, não de migração.
- **O que o mock mostra e não foi construído, com o motivo:**
  - *"Publicar invalida o cache do cardápio no edge"* — o purge do Cloudflare
    precisa de token de API, que não existe configurado aqui; a resposta
    devolve `edge_purged: false` em vez de fingir. O service worker do PWA já
    busca cardápio pela rede primeiro (Fase 7.1), então o cliente online vê o
    preço novo assim que publica.
  - *Rascunho no servidor* ("alterações não publicadas") — vive no navegador:
    guardar rascunho pediria coluna ou tabela de versão que a especificação
    não tem, e o efeito prático é o mesmo, porque o cliente só vê o que foi
    publicado. Fechar o painel sem publicar avisa antes de descartar.
  - *Foto do item* — FEITO depois (`restaurants/menu_photo.php`, ver
    "Lacunas do app do cliente fechadas").
  - *"Nova categoria"* — categoria é texto em `menu_items`, não tabela; um
    botão próprio criaria categoria fantasma, sem item dentro. A tela explica
    que ela nasce ao publicar um item com o nome dela.
  - *"Fechar 30 min mais tarde na sexta rendeu +11 pedidos"* — é comparação
    contrafactual: exigiria histórico de MUDANÇA de horário, que ninguém
    guarda. Fica o pico real, que é medido.
- **Validado com banco e navegador reais.** `tests/smoke_store.sh` cobre
  pausa sem motivo barrada, pausa de 45 min recusada, loja pausada sumindo da
  lista e barrando o checkout, "fechar por hoje" resistindo ao job, tempo de
  preparo chegando ao cliente, esgotar/voltar com carimbo, item de outra loja
  recusado (404), publicação com variações substituídas em bloco, horário
  aplicado a cinco dias numa transação, último pedido depois do fechamento
  recusado, turno que vira a madrugada aceito, o job abrindo a loja pelo
  horário, feriado de hoje fechando e sua remoção reabrindo, e os 403 de quem
  não é a loja. No Playwright: a pausa de 30 min com motivo, o sumiço da loja
  da lista de abertos, o preparo indo de 30 a 40 min em dois cliques,
  "Esgotada hoje" na linha, o rascunho marcado em vermelho e publicado, um
  item novo com variação, o horário de seg a sex salvo de uma vez e o feriado
  virando "fechada" no cabeçalho.
