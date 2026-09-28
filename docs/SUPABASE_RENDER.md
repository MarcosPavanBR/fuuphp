# Supabase + Render: pôr o FUUdelivery no ar

Alternativa à VPS (docs/GO_LIVE.md): banco no **Supabase**, aplicação no
**Render** (imagem Docker deste repositório). As regras são as mesmas:
segredo só em variável de ambiente, nunca no git; produção falha fechado.

## 1. Supabase (banco)

Projeto **`fuuphp`** já criado (ref `vhkhwvbjhyawoocisuux`, us-east-1) com as
48 migrações, os 7 jobs do pg_cron e o `deploy/supabase/lockdown.sql`
aplicados (65 tabelas; anon/authenticated sem acesso nenhum).

Bucket **`avatars`** (foto de perfil) também criado: leitura pública,
1 MB, só JPEG, **sem política de escrita** -- quem grava é só o servidor,
com a chave secreta (`SUPABASE_SERVICE_KEY`). A imagem chega recortada,
reduzida a 256 px e sem EXIF (lib/storage/avatars.php).

Falta, no painel do Supabase (só você -- são segredos):

1. **Settings > API > Data API: desligar.** O app não usa a API REST do
   Supabase; desligada, a chave anon não abre nada.
2. **SQL Editor**, trocando as senhas por geradas (`openssl rand -hex 24`):
   ```sql
   ALTER ROLE app_rw   PASSWORD '<senha-app_rw>';
   ALTER ROLE migrator PASSWORD '<senha-migrator>';
   ALTER ROLE app_ro   PASSWORD '<senha-app_ro>';
   ```
3. **Admin fundador**, da sua máquina (Connect > Session pooler):
   `DATABASE_URL='postgres://postgres.vhkhwvbjhyawoocisuux:<senha-do-projeto>@aws-0-us-east-1.pooler.supabase.com:5432/postgres?sslmode=require' php bin/bootstrap_admin.php`

Projeto novo do zero: criar na mesma região do Render, rodar
`DATABASE_URL=<conexão direta ou session pooler> bash db/migrate.sh up` e
depois `psql "$DATABASE_URL" -f deploy/supabase/lockdown.sql`.

Migrações novas (versões futuras): antes do deploy,
`DATABASE_URL=<...> bash db/migrate.sh from <N>`. Nunca `down` em produção.

## 2. Render (aplicação)

1. **New > Blueprint**, escolher este repositório: o `render.yaml` cria o
   serviço `fuuphp` (Docker, Virgínia, plano Starter, disco de 5 GB em
   `/var/fuuphp/storage`, health check em `/api/v1/system/health.php`).
2. Preencher os segredos que o painel pede:
   - `DATABASE_URL` = `postgres://app_rw.vhkhwvbjhyawoocisuux:<senha-app_rw>@aws-0-us-east-1.pooler.supabase.com:5432/postgres?sslmode=require`
     (pooler de **sessão**, porta 5432: o Render só tem IPv4 e o
     acompanhamento ao vivo usa LISTEN/NOTIFY, que o pooler de transação não tem);
   - `DATA_ENCRYPTION_KEY` = `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`
     (guarde uma cópia no cofre: perder é perder os documentos de entregador);
   - Mercado Pago (credenciais de produção), Twilio, `VAPID_SUBJECT`,
     `PUBLIC_ORIGIN=https://<domínio>`. `JWT_SECRET` o Render gera;
   - `SUPABASE_SERVICE_KEY` = Supabase > Settings > API Keys > **Secret key**
     (`sb_secret_...`). Só no Render, nunca no front nem no git.
3. Primeira subida: `deploy/render/start.sh` cria as pastas no disco, gera a
   chave VAPID uma única vez e roda `bin/check_production.php`. Faltou
   segredo, modo simulado, banco como superusuário ou pg_cron parado = o
   contêiner não sobe e a versão anterior continua no ar.
4. Mercado Pago > Webhooks: `https://<domínio>/api/v1/payments/webhook_mercadopago.php`.
5. Domínio: Settings > Custom Domains no Render; no Cloudflare, CNAME para
   `fuuphp.onrender.com` com proxy ligado e SSL "Full (strict)". O IP real do
   cliente chega pelo X-Forwarded-For (deploy/render/nginx.conf).

As tarefas que na VPS são do cron (push, rodadas de entregador, estornos,
cancelamento sem entregador, bloqueio financeiro) rodam dentro do contêiner
(`deploy/render/jobs.sh`). As do banco continuam no pg_cron do Supabase.

## Custos

Supabase: projeto na organização ligada à Vercel. Render: Starter (web) +
disco. Plano grátis do Render não serve: sem disco, dorme e perde arquivos.
