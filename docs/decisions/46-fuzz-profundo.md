# 46 — Fuzz profundo: o que só aparece depois de achar o registro

**De onde veio:** depois das correções da auditoria ([45](45-correcoes-da-auditoria.md)),
o Marcos perguntou se havia mais alguma coisa no código que precisava de
atenção.

O `smoke_fuzz.sh` manda lixo com ids que não existem. A rota responde 404
na primeira linha e nunca chega ao código que valida (ou não valida) o
resto. O `smoke_fuzz_deep.sh` resolve isso partindo de um corpo válido,
sobre registros que existem de verdade:

- loja com cardápio;
- pedido em rota com o entregador do teste;
- pedido entregue;
- pedido aguardando pagamento;
- comprovante de Pix pendente;
- reembolso pendente;
- loja esperando aprovação.

Depois troca **um campo por vez** por 16 valores ruins. Na primeira rodada
deram **223 respostas 500 em 22 rotas**. Aprofundando o teste, apareceram
furos que não davam 500, mas custavam dinheiro. Tudo foi reproduzido antes
de corrigir e está coberto por teste.

## Furos de dinheiro e de fraude

### Cupom de uso único usado quantas vezes quisesse (migração 044)

O carrinho guardava só o valor do desconto, não **qual** cupom. O checkout
registrava o uso pelo `coupon_code` do corpo. Isso abria três furos:

- **Fechar sem mandar o código:** o desconto passava, nenhum uso era
  registrado, o orçamento da campanha ficava intocado e o custo não entrava
  no livro. Reproduzido: o mesmo cupom usado 3 vezes, 0 resgates, R$ 0,00
  gastos.
  - O app caía nisso sem ninguém tentar: a gaveta do carrinho guardava o
    cupom aplicado só na memória, e ao reabrir fechava o pedido sem código.
- **Fechar com outro código** (de frete grátis): desconto em dobro, e o uso
  ia pra conta do cupom errado.
- **Aplicar e depois tirar itens:** o desconto ficava valendo abaixo do
  pedido mínimo do cupom.

**Correção:**

- `orders.coupon_id` guarda o cupom aplicado. O checkout usa esse e confere
  tudo de novo, com a linha do cupom travada:
  - prazo e loja;
  - pedido mínimo sobre o subtotal de agora;
  - uso por CPF e por conta;
  - público da campanha;
  - orçamento, **contando este desconto**. Passar do teto antes dava 500 no
    CHECK do banco.
- O desconto é recalculado a cada mudança no carrinho
  (`refresh_cart_coupon`) e no fechamento.
- Um `coupon_code` diferente do cupom do carrinho é recusado com
  `coupon_not_applied`.
- A migração zera o desconto dos carrinhos abertos, porque o cupom deles é
  desconhecido. O cliente aplica de novo.

### Entrega "provada" com qualquer texto

`couriers/deliver.php` aceitava qualquer `photo_storage_key` não vazia como
prova. Dava pra fechar a entrega sem o código do cliente e sem foto nenhuma.
O sha256 gravado era o que o aparelho dizia, e por isso a foto reaproveitada
escapava do alerta de fraude.

O próprio teste de fraude usava a brecha: fechava o pedido O2 com a foto
enviada para o O1.

**Correção** (`lib/dispatch/delivery_photos.php`): a chave tem que ser deste
pedido, o arquivo tem que existir, e o hash é recalculado do conteúdo. A
abertura de ocorrência segue a mesma regra.

### Valores sem teto

Em várias rotas, valor em reais era `(float)` do que viesse:

- `"x"` virava 0: preço de item "x" publicava o item **de graça**;
- `-1` passava no valor conferido do comprovante de Pix;
- 1e30 estourava a coluna e dava 500 no meio de transações de dinheiro.

Agora tudo passa por `money_input()`: número finito dentro de uma faixa.
Os tetos são de sanidade (zero a mais digitado), não regra de negócio:

| Campo | Faixa |
|---|---|
| Valor de cupom e campanha | até R$ 10.000 |
| Pedido mínimo | até R$ 100.000 |
| Orçamento de campanha | até R$ 10 milhões |
| Teto de cupom da loja | até R$ 1 milhão |
| Bônus de reembolso em carteira | até R$ 1.000 |
| Preço de item e acréscimo de variação | até R$ 10.000 (`MENU_PRICE_MAX`) |
| Valor conferido (Pix, baixa) | até R$ 100.000 |
| Troco, baixa do entregador, venda na maquininha | até R$ 100.000 |
| Limites de pagamento da loja | até R$ 100.000 |

### Formas de pagamento da loja: salvar com campo em branco dava 500

O app omite o "troco máximo" ou o "pedido mínimo" quando o campo está vazio.
O PostgreSQL confere o NOT NULL da linha nova **antes** do `ON CONFLICT`, e
o COALESCE do UPDATE nunca chegava a rodar. Agora o valor em branco é
resolvido antes (mantém o anterior, ou o padrão da coluna).

## Endereço que mudava por baixo do pedido (migração 043)

O pedido não guarda cópia do endereço: `orders.address_id` aponta pra linha,
e é ela que o entregador, a comanda e o recibo leem. Editar o endereço
reescrevia o destino de um pedido a caminho e o histórico dos antigos.

**Agora endereço usado em pedido é imutável:**

- editar cria uma versão nova e arquiva a antiga (`replaced_id` na
  resposta);
- apagar arquiva. Antes dava 409 e o endereço velho ficava na lista pra
  sempre.

O arquivado some da lista e do checkout; os pedidos seguem apontando pra
ele. A validação do endereço também não existia na edição: latitude 999,
IBGE `"x"`, rua `"Array"` e 20 mil letras passavam. Criar e editar agora
usam a mesma `address_input()`.

## Entrada ruim que virava 500

Os validadores novos ficam em `lib/core/validation.php`:

| Validador | O que confere | Antes |
|---|---|---|
| `is_valid_date` | 2026-02-30 não passa | o formato bastava e o banco dava 500 |
| `is_valid_time` | 25:99 não passa | idem |
| `is_number_between` / `is_int_between` | número finito na faixa | `"NaN"`, 1e30, `true` |
| `positive_id` | id numérico de verdade | `""` e `"x"` chegavam no bigint |
| `body_text` | texto (não lista) até N letras | substitui 38 `trim((string) ...)` |
| `has_nul_byte` | caractere nulo, no corpo e na query | o PostgreSQL recusa `\0` em text |

Outros casos corrigidos:

- uuid validado antes do banco em campanhas, candidaturas, aprovação de loja,
  maquininhas e checkout.

**Limites de texto** que vão pro papel ou pra outra pessoa ler:

- observação do item: 200 letras. Vai impressa na comanda, e 10 mil letras
  eram metros de papel. O app ganhou `maxlength`;
- nome do item: 80 letras;
- motivo de recusa: 300;
- comentário da avaliação: 1000;
- etiquetas da avaliação: até 10, com 40 letras cada.

**Quantidade por linha do carrinho:** de 1 a 99 (`CART_MAX_QUANTITY`), com o
limite também nos botões do app.

## "Array" gravado sem erro nenhum

Não dar 500 não basta. No PHP, `(string)` de uma lista vira o texto
`"Array"`, e isso é gravado sem erro. Foi assim que um bairro chamado
"Array" apareceu no onboarding: `admin/cities.php` aceitava uma lista dentro
da lista de bairros. A rota agora recusa item que não é texto.

O fuzz profundo ganhou uma varredura final: nenhuma coluna de texto (nem
lista de texto) do banco pode conter `"Array"`. A varredura foi conferida
contra o banco antigo, onde achou o bairro.

## Onboarding preso numa cidade sem bairro

A coluna `service_cities.neighborhoods` aceita lista vazia (é o padrão). Só a
tela do admin exige pelo menos um bairro. Uma cidade inserida direto no
banco nascia sem bairro, e o botão "Confirmar" do onboarding, que exigia um,
nunca ligava: o cliente ficava preso.

Agora, sem bairro cadastrado, o app confirma só com a cidade, e a Home não
mostra mais a vírgula sobrando. Achado no teste de navegador da ARQ-02.

## Maquininha: retirada dava 500 com a política padrão

`platform_policies.pos_return_deadline` é texto, e o padrão da coluna
(migração 003) é `'shift_end'`, "devolve no fim do turno". A rota de
retirada mandava esse texto direto pra um `::interval`, e **toda** retirada
de maquininha dava 500 com a política padrão, que é a que o
`bootstrap_admin` cria. O teste da maquininha gravava `'6 hours'` e escondia
o caso. O fuzz achou quando passou a retirar a máquina de verdade.

**Correção:** `pos_due_at()` (`lib/ledger/pos.php`) interpreta a política.

- Intervalo (`'6 hours'`, `'06:00:00'`): o prazo conta a partir de agora.
- `'shift_end'`: o fechamento do turno **da loja** em andamento
  (`business_hours`, no relógio da cidade, inclusive turno que vira a
  madrugada). O turno do entregador não tem hora marcada pra acabar; o da
  loja tem, e é quando ela confere a devolução no balcão.
- Sem turno cadastrado, ou com texto que não é prazo: 6 horas.

Essa leitura de "fim do turno" é uma **interpretação**: se o Marcos quiser
outra, é trocar essa função. Teste em `smoke_money.sh`, com a política
padrão.

## Até onde o fuzz chega

Com as sementes que ele cria (pedido entregue em dinheiro, pedido na
maquininha em rota com a máquina na mão, baixa de espécie aberta,
comprovante de baixa Pix de um segundo entregador, candidatura com
documentos, disputa, ocorrência e erro em aberto), o corpo válido de quase
toda rota passa, e as trocas testam a validação de verdade.

As que ainda param antes, de propósito ou por regra de negócio:

- **Idempotência:** trocar o método de pagamento pro mesmo método;
- **Regra de estado:** turbo de pedido que não está esperando entregador;
- **Proteção de segurança:** limite de cadastro de loja por IP e código de
  login errado;
- **Destrutivo de propósito:** excluir conta sem digitar EXCLUIR.

`FUZZ_VERBOSE=1` mostra o status do corpo válido de cada rota.

## Tetos

- **Os tetos da tabela acima** são de sanidade. Se o Marcos quiser outros
  números, é trocar a constante.
