# Endereço, área de entrega e frete no servidor (Fase 14.3)

"CEP preenche, pino corrige, ponto de referência salva a entrega. A área de
cobertura é validada no servidor e a taxa aparece antes de salvar — não na
hora de pagar."

Esta tela fecha, de quebra, o buraco que o próprio README vinha apontando em
"Próximos passos": **o frete era o único número do dinheiro que ainda vinha
do cliente**. `orders/checkout.php` e `orders/create.php` aceitavam
`delivery_fee` no corpo -- bastava mandar `0` para não pagar entrega, num
projeto onde preço de item, mínimo de pedido, comissão e total sempre foram
decididos no servidor.

- **A tarifa virou política versionada, não constante no código.** Migração
  `019` acrescenta `delivery_base_fee`, `delivery_per_km` e `delivery_max_km`
  a `platform_policies`, e a tela 10.5 (admin) passa a editá-los. Entram no
  `policy_snapshot` do pedido pelo mesmo motivo que a taxa de cancelamento:
  republicar a política amanhã não pode reescrever o frete de um pedido de
  ontem -- e o teste prova isso.
- **Os três nascem zerados, e isso é a decisão.** Inventar "R$ 5,00 +
  R$ 1,50/km" na migração seria cobrar do cliente um número que ninguém
  decidiu. Enquanto a plataforma não publicar tarifa, o frete é zero, e a
  tela do endereço diz isso com todas as letras em vez de esconder.
- **Distância é Haversine, e o README assume o que isso significa.** Linha
  reta subestima a rota real de moto. Roteamento exige provedor de mapas, que
  não está na cláusula zero; inflar o número "pra compensar" seria tarifa
  inventada. Fica a menor distância possível, documentada.
- **Loja sem coordenada não bloqueia o pedido.** `restaurants.lat/lng` é
  nullable desde a migração `010`. Bloquear o checkout por causa de um
  cadastro que não é do cliente o puniria por erro alheio; cobrar por km sem
  saber os km seria pior. Cobra-se a base, e a resposta diz que a distância é
  desconhecida.
- **Raio vazio é "sem limite", não zero.** Zero seria "não entregamos em
  lugar nenhum", e o endpoint do admin recusa zero explicitamente com essa
  frase. Fora do raio, o checkout responde `out_of_delivery_area` com o
  motivo escrito -- a distância medida e o limite, os dois no texto.
- **Ponto de referência é coluna nova, não complemento.** Complemento
  identifica a unidade (apto, bloco) e vai no cupom; referência é instrução
  pra quem entrega ("portão cinza ao lado da padaria"). Enfiar as duas no
  mesmo campo faz uma sumir.
- **O mapa do mock não existe, e a tela diz o que existe no lugar.** Não há
  provedor de mapas na cláusula zero, então não há pino pra arrastar. O que
  dá pra fazer de verdade é usar a posição do aparelho (Geolocation, a mesma
  API da 1.3) -- e a tela mostra qual das duas coordenadas está valendo, a do
  aparelho ou o centro da praça escolhida.
- **Um bug de verdade achado no navegador, não no teste de API.** O aviso
  "Buscando pelo CEP…" aparecia e sumia num `{#if}`, e a busca dispara no
  `blur` do campo. Tocar em "Salvar endereço" logo depois de digitar o CEP
  disparava o blur, o aviso entrava no fluxo do documento e empurrava o botão
  pra baixo ENTRE o mousedown e o mouseup -- o clique não completava, e o
  formulário ficava aberto sem erro nenhum. O aviso agora fica sempre no DOM
  (invisível), com o espaço reservado.
- **Validado com banco e navegador reais.** `tests/smoke_address.sh` cobre a
  referência gravada e devolvida, a taxa calculada antes de salvar, o
  endereço a 11 km recusado pelo raio de 5, a cobertura por praça (com a loja
  sem coordenada corretamente fora da conta), o checkout ignorando
  `delivery_fee: 0` mandado pelo cliente, o pedido fora do raio barrado, a
  loja sem coordenada cobrando só a base, o endereço alheio não cotável, a
  tarifa nova valendo no pedido seguinte E o snapshot do pedido antigo
  intacto, mais tarifa negativa e raio zero recusados. No Playwright: o
  painel verde "Dentro da área de entrega de 2 lojas · Taxa: R$ 4,00 +
  R$ 1,50/km · loja mais perto a 0,2 km", as pastilhas Casa/Trabalho/Outro, e
  o endereço salvo com a referência aparecendo na lista.
