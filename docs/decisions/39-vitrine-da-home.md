# 39 — Vitrine da Home: banners, logo, categorias, favoritas e queridinhos

**De onde veio:** o Marcos mandou uma cópia salva da Home do MaisDelivery (o
concorrente que é a referência do modelo de negócio) em Ribas do Rio Pardo
(MS). Comparando com a Home do FUU, faltavam cinco coisas, e ele pediu o que
fosse melhor, completo. Todas foram feitas, menos o cashback (abaixo).

## O que foi feito

- **Banners** (migração 037, `promo_banners`): a plataforma cria na aba
  Banners do admin (imagem, cidade ou todas, loja pra onde o toque leva,
  primeiro e último dia, ordem), liga, desliga, reordena e apaga, tudo no
  `audit_log`. A Home mostra até 8 no ar, em carrossel que anda sozinho a cada
  5 s (parado pra quem pede menos movimento no sistema). O link só sai se a
  loja estiver aprovada e for da cidade do banner. É a vitrine que dá pra
  vender às lojas.
- **Logo da loja**: a coluna existia desde a migração 010, faltava subir. A
  equipe sobe na aba Loja do painel (`restaurants/logo.php`); o centro vira um
  quadrado de 400 px. Aparece no card da Home e no topo da loja; sem logo,
  ficam as iniciais.
- **Categorias**: eram 6, só de comida. Viraram 18, incluindo o comércio do
  bairro que não é comida (pet shop, beleza e perfumaria, moda e presentes,
  tabacaria, bebidas...). As 6 antigas continuam, então nenhuma loja muda. A
  loja troca a própria categoria no painel (vai pro `audit_log`), e a Home só
  mostra os atalhos que têm loja aprovada na cidade.
- **Favoritas** (`favorite_restaurants`): o coração no card, e o atalho
  "Favoritas" quando há alguma. Marcar duas vezes não duplica; loja não
  aprovada não entra. É dado pessoal: sai no "baixar meus dados", é apagado no
  "excluir conta" e está no aviso de privacidade. O coração é em tinta, não em
  vermelho: vermelho cheio é da ação principal.
- **Queridinhos** (`restaurants/popular_items.php`): os itens mais pedidos da
  cidade nos últimos 30 dias. Conta só pedido **entregue**, só de loja
  aprovada e **aberta agora** (a faixa é pra pedir, não pra olhar) e no máximo
  2 itens por loja, senão a campeã ocupa tudo. Sem venda, a faixa não aparece.

Por baixo:

- `lib/catalog/public_images.php`: a regra de imagem pública que nasceu na
  foto do cardápio (GD recodifica em JPEG e tira EXIF/GPS; nome = SHA-256 do
  conteúdo; a rota só aceita esse formato de chave) agora serve foto,
  logo e banner. A foto do cardápio foi refatorada pra usar a mesma função.
- O Nginx deixava `no-cache` por cima do cache de um ano dessas imagens; as
  três rotas de imagem ficaram de fora do `no-cache`.
- O service worker guarda banners, queridinhos e logos, então a Home abre
  offline.
- Teste: `tests/smoke_showcase.sh` (logo, categoria, banners, favoritas,
  queridinhos).

## Achados no caminho

- **Placeholders do mock que iam chegar ao cliente:** a Home tinha uma caixa
  com o texto "banner promocional" e o topo da loja, "banner do restaurante".
  A primeira saiu antes; a segunda virou a faixa com o logo da loja.
- **Dois testes calculavam "hoje" em UTC** (`smoke_machine`, `smoke_money`).
  O sistema está em Brasília desde a [38](38-revisao-do-codigo.md), então os
  testes falhavam entre 21h e meia-noite. Agora usam o dia de Brasília.

## Fora, de propósito

- **Cashback.** O concorrente dá cashback em quase toda loja; o FUU usa pontos
  que viram cupom, decisão do Marcos (a [32](32-fidelidade.md)). Trocar é
  decisão de modelo de negócio, não técnica.
- **Botão "+" no queridinho.** O toque abre a loja: o item pode ter variação
  (tamanho, borda) que precisa da tela do item.
