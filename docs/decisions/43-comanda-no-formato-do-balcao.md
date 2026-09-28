# 43 — Comanda no formato que o balcão já conhece

**De onde veio:** o Marcos mandou a foto do papel que a impressora térmica do
MaisDelivery solta e que vai grampeado no saco pro cliente.

## O que mudou na comanda (`lib/printing/documents.php`)

| Papel do MaisDelivery | Comanda do FUU agora |
|---|---|
| marca + número do pedido no topo | `FUUdelivery` + `PEDIDO #código` (grande) + data e hora da loja |
| nome da loja | nome da loja em maiúsculas |
| cliente com nome completo | **primeiro e último nome** ("Maria Souza"): acha a pessoa na porta sem o nome inteiro num papel que fica no balcão e no lixo |
| "Cliente Novo: Não" | "Cliente novo: SIM/Não", pelo critério do cupom de primeiro pedido, **na loja**; SIM sai em negrito, pra a loja caprichar |
| endereço em negrito com complemento e referência entre parênteses, bairro e cidade | igual, e agora com a cidade |
| "Cobrar no Cartão de Crédito" | "COBRAR NO CARTÃO DE CRÉDITO/DÉBITO" + "Na entrega · levar a maquininha"; em dinheiro, o troco em letra grande (como já era) |
| Sub Total (+), Taxa Entrega (+), Total | Subtotal (+), Taxa de entrega (+), turbo, gorjeta, Desconto (-), TOTAL |
| itens espremidos na "Obs" | um item por linha com preço, opções (`+ borda`) e observação recuadas embaixo do item |
| — | rodapé com o slogan: "Pediu, fuu, chegou." |

Continua de fora, de propósito: o código de entrega (é o segredo que prova a
entrega, decisão 8.6) e o telefone do cliente.

Achado no caminho: o recuo das opções e da observação (`   + borda`) se
perdia na quebra de linha (`escpos_wrap` tirava os espaços do começo). Agora o
recuo vale pra todas as linhas do parágrafo.

Teste: `smoke_courier.sh` confere marca, nome curto (e que o nome inteiro não
sai), cliente novo e endereço, além do que já conferia (troco, código de
entrega fora, largura, bytes ESC/POS).
