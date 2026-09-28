# 48 — Pagamento que chega depois (migração 045)

**De onde veio:** revisão do webhook do Mercado Pago, na mesma varredura da
[47](47-autorizacao-provada-e-entrada-tipada.md). O Mercado Pago responde
depois da tela:

- o antifraude segura o cartão em análise e decide minutos depois;
- o Pix automático é pago quando o cliente quiser, até o QR vencer;
- o aviso (webhook) chega repetido, fora de ordem ou junto com outro.

O código tratava a resposta como se chegasse uma vez, na hora e na ordem
certa. Tudo abaixo foi reproduzido antes de corrigir, e a suíte nova
`smoke_late_payment.sh` cobre cada caso.

## Os furos

### Cartão em análise recusava o pedido e cobrava depois

`payments/pay.php` recusava o pedido com qualquer resposta diferente de
"aprovado". Quando a análise aprovava, o webhook só marcava o pagamento
como aprovado. **Resultado: o cliente pagava por um pedido recusado, e
nenhum estorno nascia.** Isso acontece sem ninguém tentar: basta o
antifraude segurar um cartão.

**Reproduzido:** pedido cancelado, pagamento aprovado, zero estornos.

O modo simulado também escondia o caso: tratava o cartão de teste `CONT`
como recusado. No sandbox do Mercado Pago, `CONT` é "pagamento pendente".

### Cancelar deixava o QR do Pix vivo

Cancelar um pedido que esperava pagamento não cancelava a cobrança no
Mercado Pago. O QR continuava pagável até vencer, e o dinheiro caía num
pedido cancelado, de novo sem estorno.

### Duas cobranças do mesmo pedido ao mesmo tempo

A chave de idempotência junta repetições da **mesma** chave. Clique duplo
ou duas abas mandam chaves diferentes: as duas chamadas liam o pedido
esperando pagamento e as duas cobravam o cartão. Só uma era gravada; a
outra dava 500 **depois** da cobrança.

A sonda da 47 (seis pagamentos ao mesmo tempo, um aprovado) não via isso,
porque o Mercado Pago simulado responde na hora. Com meio segundo de demora,
como a rede de verdade: **1 sucesso, 1 recusa e 4 erros 500, com quatro
cobranças sem registro.**

### Cobrança que o Mercado Pago fez e nós não gravamos

Se a nossa requisição cai depois de o Mercado Pago cobrar (timeout), o
aviso chegava depois com um id desconhecido. O webhook respondia "não
conheço", e o dinheiro ficava na conta sem pedido.

### Avisos repetidos e fora de ordem

- **Dois avisos juntos** (o Mercado Pago manda `payment.created` e
  `payment.updated` quase ao mesmo tempo): os dois liam o pagamento antes
  de travar. O segundo tentava avançar o pedido de novo e dava 500.
- **Aviso velho depois de um novo:** voltava o pagamento de aprovado pra
  "em análise".
- **Aviso de aprovado depois do estorno** decidido: desfazia o "devolvido".

## As correções

### Uma regra só pro status que vem do gateway

`lib/payments/gateway_status.php`, usada pelo webhook e pelo cancelamento:

- **Trava o pedido e depois a cobrança, e relê.** A ordem é a mesma do
  `pay.php` e do cancelamento, pra duas portas nunca travarem uma à outra.
- **Só anda pra frente** (`PAYMENT_GATEWAY_TRANSITIONS`). Aviso repetido,
  velho ou fora de ordem não desfaz o que já andou.

| De | Pode ir pra |
|---|---|
| criado | em análise, aprovado, recusado |
| em análise | aprovado, recusado |
| recusado | aprovado (o Mercado Pago diz que o dinheiro caiu) |
| aprovado | devolvido, contestado |
| devolvido, contestado | nada |

- **Aprovado com o pedido esperando:** o pedido vira pago.
- **Aprovado sem pedido pra pagar** (pedido cancelado ou recusado, ou que
  já tem outra cobrança aprovada): é **pagamento tardio**. A cobrança fica
  "devolvida" e nasce um estorno integral.

### Estorno de pagamento tardio (migração 045)

Causa nova: `late_payment`.

- Valor inteiro da cobrança, sem taxa.
- Entra na fila do admin (tela 13.4), como todo estorno. Lá é enviado, e o
  executor devolve pelo Mercado Pago.
- **Nada vai pro livro.** O dinheiro entrou e sai sem nunca ter sido de
  ninguém, então `refund_ledger` ignora essa causa.
- **O pedido não muda de status.** Um pedido entregue que recebeu uma
  cobrança em dobro continua entregue.
- **Não vira oferta de crédito:** volta pelo caminho em que veio.
- É idempotente **por cobrança**, não por pedido. Isso importou:
  `record_refund` procurava "o estorno do pedido" e teria devolvido o
  estorno tardio como se fosse o do cancelamento. O dinheiro do pedido
  nunca voltaria. Agora ele ignora os tardios, e o teste confere.

### Cartão em análise: o pedido espera

`pay.php` responde **202** com `in_review: true`, e o pedido continua
esperando pagamento. O app segue pro acompanhamento, que mostra "Cartão em
análise" e muda sozinho quando o webhook decidir.

Enquanto houver cobrança em análise, um segundo cartão é recusado (409
`payment_in_review`).

### Cancelar mata a cobrança primeiro

`orders/status.php`, ao desfazer um pedido que espera pagamento:

1. Trava o pedido e confere que ele não mudou desde a leitura (409
   `order_changed`).
2. Cancela no Mercado Pago cada cobrança aberta (`mp_cancel_payment`). A
   cobrança fica recusada (`cancelled_with_order`).
3. Se o Mercado Pago disser que a cobrança **já foi paga**, o pagamento
   vale: o pedido vira pago, e a resposta é 409 `payment_approved`. Quem
   ainda quiser cancelar cancela um pedido pago, com a devolução integral
   de antes do preparo.
4. **Mercado Pago fora do ar: 503**, e o pedido fica como estava. Sem a
   certeza de que a cobrança morreu, não se cancela às cegas. O detalhe do
   erro vai pro log, não pra resposta.

### Uma cobrança por vez

`pay.php` trava o pedido (`FOR UPDATE`) do começo ao fim, **inclusive
durante a chamada ao Mercado Pago**. A segunda chamada espera a primeira e
encontra o pedido já decidido (409).

Segurar a linha do pedido durante a chamada (até 15 segundos) só faz
esperar quem mexe nesse mesmo pedido.

### Cobrança órfã

O pedido vai pro Mercado Pago como `external_reference`, no cartão e no
Pix. Quando chega um aviso de cobrança desconhecida, o webhook consulta o
pagamento e usa essa referência pra achar o pedido:

- se o pedido ainda espera pagamento, a cobrança o paga;
- se não, a cobrança volta como pagamento tardio.

O executor de estornos passou a reconhecer cobrança do Mercado Pago pelo
provedor, mesmo que o pedido tenha trocado de forma de pagamento depois.

## Como o teste prova

`tests/smoke_late_payment.sh`, com o Mercado Pago simulado:

| Cartão de teste ou referência | Simula |
|---|---|
| `CONT` | cartão em análise |
| `SLOW` | meio segundo de demora da rede |
| `PAID...` | pago no meio do cancelamento |
| `DOWN...` | Mercado Pago fora do ar |

**O teste pega mesmo?** Conferido estragando o código de propósito:

- sem a trava do `pay.php`, a cobrança simultânea dá 200, 409 e quatro 500;
- sem a tabela de transições, o aviso "pendente" atrasado volta o
  pagamento pra análise.

Nos dois casos, o teste falhou apontando o problema.

## De passagem: mais leituras cruas de entrada

A varredura da 47 cobriu o corpo JSON. Faltavam a query string e o
formulário de upload (multipart), que o fuzz não manda como lista:

- **Nota da avaliação, parcelas do cartão e valor do turbo:** `(int)` de
  uma lista dava 1. Nota "[5]" virava 1 estrela, e "[12]" parcelas virava
  uma.
- **Latitude e longitude da busca:** `(float) "abc"` dava 0, e a distância
  passava a ser medida da costa da África.
- **Limite e paginação** (`?days`, `?limit`, `?offset`): agora
  `query_int()`, que prende na faixa e usa o padrão quando o valor não é
  número.
- **Banner, comprovante de baixa, foto de ocorrência e documento de
  candidato:** `input_str()` e `positive_id()` no multipart.
- **Tempo de preparo e pausa:** "60x" passava como 60.

## O que fica pro Marcos

- **Estorno tardio na fila:** hoje ele espera o admin enviar, como todo
  estorno. Dá pra mandar direto pro executor, já que não há nada a
  decidir; é trocar o estado inicial em `record_late_payment_refund`.
- **Prazo do QR do Pix automático:** a cobrança vai sem
  `date_of_expiration`, então vale o padrão do Mercado Pago. Um prazo curto
  (30 minutos, por exemplo) reduz a janela de pagamento tardio.
- **Nada disso foi conferido contra a API real do Mercado Pago:** este
  ambiente não alcança o Mercado Pago. O cancelamento
  (`PUT /v1/payments/{id}`) e a consulta seguem a documentação pública. Vale
  um teste no sandbox antes de abrir.
