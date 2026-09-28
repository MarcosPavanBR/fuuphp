# 50 — Auditoria DevSecOps de 27/09/2026

**De onde veio:** o Marcos trouxe uma auditoria externa no formato
DevSecOps. Ela deu 75% de prontidão, com um plano de ação em 11 itens.
Abaixo, o que foi feito com cada um. Cada achado foi conferido no código (e,
quando dava, reproduzido) antes de corrigir.

## Feito

### Corrida na renovação de sessão (alta)

Reproduzido: 20 renovações simultâneas com o mesmo refresh token, e até 4
eram aceitas. A correção veio pronta no patch da auditoria:
`UPDATE ... AND revoked_at IS NULL`, e se nenhuma linha mudou, é reuso. Com
ela, exatamente uma é aceita.

Teste em `smoke_authz.sh`, conferido tirando a correção (o teste falha).

### Segundo fator obrigatório pro admin (alta)

Em produção e homologação, toda rota do painel responde 403
`totp_setup_required` a admin sem autenticador confirmado, menos a rota que
liga o autenticador (`api/v1/admin/guard.php`).

- O painel abre direto na tela de ligar o fator.
- Lá o fator não pode ser desligado; trocou de celular, a recuperação é no
  servidor (OPERATIONS.md).
- Em desenvolvimento segue opcional. `ADMIN_TOTP_REQUIRED=1` liga a
  exigência (é assim que o `smoke_admin.sh` confere), e não existe variável
  que desligue em produção.

### Acompanhamento ao vivo não derruba mais a API (alta)

Cada cliente olhando o pedido segura um processo do PHP-FPM por até 25 s. No
mesmo pool da API, uns 40 clientes paravam login, pagamento e painel.

- **Pool próprio** (`[fuuphp-sse]` em `deploy/php-fpm/fuuphp.conf`): o Nginx
  manda só o `orders/track.php` pra ele. Lotar o acompanhamento só atrasa o
  acompanhamento.
- **No máximo 3 conexões ao vivo por pessoa**, com advisory lock na conexão
  crua do `track.php`, que some sozinho se o processo morrer. A quarta aba
  recebe o estado de agora e volta em 15 s: a tela continua atualizando,
  só sem o "ao vivo".

Teste em `smoke_authz.sh`: três conexões ficam ao vivo, a quarta volta em
0,1 s com o estado e o `retry`.

### Backup (alta e média)

- **Escalada pra root:** o cron rodava como root o `backup.sh` de dentro de
  `/srv/fuuphp/current`, que é do usuário de deploy. Quem pudesse fazer
  deploy virava root. Agora o cron roda `/usr/local/sbin/fuuphp-backup`
  (dono root, 700). O script recusa rodar como root se ele, ou a pasta dele,
  puder ser alterado por outro usuário (testado com um arquivo de outro
  dono).
- **Cifra:** com `BACKUP_GPG_RECIPIENT` em `/etc/fuuphp/backup.env`, o dump e
  o tar do storage ficam cifrados com gpg (que já vem no Ubuntu). Só a chave
  pública fica na VPS. O teste de restauração roda antes de cifrar.
- **Nada sai em claro:** com cópia externa configurada e sem chave, o backup
  falha de propósito e aparece em "Saúde do sistema".
- Testado aqui de ponta a ponta com uma chave de teste: arquivos cifrados,
  checksums conferidos, e o dump decifrado abre no `pg_restore`.

### Migração no deploy (média)

`deploy/deploy.sh` não põe no ar versão com migração pendente. O número da
última aplicada fica em `/srv/fuuphp/schema_version`.

- **Sem `--migrate`:** o deploy para, lista as migrações e dá o comando.
- **Com `--migrate`:** faz o backup, aplica as novas uma por vez como
  `postgres` e grava o número a cada uma. Se uma falhar, a versão nova não
  entra no ar.

O `--migrate` precisa de sudoers (superusuário do banco pro usuário de
deploy, porque o pg_cron exige). É um poder a mais, por isso ficou explícito
e opcional. Os quatro caminhos foram testados num ambiente simulado:
primeira instalação, pendência recusada, migração que falha e migração que
passa.

### Refresh token em cookie `HttpOnly` (média)

Feito num lote próprio, logo depois. O refresh de 30 dias saiu do
`localStorage` e foi pra um cookie que o JavaScript não lê:

- `HttpOnly; SameSite=Strict`, `Path=/api/v1/auth/` (só vai no refresh e no
  sair), `Secure` em produção;
- um cookie por app (`fuu_rt_customer`, `_staff`, `_courier`, `_admin`),
  como eram as chaves do `localStorage`;
- em homologação e produção ele não sai mais no JSON do login;
- quem tinha o refresh guardado no aparelho usa uma última vez (o servidor
  troca pelo cookie) e a chave antiga é apagada: ninguém é deslogado na
  troca;
- CORS com `Allow-Credentials: true` pra origem fixa do Vite em
  desenvolvimento (nunca `*`).

Testes: `smoke_identity.sh` (atributos do cookie, renovar só com o cookie,
o cookie gira, o de um app não renova outro, sair apaga),
`smoke_production_guard.sh` (fora do JSON em homologação e produção) e os
testes do front (`npm test`, 9, com a migração do token antigo).

### Documento de entregador cifrado em disco (baixa)

Feito em seguida, junto com a tela de candidaturas que faltava no painel: ver a
[51](51-candidatura-de-entregador-no-painel.md).

### Menores

- **Log em JSON:** o erro não tratado vira uma linha `fuu {json}` com
  `trace_id`, rota, método, classe, arquivo e linha.
- **CI:** o texto "38 migrações" virou "todas as migrações".

## Não feito, e por quê

| Item | Por quê |
|---|---|
| PHPStan e gitleaks no CI | ferramentas novas: precisam da autorização do Marcos |
| Testes unitários das regras de dinheiro | esforço alto; hoje cobertas pelas suítes de ponta a ponta (`smoke_money`, `smoke_late_payment`, fuzz) |
| Destino da cópia externa do backup | decisão do Marcos (continua pendente) |
