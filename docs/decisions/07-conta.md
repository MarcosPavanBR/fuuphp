# Módulo de conta

Fase 6 do mock (endereços, cartões, configurações), a parte que é backend
puro: CRUD de endereços completo e cartão salvo via Mercado Pago.

- **Endereços ganharam `update.php`/`delete.php`** — só existiam
  `create`/`list` desde o módulo catalog+ordering. Trocar de padrão
  desmarca os outros do mesmo usuário na mesma transação (não existe
  `UNIQUE` parcial no banco garantindo "só um padrão"; é regra de
  aplicação, testada no smoke test). Apagar um endereço já usado num
  pedido é bloqueado pela própria FK (`orders.address_id` não tem `ON
  DELETE`) — capturado e devolvido como 409, não como 500 cru.
- **Taxa de entrega por endereço não é mostrada em lugar nenhum desta
  tela** — mesmo achado documentado em `PaymentSelectorScreen.svelte`
  (Fase 4.1): não existe cálculo de frete por bairro/distância neste
  backend (`orders/checkout.php` recebe `delivery_fee` do corpo da
  requisição, não calcula). O mock mostra "Taxa R$ 6,90 · 25–35 min" por
  endereço; inventar esse número aqui seria fabricar um dado que o
  sistema não sustenta. Registrado em "Próximos passos".
- **Cartão salvo usa o modelo real do Mercado Pago: Customer → Cards.**
  Um Customer por usuário (`users.mp_customer_id`, criado na primeira vez
  que alguém salva um cartão), N cartões por Customer — sem guardar esse
  id, cada cartão novo criaria um Customer à toa. `lib/payments/mercadopago.php`
  ganhou `mp_create_customer()`/`mp_create_card()`/`mp_delete_card()`,
  seguindo o mesmo contrato documentado da API real, com o mesmo modo
  fake já usado pelo módulo de pagamentos (sem conta sandbox neste
  ambiente).
- **Bandeira em modo fake é um palpite, nunca usado pra cobrança.** Sem
  base de BIN neste ambiente, `mp_fake_create_card()` só olha o primeiro
  dígito do token-placeholder (5→Mastercard, 4→Visa, resto→Elo) —
  cosmético, documentado no código; em produção quem decide a bandeira de
  verdade é o Mercado Pago, a partir do token real do SDK.
- **`cards/update.php` só aceita `is_default`.** Os outros campos de um
  cartão salvo (bandeira, últimos 4 dígitos, validade) vêm do Mercado Pago
  no momento da criação — não existe "editar cartão" de verdade, e deixar
  o endpoint aceitar isso silenciosamente criaria um cartão salvo com
  dados que não batem com o que o Mercado Pago realmente tem.
- **Validado contra Postgres e PHP reais.** `tests/smoke_account.sh` cobre
  os dois endereços com troca de padrão, edição, e o bloqueio de apagar
  endereço em uso; dois cartões com o mesmo `mp_customer_id` reaproveitado
  (confirmado por query direta no banco, não só pela resposta da API),
  troca de padrão e remoção.
