# 36 — Cadastro de loja, chave Pix, saúde e pg_cron

Revisão "o que impede o sistema de ganhar dinheiro no dia 1", feita antes do
lançamento. Quatro achados, cada um com correção e teste.

## 1. Loja não tinha como entrar sozinha

**O que foi achado:** a aprovação de loja existia (tela 12.1, fila da
plataforma), mas nada criava o cadastro: a loja só entrava se alguém a
inserisse no banco. E a chave Pix da loja (onde cai o Pix direto do cliente)
não tinha tela.

**O que foi feito** (migração 033):

- `POST restaurants/signup.php`, público: a loja preenche nome, CNPJ,
  categoria, endereço, responsável, e-mail, chave Pix e senha, e aceita os
  termos. Numa transação só nascem a loja (sem aprovação), a conta da equipe,
  o login de parceiro (CNPJ + senha em bcrypt), a chave Pix e o aceite dos
  termos e do aviso de privacidade (com versão e IP).
- A loja entra no painel na hora, com a faixa **Em análise** (ou o motivo da
  recusa): monta o cardápio enquanto espera.
- **Não vende antes de aprovada:** `require_store_accepting_orders()` recusa
  carrinho, checkout, pedido e "pedir de novo" com `store_not_available`, e a
  busca de produtos esconde a loja. A vitrine já filtrava.
- **Chave Pix** (`restaurants/pix_key.php`, na aba Pagamentos): aleatória,
  e-mail, telefone ou o próprio CNPJ. CPF é recusado. Chave igual ao CNPJ é
  titularidade conferida; as outras chegam à fila da plataforma com
  "confira a titularidade". Cada troca vai pro `audit_log`, com a chave
  anterior.
- 3 cadastros por IP em 24 horas; o IP é apagado na aprovação.
- Teste: `tests/smoke_store_signup.sh`.

**Simplificado / fora:** a titularidade da chave que não é o CNPJ é conferida
por uma pessoa. Consultar o DICT do Banco Central exige ser participante do
Pix. O CNPJ é validado pelo dígito, não na Receita.

## 2. O pg_cron não rodava nada, e ninguém via

**O que foi achado:** com a configuração padrão do Postgres, o pg_cron agenda
as tarefas mas **todas** falham com "connection failed" (ele tenta conectar
por socket/senha e não consegue). As tarefas estavam lá, o
`check_production` via a extensão instalada e dava ok. Na prática: Pix não
conferido nunca seria recusado, a loja não abriria nem fecharia sozinha e o
acerto de terça não sairia.

**O que foi feito:**

- `cron.use_background_workers = on` (e `max_worker_processes = 20`) no
  `postgresql.conf` de produção ([GO_LIVE.md](../GO_LIVE.md)), no CI e no
  `docker-compose.yml`. Conferido: as tarefas passam a terminar com
  `succeeded`.
- `cron_healthy()` (migração 035): verdadeiro se alguma tarefa terminou bem
  nos últimos 5 minutos. É `SECURITY DEFINER` porque o `app_rw` não enxerga o
  esquema `cron`; só o booleano sai.
- `bin/check_production.php` recusa o deploy com o cron parado.

## 3. Nada avisava que o sistema caiu

**O que foi feito:** `GET api/v1/health.php` responde 200 com banco e cron
rodando, 503 com `failing: ["db"|"cron"]` se não, sem detalhe interno. É o
endereço do monitor externo; qual monitor usar fica com o Marcos. O teste
(`smoke_db_roles.sh`) consulta a rota com a API como `app_rw`.

Junto, o GO_LIVE ganhou o endurecimento do Cloudflare: TLS 1.2 mínimo, Bot
Fight Mode e rate limit por IP nas rotas de login e cadastro.

## 4. Código do entregador em SHA-256 sem sal

**O que foi feito** (migração 034): o código de acesso passa a ser gravado com
`password_hash` (bcrypt). O código antigo (SHA-256) ainda entra e é regravado
em bcrypt no mesmo login, então ninguém precisa de código novo. Testes:
`smoke_courier.sh` (regravação) e `smoke_growth.sh` (código novo já em
bcrypt).

## Termos e privacidade

`web/public/termos.html` e `privacidade.html`, versão 2026-09-01, a mesma
gravada nos aceites. O texto descreve o que o sistema faz de verdade (retenção,
com quem os dados são compartilhados, como apagar a conta). Os dados da
empresa estão entre colchetes, a preencher, e o texto precisa da revisão de
um advogado antes de abrir.
