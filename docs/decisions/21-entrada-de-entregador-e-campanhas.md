# Entrada de entregador e campanhas (Fase 15.2 e 15.3)

Os dois fecham a Fase 15. Um é como alguém ENTRA na plataforma para
entregar; o outro é de que bolso sai o desconto.

### 15.2 — Onboarding do entregador

"Documento por documento com verificação automática visível. A regra 'chave
Pix tem que ser sua' é antifraude, e dizer isso na tela evita 90% das
tentativas."

- **A regra da chave Pix é código, não texto.** Chave que é CPF tem que bater
  com o CPF da candidatura; chave que é telefone, com o telefone; e-mail, com
  o e-mail da conta. Chave aleatória não dá pra conferir aqui -- a
  titularidade é do banco, na transferência -- e a resposta diz isso em vez
  de fingir que conferiu.
- **Candidatar-se exige conta, e isso não é burocracia:** `couriers.user_id`
  é `NOT NULL`, então aprovar sem pessoa cadastrada seria impossível. A
  entrada é pelo mesmo OTP do app do cliente; o login de CPF + código de
  acesso do app do entregador é o que a APROVAÇÃO cria.
- **Aprovar é o ato que cria o entregador.** Antes disso, entrar na
  plataforma dependia de `INSERT` manual no banco (era assim que os smoke
  tests semeavam entregador). Agora a aprovação cria `couriers` (com o id da
  candidatura, como a migração 007 manda), promove o usuário a `courier` e
  gera o código de acesso -- mostrado UMA vez a quem aprovou, porque não há
  integração de WhatsApp e fingir que mandamos seria pior.
- **Candidatura incompleta não entra na fila.** Faltando documento, o envio é
  recusado com a lista do que falta: revisor abrindo e fechando candidatura
  incompleta é o que faz "análise em até 48 h" virar mentira.
- **Arquivo repetido em outra candidatura é barrado** por sha256 -- a mesma
  CNH tentando virar dois entregadores é o caso clássico, e a checagem é de
  uma linha.
- **O aceite do contrato é registro, não checkbox:** vai pra `consents` com
  IP, e a VERSÃO fica em `courier_applications.contract_version`. É isso que
  faz "contrato versionado" ser verdade.
- **O que o mock mostra e não foi construído:** o match facial e o liveness
  ("rosto confere · 96%") precisam de um provedor de visão, que não está na
  cláusula zero. `face_match` fica nulo, a conferência da selfie é humana, e
  a tela diz isso em vez de estampar uma porcentagem inventada. O aviso por
  WhatsApp também não existe -- o resultado aparece na própria tela.

### 15.3 — Cupons e campanhas

"A coluna que falta em quase todo painel: **quem paga o desconto**."

- **`coupons.payer` deixou de ser rótulo.** A tabela já tinha os três valores
  desde a migração 008, mas o resgate não mexia no livro: o desconto saía do
  total e ninguém ficava devendo a ninguém. Agora cada resgate lança em
  `ledger_entries` com `origin = 'coupon'` -- `store_receivable` quando a loja
  banca, `platform_expense` quando somos nós, metade de cada no 50/50 (o
  centavo ímpar fica com a plataforma, e está escrito por quê).
- **O teto desativa o cupom sozinho.** O `CHECK (spent <= budget_cap)` já
  impedia passar; o que faltava era desligar ao ENCOSTAR, pra ninguém
  descobrir no fechamento. O teste leva um cupom de teto R$ 10 até o limite e
  confere que o próximo cliente não consegue mais aplicar.
- **A projeção é pedida ao servidor antes de criar** (`dry_run`), porque usa
  ticket médio dos últimos 90 dias e a comissão vigente -- números que o
  navegador não tem. Quando não há pedido suficiente, a resposta diz isso em
  vez de projetar em cima de zero. O "se 3 de 10 voltarem a pedir" do mock
  fica de fora: é previsão de comportamento, e não há dado de recompra.
- **O tamanho do público é consulta, não cadastro.** "Sem pedir há 15 dias" é
  um `NOT EXISTS` em `orders`; não existe (nem precisa existir) tabela de
  segmentação.
- **O que o mock promete e ficou de fora, com o motivo:** "cupom de loja ela
  cria sozinha no painel, dentro do teto que você liberar aqui". O cupom de
  loja existe (sai do repasse dela), mas quem cria ainda é a plataforma --
  deixar a loja criar exige um teto por loja na política, que não existe; sem
  ele, "dentro do teto que você liberar" não teria o que respeitar.
- **Validado com banco e navegador reais.** `tests/smoke_growth.sh` cobre as
  duas telas ponta a ponta: chave Pix de outro CPF recusada, moto sem placa
  barrada, candidatura sem documento sem ir pra análise, README recusado como
  foto de CNH, aceite de contrato registrado, fila do admin fechada pra
  cliente, recusa sem motivo barrada, aprovação criando entregador + login
  que REALMENTE entra no app (`couriers/me.php` responde), campanha sem teto
  recusada, projeção sem gravar nada, código duplicado barrado, os dois
  lançamentos de R$ 5,00 no livro e o cupom desativado ao encostar no teto.

**Atualização (go-live).** O que mudou desde este registro:

- **O cupom que a loja cria sozinha está construído** (migração 031). A
  plataforma libera um teto por loja na aba Campanhas
  (`admin/store_coupon_limits.php`, com auditoria). A loja cria e desliga os
  próprios cupons na aba Cupons do painel (`restaurants/coupons.php`), pagos
  pelo repasse dela. O teto vale sobre o orçamento dos cupons vivos, e
  desligar ou vencer um cupom devolve o que ele não gastou
  (`tests/smoke_store_coupons.sh`).
