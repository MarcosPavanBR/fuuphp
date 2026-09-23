# Catálogo da API

> Gerado por `php bin/generate_api_catalog.php` a partir dos próprios arquivos de
> `api/v1`. Não edite à mão: o CI confere (`--check`) e falha se estiver desatualizado.

106 rotas. Toda rota responde JSON (exceto as de imagem, CSV e SSE); erro é sempre
`{code, message, trace_id}` com o status HTTP certo (`lib/core/response.php`). As URLs são
contrato público: mudar um caminho quebra app instalado.

**Quem:** `público` sem login · `autenticado` qualquer token válido · `cliente`, `loja`,
`entregador`, `admin` pelo papel do token. **Idem.:** exige `X-Idempotency-Key` (UUID).

## Endereços do cliente (6.1, 14.3)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/addresses/create.php` | POST | autenticado |  | Tela 6.1/14.3 — cadastra um endereço do cliente logado. CEP de 8 dígitos, UF de 2 letras e coordenada obrigatórias: é a coordenada que o frete e a área de entrega usam (lib/ordering/delivery.php). |
| `/api/v1/addresses/delete.php` | POST | autenticado |  | Tela 6.1 — apaga um endereço do cliente logado. Endereço já usado em pedido não apaga (409 address_in_use): o pedido guarda pra onde foi, e a chave estrangeira impede que o histórico perca o destino. |
| `/api/v1/addresses/list.php` | GET | autenticado |  | Tela 6.1 — os endereços salvos do cliente logado, o padrão primeiro. |
| `/api/v1/addresses/quote.php` | GET | autenticado |  | Tela 14.3 — "A área de cobertura é validada no servidor e a taxa aparece antes de salvar — não na hora de pagar." |
| `/api/v1/addresses/update.php` | POST | autenticado |  | Tela 6.1 — Endereços salvos. "CRUD com endereço padrão." Edição parcial (só os campos mandados mudam) e troca de padrão: marcar um endereço como is_default=true desmarca os outros do mesmo usuário na mesma transação -- nunca dois padrões ao mesmo tempo, sem… |

## Painel da plataforma (Fase 12, 10.5, 13.4, 15.2, 15.3)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/admin/campaigns.php` | GET, POST | admin |  | Tela 15.3 — "Cupons e campanhas". |
| `/api/v1/admin/couriers.php` | GET, POST | admin |  | Tela 15.2, lado de dentro: a fila de análise das candidaturas. |
| `/api/v1/admin/disputes.php` | POST | admin |  | Tela 12.2 — "Disputas e galeria antifraude". |
| `/api/v1/admin/export.php` | GET | admin |  | Tela 12.3 — a exportação contábil que a tela promete. |
| `/api/v1/admin/incident_photo.php` | GET | admin |  | A foto da ocorrência (tela 13.3) pra quem decide o destino da sacola. |
| `/api/v1/admin/incidents.php` | GET, POST | admin |  | Tela 13.3, o outro lado: "Passado o prazo, o suporte libera: devolver à loja ou descartar." |
| `/api/v1/admin/netting.php` | GET, POST | admin |  | Tela 9.7 — "Painel da plataforma: netting semanal e repasse". |
| `/api/v1/admin/policy.php` | POST | admin |  | Tela 10.5 — "Painel admin: políticas (teto, prazo, comissão, métodos)". |
| `/api/v1/admin/refunds.php` | GET, POST | admin |  | Tela 13.4 — "Admin: reembolso por método e quem paga". |
| `/api/v1/admin/reports.php` | GET | admin |  | Tela 12.3 — "Os números que mudam decisão". |
| `/api/v1/admin/restaurants.php` | POST | admin |  | Tela 12.1 — "Cadastro e aprovação de lojas". |

## Acesso: OTP, parceiros, sessão (Fase 10)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/auth/consent.php` | POST | autenticado |  | Tela 10.3 — registra o aceite de um termo (termos, privacidade, marketing, localização) com versão e IP. Termos + privacidade marcam a conta como `lgpd_accepted_at`; marketing e localização ficam como consentimentos separados e revogáveis. |
| `/api/v1/auth/otp_request.php` | POST | público |  | Tela 10.1/10.2 — pede o código de 6 dígitos (OTP) por telefone ou e-mail, pra entrar (`login`), criar conta (`signup`) ou confirmar um telefone (`phone_verify`). Em desenvolvimento o código volta em `dev_code`; em produção só vai pelo canal. |
| `/api/v1/auth/otp_verify.php` | POST | público |  | Tela 10.2 — confere o código OTP e devolve access token (15 min) e refresh token (30 dias, com rotação). Código tem prazo e limite de tentativas; conta bloqueada ou excluída (LGPD, migração 026) não entra. |
| `/api/v1/auth/partner_login.php` | POST | público |  | Tela 10.7 / 8.1 — login de parceiro: loja com CNPJ + senha, entregador com CPF + código de acesso. Devolve o token com o papel e o vínculo (restaurant_id / courier_id) que os guardas de cada rota conferem. |
| `/api/v1/auth/refresh.php` | POST | público |  | Troca um refresh token válido por um par novo (rotação: o antigo deixa de valer). Sessão revogada -- logout, conta excluída -- responde 401. |

## Cartões salvos (6.2)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/cards/create.php` | POST | cliente |  | Tela 6.2 — Adicionar cartão. Mesmo limite já documentado em payments/pay.php: sem MercadoPago.js real integrado neste ambiente, o "token" que chega aqui é o placeholder que CardPaymentScreen.svelte manda (os dígitos do cartão), não uma tokenização de verdad… |
| `/api/v1/cards/delete.php` | POST | autenticado |  | Tela 6.2 — remove um cartão salvo, aqui e no Mercado Pago (Customer/Card). |
| `/api/v1/cards/list.php` | GET | autenticado |  | Tela 6.2 — os cartões salvos do cliente (bandeira, final, validade; nunca o número): referência tokenizada no Mercado Pago, o padrão primeiro. |
| `/api/v1/cards/update.php` | POST | autenticado |  | Só is_default muda depois de salvo -- os outros campos do cartão vêm direto do Mercado Pago no momento da criação e não fazem sentido editar por aqui (mudar a validade de um cartão salvo não é uma operação real). |

## Carrinho (Fase 3)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/cart/add_item.php` | POST | autenticado |  | Tela 3.2 — põe um item (com variações e observação) no carrinho da loja, criando o carrinho se preciso. O preço vem do cardápio atual, nunca do corpo da requisição (lib/ordering/cart.php). |
| `/api/v1/cart/apply_coupon.php` | POST | cliente |  | Resgate de cupom no carrinho. A tabela `coupons` existe desde a migração 008 e o campo já estava na tela 3.3 esperando backend. |
| `/api/v1/cart/remove_item.php` | POST | autenticado |  | Tela 3.3 — tira um item do carrinho do próprio cliente. |
| `/api/v1/cart/show.php` | GET | autenticado |  | O carrinho do cliente numa loja (um pedido em status 'cart' por loja). |
| `/api/v1/cart/update_quantity.php` | POST | autenticado |  | Tela 3.3 — muda a quantidade de um item do carrinho (mínimo 1; pra tirar, remove_item.php) e recalcula o subtotal. |

## App do entregador (Fases 8, 9, 13.3, 15)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/couriers/accept_offer.php` | POST | entregador |  | Tela 8.2 — "aceite idempotente: dois entregadores não pegam a mesma corrida." |
| `/api/v1/couriers/application.php` | GET | autenticado |  | Tela 15.2 — a lista de documentos, item por item, com o que falta. |
| `/api/v1/couriers/apply.php` | POST | autenticado |  | Tela 15.2 — Onboarding do entregador. |
| `/api/v1/couriers/apply_document.php` | POST | autenticado |  | Tela 15.2 — envio de um documento da candidatura. |
| `/api/v1/couriers/deliver.php` | POST | entregador | sim | Telas 8.5 e 8.6 — entrega, cobrança e prova. |
| `/api/v1/couriers/earnings.php` | GET | entregador |  | Tela 8.7 — "Livro de lançamentos, não um campo de saldo: cada corrida, bônus, gorjeta e repasse é uma linha — é o que permite fechar o caixa sem discussão." |
| `/api/v1/couriers/incident.php` | GET, POST | entregador |  | Tela 13.3 — "Entregador: ocorrência na entrega". |
| `/api/v1/couriers/incident_photo.php` | POST | entregador |  | Tela 13.3 — "PROVA (OBRIGATÓRIA): foto do local · GPS e hora gravados". |
| `/api/v1/couriers/me.php` | GET | entregador |  | Tela 8.1 — "Turno e saldo em espécie". Tudo que o app do entregador precisa pra desenhar a tela inicial numa chamada só: turno aberto ou não, saldo em dinheiro vivo (que é passivo dele com a loja), o que a plataforma deve, e as duas travas da política -- te… |
| `/api/v1/couriers/offers.php` | GET | entregador |  | Tela 8.2 — "Tudo que decide o aceite em uma tela: ganho, distância, forma de pagamento e troco." |
| `/api/v1/couriers/pickup.php` | POST | entregador |  | Telas 8.3 e 8.4 — "Cheguei" grava evento com carimbo de GPS, e a coleta mostra o troco calculado pelo servidor. |
| `/api/v1/couriers/pos.php` | GET, POST | entregador |  | POST\|GET /v1/couriers/pos.php — maquininha no app do entregador. |
| `/api/v1/couriers/position.php` | POST | entregador |  | POST /v1/couriers/position.php — onde o entregador está agora. |
| `/api/v1/couriers/settle_intent.php` | POST | entregador |  | Telas 9.1 e 9.2 — "Intenção de baixa com validade de 10 min. Guardamos só o hash do código; o valor é imutável depois de gerado." |
| `/api/v1/couriers/settle_proof.php` | GET, POST | entregador | sim | Tela 9.5 — o comprovante da baixa por Pix, lado do entregador. |
| `/api/v1/couriers/settlements.php` | GET | entregador |  | Tela 9.4 — "Entregador — recibo e saldo zerado": "Baixa confirmada. Ana (caixa) recebeu R$ 262,80 às 23:14. Saldo em espécie R$ 0,00 ... Recibo #BX-4417 · hash 9f2c…4a1b · via impressa ficou na loja." |
| `/api/v1/couriers/shift.php` | POST | entregador |  | Tela 8.1 — abrir e fechar turno. O índice `one_open_shift` (migração 007) garante um turno aberto por entregador: abrir duas vezes não cria dois registros, e é o banco que diz isso, não um `if` no PHP. |
| `/api/v1/couriers/submit_application.php` | POST | autenticado |  | Tela 15.2, última etapa: "você lê e aceita o contrato de prestação de serviço e a política de dados". |

## Pedido: checkout, acompanhamento, recibo (Fases 4, 5, 13, 14, 15.1)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/orders/cancel_quote.php` | GET | autenticado |  | Tela 13.1 — "Taxa e valor do estorno na mesma tela, antes de confirmar — nada de descobrir depois." |
| `/api/v1/orders/checkout.php` | POST | cliente |  | Converte o carrinho incremental (status='cart', montado por api/v1/cart/*.php, Fase 3) num pedido aguardando pagamento -- o passo que faltava entre "Ir para pagamento" (CartDrawer.svelte) e a Fase 4 (seleção de método). Diferente de orders/create.php (check… |
| `/api/v1/orders/courier_location.php` | GET | autenticado |  | Tela 5.3 — o mapa da entrega: "1,4 km · 8 min", "Jonas está levando · Honda Biz · placa QQP-1B34". |
| `/api/v1/orders/create.php` | POST | autenticado |  | Checkout em um passo (lista de itens no corpo), anterior ao carrinho incremental: cria o pedido já em 'pending_payment'. O app usa cart/* + orders/checkout.php; esta rota fica pra integrações e testes. |
| `/api/v1/orders/dispatch_action.php` | POST | cliente |  | Tela 15.1 — as saídas concretas. Uma rota, três ações, porque são três respostas à MESMA pergunta ("ninguém aceitou a corrida, e agora?") e compartilham as mesmas pré-condições. |
| `/api/v1/orders/dispatch_status.php` | GET | autenticado |  | Tela 15.1 — "Sem entregador disponível". |
| `/api/v1/orders/list.php` | GET | cliente |  | Tela 2.4 — os pedidos do cliente logado (sem carrinhos), mais novos primeiro, com loja, total, forma de pagamento e quantidade de itens. |
| `/api/v1/orders/messages.php` | GET | autenticado |  | Tela 14.2 — "Chat do pedido (três pontas)". GET lista, POST manda. |
| `/api/v1/orders/receipt.php` | GET | autenticado |  | Tela 2.5 — "Notas e comprovantes". O recibo de um pedido: quem vendeu (nome e CNPJ da loja), o que foi comprado, quanto foi cobrado e como, e o que voltou (estornos, crédito em carteira, gorjeta cobrada à parte). |
| `/api/v1/orders/reorder.php` | POST | cliente |  | Tela 2.4 — "Repetir" um pedido: põe os mesmos itens (e variações e observações) no carrinho daquela loja, e o cliente segue pro checkout normal. |
| `/api/v1/orders/show.php` | GET | autenticado |  | Tela 5 — um pedido com itens, linha do tempo (order_events) e a avaliação, se houver. Cliente vê só o próprio; loja, só os da loja. |
| `/api/v1/orders/slots.php` | GET | autenticado |  | Tela 14.4 — "Quando você quer receber?" |
| `/api/v1/orders/status.php` | POST | autenticado |  | Pede uma mudança de status do pedido. Aqui só se decide QUEM pode pedir cada transição (loja: preparo, pronto, saiu, recusar, cancelar e -- só na retirada no balcão -- entregue; cliente: cancelar); se a transição é válida, quem decide é `advance_order()` no… |
| `/api/v1/orders/track.php` | GET | autenticado (token também por query, SSE) |  | Tela 5.3 — Tracking em tempo real. "O aviso chega por SSE alimentado por LISTEN/NOTIFY do PostgreSQL." advance_order() já faz PERFORM pg_notify('order_changed', order_id) a cada transição (migração 004); este endpoint só escuta. |

## Pagamentos (Fase 4, 7.1)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/payments/change_method.php` | POST | cliente |  | Trocar a forma de pagamento de um pedido que ainda aguarda pagamento. |
| `/api/v1/payments/config.php` | GET | público |  | Tela 4.2 — o que o navegador precisa pra tokenizar o cartão com MercadoPago.js: a Public Key (pública por definição; o Access Token fica só no PHP) e o modo. Em `fake` não há chave, e o app usa um token de teste local em vez de carregar o SDK -- o backend f… |
| `/api/v1/payments/pay.php` | POST | cliente | sim | Fase 4 — dispara a cobrança do método já escolhido no checkout (orders/checkout.php). O método é lido do próprio pedido, não do corpo da requisição: o cliente não pode pagar um pedido de cartão como se fosse dinheiro só trocando o body. |
| `/api/v1/payments/upload_proof.php` | POST | cliente | sim | Fase 4.4 — upload do comprovante de Pix manual. MIME real por finfo (não o Content-Type que o navegador mandou), hash sha256 (repetição exata) e um "average hash" (aHash) de 64 bits como phash simplificado (repetição de print reeditado/recortado) -- documen… |
| `/api/v1/payments/webhook_mercadopago.php` | POST | Mercado Pago (assinatura) |  | Webhook do Mercado Pago (Fase 5.1: "O webhook do Mercado Pago é a fonte da verdade, não a resposta da tela"). Cobre dois casos que a resposta síncrona de payments/pay.php não fecha sozinha: cartão que muda de status depois de "in_process" (ex.: antifraude a… |

## Conta do cliente e LGPD (2.5, 6.3, 13.4)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/profile/delete_account.php` | GET, POST | cliente |  | Tela 6.3 — "Excluir conta" (LGPD art. 18, VI). |
| `/api/v1/profile/export.php` | GET | cliente |  | Tela 6.3 — "Baixar meus dados (LGPD)". Devolve um JSON com tudo que o sistema guarda sobre o cliente logado (lib/account/account_privacy.php decide o que entra e o que fica de fora), como anexo pra download. |
| `/api/v1/profile/loyalty.php` | GET, POST | cliente |  | Tela 2.3 — Fidelidade. "Saldo calculado no banco (soma dos lançamentos), nunca no cliente." |
| `/api/v1/profile/show.php` | GET | autenticado |  | Tela 2.5 — o perfil do cliente: dados da conta (CPF só mascarado) e os números do topo (pedidos, cupons; pontos ainda sem tabela). |
| `/api/v1/profile/update.php` | POST | autenticado |  | Tela 10.3 — "Cadastro: mínimo necessário + LGPD por campo". O cadastro começa no OTP (que já cria o usuário com nome e telefone verificados) e termina aqui: CPF pra nota fiscal, e-mail, e os opcionais que só são gravados se a pessoa consentir. |
| `/api/v1/profile/wallet.php` | GET, POST | autenticado |  | Tela 13.4, o lado do cliente: "crédito em carteira é oferta, nunca imposição". |

## Notificações push (7.2)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/push/config.php` | GET | público |  | GET /v1/push/config.php — a chave pública VAPID (tela 7.2). |
| `/api/v1/push/pending.php` | POST | público |  | POST /v1/push/pending.php — o que o service worker mostra ao ser acordado. |
| `/api/v1/push/subscribe.php` | POST | autenticado |  | POST /v1/push/subscribe.php — liga, ajusta ou desliga o push deste aparelho. |

## Lojas: catálogo público e painel da loja (Fases 2, 3, 7.3, 9, 11)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/restaurants/approve_pix.php` | POST | autenticado |  | Fase 7.3 — painel do restaurante, validação humana do comprovante de Pix manual. "SELECT ... FOR UPDATE impede aprovação dupla": o lock pega a linha de payment_proofs antes de decidir, então duas abas do painel clicando "Aprovar" ao mesmo tempo não aprovam… |
| `/api/v1/restaurants/confirm_settlement.php` | POST | autenticado |  | Tela 9.3 — "Esta é a resposta a 'como eu sei que o motoboy entregou': a loja conta, digita o código do app dele e confirma." |
| `/api/v1/restaurants/holiday.php` | POST | loja |  | Tela 11.4 — "Feriados e datas especiais". |
| `/api/v1/restaurants/hours.php` | GET | loja |  | Tela 11.4 — "Horário, feriados e último pedido". |
| `/api/v1/restaurants/hours_save.php` | POST | loja |  | Tela 11.4 — "Salvar horário", com o "APLICAR PARA" do lado (só terça, seg a sex, todos os dias). |
| `/api/v1/restaurants/list.php` | GET | público |  | Tela 2.1 — lojas aprovadas de uma cidade, abertas por padrão, com distância, nota, frete e tempo quando o app manda a posição (lat/lng). |
| `/api/v1/restaurants/menu.php` | GET | público |  | Tela 3.1 — o cardápio público de uma loja, com variações; item esgotado vem marcado, não escondido. |
| `/api/v1/restaurants/menu_admin.php` | GET | loja |  | Tela 11.3 — o cardápio pelos olhos da loja. |
| `/api/v1/restaurants/menu_availability.php` | POST | loja |  | Tela 11.3 — "Esgotar é um toque na lista". |
| `/api/v1/restaurants/menu_item.php` | POST | loja |  | Tela 11.3 — o painel lateral: criar ou publicar um item com seus tamanhos. |
| `/api/v1/restaurants/menu_photo.php` | GET, POST | loja |  | Foto do item do cardápio (tela 11.1 no painel, 3.1/3.2 no app do cliente). |
| `/api/v1/restaurants/orders.php` | GET | autenticado |  | Tela 7.3/11.1 — a fila de pedidos da loja: `scope=kds` (o que a cozinha precisa preparar agora) ou `scope=recent` (a tabela do painel). |
| `/api/v1/restaurants/pause.php` | POST | loja |  | Tela 11.2 — pausar, fechar por hoje e voltar. |
| `/api/v1/restaurants/pause_status.php` | GET | loja |  | Tela 11.2 — "Pausa com volta automática e o custo estimado ao lado: decisão com número, não no escuro." |
| `/api/v1/restaurants/payment_settings.php` | GET, POST | loja |  | Tela 10.4 — "Painel da loja: configurar formas de pagamento". |
| `/api/v1/restaurants/pending_proofs.php` | GET | autenticado |  | Tela 7.3 — fila de validação do painel da loja. Devolve, de uma vez, tudo que o modal de decisão precisa mostrar: o comprovante (referência), os dados do pedido e a "conferência automática" (hash, phash, horário, marca d'água) que o mock descreve -- "reduz… |
| `/api/v1/restaurants/pos_devices.php` | POST | loja |  | Tela 10.4, bloco "MAQUININHAS CADASTRADAS" — e a outra ponta da 10.6. |
| `/api/v1/restaurants/prep_time.php` | POST | loja |  | Tela 11.2 — "Tempo de preparo informado ao cliente". |
| `/api/v1/restaurants/print_queue.php` | GET, POST | loja |  | A impressora térmica da loja (ESC/POS): o que falta imprimir, e o registro do que saiu no papel. Quem imprime é o tablet do balcão, que está ligado na impressora por USB -- o servidor não alcança a rede da loja. |
| `/api/v1/restaurants/proof_image.php` | GET | autenticado |  | Serve a imagem do comprovante pro painel da loja (tela 7.3) -- o arquivo fica fora da raiz servida (PROOF_STORAGE_DIR), então não existe URL pública pra ele: a única porta é esta, e ela confere se o comprovante é mesmo da loja que está pedindo antes de mand… |
| `/api/v1/restaurants/reconciliation.php` | GET, POST | loja |  | Tela 9.6 — "Painel da loja: conciliação da maquininha física". |
| `/api/v1/restaurants/search_products.php` | GET | público |  | Tela 2.2 — busca de produto por nome nas lojas abertas da cidade (índice trigram), cada resultado com nota, frete e tempo da loja pros filtros. |
| `/api/v1/restaurants/settlement_proofs.php` | GET, POST | loja |  | Tela 9.5, lado da loja — conferir a baixa de espécie que o entregador fez por Pix: "Reaproveita a validação humana já existente." |
| `/api/v1/restaurants/settlements.php` | GET | autenticado |  | Tela 9.3, lado da loja — o que está esperando conferência no caixa e o que já foi baixado hoje. |
| `/api/v1/restaurants/show.php` | GET | público |  | Tela 3.1 — os dados públicos de uma loja: horário, tempo de preparo em vigor e as formas de pagamento que ela aceita agora. |
| `/api/v1/restaurants/stats.php` | GET | autenticado |  | Tela 7.3 — "VISÃO GERAL DE HOJE": faturado, pedidos, Pix validados, recusados. "Hoje" é o dia corrente no fuso do banco (now()::date), não as últimas 24h -- é o que um dono de loja entende por "hoje". |

## Avaliação (5.5)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/reviews/create.php` | POST | cliente |  | Tela 5.5 — Avaliação. "Só habilitada para pedido com status = 'delivered' -- uma nota por pedido, garantida por índice único." A UNIQUE (order_id) da migração 011 faz o "uma nota por pedido"; aqui só confere o dono e o status antes de deixar gravar. |

## Ajuda e chat (14.1, 14.2)

| Rota | Métodos | Quem | Idem. | O que faz |
|---|---|---|---|---|
| `/api/v1/support/answer.php` | GET | cliente |  | Tela 14.1 — "cada um abre um fluxo automático antes de chamar gente". |
| `/api/v1/support/home.php` | GET | cliente |  | Tela 14.1 — a central de ajuda inteira numa chamada. |
| `/api/v1/support/ticket.php` | POST | cliente |  | Tela 14.1 — abrir chamado, depois do fluxo automático. |
