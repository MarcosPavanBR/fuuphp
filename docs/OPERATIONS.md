# Operação

Como subir, configurar e manter o FUUdelivery rodando. Produção na VPS
(servidor, credenciais reais, validação antes de abrir): [GO_LIVE.md](GO_LIVE.md).

## Subir em desenvolvimento

```bash
docker compose up -d db                       # PostgreSQL 16 + pg_cron
cp .env.example .env                          # ajuste DATABASE_URL se preciso
bash db/migrate.sh up                         # aplica todas as migrações
php -S 127.0.0.1:8300 -t .                    # API (PHP_CLI_SERVER_WORKERS=4 se usar SSE)
cd web && npm ci && VITE_API_BASE=http://127.0.0.1:8300/api/v1 npx vite --port 5173
```

Os quatro apps: `http://localhost:5173/` (cliente), `/painel.html` (loja),
`/entregador.html`, `/admin.html`. O servidor embutido do PHP atende uma
requisição por vez: com o acompanhamento aberto (SSE), use
`PHP_CLI_SERVER_WORKERS` > 1, senão as outras chamadas esperam.

## Variáveis de ambiente

| Variável | Pra quê | Padrão |
|---|---|---|
| `DATABASE_URL` | conexão PostgreSQL | obrigatória |
| `JWT_SECRET` | assina os tokens de acesso | obrigatória |
| `APP_ENV` | `development`/`testing` devolvem o código OTP na resposta; `staging`/`production` ligam a trava de produção | `development` |
| `FUU_ENV_FILE` | caminho do arquivo de variáveis (na VPS: `/etc/fuuphp/fuuphp.env`) | `.env` da raiz |
| `ALLOWED_ORIGIN` | CORS do front em dev; vazio em produção (mesmo domínio) | `http://localhost:5173` |
| `MERCADOPAGO_ACCESS_TOKEN` | conta real do Mercado Pago | vazio = modo fake |
| `MERCADOPAGO_MODE` | `fake` força simulação | — |
| `MERCADOPAGO_WEBHOOK_SECRET` | confere a assinatura do webhook (sem ele, recusado em staging/produção) | — |
| `MERCADOPAGO_PUBLIC_KEY` | tokenização do cartão no navegador | — |
| `OTP_SENDER` | provedor do código de login: `twilio` em produção | `log` em development/testing |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` | credenciais da Twilio (SMS do código de login) | — |
| `TWILIO_FROM` ou `TWILIO_MESSAGING_SERVICE_SID` | remetente do SMS (`+55...` ou `MG...`) | — |
| `TWILIO_WHATSAPP_FROM` | liga "Receber por WhatsApp" na tela do código | desligado |
| `PROOF_STORAGE_DIR` | comprovantes de Pix e fotos de ocorrência (privado) | `storage/proofs` |
| `MENU_PHOTO_DIR` | fotos do cardápio (público) | `storage/menu` |
| `COURIER_DOC_DIR` | documentos da candidatura (privado) | `storage/courier_docs` |
| `PUSH_MODE` | `live` envia push de verdade | fake |
| `VAPID_PRIVATE_KEY_FILE` | chave do push | `storage/vapid/private.pem` |
| `VAPID_SUBJECT` | contato no JWT do push | `mailto:suporte@fuudelivery.com.br` |

Caminho relativo é resolvido a partir da raiz do projeto (`app_path()`).
`storage/` fica fora da raiz servida e fora do git.

Em `staging`/`production`, qualquer uma dessas simulada, vazia ou com o
marcador `<...>` do modelo faz a API responder 503 e os scripts saírem com 1
(`lib/core/production_guard.php`). O modelo de produção é
`deploy/env/fuuphp.env.example`.

## Tarefas agendadas

**No banco (pg_cron, criadas pelas migrações — nada a configurar):**
recusa de Pix não validado no prazo (a cada minuto), expiração de intenção
de baixa de espécie (5 min), abre/fecha loja pelo horário (a cada minuto),
repasses da semana (terça, 3h), purga por retenção (todo dia, 4h).

O pg_cron precisa de `cron.use_background_workers = on` no
`postgresql.conf`: sem isso, **toda** tarefa falha com "connection failed" e
nada avisa. `cron_healthy()` (migração 035) diz se alguma terminou nos
últimos 5 minutos; a rota de saúde e o `bin/check_production.php` usam ela.
Pra ver o histórico: `SELECT jobid, status, return_message, start_time FROM
cron.job_run_details ORDER BY start_time DESC LIMIT 20;`

**No servidor, uma linha por script** (na VPS: `deploy/cron/fuuphp`, com `flock`):

```cron
* * * * * php /caminho/app/bin/push_worker.php            >> logs/push.log 2>&1
* * * * * php /caminho/app/bin/dispatch_rounds.php        >> logs/dispatch.log 2>&1
* * * * * php /caminho/app/bin/execute_refunds.php        >> logs/refunds.log 2>&1
* * * * * php /caminho/app/bin/auto_cancel_no_courier.php >> logs/no_courier.log 2>&1
0 * * * * php /caminho/app/bin/apply_financial_blocks.php >> logs/blocks.log 2>&1
```

Todos são idempotentes: rodar duas vezes, ou atrasar, não duplica nada.

## Saúde

`GET /api/v1/system/health.php` responde `200 {"status":"ok"}` com o banco e o
pg_cron rodando, e `503 {"status":"degraded","failing":["db"|"cron"]}` se
não. É o endereço do monitor externo, e o primeiro endereço a abrir depois
de um deploy.

## Tarefas de uma vez

- `php bin/bootstrap_admin.php --name=... --phone=...`: cria o admin
  fundador e a política v1. Só no primeiro deploy e recusa se já existir admin.
- `php bin/check_production.php`: diz se a versão sobe em produção (a
  trava, mais o papel do banco e o pg_cron). O `deploy/deploy.sh` roda antes
  de trocar a versão.
- `php bin/generate_vapid_keys.php`: gera a chave do push (0600). Nunca
  sobrescreve, porque trocar a chave invalida todas as assinaturas.
- `php bin/generate_api_catalog.php` — atualiza `docs/API.md` depois de
  mexer em rota.
- `php bin/generate_db_map.php` — atualiza `docs/DATABASE.md` depois de
  migrar.

## Migrações

```bash
bash db/migrate.sh up        # aplica todas, em ordem
bash db/migrate.sh from N    # só as de número >= N (produção)
bash db/migrate.sh down N    # desfaz as N últimas (nunca em produção)
```

O script não guarda estado: `up` num banco já migrado falha na primeira
migração existente. Em produção, use só `from N`, com backup antes
([GO_LIVE.md](GO_LIVE.md#migrações-em-produção)).

## Testes

```bash
export DATABASE_URL=...   # banco LIMPO e migrado
for t in $(grep -o 'tests/smoke_[a-z_]*\.sh' .github/workflows/ci.yml); do bash "$t" || break; done
```

Na ordem do CI: algumas suítes contam registros e supõem banco limpo.

Como em produção, o CI sobe a API das suítes conectada como `app_rw`
(`API_DATABASE_URL`; o semeio dos testes usa o dono, em `DATABASE_URL`). Pra
fazer igual localmente:

```bash
psql "$DATABASE_URL" -c "ALTER ROLE app_rw PASSWORD 'local'"
export API_DATABASE_URL=postgres://app_rw:local@localhost:5432/fuudelivery
```

Sem `API_DATABASE_URL`, a API usa `DATABASE_URL`. `tests/smoke_db_roles.sh`
exige a variável: confere a RLS e as permissões direto no banco.
