# Lacunas do app do cliente fechadas (migração 026)

O que o app ainda avisava como "não construído" (toasts, notas, placeholders)
virou funcionalidade. Uma varredura por "ainda não" no front achou a lista.

- **LGPD de verdade (tela 6.3).** "Baixar meus dados" entrega um JSON com
  tudo que o sistema guarda sobre a pessoa (`profile/export.php`,
  `lib/account/account_privacy.php`) e nada que seja segredo (hash de sessão, código
  OTP, token do cartão no MP). "Excluir conta" é **anonimização**: nome,
  CPF, telefone, e-mail, nascimento, cartões, push e sessões somem; pedidos e
  pagamentos ficam, sem identificar ninguém, porque a lei fiscal obriga
  (LGPD art. 16, I). Endereço usado em pedido fica só com cidade, CEP de 5
  dígitos e coordenada arredondada. Barrada com pedido em andamento ou
  reembolso vivo; saldo de carteira exige aceite explícito de perda;
  confirmação digitando EXCLUIR. O telefone fica livre pra conta nova.
  Limite conhecido: o access token (15 min) de quem excluiu continua
  assinado até vencer -- o app faz logout na hora, e o refresh já é recusado.
- **"Alterar senha"** explica que conta de cliente não tem senha (entra por
  código no celular) em vez de abrir um formulário que não faria nada.
- **"Cardápios offline"** lista o que o service worker guardou de verdade
  (cache `*-data`) e deixa apagar; o rodapé mostra a versão real do SW.
- **Perfil (2.5) completo**: "Editar perfil" (nome, e-mail, CPF só
  mascarado na volta -- `cpf_masked` --, nascimento), "Notas e comprovantes"
  (recibo por pedido em `orders/receipt.php`: loja com CNPJ, itens, taxas,
  como pagou, estornos, gorjeta cobrada à parte; imprimível; diz que não é
  nota fiscal -- quem emite é a loja) e "Privacidade e dados (LGPD)".
- **"Repetir" pedido (2.4)** (`orders/reorder.php`): os mesmos itens,
  variações e observações voltam pro carrinho com o **preço de hoje**; o
  que saiu do cardápio é pulado e listado, em vez de falhar tudo.
- **Mapa da entrega (5.3)**: `DeliveryMap.svelte` com Leaflet (o chip do
  mock), pinos de loja, destino e entregador, "0,8 km · 3 min" e "Jonas está
  levando · Moto · placa". A posição só é liberada enquanto o pedido está
  em rota (`orders/courier_location.php`) -- antes e depois, o entregador
  não é rastreável por cliente. Sinal velho aparece como "última posição".
  A candidatura guarda o tipo de veículo, não o modelo ("Moto", não "Honda
  Biz").
- **Foto do item (11.1 → 3.1/3.2/2.2)** (`restaurants/menu_photo.php`):
  recodificada com GD pra JPEG de até 900 px (tira EXIF/GPS, neutraliza
  arquivo disfarçado), chave = hash do conteúdo, servida pública com cache
  imutável e guardada pelo service worker pro cardápio offline. Sobe na hora,
  fora do rascunho do editor (foto não muda preço nem regra).
- **Nota, tempo e frete no card da loja e nos filtros da busca (2.1/2.2)**
  (`lib/catalog/restaurant_facts.php`): nota das avaliações (só com 3 ou mais),
  frete pelo MESMO `delivery_quote()` que o checkout cobra (o teste confere
  que card e cobrança batem), tempo = preparo informado pela loja com a
  fila + viagem a `DELIVERY_AVG_KMH`. "Entrega grátis", "Até 30 min" e
  "4,5+" filtram de verdade; resultado sem o dado não passa no filtro.
  Custo conhecido: calcula por loja a cada listagem (algumas consultas por
  loja) -- aceitável no tamanho de uma praça; cache vira assunto se a lista
  crescer.
- **Pontos de fidelidade continuam sem tabela** (decisão de produto,
  "Próximos passos" 7) -- o perfil segue dizendo isso.
- **Cupom: público e frete grátis passaram a valer** (achados ao escrever a
  documentação). O público da campanha (15.3: primeiro pedido, inativos há
  15/30 dias) só era usado pra *projetar* o alcance -- qualquer pessoa
  resgatava qualquer cupom. Agora é uma condição SQL só
  (`coupon_audience_condition()`), que conta o público na projeção e barra no
  resgate e de novo no checkout (`409 coupon_audience`). E o cupom de frete
  grátis descontava o frete do *carrinho*, que ainda não tem endereço e por
  isso vale zero: nunca descontava nada. Agora ele desconta no checkout, sobre
  o frete calculado, e o carrinho avisa "o frete sai grátis no pagamento".
