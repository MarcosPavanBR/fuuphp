# 33 — Go-live: do simulado ao real

**O que o plano pedia:** tirar todo modo simulado antes de produção. A
produção falha fechado, segredo só no `.env` do servidor, admin fundador por
script, VPS com Nginx + PHP-FPM + PostgreSQL + Cloudflare. Nada novo na
stack sem autorização. As escolhas de provedor são do Marcos.

**O que foi feito** (passo a passo em [GO_LIVE.md](../GO_LIVE.md)):

- **Trava de produção** (`lib/core/production_guard.php`). Em `production`
  e `staging`, a API não sobe (503 sem detalhe; os motivos vão pro log) e os
  scripts de `bin/` saem com 1 se houver:
  - Mercado Pago em modo fake, ou sem token, public key ou segredo do webhook;
  - push fake ou sem chave VAPID;
  - OTP sem provedor;
  - JWT fraco;
  - `ALLOWED_ORIGIN` ausente;
  - pasta de arquivo dentro do projeto;
  - marcador `<...>` do modelo sem preencher.

  `staging` é igual a `production`, exceto que aceita o token de sandbox
  (`TEST-`). Antes, o padrão era o contrário: sem token, o pagamento caía em
  fake; sem segredo, o webhook aceitava qualquer notificação.
- **`bin/check_production.php`**, chamado pelo deploy. Roda a mesma trava e
  faz o que ela não faz a cada requisição, porque custaria ida ao banco:
  - conecta com o `DATABASE_URL` real;
  - recusa papel de banco que ignora RLS;
  - confere o pg_cron.
- **OTP plugável** (`lib/messaging/otp_sender.php`). `send_otp()` devolve
  `bool`. Quando o envio falha, a rota apaga o código e responde 502. O
  código nunca vai pro log. O driver `log` só existe em
  development/testing. O provedor real **não foi escolhido**, e nenhum foi
  implementado por conta própria.
- **Admin fundador** (`bin/bootstrap_admin.php`). Cria o primeiro admin e a
  política v1 uma vez e recusa se já existir admin. Não há senha: o admin
  entra por OTP.
- **API como `app_rw`, com RLS de verdade.** As policies da migração 009
  existiam, mas a API sempre rodou como dono das tabelas, que ignora RLS.
  Rodar as suítes conectando como `app_rw` quebrou tudo que toca `orders`,
  porque a conexão nunca dizia quem era: em produção, com o papel certo, a
  API inteira teria caído. Agora:
  - `db()` abre toda conexão como `app.role = platform`, porque quem pode ver
    o quê continua decidido no PHP;
  - `require_store_staff()` estreita a conexão pra loja do token
    (`db_scope_to_restaurant`). A partir daí, pedido de outra loja não
    existe nem pro banco.

  O CI roda **todas** as suítes com a API como `app_rw`, e
  `tests/smoke_db_roles.sh` prova direto no banco:
  - a RLS por loja funciona;
  - o livro-razão e os pontos são só de inserção;
  - o `app_rw` não faz DDL.
- **IP do cliente.** `client_ip()` usava o primeiro valor de
  `X-Forwarded-For`, um cabeçalho que o próprio cliente escreve. Com isso, o
  IP da prova de consentimento (LGPD) podia ser forjado. Agora o PHP usa só
  `REMOTE_ADDR`, e o Nginx o acerta a partir de `CF-Connecting-IP`, confiando
  apenas nas faixas do Cloudflare.
- **`deploy/`**:
  - Nginx: serve só `web/dist` e as rotas `api/v1/<área>/<ação>.php`.
    Conferido com `nginx -t` e requisições reais;
  - pool do PHP-FPM, com `clear_env`, `open_basedir` e `.env` por
    `FUU_ENV_FILE`;
  - cron do servidor com `flock`;
  - logrotate;
  - backup diário (dump + storage + conferência), testado;
  - senhas dos papéis do banco;
  - deploy atômico por symlink, com rollback e trava antes de trocar;
  - modelo do `.env` de produção.
- **`db/migrate.sh from N`:** em produção aplica só as migrações novas.
  `up` reaplicaria tudo, e `down` apaga dado.

**Simplificado / fora:**

- Migrações rodam como `postgres`, não como `migrator`, porque o pg_cron só
  é criado por superusuário. O `migrator` fica sem login na VPS.
- A cópia do backup fora da VPS tem o gancho (`OFFSITE_CMD`), mas o destino
  é decisão pendente. O script avisa em todo backup enquanto não houver.
- Storage fase 2 (R2) não foi feita: depende de quando o Marcos decidir.
- O PHP-FPM não pôde ser instalado neste ambiente (repositório bloqueado).
  O pool foi escrito pela documentação, não rodado. O Nginx foi rodado de
  verdade.

**Decisões pendentes do Marcos:**

- o provedor de OTP;
- a VPS e o domínio;
- o destino da cópia externa do backup;
- quando migrar pro R2.

**Já decidido pelo Marcos:**

- Mercado Pago com token único da plataforma: o dinheiro entra na conta da
  plataforma e o repasse sai pelo livro-razão. As colunas `mp_public_key`,
  `mp_access_token` e `mp_user_id` de `restaurant_credentials` (migração 002)
  ficam sem uso e vazias;
- a fidelidade (2.3) entra no lançamento;
- o login com Google/Apple fica pra v2.
