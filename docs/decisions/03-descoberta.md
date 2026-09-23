# Módulo de descoberta

A migração `010` e os endpoints `restaurants/list.php` e
`restaurants/search_products.php` existem porque a tela 2.1 (Home/Explorar)
do mock mostra categoria, distância e logo por loja, e nenhum dos três tinha
coluna no esquema — a Parte II original fixa 42 tabelas e nenhuma delas tem
`category`/`lat`/`lng` em `restaurants`.

- **O que foi adicionado (`restaurants.category`, `logo_key`, `lat`, `lng`)
  é extensão justificada, não invenção livre.** São fatos básicos de
  catálogo — "que tipo de comida" e "onde fica" — sem os quais a tela que
  o próprio dono do produto encomendou não tem como funcionar de verdade.
- **O que ficou de fora por ser escopo grande demais pra uma migração de
  suporte: nota da loja (rating).** O mock mostra "4,8 (812)" por loja —
  isso pede uma tabela de avaliações inteira (nota + comentário +
  moderação + agregação), que não está em nenhum lugar da especificação.
  Inventar esse sistema sem decisão do dono do produto seria escopo novo
  demais; fica de fora, documentado, até vir decisão.
- **Distância é Haversine de verdade, calculado no PostgreSQL** — não
  estimativa nem mock. Só fica `NULL` quando falta lat/lng de um dos dois
  lados (cliente não mandou, ou a loja não tem coordenada cadastrada).
- **Busca de produto usa `ILIKE` sobre o índice GIN trigram**
  (`gin_trgm_ops`, extensão `pg_trgm` já criada na migração `001`) — é
  exatamente o que a tela 2.2 pede no chip "PostgreSQL trigram". Os
  filtros do mock (Entrega grátis / Até 30 min / 4,5+) passaram a filtrar
  de verdade -- ver "Lacunas do app do cliente fechadas".

**Atualização (go-live).** O que mudou desde este registro:

- **A nota da loja existe.** Ela vem das avaliações de pedido entregue
  (`reviews`, migração 011) e é agregada em `restaurant_card_facts()`
  (`lib/catalog/restaurant_facts.php`): média e contagem reais, e nunca um
  número inventado quando não há avaliação ([06](06-acompanhamento.md),
  [16](16-lacunas-do-app-do-cliente.md)).
