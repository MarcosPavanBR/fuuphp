# 35 — Limite de tentativas no login de parceiro

**O que foi achado:** na revisão pra documentar a segurança
([SECURITY.md](../SECURITY.md)), o login de parceiro não tinha limite nenhum
de tentativas. É o login da loja (CNPJ + senha) e do entregador (CPF + código
de acesso). O código de acesso do entregador tem 6 dígitos: um milhão de
combinações, que um script testa inteiro em pouco tempo. O login do cliente já
tinha limite (OTP), o de parceiro não.

**O que foi feito** (migração 032, `api/v1/auth/partner_login.php`):

- Cada erro vira uma linha em `partner_login_failures` (tipo, login, IP,
  hora).
- **5 erros no mesmo login ou 30 no mesmo IP em 15 minutos dão
  `429 login_locked`.** A checagem vem **antes** da conferência da senha: se
  viesse depois, o bloqueio ainda deixaria descobrir a senha certa (ela seria
  a única resposta diferente).
- Conta inexistente e senha errada dão a mesma resposta (`401
  invalid_credentials`) e contam igual, pra não revelar quais CNPJs e CPFs
  existem.
- Acertar a senha zera os erros daquele login.
- O pg_cron apaga as linhas com mais de um dia (a janela é de 15 minutos).
- Teste: `tests/smoke_partner_device.sh`. Três erros e um acerto zeram;
  cinco erros travam até a senha certa; conta inexistente conta igual.

**Simplificado / fora:**

- O limite por IP (30) é mais largo que o por login, porque várias lojas
  podem sair pelo mesmo IP (rede de shopping, operadora com CGNAT). Ele pega o
  ataque que varre muitos logins, não o que insiste em um.
- O código do entregador continua com hash SHA-256 sem sal. Com o limite, a
  força bruta pela API acabou. Pelo banco (se vazar), os 10⁶ códigos ainda
  seriam recuperáveis; a resposta a um vazamento é gerar códigos novos. Está
  em "Limites conhecidos" no [SECURITY.md](../SECURITY.md).
