# Go-live: do simulado ao real

Como tirar o FUUdelivery do modo simulado e pôr no ar numa VPS. A ordem é a
do plano de go-live: **trava → OTP → admin → VPS em homologação → Mercado
Pago sandbox → push → storage → validação → credenciais de produção**.

Regras que valem pra tudo aqui:

- A stack é fixa (README, cláusula zero): Svelte, Bootstrap, PHP com PDO,
  PostgreSQL 16, Cloudflare, Mercado Pago, ESC/POS. Nada de biblioteca, SDK
  ou serviço novo sem autorização do Marcos. Nunca Go, nunca AbacatePay.
- **Produção falha fechado.** Com `APP_ENV=production` (ou `staging`), se
  qualquer integração estiver simulada ou sem segredo, a API não sobe.
- Segredo só no `.env` do servidor (`/etc/fuuphp/fuuphp.env`, 600, fora do
  git e da raiz servida). Nunca no front, nunca em commit.
- Smoke test só contra uma **cópia** do banco de produção, nunca contra o real.
  Nunca `down` em produção.

## Onde cada coisa está

| Passo | Estado | Onde |
|---|---|---|
| 1. Trava de produção | pronto | `lib/core/production_guard.php`, `bin/check_production.php`, `tests/smoke_production_guard.sh` |
| 2. Mercado Pago sandbox → produção | pronto no código; falta a conta | "Mercado Pago" abaixo |
| 3. OTP real | envio plugável pronto; **provedor pendente** | `lib/messaging/otp_sender.php` |
| 4. Push real | pronto; falta gerar a chave na VPS | "Push" abaixo |
| 5. Storage | fase 1 (disco da VPS, fora do projeto) pronta; fase 2 (R2) **pendente** | "Arquivos enviados" abaixo |
| 6. Admin fundador | pronto | `bin/bootstrap_admin.php`, `tests/smoke_bootstrap_admin.sh` |
| 7. VPS | arquivos prontos | `deploy/` |
| 8. `.env` de produção | modelo pronto | `deploy/env/fuuphp.env.example` |
| 9. Validação | roteiro abaixo | "Validação antes de abrir" |

## 1. A trava de produção

Chamada no fim de `lib/bootstrap.php`, vale pra toda rota e todo script de
`bin/`. Recusa subir se:

- `JWT_SECRET` vazio, com menos de 32 caracteres ou igual ao do `.env.example`;
- Mercado Pago fora do modo live, ou sem access token, public key ou
  segredo do webhook; token de sandbox (`TEST-`) em `production`;
- `PUSH_MODE` não é `live` ou a chave VAPID não está legível;
- o provedor de OTP não está configurado (ou é o `log` de desenvolvimento);
- `ALLOWED_ORIGIN` ausente (vazia é válido: mesmo domínio, sem CORS);
- as pastas de arquivo não existem, não são graváveis ou ficam dentro do projeto;
- qualquer valor ainda com o marcador `<...>` do modelo.

Na API, a resposta é `503 service_unavailable` sem detalhe; os motivos vão pro
log do PHP com o `trace_id`. Na linha de comando, os motivos saem no stderr.

`bin/check_production.php` roda a mesma trava **e** confere o banco: conecta
com o `DATABASE_URL` do `.env`, recusa se o papel for superusuário ou
ignorar RLS, e confere o pg_cron. O `deploy/deploy.sh` roda esse script antes
de trocar a versão no ar.

Também muda em homologação/produção: o webhook do Mercado Pago sem segredo
configurado é recusado (em desenvolvimento ele segue aceito, pro modo fake),
e o `dev_code` do OTP só aparece em `development`/`testing`.

## 2. Servidor (VPS)

Pensado pra Ubuntu 24.04 LTS (ou Debian 12). Tamanho e provedor da VPS e o
domínio são decisão do Marcos; 2 vCPU e 2–4 GB de RAM atendem o começo (o
PostgreSQL e o PHP-FPM na mesma máquina).

### Pacotes

```bash
sudo apt install nginx postgresql-16 postgresql-16-cron \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-gd php8.4-curl php8.4-mbstring \
  git
# Node 22 só pra compilar o front no deploy (npx vite build).
```

As extensões são as que o código usa: `pdo_pgsql` e `pgsql` (o
acompanhamento ao vivo usa LISTEN/NOTIFY), `gd` (marca d'água do
comprovante), `curl` (Mercado Pago, push), `mbstring` (cupom ESC/POS);
`fileinfo`, `openssl` e `iconv` já vêm no PHP.

### Usuários e pastas

```bash
sudo adduser --system --group --no-create-home fuuphp          # roda o PHP
sudo mkdir -p /srv/fuuphp/releases /etc/fuuphp /var/log/fuuphp \
  /var/fuuphp/storage/{proofs,menu,courier_docs,vapid} /var/backups/fuuphp
sudo chown -R fuuphp:fuuphp /var/fuuphp/storage /var/log/fuuphp
sudo chown root:fuuphp /etc/fuuphp            # o fuuphp atravessa a pasta pra ler o .env
sudo chmod 750 /var/fuuphp/storage /etc/fuuphp && sudo chmod 700 /var/backups/fuuphp
sudo git clone --mirror <url-do-repositorio> /srv/fuuphp/repo.git
```

O usuário que faz deploy é dono de `/srv/fuuphp` e pode rodar
`sudo systemctl reload php8.4-fpm`. O `fuuphp` não tem shell e não escreve no
código.

### PostgreSQL

Em `/etc/postgresql/16/main/postgresql.conf`:

```
listen_addresses = 'localhost'
shared_preload_libraries = 'pg_cron'
cron.database_name = 'fuudelivery'
cron.timezone = 'America/Sao_Paulo'
```

O relógio da VPS fica em UTC (padrão). `cron.timezone` faz as tarefas do
banco seguirem o horário de Brasília: o repasse sai "terça, 3h" daqui, não
de Greenwich. O horário das lojas já é calculado em America/Sao_Paulo.

Em `pg_hba.conf`, a API entra por senha e só pela máquina:

```
host  fuudelivery  app_rw  127.0.0.1/32  scram-sha-256
host  fuudelivery  app_ro  127.0.0.1/32  scram-sha-256
```

Depois:

```bash
sudo systemctl restart postgresql
sudo -u postgres createdb fuudelivery
cd /srv/fuuphp/current   # ou um checkout da versão
sudo -u postgres DATABASE_URL=postgresql:///fuudelivery bash db/migrate.sh up   # SÓ na primeira vez
read -rs APP_RW_PW; read -rs APP_RO_PW
sudo -u postgres psql -d fuudelivery -v ON_ERROR_STOP=1 \
  -v app_rw_pw="$APP_RW_PW" -v app_ro_pw="$APP_RO_PW" -f deploy/postgres/set_passwords.sql
```

As migrações rodam como `postgres` (o pg_cron só é criado por
superusuário). A API conecta como `app_rw`, que:

- só enxerga pedido, pagamento, comprovante e mensagem se a conexão disser
  quem é (RLS da migração 009). O PHP abre toda conexão como "plataforma" e
  estreita a equipe de loja pra própria loja (`db_scope_to_restaurant`);
- não altera nem apaga o livro-razão e os pontos de fidelidade;
- não cria tabela.

O CI roda todas as suítes com a API conectada como `app_rw`, e
`tests/smoke_db_roles.sh` confere essas regras direto no banco.

#### Migrações em produção

Uma versão nova com migração nova (ex.: `030_x.up.sql`):

```bash
# 1. backup antes (deploy/backup/backup.sh) e teste numa CÓPIA do banco
# 2. só as novas:
sudo -u postgres DATABASE_URL=postgresql:///fuudelivery bash db/migrate.sh from 30
# 3. deploy do código (abaixo)
```

Nunca `up` num banco já migrado, nunca `down` em produção.

### O `.env`

```bash
sudo cp deploy/env/fuuphp.env.example /etc/fuuphp/fuuphp.env
sudo chown fuuphp:fuuphp /etc/fuuphp/fuuphp.env && sudo chmod 600 /etc/fuuphp/fuuphp.env
sudo -e /etc/fuuphp/fuuphp.env     # preencher cada <...>
```

Primeiro com `APP_ENV=staging` e as credenciais de teste do Mercado Pago
(passo "Mercado Pago" abaixo); produção só depois da validação.

### PHP-FPM, Nginx, Cloudflare, cron, logs

```bash
sudo cp deploy/php-fpm/fuuphp.conf /etc/php/8.4/fpm/pool.d/fuuphp.conf
sudo sed -i 's/^expose_php.*/expose_php = Off/' /etc/php/8.4/fpm/php.ini
sudo cp deploy/nginx/fuuphp.conf /etc/nginx/sites-available/fuuphp   # trocar DOMINIO
sudo ln -s /etc/nginx/sites-available/fuuphp /etc/nginx/sites-enabled/fuuphp
sudo rm -f /etc/nginx/sites-enabled/default
sudo cp deploy/cron/fuuphp /etc/cron.d/fuuphp
sudo cp deploy/logrotate/fuuphp /etc/logrotate.d/fuuphp
sudo nginx -t && sudo systemctl reload nginx php8.4-fpm
```

O Nginx serve **só** o PWA compilado (`web/dist`) e as rotas no formato
`api/v1/<área>/<ação>.php`. O resto do projeto não é alcançável; `.env`,
`lib/`, `storage/` e afins nunca saem. O arquivo foi conferido com
`nginx -t` e com requisições reais (dotfiles 404, código-fonte nunca servido,
HSTS em tudo, cache longo só em `/assets/`).

No Cloudflare:

- DNS do domínio com proxy (nuvem laranja);
- SSL/TLS em **Full (strict)**, com um certificado de origem
  (SSL/TLS > Origin Server) salvo em `/etc/ssl/cloudflare/`;
- "Always Use HTTPS" ligado;
- regra de cache: `/api/*` com bypass.

O Nginx só aceita o IP do cliente vindo do Cloudflare (`real_ip`), e o PHP
usa só esse IP (`client_ip()`). Assim, a prova de consentimento não grava IP
forjado. No firewall da VPS, deixe aberto só SSH e 80/443.

### Deploy e rollback

```bash
bash deploy/deploy.sh v1.0.0      # tag ou commit
bash deploy/deploy.sh --rollback  # volta pra versão anterior
```

Cada versão vai pra `/srv/fuuphp/releases/<data>-<commit>`. O symlink
`current` só é trocado depois de três coisas: `php -l` em todo PHP, o build
do front e o `bin/check_production.php` passar com o `.env` real. Se a trava
recusar, nada muda no ar. O deploy guarda as últimas 5 versões.

### Tarefas agendadas e backup

`deploy/cron/fuuphp` roda os scripts de `bin/` (push, rodadas de oferta,
estornos, pedido sem entregador, bloqueio financeiro) com `flock`. As
tarefas do banco (Pix vencido, baixa de espécie, horário das lojas, repasse de
terça, retenção) já estão no pg_cron.

`deploy/backup/backup.sh` roda todo dia às 3h30 e faz:

- `pg_dump` do banco;
- um `tar` do storage (comprovantes, fotos, documentos, chave VAPID);
- a conferência de que o dump abre;
- guarda 14 dias em `/var/backups/fuuphp`.

**Cópia fora da VPS é obrigatória antes de abrir.** O destino é decisão
pendente; ele entra em `OFFSITE_CMD`. Teste de restauração, uma vez antes de
abrir e depois todo mês:

```bash
sudo -u postgres createdb fuu_restore_test
sudo -u postgres pg_restore -d fuu_restore_test /var/backups/fuuphp/<data>/fuudelivery.dump
```

Logs: `/var/log/fuuphp/*.log` (cron e erros do PHP) e
`/var/log/nginx/fuuphp.*.log`, com 30 dias de rotação. O código de OTP e os
segredos nunca vão pro log.

## 3. Admin fundador

Uma vez só, depois das migrações:

```bash
sudo -u fuuphp FUU_ENV_FILE=/etc/fuuphp/fuuphp.env \
  php /srv/fuuphp/current/bin/bootstrap_admin.php --name="Marcos ..." --phone=DDDNUMERO
```

O script cria o admin e a política da plataforma v1 (comissão 8%, teto de
espécie R$ 300, os cinco meios de pagamento). Ele recusa se já existir admin.
Não há senha: o admin entra pelo mesmo código por SMS/e-mail. Por isso, **o
provedor de OTP precisa estar no ar antes do primeiro login**. Frete e taxas
começam zerados e são ajustados no painel (10.5).

## 4. Mercado Pago: sandbox → produção

Decisão pendente: **um token único da plataforma** (é o que o código faz
hoje: a plataforma recebe e repassa pelo livro-razão) **ou um token por
loja** (cada loja recebe direto, com split). O modelo de `.env` é o do
token único.

1. No painel do Mercado Pago (Suas integrações), crie a aplicação e os
   usuários de teste (vendedor e comprador).
2. Em homologação (`APP_ENV=staging`), use as credenciais de **teste**
   (`TEST-...`): access token e public key. O webhook vai em
   `https://<dominio>/api/v1/payments/webhook_mercadopago.php`, com o evento
   Pagamentos, e a "assinatura secreta" gerada vai em
   `MERCADOPAGO_WEBHOOK_SECRET`.
3. Pague com os cartões de teste da documentação do Mercado Pago (o nome do
   titular decide o resultado: `APRO` aprova, `OTHE` recusa) e confira:
   - pedido aprovado vai pra cozinha;
   - recusado volta pra escolha do meio;
   - o webhook chega com assinatura válida;
   - o estorno (13.4) sai.
4. Pix no sandbox: confira a geração do QR/copia-e-cola e a expiração. O
   pagamento de verdade é conferido em produção com um valor baixo, estornado
   em seguida.
5. Produção: troque para as credenciais de **produção** (`APP_USR-...`) e
   `APP_ENV=production`. A trava recusa `TEST-` em produção.

## 5. OTP (login)

`send_otp($canal, $destino, $codigo)` em `lib/messaging/otp_sender.php`:

- quando o envio falha, a rota apaga o código gerado e responde `502
  otp_send_failed`, e a pessoa pode pedir de novo;
- o limite de tentativas (429) continua valendo;
- o código nunca vai pro log.

O driver `log` só existe em `development`/`testing`.

**Pendente: qual provedor de SMS/e-mail.** Escolhido o provedor, ele entra
como um driver novo em `otp_sender.php`: `curl` nativo, timeout curto,
credenciais no `.env`. Sem SDK e sem Composer. Até lá, a trava não deixa
subir em produção.

## 6. Push

```bash
sudo -u fuuphp FUU_ENV_FILE=/etc/fuuphp/fuuphp.env php /srv/fuuphp/current/bin/generate_vapid_keys.php
```

Isso gera a chave uma vez (0600). O script nunca sobrescreve: trocar a chave
derruba todas as assinaturas. Depois, `PUSH_MODE=live`. Teste num aparelho
real:

- Android/Chrome com o PWA aberto;
- iPhone só com o PWA **instalado** na tela inicial (iOS 16.4+).

## 7. Arquivos enviados

**Fase 1 (pronta):** disco da VPS, em `/var/fuuphp/storage`, fora do projeto
e fora da raiz servida. A trava recusa pasta dentro do projeto.

- Comprovantes de Pix, fotos de ocorrência e documentos de entregador
  **nunca** são públicos: só saem por rota autenticada da API, que confere
  quem pede.
- Foto do cardápio sai pela rota pública `restaurants/menu_photo.php`.
- Entram no backup diário.

**Fase 2 (pendente, decisão do Marcos: quando):** Cloudflare R2 com URL
assinada. A integração é por `curl` nativo, sem SDK.

## 8. Validação antes de abrir

Em homologação (`APP_ENV=staging`, Mercado Pago de teste), na VPS de
verdade:

- [ ] `php bin/check_production.php` diz "ok" com o `.env` real.
- [ ] Sem o `.env` (ou com um segredo apagado), a API responde 503 e o log diz o que falta.
- [ ] `https://<dominio>/.env`, `/lib/core/db.php` e `/storage/...` não entregam nada do projeto.
- [ ] Login por SMS/e-mail chega de verdade; errar 5 vezes dá 429.
- [ ] O admin fundador entra e ajusta frete e taxas (10.5).
- [ ] Uma loja de teste recebe pedido, aceita, imprime (ESC/POS) e marca pronto.
- [ ] Cartão de teste aprovado e recusado; o webhook chega; o estorno sai.
- [ ] Pix manual: comprovante enviado, a loja valida; o comprovante não abre sem login.
- [ ] Espécie: entregador fecha o caixa, a loja dá baixa, o recibo imprime.
- [ ] O push chega no Android e no iPhone (PWA instalado).
- [ ] Fidelidade: um pedido entregue dá pontos; o resgate vira um cupom pessoal que só o dono usa; o estorno tira os pontos.
- [ ] O acompanhamento ao vivo atualiza sem recarregar (SSE pelo Cloudflare).
- [ ] O cron roda (`/var/log/fuuphp/*.log` mexendo) e o pg_cron também (`SELECT * FROM cron.job_run_details ORDER BY start_time DESC LIMIT 5`).
- [ ] O backup da noite existe, a restauração num banco de teste funciona e a cópia externa chegou.
- [ ] O `.env` está com 600, dono `fuuphp`, e fora do git (`git status` limpo na VPS).

Tudo marcado: troque as credenciais para as de produção (`APP_USR-...`,
`APP_ENV=production`), rode o deploy de novo e faça um Pix real de valor
baixo, estornado em seguida.

## Decisões do Marcos

| Decisão | Enquanto não decide |
|---|---|
| Provedor de OTP (SMS/e-mail) | a produção não sobe |
| Mercado Pago: token único x por loja | código usa token único |
| VPS (provedor, tamanho) e domínio | `deploy/` está pronto pra qualquer Ubuntu 24.04/Debian 12 |
| Cópia do backup fora da VPS (destino) | backup só dentro da VPS (o script avisa) |
| Quando levar os arquivos pro R2 | disco da VPS (fase 1) |

Já decidido pelo Marcos: a **fidelidade (2.3) entra no lançamento** e fica
na lista de validação abaixo; o **login com Google/Apple fica pra v2**.
