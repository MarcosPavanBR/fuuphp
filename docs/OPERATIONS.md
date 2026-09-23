# Operação

Como subir, configurar e manter o FUUdelivery rodando.

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
| `APP_ENV` | `development` devolve o código OTP na resposta | — |
| `ALLOWED_ORIGIN` | CORS do front em dev; vazio em produção (mesmo domínio) | `http://localhost:5173` |
| `MERCADOPAGO_ACCESS_TOKEN` | conta real do Mercado Pago | vazio = modo fake |
| `MERCADOPAGO_MODE` | `fake` força simulação | — |
| `MERCADOPAGO_WEBHOOK_SECRET` | confere a assinatura do webhook | — |
| `PROOF_STORAGE_DIR` | comprovantes de Pix e fotos de ocorrência (privado) | `storage/proofs` |
| `MENU_PHOTO_DIR` | fotos do cardápio (público) | `storage/menu` |
| `COURIER_DOC_DIR` | documentos da candidatura (privado) | `storage/courier_docs` |
| `PUSH_MODE` | `live` envia push de verdade | fake |
| `VAPID_PRIVATE_KEY_FILE` | chave do push | `storage/vapid/private.pem` |
| `VAPID_SUBJECT` | contato no JWT do push | `mailto:suporte@fuudelivery.com.br` |

Caminho relativo é resolvido a partir da raiz do projeto (`app_path()`).
`storage/` fica fora da raiz servida e fora do git.

## Tarefas agendadas

**No banco (pg_cron, criadas pelas migrações — nada a configurar):**
recusa de Pix não validado no prazo (a cada minuto), expiração de intenção
de baixa de espécie (5 min), abre/fecha loja pelo horário (a cada minuto),
repasses da semana (terça, 3h), purga por retenção (todo dia, 4h).

**No servidor (cron do cPanel ou do sistema), uma linha por script:**

```cron
* * * * * php /caminho/app/bin/push_worker.php            >> logs/push.log 2>&1
* * * * * php /caminho/app/bin/dispatch_rounds.php        >> logs/dispatch.log 2>&1
* * * * * php /caminho/app/bin/execute_refunds.php        >> logs/refunds.log 2>&1
* * * * * php /caminho/app/bin/auto_cancel_no_courier.php >> logs/no_courier.log 2>&1
0 * * * * php /caminho/app/bin/apply_financial_blocks.php >> logs/blocks.log 2>&1
```

Todos são idempotentes: rodar duas vezes, ou atrasar, não duplica nada.

## Tarefas de uma vez

- `php bin/generate_vapid_keys.php` — gera a chave do push (0600, nunca
  sobrescreve: trocar a chave invalida todas as assinaturas).
- `php bin/generate_api_catalog.php` — atualiza `docs/API.md` depois de
  mexer em rota.
- `php bin/generate_db_map.php` — atualiza `docs/DATABASE.md` depois de
  migrar.

## Migrações

```bash
bash db/migrate.sh up        # aplica todas, em ordem
bash db/migrate.sh down N    # desfaz as N últimas
```

O script não guarda estado: `up` num banco já migrado falha na primeira
migração existente. Em produção, aplique só as novas com
`psql "$DATABASE_URL" -f db/migrations/NNN_x.up.sql`.

## Testes

```bash
export DATABASE_URL=...   # banco LIMPO e migrado
for t in $(grep -o 'tests/smoke_[a-z_]*\.sh' .github/workflows/ci.yml); do bash "$t" || break; done
```

Na ordem do CI: algumas suítes contam registros e supõem banco limpo.
