# Sem entregador disponível (Fase 15.1)

"O momento que mais gera ticket e ninguém desenha: em vez de 'aguarde', três
saídas concretas e a promessa escrita de cancelamento automático com
devolução integral. A comida já feita é paga pela plataforma, não pela loja."

Até aqui, um pedido que ficava pronto e não era aceito por ninguém ficava
pronto para sempre: a oferta existia (Fase 8), mas o cliente via só "Pronto,
aguarda entregador" e não tinha saída nenhuma. Esta tela é o contrário disso.

- **A promessa da tela exigiu abrir a máquina de estados.** `('ready',
  'cancelled')` não existia na lista de transições da migração `004` -- e sem
  ela as duas promessas centrais da 15.1 ("Cancelar e receber tudo de volta"
  e "passados 15 min cancelamos sozinhos") são recusadas pelo banco, porque
  as duas acontecem com o pedido em `ready`. A 004 fechava `ready` de
  propósito ("a comida está na bancada esperando o entregador"); o caso que
  faltava era exatamente o inverso: a comida na bancada e ninguém vindo
  buscar. A migração `017` acrescenta só essa transição, e o `down` devolve a
  lista idêntica à da 004.
- **O relógio é uma coluna, não um `SELECT` derivado.**
  `orders.no_courier_since` é carimbado quando o pedido vira oferta sem
  entregador e limpo no instante em que alguém aceita (ou quando vira
  retirada). Dava pra derivar de `order_events` toda vez, mas quem lê isso é
  uma varredura que roda a cada minuto -- uma coluna indexada vale mais que
  um subselect por linha. É esse carimbo que decide o "há 6 min", a barra de
  progresso, a troca de "chamando quem está por perto" pra "está mais difícil
  que o normal" (um terço do prazo) e a hora de cancelar sozinho.
- **Turbinar o frete só existe onde o dinheiro ainda não andou.** No mock,
  quem paga os R$ 4,00 a mais é o cliente. Isso é honesto em dinheiro e
  maquininha, que pagam na entrega; em pedido já pago no cartão ou no Pix,
  cobrar a mais exigiria uma segunda transação no Mercado Pago, que este
  módulo não faz. Então a opção aparece desabilitada com o motivo escrito na
  própria tela -- não some, e não mente.
- **Turbinar mexe em DOIS lugares, na mesma transação.** `orders.surge_fee`
  (o cliente paga) e `offers.bonus` (o entregador vê). Só o primeiro seria
  cobrar sem oferecer nada; só o segundo seria prometer dinheiro que ninguém
  pagou. A oferta ainda ganha mais 5 minutos de validade, porque valor novo
  precisa de tempo pra ser visto.
- **Retirar na loja é uma devolução PARCIAL, e isso mudou `record_refund`.**
  Zerar `delivery_fee`/`surge_fee` muda o total (coluna gerada) e devolve o
  frete de quem já tinha pago -- mas o cliente continua tendo pago a comida.
  A função ganhou um parâmetro `partial` que impede o `payments.status` de
  virar `refunded`: devolver o frete não desfaz a cobrança do pedido.
- **Cancelar aqui é falha nossa, e a conta é nossa.** A causa é `no_courier`,
  que `refund_payer()` já mapeava pra `platform`, e `refund_plan()` não cobra
  taxa nenhuma -- mesmo com a cozinha já tendo terminado, que é o único caso
  em que a taxa existiria. Também não se pergunta o motivo: a tela de
  cancelamento troca os quatro motivos fechados por um só, já marcado, porque
  cobrar explicação de quem esperou quinze minutos por um entregador que não
  veio seria absurdo.
- **O cancelamento automático é PHP em cron, não `pg_cron`** --
  `bin/auto_cancel_no_courier.php`, uma linha no cron do cPanel a cada
  minuto. O timeout do Pix (migração `009`) pode viver dentro do banco porque
  lá nada foi cobrado; aqui a varredura precisa decidir dinheiro, e quem sabe
  por onde o estorno volta, quanto volta e de que bolso sai é
  `lib/payments/refunds.php`. Reescrever essa tabela em PL/pgSQL criaria uma segunda
  fonte de verdade sobre o dinheiro, e as duas iam divergir no primeiro
  ajuste. O prazo lido é o da política congelada em cada pedido, não a de
  agora: encurtar o prazo hoje não pode cancelar mais cedo o pedido de ontem.
- **A retirada precisou aparecer na cozinha e ganhar quem a feche.** O KDS
  mostra `RETIRADA — o cliente vem buscar` no lugar do nome do entregador: a
  sacola fica no balcão esperando uma pessoa, não uma moto. E como não há
  entregador pra encerrar a corrida, a loja passou a poder registrar
  `delivered` -- só em pedido de retirada. Em pedido com entrega, quem
  confirma que chegou continua sendo quem chegou.
- **O que o mock diz e não foi construído, com o motivo:**
  - *"Chuva na região e muitos pedidos ao mesmo tempo"* — não existe clima
    nem densidade de pedidos em lugar nenhum da especificação. Inventar uma
    desculpa é pior que não dar nenhuma; a tela diz o que é verdade (há
    quanto tempo procura).
  - *"Costuma achar entregador em 2 min"* — é uma estatística, e não há
    histórico de despacho pra medir. No lugar, a mecânica verdadeira: o valor
    a mais aparece na hora pra quem está com o app aberto.
  - *"Avisamos assim que alguém aceitar"* — seria push (7.2), que não existe.
    A tela se atualiza sozinha enquanto está aberta, e é isso que ela diz.
  - *`dispatch_attempts`, raio crescente e rodadas* — o despacho continua
    sendo uma rodada só (`lib/dispatch/dispatch.php`). O surge por pedido existe agora
    porque a tela precisa dele; o resto da Fase 15 não.
  - *"· 1,2 km de você"* — essa existe: `restaurants.lat/lng` (migração
    `010`) e o endereço do cliente dão a distância real, em Haversine no SQL.
    Quando a loja não tem coordenada cadastrada, a linha aparece sem a
    distância, em vez de com um número inventado.
- **O SSE dá lugar ao polling enquanto essa tela está no ar.** Aceitar uma
  corrida não passa por `advance_order`, então o stream de tempo real nem
  saberia avisar. Fechar o `EventSource` tem o efeito colateral bom de
  desbloquear o `php -S`, que atende uma requisição por vez -- o mesmo motivo
  documentado no diálogo de cancelamento.
- **Validado com banco e navegador reais.** `tests/smoke_dispatch.sh` cobre o
  relógio começando e parando, o turbo chegando na oferta do entregador, o
  turbo negado em pedido pago, a retirada com devolução parcial sem marcar o
  pagamento como estornado, o fechamento da retirada pela loja (e a recusa do
  mesmo em pedido com entrega), o cancelamento integral por `no_courier`, a
  varredura cancelando o vencido e não encostando em quem está no prazo, e os
  403/404 de pedido alheio. No Playwright: a tela da 15.1 com o contador
  andando de segundo em segundo, o turbo subindo o total de R$ 58,10 pra
  R$ 62,10, a opção desabilitada com o motivo escrito no pedido de cartão, a
  retirada devolvendo R$ 6,90 e virando "Pronto para retirada", e o KDS da
  loja fechando esse pedido no balcão.
