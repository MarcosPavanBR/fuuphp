# Módulo catalog+ordering+checkout

- **Frete (`delivery_fee`) é informado pelo cliente do checkout, não
  calculado aqui.** O esquema não modela uma tarifa base por loja/distância
  (só `surge_fee`, o frete turbinado do cenário sem entregador — Fase 15) e
  a especificação não detalha de onde vem o valor normal. Fica validado
  como número ≥ 0 e nada mais; o cálculo por distância/geolocalização é
  responsabilidade de um módulo futuro (dispatch ou um serviço de tarifação
  à parte).
- **`policy_overrides` com `scope='city'` não entra no merge de
  `lib/catalog/policy.php`.** Só `scope='restaurant'` é aplicado — um override por
  praça exigiria cruzar `city_ibge_code` do endereço de entrega contra a
  praça da loja, e essa resolução geográfica não existe neste módulo ainda.
  Fica comentado no código.
- **`POST /v1/orders/status` é uma porta só, por cima de `advance_order()`,**
  em vez de um endpoint por transição (`/accept`, `/reject`, `/ready`...).
  A legalidade de uma transição (de/para) é decidida inteiramente pelo
  banco; o PHP só decide **quem tem permissão de pedir** cada transição
  (cliente só cancela o próprio pedido; loja avança/recusa/cancela o seu).
  Isso significa dois níveis de erro diferentes por design: 403 quando o
  papel não pode pedir aquilo, 409 quando o banco recusa a transição em si.
- **RLS por restaurante (migração 009) ainda não está em uso real.** As
  policies existem no banco, mas a conexão PHP de `lib/core/db.php` usa o que
  `DATABASE_URL` apontar — em dev/CI isso é o superusuário `postgres`, que
  ignora RLS por padrão (é dono das tabelas). A autorização de acesso a
  pedido hoje é feita inteiramente em `lib/ordering/orders.php`
  (`authorize_order_access()`), comparando `user_id`/`restaurant_id` do
  token com os do pedido. Rodar como o role `app_rw` (já com `GRANT`
  configurado em `009`) e emitir `SET LOCAL app.role` / `app.restaurant_id`
  por requisição é o próximo passo para RLS virar defesa em profundidade de
  verdade, não só desenho.
