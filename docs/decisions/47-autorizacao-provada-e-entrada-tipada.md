# 47 — Autorização provada, entrada sem aviso e app que atualiza

**De onde veio:** continuação da varredura da [46](46-fuzz-profundo.md). O
Marcos pediu pra fazer tudo que fosse possível, e perguntou se havia mais
alguma coisa pra corrigir.

Cada item abaixo foi reproduzido antes de corrigir e tem teste no CI.

## Autorização testada, não suposta (`smoke_authz.sh`)

Até aqui, a autorização era conferida rota a rota, lendo o código. Agora
existe um teste que tenta, de verdade, entrar onde não deve.

**Parte 1: papel errado.** A lista de rotas sai do próprio código:

| A rota chama | Ela é de |
|---|---|
| `require_admin(` | admin |
| `require_store_staff(`, ou confere `!== 'restaurant_staff'` | loja |
| `require_courier(` | entregador |
| confere `!== 'customer'` | só cliente |

Cada rota é chamada por GET e por POST sem login e com cada papel que não é
o dela. A resposta tem que ser 401, 403 ou 405. Rota nova entra no teste
sozinha, sem ninguém lembrar de listar.

As rotas de imagem pública (foto do cardápio, logo) servem o GET a qualquer
um de propósito, então só o POST delas é conferido.

**Parte 2: dono errado.** Um segundo mundo, B, com cliente, loja e
entregador próprios, tenta ler e mexer no que é de A com o próprio login.
São 55 tentativas:

- **cliente B:**
  - pedido de A: ver, recibo, cancelar, chat, posição do entregador,
    "pedir de novo", acompanhar, trocar o método, pagar, enviar
    comprovante, avaliar;
  - pagar o próprio pedido com o cartão salvo de A;
  - endereço de A: editar, apagar, cotar frete, fechar pedido com ele;
  - carrinho, cartões e carteira de A;
  - chamado de suporte de A;
- **loja B:** pedido de A, Pix de A, comprovante de baixa, baixa de espécie
  com o código real de A, fila de impressão, cardápio, maquininha,
  feriado, cupom e foto de A;
- **entregador B:** entregar o pedido de A **com o código certo**,
  retirar, abrir ocorrência, vender na maquininha, ver a baixa, a foto da
  ocorrência, o chat e a posição.

Resposta 2xx é vazamento; 5xx é defeito. Depois, o teste confere no banco
que nada de A mudou: carrinho, endereço, cartão, crédito, status dos
pedidos, comprovante, cardápio, maquininha, feriado, cupom e baixa, e
nenhuma prova de entrega, venda na maquininha ou mensagem nova.

**Resultado:** 647 chamadas, nenhum problema.

**O teste pega mesmo?** Foi conferido estragando o código de propósito: com
`OR true` no filtro de dono de `cards/delete.php`, o teste falhou apontando
a rota. Depois o código voltou ao normal.

O mundo de teste (loja, cardápio, pedidos em cada estado, entregador,
admin) saiu do `smoke_fuzz_deep.sh` pra `tests/support/seed_world.sh`, e
agora é usado pelo fuzz profundo e pelo teste de autorização.

## Aviso do PHP virava comportamento errado

No PHP, converter uma lista com `(string)` dá o texto `"Array"` e um aviso
no log. Converter com `(int)` dá **1**. Então `{"order_id": [5]}` agia sobre
o **pedido 1**. A checagem de dono barrava o acesso a pedido dos outros,
mas a rota respondia sobre o registro errado.

Dois leitores novos, em `lib/core/validation.php`:

- `input_str($fonte, 'campo')` pra texto: lista ou objeto vira vazio (e a
  rota responde "campo obrigatório"), número vira texto.
- `positive_id(...)` pra id: o que não é inteiro positivo vira 0 (404 ou
  422).

Foram 64 leituras de texto e 45 de id trocadas, em 70 rotas.

**Regra nova nos dois fuzz:** qualquer `PHP Warning`, `Notice` ou
`Deprecated` no log do servidor reprova o teste. Aviso é defeito que ainda
não foi notado. A varredura por `"Array"` gravado no banco passou a olhar
também as colunas jsonb.

## Busca com curinga

Na busca de produtos, `%` e `_` funcionavam como curinga do `LIKE`: buscar
`%%` listava o catálogo inteiro. Não vazava nada (o catálogo é público),
mas furava o tamanho mínimo da busca e forçava a varredura da tabela.

- `like_escape()` trata `%`, `_` e `\` como letra comum.
- A busca vai até 80 letras (422 `query_too_long`).
- As buscas do admin (aparelhos de parceiro, teto de cupom da loja)
  ganharam o mesmo tratamento e param em 100 letras.

Teste: `smoke_discovery.sh`.

## O app que não atualizava (service worker)

Conferido no navegador, com o service worker de verdade:

- **Deploy novo não chegava.** A página e o JS eram "cache primeiro": o
  cliente ficava na versão antiga até alguém lembrar de trocar a `VERSION`
  do `sw.js`.
- **API sem `Authorization` ia pro cache**, inclusive o acompanhamento ao
  vivo, que autentica por ticket na URL e é um stream sem fim.

Agora são três estratégias:

- `/assets/` (nome com hash, muda a cada build): cache primeiro;
- a página e o resto do app: rede primeiro, com cópia pro offline;
- da API, só as listas públicas (rede primeiro); o resto passa direto.

**Conferido no navegador:** a versão nova chega no reload, nada da API
privada fica guardado, e offline a página abre e a lista de cidades
responde. Detalhe em [18](18-pwa-offline.md).

## Cupom do carrinho reaberto

Desde a migração 044, o carrinho guarda qual cupom foi aplicado. Mas a
gaveta do carrinho só sabia o código enquanto estava aberta: ao reabrir,
mostrava "Desconto" sem o código.

Agora `cart/show.php` e `fetch_order` devolvem `coupon_code`, e a gaveta usa
esse código, tanto no rótulo quanto no fechamento.

## Limite visível no campo

31 campos dos quatro apps ganharam `maxlength` igual ao limite do servidor:

- nome e e-mail do cadastro;
- endereço rápido;
- comentário da avaliação, ajuda e chat;
- código de cupom;
- motivos de recusa e de troca de aparelho;
- chave Pix, placa e maquininha;
- reembolso e disputa no admin.

Quem digita vê o limite na hora, em vez de receber um erro depois de
enviar.

## Conferido e sem mudança

- **Concorrência.** Seis pedidos iguais disparados ao mesmo tempo
  (clique duplo, rede que repete):
  - fechar o carrinho ×6: um pedido;
  - pagar ×6: um pagamento aprovado;
  - nenhum 5xx.

  O pagamento, porém, foi medido no modo simulado, em que o Mercado Pago
  responde na hora. Com a demora real da rede, não bastava: ver a
  [48](48-pagamento-que-chega-depois.md).

  A carteira e os pontos já travavam a linha antes de gastar. O estorno
  já era idempotente, e o "pedir de novo" usa o preço de agora.
- **Webhook repetido.** A assinatura do Mercado Pago não tem janela de
  tempo, então uma notificação capturada poderia ser reenviada depois. Isso
  foi aceito porque, em produção, o webhook não confia no corpo: ele busca o
  pagamento na API do Mercado Pago. Repetir o aviso só relê o status real.
  O Mercado Pago, aliás, reenvia sozinho até receber 200.
