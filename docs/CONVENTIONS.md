# Convenções

Como o código deste repositório é escrito. Vale pra código novo e pra
qualquer arquivo que for mexido.

## Nomes

| O quê | Padrão | Exemplo |
|---|---|---|
| Rota da API | `api/v1/<área>/<ação>.php`, snake_case | `payments/change_method.php` |
| Lib PHP | `lib/<domínio>/<assunto>.php` | `lib/ledger/netting.php` |
| Função PHP | `<assunto>_<verbo>` em snake_case, prefixo do arquivo | `netting_due()`, `push_notify()` |
| Constante PHP | MAIÚSCULAS com o assunto na frente | `REFUND_MAX_ATTEMPTS` |
| Script de operação | `bin/<verbo>_<objeto>.php` | `bin/execute_refunds.php` |
| Migração | `db/migrations/NNN_<assunto>.up.sql` + `.down.sql` | `026_account_deletion` |
| Teste | `tests/smoke_<área>.sh` | `tests/smoke_privacy.sh` |
| Componente Svelte | PascalCase, com sufixo de papel (abaixo) | `OrderTrackingScreen.svelte` |
| Módulo JS | camelCase; `.svelte.js` quando usa runes | `customerSession.svelte.js` |
| Variável CSS | `--fuu-<grupo>-<nível>` (tokens do mock) | `--fuu-ink-3` |

**Sufixos de componente**, pra saber o que é pelo nome:

- `…Screen` — uma tela inteira do mock (`OrderTrackingScreen`, `SettingsScreen`);
- `…Flow` — sequência de telas com estado próprio (`PaymentFlow`, `AuthFlow`);
- `…Dialog` / `…Modal` / `…Drawer` — sobreposição (`CancelDialog`, `ItemModal`);
- `…Queue` / `…Board` / `…List` / `…Panel` — parte de uma tela
  (`ProofQueue`, `KdsBoard`, `OfferList`, `NoCourierPanel`);
- `…App` — a raiz de um dos quatro apps (`web/src/apps/`).

Telas de cada app ficam em `web/src/lib/screens/<app>/` (`customer`,
`panel`, `courier`, `admin`); o que mais de uma tela usa, em
`web/src/lib/components/`.

## Comentários

Em português, explicando **por quê** -- o "o quê" o código já diz.

- **Todo arquivo começa com um comentário de cabeçalho**: a tela do mock que
  ele serve (número e frase do mock, entre aspas, quando existe) e as
  decisões que não são óbvias. Nas rotas, o primeiro parágrafo vira o
  resumo do [catálogo da API](API.md) -- `php bin/generate_api_catalog.php
  --missing` lista quem está sem.
- **Toda função de `lib/` tem docblock** (`/** ... */`) logo acima: o que
  ela faz, o que garante e, quando importa, quem chama. Função pequena
  ganha uma linha; regra de negócio ganha o porquê.
- Simplificação assumida, limite conhecido ou coisa não validada (ex.: API
  real do Mercado Pago) é escrita no código **e** na decisão do módulo, com
  a palavra "não" bem visível. Nada finge funcionar.
- Comentário que ficou falso é bug: corrige junto com o código.

## Erros

- Resposta de erro é sempre `{code, message, trace_id}` via
  `error_response()`: `code` em snake_case estável (o front e os testes
  dependem dele), `message` em português pra pessoa ler, com a saída
  possível ("Cadastre-se para continuar", "Gere o QR de novo").
- `422` preenchimento (com `fields`), `401` sem sessão, `403` papel errado,
  `404` não existe **ou não é seu** (não se confirma a existência do que é de
  outra pessoa), `409` conflito de estado, `410` expirado, `429` limite.

## Dinheiro

- `numeric(12,2)` no banco; `round(..., 2)` no PHP antes de gravar.
- Nada muda saldo sem linha no livro (`ledger_add()`), e o livro só recebe
  INSERT. Corrigir é lançar a contrapartida.
- Sinal: positivo = a outra parte deve à plataforma (loja, espécie com o
  entregador) ou a plataforma deve ao entregador (`courier_payable`). Cada
  lançamento tem `origin` + `origin_id` únicos o bastante pra ser
  idempotente (`'delivered:<pedido>'`, `'review_tip:<pedido>'`).
- Chamada ao Mercado Pago fica fora da transação; o resultado é gravado
  depois, e retentativa usa chave de idempotência.

## Banco

- Toda mudança de esquema é uma migração nova com `down` que desfaz tudo; o
  CI prova `down N` + `up`. Migração aplicada não se edita.
- Status de pedido só por `advance_order()`; evento que não muda status vai
  pra `order_events` com `from_status = to_status` e o fato em `meta`.
- `docs/DATABASE.md` é gerado: `php bin/generate_db_map.php` depois de migrar.

## Fuso horário

- Dois relógios. O **da plataforma** é America/Sao_Paulo, no PHP
  (`lib/bootstrap.php`) e na sessão do banco (`lib/core/db.php`): relatórios,
  acerto de terça, exportação. O **da loja** é o fuso da cidade dela
  (`service_cities.timezone`, migração 038): abrir e fechar sozinha, faixa de
  agendamento, "hoje" da conciliação, "fechar por hoje", hora no comprovante e
  na comanda. No PHP, `store_timezone($pdo, $restaurantId)`; no SQL,
  `restaurant_timezone(restaurant_id)`. Hora de parede de loja nunca sai de
  `date()` puro.
- O servidor continua em UTC; instantes (`timestamptz`) são os mesmos em
  qualquer fuso.
- Em teste, compare horários como instante (`date -d ... +%s`), nunca como
  texto: `20:30-03:00` e `23:30Z` são o mesmo momento.

## Testes

- Cada funcionalidade entra com o teste dela no `smoke_<área>.sh` certo (ou
  num novo, adicionado ao CI), cobrindo o caminho feliz **e** as recusas.
- Suítes rodam em banco limpo, na ordem do CI; cada uma cria os próprios
  dados com identificadores únicos (CNPJ/CPF aleatórios, `STAMP`) e
  geografia própria quando o despacho está envolvido.
- Tela nova é conferida no navegador (Playwright) sem erro de console.

## Commits

Mensagem em português: primeira linha curta com o que muda pra quem usa;
corpo com o porquê quando não é óbvio. Um commit por módulo fechado, com
testes passando.
