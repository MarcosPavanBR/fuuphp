# Chat do pedido e cupons (Fase 14.2 + migração 008)

Dois buracos que o próprio README já vinha apontando: `talkToStore()` no
acompanhamento mostrava "Fase 14 ainda não foi portada", e o campo de cupom
do carrinho (3.3) estava na tela desde a Fase 3 avisando que não tinha
backend. Os dois fecham aqui, sobre tabelas que existem desde a migração
`008`.

- **Quem entra na conversa é decidido pelo VÍNCULO com o pedido, não pelo
  papel.** Cliente dono do pedido, a loja daquele pedido, o entregador
  designado e o suporte -- nessa ordem de checagem, em `match`. Uma loja não
  entra no chat do pedido da loja vizinha, e a resposta pra quem não é parte
  é 404 (não conta nem que o pedido existe).
- **Eventos do sistema entram na mesma linha do tempo.** Mensagens e
  transições de status vêm separadas do servidor e são intercaladas por
  horário na tela. É isso que faz "Saiu para entrega às 20:29" aparecer entre
  duas falas, como a conversa aconteceu de verdade.
- **O chat fecha 2 h depois da entrega, mas só pra escrever.** O comentário
  estava na própria migração `008` ("regra na API, histórico permanece"):
  ler continua valendo pra sempre, porque prova de disputa não pode sumir. A
  hora da entrega sai do `order_events` -- não existe coluna `delivered_at`,
  e criar uma seria duplicar o que a linha do tempo já sabe.
- **Respostas rápidas dependem de quem está falando** ("evitam digitar de
  moto"): o cliente recebe "Já desço"/"Deixe na portaria", a loja recebe
  "Saindo em 5 min"/"Acabou um item, posso trocar?", o entregador recebe
  "Estou no portão". Um mesmo componente serve os dois apps -- quem está
  falando vem do servidor (`me`), não de uma prop.
- **Cupom é validado inteiro no servidor**, porque cada regra dessas é
  dinheiro: prazo, loja, pedido mínimo (sobre o SUBTOTAL -- senão o frete
  ajudaria a atingir o mínimo, o oposto do que o cupom quer), orçamento da
  campanha e um uso por CPF.
- **Um uso por CPF, não por conta** -- é a `UNIQUE (coupon_id, cpf)` da
  migração `008`, e é por isso que quem não preencheu CPF no cadastro recebe
  409 `cpf_required` com a saída na mensagem, em vez de um "cupom inválido"
  que esconde o que dava pra corrigir.
- **O desconto entra em `orders.discount` e o total se recalcula sozinho**,
  porque `total` é coluna gerada. Nada é somado no PHP nem no navegador.
- **Aplicar no carrinho e consumir orçamento são momentos diferentes.**
  `cart/apply_coupon.php` só grava o desconto; o registro em
  `coupon_redemptions` e o `spent + valor` acontecem no checkout, dentro da
  transação que avança o pedido -- carrinho abandonado não pode segurar
  dinheiro de campanha, e se o orçamento estourar entre aplicar e fechar, o
  `CHECK within_budget` derruba o checkout inteiro.
- **Código vazio remove o cupom**, no mesmo endpoint: desfazer não precisa de
  rota nova.
- **Validado com Postgres e navegador reais.** `tests/smoke_support.sh` cobre
  cupom sem CPF, inexistente, de outra loja, abaixo do mínimo, aplicado,
  removido, resgatado no checkout e recusado na segunda tentativa do mesmo
  CPF; e no chat: as duas pontas conversando, o evento de status na linha do
  tempo, a marcação de lida, a loja de fora barrada nas duas direções,
  mensagem vazia e o fechamento 2 h depois da entrega com histórico
  preservado. No Playwright: o cupom derrubando o total de R$ 78,40 pra
  R$ 68,40 no carrinho, e a conversa indo do app do cliente pro KDS da loja
  e voltando por resposta rápida, com "lida" aparecendo.
