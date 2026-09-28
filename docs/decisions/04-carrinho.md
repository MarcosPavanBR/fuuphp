# Módulo de carrinho

A Fase 3 revelou que `orders/create.php` (checkout de um passo só, feito
para o módulo catalog+ordering) não é como a tela realmente funciona: o
mock é item por item, com `toastr` confirmando cada adição, e "o carrinho
vive num store Svelte e é espelhado no PostgreSQL como pedido em
status='cart'". Os dois modelos convivem — nenhum substituiu o outro.

- **`price_line()` foi extraída pra `lib/ordering/cart.php` e reaproveitada em
  `orders/create.php`.** Precificar uma linha (validar item+variação
  contra o cardápio atual, nunca confiar no preço que o cliente mandou) é
  a mesma regra nos dois fluxos; duplicar essa validação seria o tipo de
  coisa que diverge silenciosamente com o tempo.
- **Um usuário só pode ter carrinho aberto numa loja por vez** — o próprio
  esquema força isso (`orders.restaurant_id` é fixo por pedido). Trocar de
  loja com o carrinho **vazio** troca sem perguntar (é o caso comum:
  passou pela loja e não pediu nada); com **item** dentro, dá 409 — a
  decisão de esvaziar e trocar fica com quem está usando o app, o backend
  não assume por conta própria.
- **Item esgotado aparece na lista, desabilitado, não escondido** — bug
  real encontrado nesta passada: `restaurants/menu.php` filtrava
  `available = true` desde a Fase 2, o que contradizia a própria
  especificação da tela 3.1 ("item esgotado desabilitado no servidor, não
  escondido"). Corrigido: o endpoint devolve todo o cardápio, com
  `available` no payload, e o front decide a aparência.
- **Cupom (`Cupom BEMVINDO10 −R$10,00` no mock) é só campo de UI.** A
  tabela `coupons` existe desde a migração `008`, mas não há endpoint de
  resgate/validação — construir isso é escopo de um cupom de verdade
  (regra de quem paga o desconto, teto, um uso por CPF), não deste
  módulo. O campo avisa que ainda não foi implementado em vez de aceitar
  qualquer código e fingir um desconto.
- **"Ir para pagamento" é onde a Fase 3 do front para.** O botão existe,
  mas ainda leva a um aviso — o backend de checkout+pagamento já existe
  (`orders/checkout.php`, `payments/pay.php`, ver seção própria abaixo),
  só a tela da Fase 4 que ainda não foi construída em Svelte.
- **Modal x classe reservada do Bootstrap: bug real, corrigido.** O
  primeiro `ItemModal.svelte` usava a classe `.modal` — que é exatamente o
  nome que o Bootstrap usa pro componente dele (`display: none` por
  padrão). O CSS com escopo do Svelte deveria vencer por especificidade,
  mas depender disso é frágil; o certo é nunca usar nomes reservados do
  framework de UI. Renomeado para `.item-modal-panel` / `.item-modal-backdrop`.
  Achado com Playwright checando `boundingBox()` do modal (`null` = não
  estava renderizando, apesar de estar no DOM) — não teria aparecido só
  olhando o código.

**Atualização (go-live).** O que mudou desde este registro:

- O cupom deixou de ser só campo de UI. O resgate de verdade está em
  `cart/apply_coupon.php` e `orders/checkout.php`, com teto, um uso por CPF e
  livro contábil de quem paga ([20](20-chat-e-cupons.md)).
- "Ir para pagamento" leva à Fase 4 construída
  ([30](30-cartao-salvo-e-total-com-frete.md)).
