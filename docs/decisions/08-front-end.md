# Front-end (`web/`) — Fases 1 a 6, 7.1, 8, 9, 10, 12, 13, 14, 15.1, decisões

Svelte 5 + Vite, Bootstrap 5, Bootstrap Icons, `sweetalert` (não
`sweetalert2` — o pacote `sweetalert` na versão 2.x do npm *é* a
biblioteca clássica, a mesma API `swal()` que o mock usa). Fase 1 (splash,
seleção de estado, cidade+bairro), Fase 2 (home, busca, fidelidade,
pedidos, perfil), Fase 3 (loja, item, carrinho), Fase 4 (pagamento), Fase 5
(pós-pedido), Fase 6 (conta, endereços, cartões, configurações) e Fase 10.1
a 10.3 (login e cadastro) e Fase 13.1/13.2 (cancelar e recusar) estão
portadas, mais o PWA com modo offline (7.1), o chat do pedido (14.2), o
painel da loja (Fase 7.3, 9.3 e 11.1, em `painel.html`), o app do entregador
(Fase 8 e 9, em `entregador.html`) e o painel da plataforma (Fase 12 e tela
10.5, em `admin.html`); todos têm seção própria adiante. As outras fases
ainda não têm componente.

```
web/
  public/
    manifest.webmanifest          7.1 — standalone, tema #CC2B1D, ícones 192/512
    sw.js                         7.1 — cache do shell e das rotas públicas;
                                   nada autenticado, nada que não seja GET
  src/
    styles/tokens.css         paleta, tipografia e forma extraídos do HTML
                               de origem (grep de #hex por frequência —
                               ver comentário no topo do arquivo)
    lib/
      api.js                      cliente fetch fino (base URL, token, erros,
                                   headers extra, multipart -- ver Fase 4)
      customerSession.svelte.js            estado de sessão reativo (login/logout real)
      cart.svelte.js                estado do carrinho, espelha a resposta da API
                                     a cada ação (não um store que finge sincronizar)
      toastr.js                    toastr sem jQuery (ver abaixo)
      datetime.js                   parsePgTimestamp() -- normaliza timestamptz
                                     do PDO (offset de 2 dígitos, sem T) pra
                                     algo que Date() sempre entende
      pwa.svelte.js                 7.1 — registra o service worker, guarda o
                                     convite de instalação, sabe se está online
      components/
        PhoneStatusBar.svelte          barra "9:41" que aparece em toda tela
        PhoneScreen.svelte              moldura de largura de celular
        BottomNav.svelte                5 abas (house/search/cart/star/person)
        QuickAddress.svelte             endereço mínimo real (ver Fase 4 abaixo)
        ItemModal.svelte                3.2 — variações, observação, preço ao vivo
        OrderChat.svelte                14.2 — chat de três pontas (cliente e loja)
      screens/
        AuthFlow.svelte                 orquestra a Fase 10: 10.1 -> 10.2 -> 10.3
        ScheduleScreen.svelte           14.4 — "quando você quer receber?":
                                         faixa com vaga real, antes da escolha
                                         do pagamento
        HelpScreen.svelte               14.1 — central de ajuda: assunto é o
                                         pedido de agora, e cada atalho
                                         responde antes de abrir chamado
        CancelDialog.svelte             13.1 — taxa e estorno antes de confirmar
                                         (e a devolução integral da 15.1)
        NoCourierPanel.svelte           15.1 — pronto e sem entregador: relógio,
                                         turbo, retirada e cancelar com tudo de
                                         volta; vive dentro de OrderTracking
        LoginScreen.svelte              10.1 — telefone ou e-mail, código de uso único
        OtpScreen.svelte                10.2 — seis caixas, reenvio, WhatsApp
        SignupScreen.svelte             10.3 — cadastro com base legal por bloco
        SplashScreen.svelte                   1.1 — fade, avança sozinho (pulado quando
                                         a praça já está salva)
        StatePickerScreen.svelte            1.2 — busca + lista com contagem de lojas
        CityPickerScreen.svelte               1.3 — busca de cidade, bairro, SweetAlert
        HomeScreen.svelte                     2.1 — categorias, lojas por distância real
        SearchScreen.svelte                   2.2 — busca de produto por trigram
        LoyaltyScreen.svelte                  2.3 — só desenho, dado de exemplo (ver abaixo)
        OrdersScreen.svelte                   2.4 — pedidos do cliente, tabs em andamento/histórico
        ProfileScreen.svelte                  2.5 — perfil, estatísticas, endereços
        RestaurantScreen.svelte           3.1 — cardápio por categoria, item esgotado visível
        CartDrawer.svelte               3.3 — itens, cupom (só UI), totais reais
        PaymentFlow.svelte              orquestra a Fase 4 inteira (endereço ->
                                         seleção -> método -> resultado)
        PaymentSelectorScreen.svelte          4.1 — grid de 5 métodos + BitPay (BETA, desabilitado)
        CardPaymentScreen.svelte                 4.2 — campos do cartão (tokenização real pendente)
        PixPaymentScreen.svelte               4.3 — QR + copia-e-cola real, contador do banco
        ProofUploadScreen.svelte            4.4 — compressão via canvas, progresso real (XHR)
        CashPaymentScreen.svelte              4.5 — troco, valida contra o total
        MachinePaymentScreen.svelte           4.6 — débito/crédito, bandeiras aceitas
        OrderTrackingScreen.svelte            5.1/5.2/5.3/5.4 numa tela só, reagindo
                                         ao status ao vivo por SSE (ver abaixo)
        ReviewScreen.svelte             5.5 — nota, tags, gorjeta, comentário
        AddressesScreen.svelte          6.1 — CRUD completo, CEP real (ViaCEP),
                                         padrão, bloqueio de apagar em uso
        PaymentMethodsScreen.svelte           6.2 — cartões salvos via Mercado Pago
        SettingsScreen.svelte           6.3 — notificações por tipo (localStorage)
        panel/                        painel da loja (entrada painel.html)
          StaffLoginScreen.svelte             10.7 — CNPJ + senha, 2FA por aparelho
          ProofQueue.svelte             7.3 — fila de validação, mais urgente no topo
          ProofReviewModal.svelte       7.3 — comprovante à esquerda (zoom/rotação),
                                         pedido à direita, aprovar ou recusar
          KdsBoard.svelte               11.1 — três colunas, cronômetro por pedido,
                                         fila de Pix fixa no canto
          RejectDialog.svelte           13.2 — recusar mostrando o custo real
          CashDeskScreen.svelte               9.3 — conferir e confirmar a baixa de espécie
        admin/                        painel da plataforma (entrada admin.html)
          AdminLoginScreen.svelte             login por OTP (admin é pessoa, não aparelho)
          StoreQueue.svelte             12.1 — aprovar/recusar cadastro de loja
          DisputeQueue.svelte           12.2 — ocorrências e galeria antifraude
          ReportsScreen.svelte          12.3 — os números que mudam decisão
          PolicyScreen.svelte           10.5 — política versionada da plataforma
        courier/                      app do entregador (entrada entregador.html)
          CourierLoginScreen.svelte           10.7 — CPF + código, 2FA por aparelho
          CourierHomeScreen.svelte            8.1 — turno, saldo em espécie, teto
          OfferList.svelte              8.2 — oferta com ganho, distância e troco
          RideScreen.svelte             8.3 a 8.6 — coleta, entrega e prova
          EarningsScreen.svelte         8.7 — livro de lançamentos
          SettleScreen.svelte           9.1/9.2/9.4 — baixa de espécie
          OverviewScreen.svelte          7.3 — visão geral de hoje + pedidos recentes
    CustomerApp.svelte                  orquestra Fase 1 -> Fase 2 (abas, e Fase 6
                                 como pseudo-abas dentro do mesmo shell) ->
                                 Fase 3/4/5 (tela cheia por cima das abas)
    StorePanelApp.svelte                raiz do painel da loja: login, abas
                                 Cozinha/Visão geral/Caixa, atualização periódica
    CourierApp.svelte              raiz do app do entregador: login, corrida em
                                 andamento, abas Corridas/Ganhos
    AdminApp.svelte                raiz do painel da plataforma: login por OTP e
                                 abas Lojas/Ocorrências/Relatórios/Políticas
```

- **`toastr` sem jQuery.** O pacote npm `toastr` declara "jQuery is
  required" no próprio `package.json` — e a cláusula zero proíbe jQuery em
  código novo. As duas regras da mesma cláusula se contradizem para
  front-end escrito do zero. Resolvido do mesmo jeito que o JWT do módulo
  identity: `web/src/lib/toastr.js` reimplementa a mesma interface
  (`toastr.success/error/warning/info`) em ~60 linhas de DOM puro, sem
  puxar jQuery. Trocar por versão nova ou biblioteca "melhor" seria
  proposta de mudança de stack, não decisão de quem está escrevendo tela.
- **Vite como bundler não é item da cláusula zero.** A cláusula lista
  frameworks e bibliotecas de runtime, não ferramenta de build; não existe
  jeito de compilar `.svelte` pra produção sem alguma. Fica registrado
  aqui pela mesma razão que o Composer não apareceu no módulo PHP: é
  plumbing, não stack.
- **UFs/cidades/bairros eram dado estático local** (`data/states.js`), com as
  contagens de "lojas ativas" do mock (SP 1.284...). **Atualizado na
  [37](37-cidades-e-numeros.md):** as cidades vêm do banco (`service_cities`,
  ligadas pela aba Cidades do admin) com a contagem real de lojas, e o arquivo
  estático saiu.
- **Geolocalização e SweetAlert são reais, não simulados.** A tela 1.3
  chama de verdade `navigator.geolocation.getCurrentPosition` depois do
  SweetAlert confirmar — testado via Playwright com permissão de
  localização concedida e coordenadas fixas. O que é simplificado é achar
  o bairro a partir de lat/lng: geocodificação reversa pede um provedor
  externo (Google/Mapbox/Nominatim) que não foi decidido ainda. Na 37, a
  posição do aparelho passou a valer pra distância das lojas e o cliente
  escolhe o bairro (antes o app dizia "bairro identificado" e pegava o
  primeiro da lista).
- **Validado visualmente, não só compilado.** `npm run build` limpo não
  prova que a tela se parece com o mock. As 3 telas da Fase 1 foram
  conferidas numa janela de 430px com Playwright + Chromium: splash com o
  fade automático, seleção de estado com destaque e botão desabilitado até
  escolher, o modal do SweetAlert dentro do fluxo de cidade, o toast de
  sucesso depois da geolocalização, e a Fase 1 fechando com o
  `city_ibge_code` certo.

**Fase 2 — decisões adicionais:**

- **A barra inferior tem 5 abas** (house/search/cart/star/person), **não
  6** — "Pedidos" (2.4) não é uma delas. No mock, ela é alcançada tocando
  a estatística "PEDIDOS" dentro do Perfil (2.5); é assim que
  `CustomerApp.svelte` liga as duas (`Profile` → `onOpenOrders` → aba `orders`,
  com botão de voltar em `OrdersScreen.svelte`, já que ela não é uma aba
  própria da navegação inferior).
- **A aba Carrinho existe mas não abre nada** — avisa que é a Fase 3
  (cardápio/item/carrinho), ainda não portada, em vez de levar a uma tela
  vazia fingindo que funciona.
- **Login é o `AuthFlow` da Fase 10** (telas 10.1, 10.2 e 10.3, seção
  própria abaixo). Ele fecha as três abas que precisam de usuário
  autenticado (fidelidade, pedidos, perfil) e também é o que aparece dentro
  do modal de item (3.2) quando alguém tenta montar um carrinho sem conta.
  O `QuickLogin.svelte` provisório foi removido no mesmo commit.
- **CORS entrou em `lib/bootstrap.php`** porque Fase 2 é a primeira vez
  que o front chama a API de verdade — Vite (porta 5173 em dev) e PHP
  (porta 8080) são origens diferentes pro navegador. `ALLOWED_ORIGIN` no
  `.env` controla isso; vazio em produção assume que PWA e API dividem
  domínio via Cloudflare (a especificação nunca fala em domínios
  separados), então nenhum header `Access-Control-*` é enviado.
- **Fidelidade (2.3) é a única tela que não chama a API.** Sem tabela de
  pontos no banco (ver "Módulo de descoberta" acima — mesma lacuna, outra
  tela), os números são fixos, os mesmos do mock, com um selo visível
  avisando que é dado de exemplo. Os botões "Resgatar"/"Trocar" mostram um
  aviso em vez de fingir uma transação.
- **`orders/list.php` foi enriquecido** (nome da loja, contagem de itens)
  depois que a tela de Pedidos (2.4) mostrou que a versão anterior (só
  `status`/`total`/`payment_method`) não bastava pra uma lista útil — o
  endpoint mudou junto com a tela que o usa, não antes.
- **Validado com Playwright de ponta a ponta, incluindo login real:**
  onboarding → Home com lojas ordenadas por distância Haversine de
  verdade → filtro de categoria refazendo a consulta → busca de produto
  por trigram → aba Fidelidade sem login → aba Perfil pedindo login →
  cadastro por OTP dentro do `QuickLogin` → Perfil com estatísticas reais
  → toque em "PEDIDOS" → sub-tela de pedidos → volta pro Perfil. Zero
  erros de console em todo o percurso (um 404 apareceu no meio do teste e
  não era bug: é a própria API respondendo `user_not_found` de propósito
  para um telefone sem cadastro — o Chromium loga qualquer `fetch` não-2xx
  como "erro" no console, mesmo quando a aplicação trata a resposta
  corretamente, como este caso trata).

**Fase 3 — decisões adicionais:**

- **`RestaurantPage`/`CartDrawer` são tela cheia por cima das abas, sem a
  barra inferior** — é assim que o mock desenha 3.1/3.3 (sem os 5 ícones
  visíveis), diferente das telas da Fase 2. `CustomerApp.svelte` trata isso como
  uma pilha própria (`restaurantId`/`cartOpen`), não como mais uma aba.
- **Adicionar item sem estar logado abre o `QuickLogin` *dentro* do
  modal**, sem fechar ou perder as variações já marcadas — testado de
  ponta a ponta: seleciona variação, tenta adicionar, loga pelo formulário
  embutido, volta pro item com tudo como estava. Depois do login, ainda é
  preciso tocar "Adicionar" de novo (não reenvia sozinho) — é uma escolha
  deliberada de não disparar uma ação de carrinho sem toque explícito
  depois de uma tela nova aparecer, não uma limitação técnica.
- **Grupo de variação vira rádio ou checkbox pela própria coluna do
  banco**: `max_selections === 1` é escolha única (rádio); qualquer outro
  valor (ou `null`, sem limite) é múltipla escolha, capada nesse número
  quando ele existir. Não tem coluna dizendo "isto é rádio" — é inferido
  do mesmo jeito que o cardápio já descreve os grupos.
- **Preço do item é recalculado a cada seleção, no cliente, só pra
  mostrar** — o preço que de fato vira `order_items.unit_price` é
  recalculado de novo no servidor (`price_line()`) quando `Adicionar` é
  clicado. O número que o cliente vê antes de confirmar é preview, nunca
  a fonte da verdade — exatamente o que o chip da tela 3.2 pede
  ("preço recalculado no servidor antes de virar item do pedido").
- **Validado com Playwright, incluindo o bug do `.modal` acima**: abrir
  loja anônimo (o 401 de `cart/show.php` pra usuário deslogado é esperado
  e já tratado, não erro), trocar de categoria, abrir item indisponível
  (não abre modal — é `disabled`), selecionar variação obrigatória +
  adicional, ver o preço somar ao vivo (R$32,90 → R$38,90), tentar
  adicionar sem login → `QuickLogin` embutido → login real por OTP →
  variações preservadas → adicionar de verdade → toast "Item adicionado
  ✓" → barra de carrinho fixa → abrir carrinho → aumentar quantidade →
  subtotal recalculado no servidor (R$38,90 → R$77,80). Zero erros de
  console do início ao fim.

**Fase 4 — decisões adicionais:**

- **`PaymentFlow.svelte` orquestra a fase inteira**, mas quem chama a API
  de verdade é cada tela filha via callback (`onSubmit`/`onContinue`) — o
  mesmo padrão de props+callback já usado em `ItemModal`/`CartDrawer`,
  não um store novo só pra isto.
- **Endereço mínimo (`QuickAddress.svelte`), no mesmo espírito do
  `QuickLogin.svelte`**: lista os endereços salvos ou cadastra um novo
  (chama `/addresses/list.php` e `/addresses/create.php` de verdade) — não
  é a tela de CRUD completo da Fase 6 (editar, apagar, rótulo, padrão),
  que ainda não foi portada. Sem isto, não haveria como testar o checkout
  ponta a ponta nesta passada.
- **`orders/checkout.php` só é chamado uma vez por fluxo.** Ele transiciona
  `cart → pending_payment`; não existe "carrinho" pra achar numa segunda
  chamada. Retry de pagamento (CVV errado, etc.) chama só `payments/pay.php`
  de novo em cima do mesmo `order.id` — `PaymentFlow` guarda esse estado
  (`order !== null` vira o sinal de "checkout já aconteceu"). Trocar de
  método DEPOIS que o checkout já rodou (ex.: Pix sem chave cadastrada,
  volta e escolhe cartão) não reabre um carrinho novo automaticamente —
  isso exigiria um endpoint de abandono que não existe ainda; registrado
  como simplificação no código.
- **Recusa de cartão (HTTP 402) não é um "erro" pro fluxo — é uma decisão
  de domínio.** `checkoutAndPay()` distingue os dois: um 402 cujo corpo já
  traz `order`/`payment` (a forma que `payments/pay.php` sempre devolve
  numa recusa) é tratado como resultado normal, não repassado como
  exceção — entrega pro `OrderTrackingScreen.svelte` (Fase 5) igual a um
  sucesso, que mostra o hero de "Pagamento recusado" (5.4) porque um
  pedido `rejected` é estado terminal no banco (`advance_order()` não tem
  transição saindo dele) — não dá pra "tentar de novo" no mesmo pedido.
- **Tokenização real do MercadoPago.js não está integrada** — este
  ambiente não tem uma Public Key de sandbox do Mercado Pago. `CardPaymentScreen.svelte`
  tem os mesmos campos do mock (4.2), mas manda um token placeholder (os
  dígitos do cartão) em vez de um token de verdade gerado pelo SDK no
  navegador; funciona porque o backend também está em
  `MERCADOPAGO_MODE=fake` neste ambiente (ver "Módulo de pagamentos"
  acima). Um selo amarelo avisa isso na própria tela, mesmo padrão do
  "login provisório" do `QuickLogin`.
- **Progresso de upload é real, não decorativo.** `fetch()` não expõe
  progresso de envio de forma confiável entre navegadores, então
  `ProofUploadScreen.svelte` usa `XMLHttpRequest` só pra esta chamada
  (`xhr.upload.onprogress`) — é por isso que este componente não usa
  `lib/api.js` como os outros. A compressão antes do envio também é real:
  redesenha a imagem num `<canvas>` (máximo 1280px no lado maior, JPEG
  80%) e mostra o tamanho antes/depois, exatamente como o chip da tela
  4.4 descreve.
- **Pix copia-e-cola (BR Code) veio de `lib/payments/pix.php` de verdade** — não é
  texto decorativo. `PixPaymentScreen.svelte` exibe o payload EMV completo
  (testado visualmente: começa com `000201`, contém `br.gov.bcb.pix`).
  O CNPJ mostrado na tela exigiu adicionar a coluna `cnpj` na resposta de
  `restaurants/show.php` (não vazava antes — é dado público, mesmo que já
  sai em `partner_login.php`).
- **Contador da tela 4.3 lê `orders.verification_deadline` do servidor**,
  não um timer local — atualizado a cada segundo (`setInterval`) só pra
  formatar `mm:ss`, nunca pra decidir quando expira; quem decide isso é o
  `pg_cron` (`expire_pending_verifications`, migração `009`).
- **Troco (`CashPayment`) valida contra o TOTAL do pedido no cliente**,
  mais rigoroso que o mínimo que o próprio banco exige (`CHECK
  cash_change_valid` só pede `change_for >= subtotal`, sem contar o
  frete) — o valor mandado pro backend nunca fica abaixo do que o cliente
  realmente deve, então a validação mais frouxa do banco nunca chega a
  ser testada pelo caminho feliz desta tela.
- **Validado com Playwright, os cinco métodos, ponta a ponta e contra o
  backend real** (não só compilado): dinheiro com troco → "Pagamento
  aprovado"; maquininha com bandeira obrigatória → "Pagamento aprovado";
  cartão aprovado (modo fake) → "Pagamento aprovado" com bandeira/final
  registrados; cartão recusado (interceptado com a resposta 402 exata que
  o backend manda, já que o formulário real só aceita dígitos e a
  convenção de recusa do modo fake do backend é alfanumérica) →
  hero "Pagamento recusado"; Pix manual → QR real exibido → upload de um
  JPEG de teste gerado on-the-fly → "Comprovante em análise" → aprovado
  pela loja via `restaurants/approve_pix.php` (login de loja real) →
  pedido confirmado `paid`. Dois bugs reais apareceram e foram corrigidos
  nesta validação: `payments/pay.php` devolve `pix_copy_paste` na raiz do
  corpo, não dentro de `payment` — o primeiro código guardava só
  `payment`, então o código Pix aparecia em branco na tela; achado
  comparando o texto renderizado com o esperado via Playwright, não só
  lendo o código. E: limpar o carrinho (`clearCartState()`) acontecia
  ANTES da tela de resultado assumir, então por uma fração de segundo o
  que estava por trás (ex.: "Total do pedido") mostrava R$0,00 — corrigido
  movendo a limpeza pra depois.

**Fase 5 — decisões adicionais:**

- **Uma tela só (`OrderTrackingScreen.svelte`) cobre 5.1, 5.2, 5.3 e 5.4** — no
  mock as quatro já são a mesma ideia ("o aviso chega por SSE"), só o
  conteúdo do "hero" muda com `order.status`; separar em 4 arquivos
  duplicaria a conexão SSE e a busca do pedido sem ganhar nada. 5.5
  (`ReviewScreen.svelte`) é tela própria de verdade, porque é a única que
  tem uma ação distinta (enviar formulário) em vez de só refletir status.
- **SSE de verdade no navegador**: `EventSource` nativo (sem lib) contra
  `orders/track.php`; reconecta sozinho quando a conexão de 25s do
  backend fecha (comportamento padrão do protocolo, documentado na seção
  "Módulo de acompanhamento pós-pedido" acima) — o front não tem nenhuma
  lógica de retry escrita à mão.
- **Token na URL, não no header, só nesta chamada** — `EventSource` não
  deixa configurar headers customizados, então a Authorization normal não
  serve aqui. `require_auth_header_or_query()` no backend é o que torna
  isso seguro sem abrir a exceção pra mais nenhuma rota.
- **Mapa e localização do entregador** eram um placeholder enquanto não
  havia quem escrevesse em `courier_positions`; hoje são o `DeliveryMap`
  com Leaflet -- ver "Lacunas do app do cliente fechadas".
- **Previsão de entrega é uma janela fixa a partir de `created_at`** (25 a
  45 min depois), igual à mesma simplificação já assumida em
  `PaymentSelectorScreen.svelte` (Fase 4.1) — sem motor de logística real
  (Fase 8/9), não tem outra fonte pra esse número.
- **Bug real de datas, achado nesta fase e corrigido em três lugares.**
  `new Date(timestamp.replace(' ', 'T'))` parece inofensivo, mas quebra
  silenciosamente ("Invalid Date", sem lançar exceção) quando o
  `timestamptz` do Postgres termina em offset de 2 dígitos sem os
  dois-pontos (`+00`, não `+00:00`) — a combinação exata que
  `ATTR_EMULATE_PREPARES` e o driver `pgsql` produzem. Achado com
  Playwright comparando o texto renderizado ("Invalid Date" na tela, não
  só no console). `web/src/lib/datetime.js` (`parsePgTimestamp()`) resolve
  isso normalizando pra ISO 8601 de verdade antes de entregar pro `Date`;
  usado em `OrderTrackingScreen.svelte` (novo) e em `OrdersScreen.svelte` (Fase 2.4,
  que já tinha o mesmo bug desde antes desta fase — `minutesLeft()` também
  corrigido).
- **`orders/show.php` ganhou `review`** (a avaliação já feita, ou `null`)
  pro botão "Avaliar pedido" não aparecer de novo pra quem já avaliou —
  em vez de deixar o clique acontecer e só então devolver 409.
- **Gorjeta da avaliação some no "R$ Outro"** se o campo numérico for
  preenchido, e os chips fixos (R$2/R$5/R$10) se desmarcam sozinhos —
  são mutuamente exclusivos por design (`effectiveTip`), igual ao rádio
  de parcelas do cartão.
- **Validado com Playwright de ponta a ponta, incluindo o SSE de
  verdade entre processos diferentes** (não só a forma da resposta):
  pedido pago → hero "Pagamento aprovado" → `advance_order()` disparado
  via `psql` num processo separado, 1,5s depois → a tela reage sozinha
  pra "Em preparo na cozinha" sem nenhum reload, comprovando o
  `LISTEN/NOTIFY` entregando entre processos de verdade → avança até
  `delivered` → "Avaliar pedido" → 5 estrelas + 2 tags + gorjeta de R$5 +
  comentário → envia → volta pra lista de pedidos → reabre o mesmo pedido
  pela aba "Histórico" → mostra "Você já avaliou esse pedido com 5
  estrelas" em vez do botão de novo. Zero erros de console do início ao
  fim, nas duas passadas completas.

**Fase 6 — decisões adicionais:**

- **Endereços/cartões/configurações entram como pseudo-abas dentro do
  mesmo `app-shell`**, não como uma pilha própria tipo `RestaurantPage`/
  `PaymentFlow` — mesmo padrão que `OrdersScreen.svelte` (Fase 2.4) já usava
  (`tab = 'orders'` sem estar na barra inferior). Simples, e consistente
  com o que já existia.
- **Busca por CEP é uma chamada real pro ViaCEP** (API pública,
  gratuita, sem chave) — não um mock. Limite honesto: este ambiente de
  desenvolvimento bloqueia tráfego de saída pra hosts fora da allowlist
  do proxy, então só o caminho de FALHA foi testável aqui (a chamada
  falha, cai de volta pro preenchimento manual, sem travar a tela). O
  caminho de sucesso não pôde ser validado neste ambiente especificamente
  — o contrato da API é público e estável, não é algo inventado, mas fica
  registrado que não foi visto funcionando de ponta a ponta nesta sessão.
- **Endereço padrão e cartão padrão usam o mesmo padrão de UI**: card
  com badge "PADRÃO" pros outros, botão "Tornar padrão" pro resto — o
  botão chama `addresses/update.php`/`cards/update.php` com só
  `is_default: true`, o backend cuida de desmarcar o resto.
- **Apagar endereço em uso mostra a mensagem certa, não um erro genérico**
  — `AddressesScreen.svelte` reconhece especificamente o código
  `address_in_use` (409) e troca a mensagem por algo que explica o motivo
  (endereço já usado num pedido), em vez de deixar o texto cru do backend
  ou um "erro desconhecido".
- **Configurações são preferência real de aparelho (localStorage), não
  decorativas** — os três toggles de notificação persistem entre reloads
  deste navegador. Não viram push de verdade ainda (Fase 7.2 não
  construída), mas o formato já é o que o worker de push vai precisar
  checar quando existir.
- **Validado com Playwright de ponta a ponta, contra o backend real, zero
  erros de console**: dois endereços criados → trocar padrão → editar
  complemento → apagar bloqueado (coberto pelo smoke test, não repetido
  aqui) → dois cartões salvos (Mastercard e Visa pelo heurístico de
  bandeira) → trocar padrão → configurações com os três toggles reais,
  incluindo o de promoções ligado manualmente e persistido.

**Atualização (go-live).** O que mudou desde este registro:

- As fases que aqui aparecem como "ainda não portadas" foram todas
  construídas depois. A Fase 3 (cardápio, item, carrinho), a Fase 6 (CRUD de
  endereços, cartões e configurações) e a tokenização real do cartão
  (MercadoPago.js, [30](30-cartao-salvo-e-total-com-frete.md)) saíram
  depois deste registro. O `QuickAddress.svelte` e o `QuickLogin.svelte`
  deram lugar às telas completas ([09](09-login-e-cadastro.md),
  [16](16-lacunas-do-app-do-cliente.md)).
