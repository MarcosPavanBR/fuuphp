# App do entregador e caixa (Fase 8 e 9)

O terceiro público do projeto, no terceiro bundle (`entregador.html`).
"Aplicativo separado, feito para ser usado com uma mão, no sol, de moto
parada: alvos grandes, números enormes. É ele que fecha o dinheiro do pedido
offline."

- **O dinheiro anda no livro, não num campo de saldo.** Entregar um pedido em
  dinheiro gera DOIS lançamentos em `ledger_entries` na mesma transação da
  entrega: `courier_cash += total` (o bruto que ficou na mão dele) e
  `courier_payable += frete` (o que a plataforma deve). Saldo é `SUM()`, e
  `app_rw` não tem `UPDATE`/`DELETE` nessa tabela (migração `006`) -- correção
  é contrapartida, nunca edição, e isso está no banco, não na boa vontade de
  quem escreve PHP.
- **O entregador devolve o BRUTO.** É o que a tela 9.3 diz com todas as
  letras: "sem descontar o frete dele — quem paga o frete somos nós". Por isso
  `courier_cash` recebe o total do pedido, e o frete corre por fora, em
  `courier_payable`.
- **Aceite de corrida é um `UPDATE` condicional, não um lock.** A própria
  migração `007` já sugeria em comentário:
  `UPDATE offers SET courier_id=:c WHERE id=:o AND courier_id IS NULL
  RETURNING id` -- zero linhas significa que o outro chegou primeiro. Aceitar
  de novo a MESMA corrida que já é sua devolve 200 com `already_mine`, porque
  retry de rede não é erro.
- **O timer de 15 s é de DECISÃO, não de validade.** No mock ele conta na tela
  do entregador; num app que busca corrida por polling, 15 s de validade
  significaria oferta sempre vencida. O servidor segura a oferta por 5 min, a
  tela conta 15 s e tira a corrida DESTA tela quando acaba -- ela continua
  valendo pros outros, que é o certo: ninguém perde corrida porque este
  entregador ficou olhando.
- **O troco é conta do servidor.** "É o erro mais comum do delivery em
  dinheiro", então `couriers/pickup.php` devolve `change_due` pronto
  (`change_for - total`, com o total que é coluna gerada) e a tela só mostra,
  em corpo grande.
- **Prova de entrega é obrigatória** (migração `015`): código de 4 dígitos que
  o cliente vê no app, ou foto com GPS. Sem uma das duas, `deliver.php`
  devolve 422 -- é o que sustenta disputa depois. O código nasce com o pedido
  (DEFAULT aleatório por linha) porque o cliente precisa vê-lo antes de o
  entregador chegar; não é segredo criptográfico, é prova de presença.
- **A baixa de espécie guarda só o hash do código**, como o OTP do módulo
  identity, e o valor fica congelado na intenção. `one_open_intent` (migração
  `006`) garante uma baixa em voo por entregador -- duas seria o caminho mais
  curto pra pagar duas vezes a mesma espécie.
- **Divergência não vira lançamento.** Se a loja conta valor diferente do
  declarado, a intenção fica `disputed` e NADA é lançado no livro. Não é
  rigor por rigor: como o livro é append-only, um número errado não teria
  desfazimento -- "abrir ocorrência" é justamente não registrar um valor que
  ninguém sabe se é o certo.
- **Despacho mínimo, e assumido como tal.** `lib/dispatch/dispatch.php` cria UMA oferta
  quando o pedido fica pronto, com o frete do pedido e bônus zero. A Fase 15
  desenha rodadas, raio crescente, surge e `dispatch_attempts`: dessas, só o
  surge por pedido passou a existir (a tela 15.1 precisa dele, e o bônus
  agora tem de onde vir -- o cliente que turbinou). Rodadas, raio e
  `dispatch_attempts` continuam fora. A tabela `offers` já é a da
  especificação, então o resto da Fase 15 substitui a função sem migrar nada.
- **O que o mock mostra e o app não tem:** navegação com áudio e o endereço
  escrito da loja (o esquema só tem `lat`/`lng` de restaurante -- logradouro
  só existe em `addresses`, que é do cliente); telefone da loja (não há
  coluna); upload da foto de entrega (o endpoint aceita `photo_storage_key`,
  mas a tela de câmera não foi construída); posição do entregador enviada a
  cada 15 s; e a Fase 9.6 (conciliação de maquininha por NSU). Tudo
  registrado, nada fingido na tela.
- **A loja ganhou a aba "Caixa" no painel** (tela 9.3): conta o dinheiro,
  digita o código, confirma. A ordem dos campos é a ordem do trabalho real --
  valor contado primeiro, código depois, e o valor declarado aparece ao lado
  mas nunca preenchido no campo, senão ninguém conta nada e só confirma.
- **Validado com Postgres e navegador reais.** `tests/smoke_courier.sh` cobre
  turno (inclusive o 409 de abrir dois), a oferta nascendo do `ready`, dois
  entregadores disputando a mesma corrida, o retry do próprio aceite, o troco
  do servidor, entrega barrada sem prova e com código errado, os dois
  lançamentos no livro, o replay idempotente e a baixa de caixa com
  divergência e com acerto. No Playwright, 430px com geolocalização
  concedida: login por CPF+código, abrir turno, aceitar corrida, "cheguei",
  troco R$ 14,10 calculado no servidor, entrega com código, saldo indo pra
  R$ 85,90 em espécie e R$ 7,50 a receber, código de baixa gerado e, do lado
  da loja, a confirmação com valor divergente abrindo ocorrência.
