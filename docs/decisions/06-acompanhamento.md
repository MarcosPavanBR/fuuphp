# Módulo de acompanhamento pós-pedido

Fase 5 do mock (aprovado, em análise, tracking em tempo real, rejeitado,
avaliação), na parte que é backend puro: linha do tempo, SSE e avaliação.
A Fase 5 no front (as 5 telas de verdade) ainda não foi portada — ver
"Próximos passos".

- **`reviews` é tabela nova (migração 011), fora das 42 originais —
  decisão consciente, não um esquecimento.** A migração `010` já tinha
  deixado registrado que "agregação, moderação" (nota média da loja
  exibida em Home/Search, moderação de comentário) ficava de fora por
  exigir decisão de produto. Esta migração respeita esse limite: nenhuma
  coluna de nota agregada entra em `restaurants`, nenhuma tela lista ou
  pondera reviews de outros usuários. O que entra é só o registro do
  feedback em si — rating, tags, comentário, gorjeta pro entregador — os
  campos que a tela 5.5 descreve, um por pedido (`UNIQUE (order_id)`), sem
  inventar além disso.
- **Gorjeta da avaliação (`courier_tip`) é REGISTRADA, não cobrada de
  novo.** O mock diz "cobrada no mesmo cartão do pedido" — fazer isso de
  verdade exigiria uma segunda transação no Mercado Pago associada ao
  pagamento original, que este módulo não implementa. Mesmo padrão de
  dinheiro/maquininha no módulo de pagamentos: intenção registrada,
  captura de valor de verdade fica pra outro módulo.
- **Avaliação só é aceita com `status = 'delivered'`** — e nenhum pedido
  chega lá sozinho ainda: a transição `delivering → delivered` só existe
  pra quem tem o app do entregador (Fase 8), que não foi construído nesta
  passada. `tests/smoke_tracking.sh` avança o pedido manualmente via
  `advance_order()` por `psql`, o mesmo artifício que os outros smoke
  tests já usavam pra simular etapas de um módulo futuro (`smoke_ordering.sh`
  já fazia isso pra simular pagamento aprovado antes deste módulo existir).
- **SSE de verdade, não polling disfarçado — com um limite documentado.**
  `orders/track.php` abre uma conexão `LISTEN order_changed` numa conexão
  pgsql crua (`raw_pg_connect()`, PDO não tem LISTEN/NOTIFY assíncrono) e
  manda um evento a cada `pg_notify` que `advance_order()` já dispara
  (migração 004). A conexão dura só 25 segundos de propósito: o servidor
  embutido do PHP (`php -S`), usado neste ambiente de desenvolvimento,
  processa uma requisição por vez — segurar a conexão pra sempre travaria
  o resto da API. O `EventSource` do navegador reconecta sozinho quando a
  conexão cai (é o próprio protocolo SSE), e cada reconexão manda um
  snapshot completo primeiro — então nenhum evento se perde, funciona como
  long-poll encadeado. Atrás de PHP-FPM com múltiplos workers (produção),
  o mesmo código aguentaria uma janela bem maior sem esse limite ser
  necessário.
- **`EventSource` não manda header customizado — o token vai por query
  string nesta rota, como exceção documentada.** `require_auth_header_or_query()`
  (`lib/core/auth_guard.php`) aceita `?token=` só aqui; toda outra rota continua
  exigindo `Authorization: Bearer` normalmente. É `GET`, então o token na
  URL não é mais exposto do que qualquer outro parâmetro de leitura —
  ainda assim, um access token de 15 minutos de vida, não um refresh.
- **`orders/show.php` ganhou `events`** (a mesma linha do tempo que o SSE
  manda) — pra quem abre o pedido sem já estar ouvindo o SSE (ex.: entrou
  direto pela lista de pedidos) ver o histórico sem esperar o próximo
  evento.
- **Validado contra Postgres e PHP reais, com o SSE testado de verdade —
  não só a forma do JSON.** `tests/smoke_tracking.sh` abre a conexão SSE
  de um processo, dispara `advance_order()` de OUTRO processo (`psql`) 2
  segundos depois, e confere que o evento `preparing` chegou no stream
  antes da conexão fechar — prova que o `LISTEN/NOTIFY` está entregando de
  verdade entre processos, não só que a rota responde 200.

**Atualização (go-live).** O que mudou desde este registro:

- A Fase 5 no front foi portada: aprovado, em análise, acompanhamento com
  mapa, rejeitado e avaliação ([08](08-front-end.md),
  [16](16-lacunas-do-app-do-cliente.md)).
