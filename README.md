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

A **fundação de banco** (trinta e cinco migrações SQL), a **API em PHP** sobre
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
bin/                       tarefas agendadas, primeiro deploy e geradores de documentação
deploy/                    produção na VPS: Nginx, PHP-FPM, cron, backup, deploy, modelo do .env
tests/smoke_<área>.sh      testes de ponta a ponta contra o banco, um por módulo
web/                       front-end (Vite): 4 apps, 1 build
  src/entries/ src/apps/ src/lib/{screens/<app>,components,services,state,utils}
  public/sw.js             service worker (offline + push)
docs/                      a documentação do sistema (abaixo)
```

## Documentação

Começo rápido: [docs/README.md](docs/README.md) diz qual documento ler pra cada necessidade.

| Documento | Pra quê |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | como o sistema é montado: camadas, caminho da requisição, regras do banco, dinheiro |
| [docs/API.md](docs/API.md) | todas as rotas, com método, quem chama e o que faz (gerado) |
| [docs/DATABASE.md](docs/DATABASE.md) | todas as tabelas, colunas e referências (gerado) |
| [docs/CONVENTIONS.md](docs/CONVENTIONS.md) | nomes, comentários, erros, dinheiro, testes |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | subir, configurar, cron, migrar, testar |
| [docs/GO_LIVE.md](docs/GO_LIVE.md) | do simulado ao real: VPS, credenciais, validação antes de abrir |
| [docs/MANUAL.md](docs/MANUAL.md) | como cada público usa o sistema: cliente, loja, entregador, plataforma |
| [docs/SECURITY.md](docs/SECURITY.md) | o que protege o sistema, onde, e com qual teste |
| [docs/BRAND.md](docs/BRAND.md) | a marca: logo, símbolo, slogan, arquivos e regras de uso |
| [docs/GLOSSARY.md](docs/GLOSSARY.md) | os termos do negócio e o nome deles no código |
| [docs/decisions/](docs/decisions/README.md) | as decisões de cada módulo: o que o mock pedia, o que foi feito, o que foi simplificado |

## Como rodar localmente

```bash
docker compose up -d db && cp .env.example .env && bash db/migrate.sh up
php -S 127.0.0.1:8300 -t .
cd web && npm ci && VITE_API_BASE=http://127.0.0.1:8300/api/v1 npx vite --port 5173
```

Detalhes (variáveis, tarefas agendadas, testes): [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Próximos passos

As 15 fases do mock estão construídas, e o caminho pra produção também:

- a trava de produção: com `APP_ENV=production`, qualquer integração simulada
  ou sem segredo impede a API de subir;
- o envio de OTP plugável;
- o admin fundador;
- os arquivos da VPS em `deploy/`;
- a API conectando como `app_rw`, com RLS por loja;
- o cadastro de loja pelo próprio painel, com análise da plataforma antes de
  vender, e os termos de uso e o aviso de privacidade (`web/public/`);
- a rota de saúde pro monitor externo, com o pg_cron conferido
  ([36](docs/decisions/36-cadastro-de-loja-e-saude.md)).

O passo a passo está em [docs/GO_LIVE.md](docs/GO_LIVE.md)
([decisão 33](docs/decisions/33-go-live.md)). O que falta depende de conta
real ou de decisão do dono do produto:

1. **Conta da Twilio** pro código de login por SMS (decisão do Marcos, já
   implementada com `curl` nativo, [34](docs/decisions/34-otp-twilio.md)):
   Account SID, Auth Token e um número com SMS pro Brasil no `.env`. A
   conta trial só manda pra números verificados.
2. **Mercado Pago: uma rodada no sandbox** com o token único da plataforma
   antes de produção: cartão, Pix, webhook, estorno e gorjeta
   ([05](docs/decisions/05-pagamentos.md),
   [14](docs/decisions/14-pontas-de-dinheiro.md),
   [15](docs/decisions/15-troca-de-metodo-gorjeta-pix-automatico.md),
   [30](docs/decisions/30-cartao-salvo-e-total-com-frete.md)).
3. **VPS e domínio**, e o destino da cópia do backup fora da VPS.
4. **Validar o que sai pra internet.** Push de verdade, tiles do mapa
   (OpenStreetMap) e busca por CEP (ViaCEP) foram testados só até onde o
   ambiente deixa ([19](docs/decisions/19-push.md),
   [16](docs/decisions/16-lacunas-do-app-do-cliente.md)). A impressora
   térmica também precisa de um teste com o aparelho de verdade
   ([31](docs/decisions/31-impressao-escpos-e-recibo-de-baixa.md)).
5. **Quando levar os arquivos pro Cloudflare R2.** Hoje ficam no disco da
   VPS, fora do projeto, e só saem por rota autenticada.
6. **Dados da empresa nos termos e no aviso de privacidade** (os trechos entre
   colchetes em `web/public/termos.html` e `privacidade.html`) e a revisão de
   um advogado.
7. **Um monitor externo** apontado pra `api/v1/system/health.php` (UptimeRobot,
   Better Stack ou outro, à escolha).

Já decidido: o **código de login vai por SMS pela Twilio**; o **Mercado Pago usa token único da plataforma** (a loja
recebe pelo repasse semanal do livro-razão); a **fidelidade (2.3) entra no
lançamento**
([32](docs/decisions/32-fidelidade.md)), e o **login com Google/Apple fica
pra v2**. A tela 10.1 mostra esses botões desabilitados, com "em breve"
([09](docs/decisions/09-login-e-cadastro.md)).

## Origem

Este repositório nasceu de um handoff do Claude Design: 64 telas desenhadas
em HTML/CSS/JS e uma especificação técnica única (arquitetura + esquema),
ambas resultado de várias sessões de design com o dono do produto. Os
arquivos de origem (telas, especificação, transcrição das conversas) estão
arquivados fora deste repositório — este código é a implementação da Parte
II da especificação, seção por seção.
