# Painel da loja (`web/painel.html`) — Fase 7.3 e 11.1 a 11.4

O tablet do balcão. Até aqui `restaurants/approve_pix.php` existia e passava
no smoke test, mas não tinha tela nenhuma: na prática, um pedido em Pix
manual ficava preso em `pending_verification` pra sempre. Este módulo fecha
esse buraco e junta o KDS, que é a outra metade do mesmo trabalho.

- **É uma página separada, não uma aba do app do cliente.** `vite.config.js`
  passou a ter duas entradas (`index.html` e `painel.html`): quem pede pizza
  não baixa a fila de validação de Pix, e o tablet da cozinha não baixa o
  carrinho. A sessão da loja também é separada — outra chave no
  `localStorage` (`fuu_staff_token`) —, então dá pra ter o app aberto numa
  aba e o painel noutra sem um login derrubar o outro.
- **Quatro endpoints novos, todos escopados pelo `restaurant_id` do
  token.** `pending_proofs.php` devolve numa consulta só tudo que o modal
  mostra (itens agregados em JSON, quantos pedidos o cliente já fez nesta
  loja, se aquela imagem já apareceu antes) — nada de N+1 numa tela que
  fica aberta o dia inteiro atualizando. `proof_image.php` transmite o
  arquivo com o MIME real e `Cache-Control: private, no-store`.
  `stats.php` é o "VISÃO GERAL DE HOJE" calculado no banco.
  `orders.php` ganhou `scope=kds|recent`. O smoke test prova o isolamento:
  a loja rival recebe fila vazia, `proof_not_found` na imagem e na
  aprovação, `restaurant_not_found` na fila de pedidos.
- **A imagem do comprovante é buscada com `fetch` + `Authorization` e virada
  em blob URL**, não posta direto num `<img src>`. Foi a forma de não abrir
  a exceção de token por query string (que só a rota de SSE tem, porque
  `EventSource` não manda header) numa rota que serve arquivo.
- **O cronômetro do KDS conta desde a entrada no status atual**, não desde a
  criação do pedido: "em preparo há 6 min" é o que a cozinha lê. Vem de
  `max(order_events.created_at)` para o status corrente, na mesma consulta.
- **`ready → delivering` virou transição pedível pela loja.** O mock 11.1
  desenha o botão "Entregue ao motoboy" na terceira coluna, e quem entrega a
  sacola em mãos é a loja. O botão só aparece quando existe entregador
  designado (`orders.courier_id`); sem ele o cartão diz "Aguardando
  entregador ser designado", como no mock. Quando a Fase 8 existir, o app do
  entregador ganha o mesmo alvo — são duas pessoas que podem registrar a
  mesma passagem de bastão.
- **O painel atualiza por polling (5 s), não por SSE — decisão consciente.**
  `advance_order()` já publica em `pg_notify` e a rota de SSE do cliente
  existe (`orders/track.php`), mas `php -S` atende uma requisição por vez:
  uma conexão SSE aberta no painel travaria as outras chamadas da própria
  tela (aprovar, avançar pedido, estatísticas). Sob `php-fpm` isso deixa de
  ser verdade e a troca é local, num `setInterval` só. O cabeçalho mostra a
  hora da última atualização e avisa quando o ciclo falha, em vez de fingir
  "conectado".
- **Desvio assumido do mock: o modal de validação é Svelte, não SweetAlert.**
  O mock diz "SweetAlert em tela cheia", mas a tela tem imagem com
  zoom/rotação e duas colunas de conteúdo — o `swal()` recebe um nó de
  conteúdo, não um componente. Mesma decisão já tomada no `ItemModal` (3.2).
  E a classe não se chama `.modal`: o Bootstrap reserva esse nome com
  `display:none` (ver "Módulo de carrinho").
- **Girar + ampliar exigiu conta, não CSS esperto.** `transform` não muda a
  caixa de layout, então uma foto em pé girada 90° desenha fora da moldura
  enquanto a rolagem continua achando que ela está em pé — o atendente rola
  e vê faixa branca (foi o que o Playwright mostrou na primeira versão). A
  moldura passou a ter um "calço" do tamanho *visual* da imagem já girada e
  ampliada, com a imagem centrada nele; a rolagem passeia pelo comprovante
  de verdade, e ampliar recentraliza em vez de jogar pro canto.
- **O que o mock mostra e esta tela não tem**: o botão de imprimir comanda
  (ESC/POS) e o indicador "impressora ok" ficaram de fora em vez de virarem
  botão morto — não existe integração com impressora neste repositório
  (item 4 de "Próximos passos"). Pausar loja, cardápio e horário (11.2 a
  11.4) também não foram construídos.
- **Validado de ponta a ponta com Postgres e navegador reais.**
  `tests/smoke_panel.sh` cobre os quatro endpoints, o isolamento entre
  lojas, a aprovação levando o pedido pra `paid` e as transições
  `paid → preparing → ready → delivering` (inclusive a ilegal, barrada pelo
  banco com 409). No Playwright, num tablet 1280×800: login da loja →
  aceitar → pronto → entregue ao motoboy → validar Pix pelo atalho do KDS →
  girar/ampliar o comprovante → recusa sem motivo barrada → aprovar com
  valor conferido → aba "Visão geral" → recusar o segundo comprovante com
  motivo → recarregar (sessão mantida) → sair. Zero erros de console
  (fora as fontes do Google, bloqueadas pelo proxy deste ambiente).
- **O 2FA por aparelho apareceu na prática durante o teste:** a segunda
  execução do Playwright, num navegador novo, levou `device_mismatch` e a
  tela mostrou "Este login está vinculado a outro aparelho" — que é
  exatamente o comportamento desenhado (`partner_accounts` faz confiança no
  primeiro uso). Liberar troca de tablet depende do suporte (Fase 14), que
  ainda não existe.

**Atualização (go-live).** O que mudou desde este registro:

- **A troca de aparelho existe.** O suporte acha a loja ou o entregador
  por CNPJ, CPF ou nome, na aba Aparelhos do painel da plataforma
  (`admin/partner_devices.php`), e libera com motivo. Isso encerra as sessões
  do aparelho antigo e grava no `audit_log`; o próximo login vira o aparelho
  confiável (`tests/smoke_partner_device.sh`).
