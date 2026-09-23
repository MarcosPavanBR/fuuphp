# fuuphp

Plataforma de delivery com quatro superfícies (cliente, entregador, loja em
tablet/KDS, painel admin), especificada em `Especificação FUUDelivery —
Documento Único` (64 telas, 15 fases, esquema PostgreSQL de 42 tabelas).

## Relação com o `fuudelivery-backend` (Go) — não usado neste projeto

Existe um backend em produção — `MarcosPavanBR/fuudelivery-backend` — em Go
(microsserviços `auth_api`/`orders_api`/`delivery_api`, GORM, três bancos
Postgres separados no Supabase, RabbitMQ, gateway AbacatePay). **Decisão do
dono do produto: `fuuphp` não usa Go nem AbacatePay, em nenhuma hipótese.**
Este repositório é a implementação completa da especificação — PHP +
Svelte + PostgreSQL único + Mercado Pago — sem depender de nada do
backend Go e sem integrar com AbacatePay. O repositório Go é citado aqui só
como contexto histórico (existe, roda em produção, tem nome parecido), não
como stack, dependência ou fallback deste projeto.

## Cláusula zero — a stack é fixa

Svelte · Bootstrap · Bootstrap Icons · SweetAlert · toastr · PHP (PDO,
prepared statements) · **PostgreSQL 16, banco único do sistema** · Cloudflare
· Mercado Pago (gateway único, token do próprio lojista) · core-js · jQuery
(congelado, só onde já existe) · ESC/POS.

Nada entra, sai ou é "melhorado" sem autorização do dono do produto — nem
por versão mais nova, nem por biblioteca que pareça melhor. Ver a
especificação completa para o texto integral da cláusula e o raciocínio por
trás de cada item.

## O que este repositório contém

A **fundação de banco** (vinte e nove migrações SQL), a **API em PHP** sobre
ela e os **quatro apps em Svelte** (`web/`): cliente, painel da loja, app do
entregador e painel da plataforma. As 15 fases do mock estão construídas --
onboarding, descoberta, cardápio e carrinho, os cinco meios de pagamento
(Mercado Pago em modo fake), acompanhamento com mapa, conta e LGPD, PWA
offline e push, painel da loja, entregador com caixa e maquininha, livro
contábil com netting semanal, login, painel da plataforma, caminho do erro
com reembolso executado, ajuda, chat, agendamento e despacho em rodadas. O
que ficou de fora, e por quê, está em "Próximos passos" abaixo.

```
api/v1/<área>/<ação>.php   uma rota por arquivo (catálogo: docs/API.md)
lib/                       regras compartilhadas, por domínio
  core/ catalog/ ordering/ payments/ ledger/ dispatch/ messaging/ account/
db/migrations/             NNN_<assunto>.up.sql + .down.sql (mapa: docs/DATABASE.md)
bin/                       tarefas agendadas e geradores de documentação
tests/smoke_<área>.sh      testes de ponta a ponta contra o banco, um por módulo
web/                       front-end (Vite): 4 apps, 1 build
  src/entries/ src/apps/ src/lib/{screens/<app>,components,services,state,utils}
  public/sw.js             service worker (offline + push)
docs/                      a documentação do sistema (abaixo)
```

## Documentação

| Documento | Pra quê |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | como o sistema é montado: camadas, caminho da requisição, regras do banco, dinheiro |
| [docs/API.md](docs/API.md) | todas as rotas, com método, quem chama e o que faz (gerado) |
| [docs/DATABASE.md](docs/DATABASE.md) | todas as tabelas, colunas e referências (gerado) |
| [docs/CONVENTIONS.md](docs/CONVENTIONS.md) | nomes, comentários, erros, dinheiro, testes |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | subir, configurar, cron, migrar, testar |
| [docs/decisions/](docs/decisions/README.md) | as decisões de cada módulo: o que o mock pedia, o que foi feito, o que foi simplificado |

## Como rodar localmente

```bash
docker compose up -d db && cp .env.example .env && bash db/migrate.sh up
php -S 127.0.0.1:8300 -t .
cd web && npm ci && VITE_API_BASE=http://127.0.0.1:8300/api/v1 npx vite --port 5173
```

Detalhes (variáveis, tarefas agendadas, testes): [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Próximos passos

As 15 fases do mock estão construídas. O que falta é o que depende de algo
que não existe neste ambiente, ou de decisão do dono do produto:

1. **Validar contra o Mercado Pago real.** Tudo roda em `MERCADOPAGO_MODE=fake`
   (este ambiente não alcança a API). O formato das chamadas segue a
   documentação, mas cartão, Pix automático, webhook, estorno e a gorjeta
   "no mesmo cartão" precisam de uma rodada numa conta sandbox antes de
   produção ([05](docs/decisions/05-pagamentos.md),
   [14](docs/decisions/14-pontas-de-dinheiro.md),
   [15](docs/decisions/15-troca-de-metodo-gorjeta-pix-automatico.md)).
2. **MercadoPago.js com Public Key real.** Cartão novo e salvo já são
   tokenizados no navegador ([30](docs/decisions/30-cartao-salvo-e-total-com-frete.md));
   falta só configurar `MERCADOPAGO_PUBLIC_KEY` e conferir numa conta sandbox.
3. **Validar o que sai pra internet.** Push de verdade (`PUSH_MODE=live`,
   serviços de push dos navegadores), tiles do mapa (OpenStreetMap) e a busca
   por CEP (ViaCEP) foram testados só até onde o ambiente deixa: assinatura,
   pinos e caminho de falha, respectivamente
   ([19](docs/decisions/19-push.md), [16](docs/decisions/16-lacunas-do-app-do-cliente.md)).
4. **Impressora física.** A comanda e o recibo ESC/POS já saem pelo tablet do
   balcão (WebUSB/Web Serial, [31](docs/decisions/31-impressao-escpos-e-recibo-de-baixa.md));
   falta conferir numa térmica de verdade.
5. **Decisão de produto: login social (Google, Apple).** A tela 10.1 mostra
   os botões desabilitados: exige OAuth e uma tabela de identidade federada.
6. **Armazenamento de arquivos em produção.** Comprovantes, fotos e
   documentos ficam em disco local (`storage/`); em produção o plano é bucket
   privado com URL assinada (Cloudflare R2), trocando só `app_path()` pelos
   caminhos do bucket.

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
