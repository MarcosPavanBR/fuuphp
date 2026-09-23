# Histórico de validação

Como cada módulo foi validado quando foi construído (comandos da época e o que
cada validação encontrou). Pra rodar hoje, veja [OPERATIONS.md](../OPERATIONS.md).

```bash
docker compose up -d
export DATABASE_URL=postgres://postgres:postgres@localhost:5432/fuudelivery
bash db/migrate.sh up           # aplica as 20, em ordem
bash db/migrate.sh down 3       # reverte as 3 últimas
bash db/migrate.sh down 26      # reverte tudo

cp .env.example .env            # ajuste DATABASE_URL/JWT_SECRET/ALLOWED_ORIGIN se precisar
JWT_SECRET=dev-secret bash tests/smoke_identity.sh    # fluxo completo de identity
JWT_SECRET=dev-secret bash tests/smoke_ordering.sh    # fluxo completo de checkout (semeia loja/cardápio sozinho)
JWT_SECRET=dev-secret bash tests/smoke_discovery.sh   # lista por distância, busca, perfil (semeia sozinho)
JWT_SECRET=dev-secret bash tests/smoke_cart.sh        # carrinho incremental (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_payments.sh   # pagamentos (semeia sozinho)
JWT_SECRET=dev-secret bash tests/smoke_tracking.sh    # timeline, SSE, avaliação (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_account.sh    # endereços e cartões (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_panel.sh      # painel da loja: fila de Pix e KDS (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_cancel.sh     # cancelamento, recusa e reembolso (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_courier.sh    # entregador e baixa de espécie (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_support.sh    # chat do pedido e cupons (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_admin.sh      # painel da plataforma (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_dispatch.sh   # pedido sem entregador: turbo, retirada, auto-cancel (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_store.sh      # loja operando a si mesma: pausa, cardápio, horário (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_help.sh       # central de ajuda: fluxo automático e chamado com prazo (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_address.sh    # endereço, área de entrega e frete no servidor (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_schedule.sh   # pedido agendado: faixas, vaga e fila da cozinha (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_growth.sh     # entrada de entregador e campanhas com teto (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_incident.sh   # ocorrência na entrega e console de reembolso (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_machine.sh    # maquininha, conciliação e netting semanal (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_money.sh      # estornos executados, CSV, livro do pedido (semeia sozinho)
JWT_SECRET=dev-secret MERCADOPAGO_MODE=fake bash tests/smoke_rounds.sh     # rodadas do despacho (semeia sozinho)

# O único processo de fundo do projeto (tela 15.1). Em produção é uma linha
# no cron do cPanel, a cada minuto; localmente, roda à mão quando quiser ver
# um pedido vencido ser cancelado:
php bin/auto_cancel_no_courier.php     # cancela pedido pronto há 15 min sem entregador

php -S localhost:8080                  # API, num terminal
cd web && npm install && npm run dev   # front-end Svelte, noutro terminal
                                       #   app do cliente:  http://localhost:5173
                                       #   painel da loja:  http://localhost:5173/painel.html
                                       #   entregador:      http://localhost:5173/entregador.html
                                       #   plataforma:      http://localhost:5173/admin.html
```

Os smoke tests semeiam dados próprios a cada execução, mas contam com um
banco recém-migrado: rodar a suíte várias vezes no mesmo banco acumula
lojas de teste e faz as asserções de contagem (ex.: "filtro de categoria
trouxe 1 loja") falharem por dado velho, não por regressão. `bash
db/migrate.sh down 26 && bash db/migrate.sh up` devolve o banco ao zero.

`MERCADOPAGO_MODE=fake` é o padrão quando `MERCADOPAGO_ACCESS_TOKEN` não
está configurado (ver seção "Módulo de pagamentos" abaixo) — não precisa
de conta sandbox pra rodar nada disto localmente.

As vinte e seis migrações foram validadas de ponta a ponta (`up` completo, `down`
completo em ordem reversa, `up` de novo) contra um PostgreSQL 16 real com
`pg_cron` instalado, incluindo um teste funcional de `advance_order()`
confirmando que transições legais avançam o pedido e transições ilegais
levantam exceção (a defesa de concorrência descrita na Parte I §4 e na
Parte II §9).

O módulo identity foi validado do mesmo jeito, ponta a ponta contra um
Postgres real: cadastro por OTP, rejeição de código errado com contador de
tentativas, emissão de tokens, `consent` autenticado x sem token, rotação
de refresh e — o caso que mais importa — reuso de um refresh já rotacionado
derrubando a família de sessão inteira (a defesa contra token roubado da
Parte I §7). `tests/smoke_identity.sh` é exatamente essa sequência,
automatizada, e roda no CI a cada push em `api/`, `lib/` ou `tests/`.

O módulo catalog+ordering+checkout também: cardápio com variação de preço
somando certo, pedido abaixo do mínimo barrado, checkout gerando
`policy_snapshot` e total corretos, transição ilegal (`pending_payment` →
`ready` direto) barrada com 409, papel sem permissão barrado com 403, fila
do KDS mostrando só o que a loja pode ver, e o caminho feliz completo
`paid → preparing → ready`. `tests/smoke_ordering.sh` semeia sua própria
loja/cardápio via `psql` e roda tudo isso a cada push, no mesmo CI.

Um bug real apareceu e foi corrigido durante essa validação: com
`PDO::ATTR_EMULATE_PREPARES` desligado (necessário pra prepared statement
de verdade, não só client-side), `PDO::execute()` manda um `false` do PHP
como string vazia `''` — e o PostgreSQL rejeita `''` como `boolean`
("invalid input syntax for type boolean"). `lib/core/db.php` ganhou `pg_bool()`
pra isso; qualquer parâmetro booleano futuro deve passar por ela.

O módulo de descoberta também: lista por distância real (Haversine)
ordenando a loja mais perto primeiro, filtro de categoria devolvendo só a
categoria pedida, busca por trigram achando o produto pelo nome parcial
mesmo na loja certa, busca curta demais barrada, e perfil autenticado
devolvendo estatísticas reais sem vazar CPF. `tests/smoke_discovery.sh`
semeia sua própria loja com CNPJ aleatório válido (`tests/support/random_cnpj.php`)
— um bug real de teste apareceu aqui: os primeiros CNPJs fixos colidiam
com os que `smoke_ordering.sh` já tinha semeado no mesmo banco, porque os
dois scripts rodam em sequência no mesmo CI sem recriar o banco entre um e
outro.

O módulo de carrinho também: variação obrigatória exigida antes de
precificar, item indisponível barrado, preço com variação somando certo
(R$30 + R$5 = R$35), troca de loja com carrinho vazio permitida e com
carrinho cheio barrada (409), acúmulo de subtotal em dois itens,
recálculo em `update_quantity`/`remove_item`. `tests/smoke_cart.sh` roda
tudo isso a cada push, no mesmo CI.

O módulo de pagamentos também: checkout do carrinho barrado sem endereço,
os cinco métodos pagando de ponta a ponta (dinheiro e maquininha indo
direto pra `paid`, cartão aprovado e recusado pelo modo fake do Mercado
Pago, Pix automático confirmado por webhook, Pix manual gerando um BR
Code de verdade), idempotência com replay idêntico/reuso barrado/chave
ausente barrada, upload de comprovante real (JPEG gerado via GD) avançando
o pedido, aprovação e recusa humana do Pix com aprovação dupla barrada por
`FOR UPDATE`. `tests/smoke_payments.sh` roda tudo isso a cada push, no
mesmo CI, em `MERCADOPAGO_MODE=fake` (ver seção "Módulo de pagamentos"
acima pro porquê).

O módulo de acompanhamento pós-pedido também: `orders/show.php` com a
linha do tempo, o SSE de `orders/track.php` recebendo evento ao vivo
disparado por outro processo (não só a forma da resposta), e
`reviews/create.php` barrando avaliação antes de `delivered` e avaliação
duplicada. `tests/smoke_tracking.sh` roda tudo isso a cada push, no mesmo
CI.

O módulo de conta também: CRUD de endereços com troca de padrão e
bloqueio de apagar em uso (409, não 500), dois cartões salvos com o mesmo
`mp_customer_id` reaproveitado (confirmado por query direta no banco),
troca de padrão e remoção. `tests/smoke_account.sh` roda tudo isso a cada
push, no mesmo CI, em `MERCADOPAGO_MODE=fake`.

O acesso do cliente também: cadastro completo com CPF gravado (conferido
por query direta), CPF inválido barrado, data de nascimento no futuro
barrada, CPF de outra conta em 409 e o canal WhatsApp indo parar em
`otp_codes.channel`. `tests/smoke_identity.sh` roda tudo isso junto com o
que já cobria de OTP e rotação de refresh.

E o painel da loja: a fila de validação com itens, endereço e contagem de
imagem repetida; o comprovante servido com autorização (401 sem token) e
negado pra loja rival nos quatro caminhos; a aprovação levando o pedido pra
`paid`; e as transições da cozinha até `delivering`, incluindo a ilegal
barrada pelo banco. `tests/smoke_panel.sh` roda tudo isso a cada push.

E o caminho do erro: cancelamento livre antes do preparo, com taxa depois,
recusa da loja sem custo pro cliente, o canal de estorno certo pra cada
método, o pagamento virando `refunded` e reembolso que não duplica.
`tests/smoke_cancel.sh` roda tudo isso a cada push.

E o app do entregador com o caixa: turno, corrida disputada por dois
entregadores, troco calculado no servidor, entrega barrada sem prova, os dois
lançamentos no livro e a baixa de espécie com divergência e com acerto.
`tests/smoke_courier.sh` roda tudo isso a cada push.

E o chat com os cupons: quem pode entrar na conversa, o fechamento 2 h
depois da entrega com histórico preservado, e o cupom barrado por CPF
ausente, loja errada, valor mínimo, repetição e orçamento.
`tests/smoke_support.sh` roda tudo isso a cada push.

E o painel da plataforma: aprovação ligando a trava de só-online, recusa
exigindo motivo, ocorrência virando contrapartida no livro com o valor certo,
e a política publicada como versão nova com a anterior intacta.
`tests/smoke_admin.sh` roda tudo isso a cada push.

O front-end validou o mesmo jeito, não só compilado, nas seis fases com
Playwright + Chromium numa janela de 430px: Fase 1 (fade do splash,
seleção de estado com destaque, SweetAlert real antes da Geolocation API,
toast sem jQuery), Fase 2 (lojas ordenadas por distância de verdade,
filtro de categoria refazendo a consulta, busca de produto, login real por
OTP destravando as abas autenticadas, perfil com estatísticas reais,
navegação Perfil → Pedidos → volta), Fase 3 (cardápio com item esgotado
visível, modal de variação com preço ao vivo, login embutido no modal
preservando seleção, carrinho persistente com recálculo real de
quantidade), Fase 4 (os cinco métodos de pagamento contra o backend real,
incluindo o ciclo completo de Pix manual com aprovação humana pelo painel
da loja — ver seção "Fase 4 — decisões adicionais" acima pros dois bugs
reais que apareceram e foram corrigidos nesta validação), Fase 5
(acompanhamento ao vivo por SSE entre processos diferentes, avaliação
completa, reabertura pela lista de pedidos mostrando "já avaliado" — ver
seção "Fase 5 — decisões adicionais" acima pro bug real de datas achado e
corrigido em três lugares nesta validação) e Fase 6 (dois endereços com
troca de padrão e edição, dois cartões salvos com bandeira detectada pelo
heurístico, configurações persistindo em `localStorage` de verdade). Um
bug real de CSS apareceu na Fase 3 e está documentado na seção "Módulo de
carrinho" acima — a classe `.modal` colidindo com o Bootstrap, achada
checando `boundingBox()` via Playwright, não só lendo o código.
