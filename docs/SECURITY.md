# Segurança

O que protege o FUUdelivery, onde cada proteção mora no código e como ela é
testada. Serve pra revisar antes de abrir, pra responder "e se alguém tentar
X?" e pra não desfazer uma proteção sem querer.

Regra de leitura: toda proteção listada aqui tem teste em `tests/`, que roda
no CI. Se uma proteção não tem teste, ela está na seção "Limites conhecidos".

## Quem é quem (papéis e login)

| Público | Como entra | Token leva | Guarda da rota |
|---|---|---|---|
| Cliente | código de 6 dígitos por SMS (Twilio) | `role=customer` | `require_auth()` + dono do pedido (`authorize_order_access`) |
| Loja | CNPJ + senha, preso ao aparelho | `role=restaurant_staff`, `restaurant_id` | `require_store_staff()` |
| Entregador | CPF + código de acesso, preso ao aparelho | `role=courier`, `courier_id` | `require_courier()` |
| Plataforma | o mesmo código por SMS do cliente | `role=admin` | `require_admin()` |

- **Tokens** (`lib/core/sessions.php`, `lib/core/jwt.php`):
  - acesso é JWT HS256 de 15 minutos;
  - refresh vale 30 dias e gira a cada uso: o antigo morre, e o banco só
    guarda o hash dele;
  - refresh reaproveitado é sinal de roubo, e a família inteira de sessões
    é revogada.
- **Loja e plataforma vêm do token, nunca do corpo da requisição.** Uma
  loja não consegue pausar, editar ou ler outra trocando um id no JSON.
- **Admin não tem senha.** Entra pelo mesmo código do cliente, então quem
  controla o celular do admin controla o painel. O admin fundador é criado
  por `bin/bootstrap_admin.php`, uma vez.

## Código de login (OTP)

`lib/core/otp.php`, `api/v1/auth/otp_*.php`, `lib/messaging/otp_sender.php`

- 6 dígitos, gerados com `random_int`, válidos por 5 minutos. O banco
  guarda só o hash.
- 5 tentativas erradas por código e o código trava (429). No máximo 3 pedidos
  de código a cada 10 minutos por pessoa, o que protege o número de quem
  recebe e a conta de SMS.
- O código nunca vai pro log. Falha da Twilio registra só o status e o código
  de erro dela, com o telefone mascarado.
- `dev_code` (o código na própria resposta) existe só em
  `development`/`testing`. Staging e produção nunca devolvem.
- Testes: `smoke_identity.sh`, `smoke_otp_twilio.sh`,
  `smoke_production_guard.sh`.

## Login de parceiro (loja e entregador)

`api/v1/auth/partner_login.php`, migração 032

- Loja: senha com `password_hash` (bcrypt). Entregador: código de acesso de
  6 dígitos (hash SHA-256), gerado pela plataforma na aprovação.
- **Limite de tentativas:** 5 erros no mesmo login ou 30 no mesmo IP em
  15 minutos dão 429, conferidos **antes** da senha. Sem isso, os 6 dígitos
  do entregador cairiam por força bruta. Conta inexistente e senha errada dão
  a mesma resposta e contam igual.
- **Preso ao aparelho:** o primeiro aparelho que entra vira o confiável; outro
  aparelho recebe `device_mismatch`. A troca é feita pelo suporte, na aba
  Aparelhos (`admin/partner_devices.php`), com motivo obrigatório. A troca
  encerra as sessões do aparelho antigo e grava no `audit_log`.
- Testes: `smoke_partner_device.sh`, `smoke_panel.sh`, `smoke_courier.sh`.

## Banco de dados

- **A API não é dona das tabelas.** Em produção ela conecta como `app_rw`
  (`deploy/postgres/set_passwords.sql`), sem superusuário, sem `BYPASSRLS` e
  sem DDL. `bin/check_production.php` recusa o deploy se a conexão ignorar RLS.
- **RLS por loja** (migração 009) em `orders`, `payments`, `payment_proofs`
  e `order_messages`:
  - toda conexão abre como `platform`, porque a autorização fina é do PHP;
  - a equipe de loja é estreitada pra própria loja
    (`db_scope_to_restaurant`).

  Se uma rota de loja esquecer o filtro, o banco ainda esconde o pedido da
  vizinha.
- **Só de inserção:** o livro-razão (`ledger_entries`) e os pontos
  (`loyalty_entries`) não aceitam `UPDATE` nem `DELETE` do `app_rw`. Corrigir é
  lançar a contrapartida.
- **Sempre prepared statements** (PDO, `ATTR_EMULATE_PREPARES=false`).
  Nenhuma consulta monta SQL com valor vindo de fora.
- Testes: `smoke_db_roles.sh`, e o CI roda **todas** as suítes com a API como
  `app_rw`.

## Dinheiro

- **Webhook do Mercado Pago:** a assinatura HMAC é conferida com
  `hash_equals`. Sem o segredo configurado, o webhook é recusado em
  staging/produção.
- **Status de pedido só muda por `advance_order()`** (banco), que recusa
  transição ilegal. A API decide só **quem** pode pedir cada transição.
- **Um pagamento aprovado por pedido** (índice único), e as escritas que um
  retry de rede duplicaria exigem `X-Idempotency-Key`:
  - pagar;
  - enviar comprovante;
  - entregar;
  - comprovante de baixa.
- **Comprovante de Pix:**
  - o SHA-256 e a impressão digital da imagem pegam o mesmo comprovante
    reaproveitado em outro pedido, mesmo recomprimido;
  - uma marca d'água com pedido e hora vai na imagem;
  - a loja tem 15 minutos pra conferir, senão o pedido é recusado sozinho e
    nada é cobrado.
- **Cupom:**
  - teto de gasto obrigatório, com `CHECK (spent <= budget_cap)` no banco;
  - um uso por CPF, não por conta;
  - cupom criado pela loja é sempre pago por ela (CHECK da migração 031) e
    fica dentro do teto que a plataforma libera.
- Testes: `smoke_payments.sh`, `smoke_money.sh`, `smoke_support.sh`,
  `smoke_store_coupons.sh`, `smoke_courier.sh`.

## Arquivos enviados

- Ficam em `/var/fuuphp/storage`, **fora do projeto e da raiz servida**. A
  trava de produção recusa pasta dentro do projeto.
- Comprovantes, fotos de ocorrência e documentos de entregador **nunca são
  públicos**. Só saem por rota autenticada que confere quem pede.
- O tipo real é conferido pelo conteúdo (`finfo`), não pela extensão, e o
  tamanho tem limite: 10 MB pra comprovante, foto de ocorrência e documento de
  entregador, e 8 MB pra foto do cardápio.
- Retenção (pg_cron, `purge_retention`):
  - comprovantes somem em 180 dias;
  - a posição e a foto das ocorrências são apagadas em 180 dias;
  - as conversas somem em 1 ano;
  - a posição ao vivo do entregador some em 30 minutos.

## Servidor e rede

- **Trava de produção** (`lib/core/production_guard.php`): em
  staging/produção, a API não sobe (503 sem detalhe) se algo estiver simulado,
  vazio ou com o marcador `<...>` do modelo. Isso vale pra Mercado Pago, push,
  Twilio, JWT fraco, CORS ausente e pastas. O `deploy/deploy.sh` roda a mesma
  checagem antes de trocar a versão.
- **Segredos** só em `/etc/fuuphp/fuuphp.env` (dono `fuuphp`, 600), fora do
  git e da raiz servida. O PHP-FPM usa `clear_env` e `open_basedir`.
- **Nginx** (`deploy/nginx/fuuphp.conf`):
  - serve só o PWA compilado e as rotas `api/v1/<área>/<ação>.php`;
  - dotfiles e código-fonte nunca saem;
  - HSTS e `nosniff` em tudo.
- **IP do cliente:** o PHP usa só `REMOTE_ADDR`, que o Nginx acerta a partir
  de `CF-Connecting-IP` **só** pras faixas do Cloudflare. Ninguém forja o IP
  gravado na prova de consentimento.
- **Erros** viram `{code, message, trace_id}` sem stack trace. O detalhe fica
  no log do servidor, achado pelo `trace_id`.

## LGPD

- Consentimento com versão, data e IP (`consents`).
- Exportar os próprios dados: `profile/export.php`.
- Apagar a conta: `profile/delete_account.php` (`account_anonymize`). Nome,
  CPF, telefone, e-mail, cartões salvos, push, notificações, códigos de login e
  consentimentos saem, e as sessões são encerradas. Pedidos e livro contábil
  ficam, porque a lei fiscal obriga, mas sem dado pessoal. O endereço dos
  pedidos vira região aproximada (cidade, CEP de 5 dígitos, coordenada de
  ~1 km).
- Logs com 30 dias de rotação; nenhum código de login ou segredo neles.
- Teste: `smoke_privacy.sh`.

## Limites conhecidos

- **A sessão do painel da plataforma** tem a força do celular do admin (login
  por SMS, sem segundo fator).
- **O hash do código de acesso do entregador** é SHA-256 sem sal. O limite de
  tentativas fecha o ataque pela API, mas um vazamento do banco revelaria os
  códigos (são só 10⁶). A mitigação é trocar o código do entregador se o banco
  vazar.
- **O rate limit do cliente** é por pessoa (OTP), não por IP. Um ataque
  distribuído que crie muitos cadastros gasta SMS; o Cloudflare na frente é
  a proteção de volume.
- **Não houve teste de invasão externo.** Recomendado antes de escalar.
