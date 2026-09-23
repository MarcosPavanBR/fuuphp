# Módulo identity

A especificação descreve o *quê* (OTP, refresh com rotação e detecção de
reuso, login de parceiro) mas não o *como* em código. Decisões tomadas para
fechar essa lacuna:

- **JWT escrito à mão** (`lib/core/jwt.php`, HS256), em vez de uma biblioteca via
  Composer — a cláusula zero não autoriza nenhuma, e o formato é simples o
  bastante para não precisar. Se algum dia crescer (RS256, JWKS, revogação
  por `jti`), isso é proposta de mudança de stack, não decisão de código.
- **Endpoints são arquivos diretos** (`api/v1/auth/otp_request.php` etc.),
  sem framework de rotas — casa com o diagrama da especificação
  (`api/*.php ─► PG`) e com a cláusula zero (PHP puro, PDO). URL bonita
  (`/v1/auth/otp/request`) viraria reescrita de servidor (nginx/.htaccess),
  ainda não configurada porque a hospedagem real não foi decidida aqui.
- **Cadastro (`purpose=signup`) exige `full_name` na primeira chamada**,
  porque `users.full_name` é `NOT NULL` no esquema — não dá para criar um
  usuário "rascunho" só com telefone. Login (`purpose=login`) exige que o
  usuário já exista; se não existir, a resposta aponta para `signup` (regra
  "erro sempre com saída", Parte I §6).
- **2FA por aparelho em `partner_login`, na forma mais simples que o
  esquema permite:** confiança no primeiro uso — o primeiro `device_id`
  enviado fica gravado em `partner_accounts.device_id`; login de outro
  aparelho dá `device_mismatch` até o suporte liberar a troca manualmente.
  A especificação menciona "2FA por aparelho" sem detalhar o fluxo de troca
  (reenvio de código, aprovação em outro dispositivo já logado etc.) —
  fica como decisão de produto em aberto.
- **`dev_code` na resposta de `otp_request` só fora de produção**
  (`APP_ENV != production`). Sem um provedor de SMS/e-mail configurado
  ainda, é assim que o fluxo é testável; o código real nunca é logado nem
  devolvido quando `APP_ENV=production`.
