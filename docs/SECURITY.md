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
  6 dígitos, também em bcrypt (migração 034), gerado pela plataforma na
  aprovação. Código antigo, gravado em SHA-256, ainda entra uma vez e é
  regravado em bcrypt no mesmo login.
- **Limite de tentativas:** 5 erros no mesmo login ou 30 no mesmo IP em
  15 minutos dão 429, conferidos **antes** da senha. Sem isso, os 6 dígitos
  do entregador cairiam por força bruta. Conta inexistente e senha errada dão
  a mesma resposta e contam igual.
- **Preso ao aparelho:** o primeiro aparelho que entra vira o confiável; outro
  aparelho recebe `device_mismatch`. A troca é feita pelo suporte, na aba
  Aparelhos (`admin/partner_devices.php`), com motivo obrigatório. A troca
  encerra as sessões do aparelho antigo e grava no `audit_log`.
- Testes: `smoke_partner_device.sh`, `smoke_panel.sh`, `smoke_courier.sh`.

## Cadastro de loja

`api/v1/restaurants/signup.php`, `api/v1/restaurants/pix_key.php`, migração 033

- **Público, mas com freio:** no máximo 3 cadastros por IP em 24 horas (429).
  O IP fica gravado só até a análise; aprovar a loja apaga o IP.
- **Loja nova não vende.** Até a plataforma aprovar, a loja não aparece na
  busca e o carrinho, o checkout e o "pedir de novo" respondem
  `store_not_available` (`require_store_accepting_orders`). O painel abre,
  pra loja montar o cardápio, com o aviso de que está em análise.
- **Chave Pix da loja:** aceita chave aleatória, e-mail, telefone ou o
  **próprio** CNPJ da loja. CPF é recusado (o dinheiro da loja não cai na conta
  de uma pessoa). Chave que não é o CNPJ chega à análise marcada pra conferir
  a titularidade. Toda troca de chave grava a anterior e a nova no
  `audit_log`.
- CNPJ e e-mail repetidos são recusados antes de gravar; senha em bcrypt;
  aceite dos termos e do aviso de privacidade gravado com versão e IP.
- Teste: `smoke_store_signup.sh`.

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
- **Gorjeta** tem teto de R$ 200 no pedido e na avaliação (`TIP_MAX`): um zero
  a mais digitado não vira cobrança.
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
  - um uso por CPF **e** por conta (trocar o CPF no perfil não libera o
    cupom de novo: `coupon_used_by`, decisão 38);
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
- **Imagens públicas** (foto do cardápio, logo da loja, banner): recodificadas
  com GD em JPEG (sem EXIF, sem GPS de quem fotografou), nome pelo SHA-256 do
  conteúdo, e a rota só aceita exatamente esse formato de chave
  (`lib/catalog/public_images.php`). Logo só a equipe da própria loja sobe;
  banner, só a plataforma.
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
- **Cloudflare** na frente (docs/GO_LIVE.md): TLS 1.2 no mínimo, Bot Fight
  Mode e uma regra de rate limit por IP nas rotas de login e de cadastro de
  loja. É a proteção de volume que o PHP, sozinho, não dá.
- **Saúde** (`api/v1/system/health.php`): responde 503 se o banco ou o pg_cron
  pararem, pro monitor externo avisar antes do cliente. A resposta diz só
  `db` ou `cron`, sem detalhe interno.
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
- **O código de acesso do entregador** tem só 6 dígitos. O limite de tentativas
  fecha o ataque pela API e o bcrypt encarece quebrar um vazamento do banco,
  mas 10⁶ combinações seguem poucas: se o banco vazar, troque os códigos.
- **A titularidade da chave Pix** que não é o CNPJ da loja é conferida por uma
  pessoa, na análise do cadastro. O sistema não consulta o DICT do Banco
  Central.
- **O rate limit do cliente** é por pessoa (OTP), não por IP. Um ataque
  distribuído que crie muitos cadastros gasta SMS; o Cloudflare na frente é
  a proteção de volume.
- **Não houve teste de invasão externo.** Recomendado antes de escalar.
