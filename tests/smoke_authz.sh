#!/usr/bin/env bash
# Smoke test de AUTORIZAÇÃO: papel errado e dono errado nunca passam.
#
# Duas perguntas, respondidas com requisição de verdade, não com leitura de
# código:
#
# 1. Matriz de papéis. Toda rota de admin, de loja, de entregador e as só de
#    cliente, chamada sem login ou com o papel errado, é recusada (401/403).
#    A lista de rotas sai do próprio código (quem chama require_admin,
#    require_store_staff, require_courier ou confere o papel à mão): rota
#    nova entra sozinha.
# 2. Acesso cruzado. Um segundo cliente, uma segunda loja e um segundo
#    entregador ("B") tentam ler e mexer no que é de "A" -- pedido,
#    endereço, cartão, crédito, item do carrinho, comprovante, baixa,
#    maquininha, cardápio, feriado, cupom. Nada responde 2xx, e no fim os
#    registros de A estão como estavam.
#
# O mundo de A é o do fuzz profundo (tests/support/seed_world.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8152
LOG=/tmp/smoke-authz-server.log
# shellcheck source=support/seed_world.sh
source "$ROOT/tests/support/seed_world.sh"

echo "== o lado B: outro cliente, outra loja, outro entregador =="
STORE_B="$(gen_uuid)"; STAFF_B_ID="$(gen_uuid)"; CNPJ_B="$(gen_cnpj)"
COURIER_B_USER="$(gen_uuid)"; COURIER_B_ID="$(gen_uuid)"; CPF_B="$(gen_cpf)"
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_B_ID}', 'restaurant_staff', 'Balcão B', 'balcao-b-${STAMP}@test.com'),
  ('${COURIER_B_USER}', 'courier', 'Entregador B', 'entregador-b-${STAMP}@test.com');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, is_open, approved_at, lat, lng)
  VALUES ('${STORE_B}', 'Loja B', '${CNPJ_B}', '${CITY}', 'Pizza', true, now(), -23.5505, -46.6333);
INSERT INTO couriers (id, user_id, city_ibge_code, active) VALUES ('${COURIER_B_ID}', '${COURIER_B_USER}', '${CITY}', true);
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash) VALUES
  ('${STAFF_B_ID}', 'restaurant', '${STORE_B}', '${CNPJ_B}', '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash) VALUES
  ('${COURIER_B_USER}', 'courier', '${COURIER_B_ID}', '${CPF_B}', encode(sha256('senha123'::bytea), 'hex'));
SQL
CUST_B=$(otp_login "$(gen_phone)" signup | jq -er '.access_token') || fail "login do cliente B"
STAFF_B=$(partner restaurant "$CNPJ_B") || fail "login da loja B"
COURIER_B=$(partner courier "$CPF_B") || fail "login do entregador B"

# B com um pedido próprio aguardando pagamento: é com ele que B tenta pagar
# usando o cartão salvo de A.
ADDR_B=$(post "$CUST_B" addresses/create.php '{"street":"Rua B","city":"São Paulo","city_ibge_code":"'"$CITY"'","state":"SP","postal_code":"01001000","lat":-23.56,"lng":-46.64}' | jq -er '.id') || fail "endereço de B"
post "$CUST_B" cart/add_item.php "{\"restaurant_id\":\"${STORE}\",\"menu_item_id\":${ITEM},\"quantity\":1}" >/dev/null
ORDER_B=$(post "$CUST_B" orders/checkout.php "{\"restaurant_id\":\"${STORE}\",\"address_id\":${ADDR_B},\"payment_method\":\"mp_card\"}" | jq -er '.order.id') || fail "pedido de B"
# E um carrinho aberto, pra tentar fechar com o endereço de A.
post "$CUST_B" cart/add_item.php "{\"restaurant_id\":\"${STORE}\",\"menu_item_id\":${ITEM},\"quantity\":1}" >/dev/null

# Coisas da loja A que a loja B vai tentar mexer.
HOLIDAY_A=$(post "$STAFF" restaurants/holiday.php '{"day":"2031-01-01","closed":true,"note":"Ano novo A"}' | jq -er '.holiday.id') || fail "feriado de A"
psql_run <<SQL
INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer, budget_cap, starts_at, created_by, created_by_store)
  VALUES ('LOJAA${STAMP: -8}', 'fixed', 5, 0, '${STORE}', 'all', 'store', 50, now(), '${STAFF_ID}', true);
SQL
COUPON_A=$(psql "$DATABASE_URL" -tAc "SELECT id FROM coupons WHERE code = 'LOJAA${STAMP: -8}'")
CUSTODY_A=$(psql "$DATABASE_URL" -tAc "SELECT id FROM pos_custody WHERE courier_id = '${COURIER_ID}' AND returned_at IS NULL")

export FUZZ_CUST_B="$CUST_B" FUZZ_STAFF_B="$STAFF_B" FUZZ_COURIER_B="$COURIER_B" FUZZ_ORDER_B="$ORDER_B" \
  FUZZ_STORE_B="$STORE_B" FUZZ_HOLIDAY_A="$HOLIDAY_A" FUZZ_COUPON_A="$COUPON_A" FUZZ_CUSTODY_A="$CUSTODY_A" \
  FUZZ_ROOT="$ROOT"

echo "== papel errado e dono errado =="
php "$ROOT/tests/support/authz.php" || fail "alguma rota deixou passar papel errado ou dono errado (lista acima)"

echo "== o que é de A continua como estava =="
q() { psql "$DATABASE_URL" -tAc "$1"; }
[ "$(q "SELECT quantity FROM order_items WHERE id = ${CART_ITEM}")" = "1" ] || fail "B mexeu no carrinho de A"
[ "$(q "SELECT street FROM addresses WHERE id = ${ADDR2} AND archived_at IS NULL")" = "Rua Fuzz Dois" ] || fail "B mexeu no endereço de A"
[ "$(q "SELECT count(*) FROM saved_cards WHERE user_id = '${CUST_ID}'")" = "1" ] || fail "B apagou o cartão de A"
[ "$(q "SELECT state FROM wallet_credits WHERE user_id = '${CUST_ID}' AND state IN ('offered','accepted','declined') LIMIT 1")" = "offered" ] || fail "B decidiu o crédito de A"
[ "$(q "SELECT status FROM orders WHERE id = ${ORDER}")" = "delivering" ] || fail "B mudou o pedido em rota de A"
[ "$(q "SELECT status FROM orders WHERE id = ${ORDER_PAY}")" = "pending_payment" ] || fail "B mudou o pedido de A que aguarda pagamento"
[ "$(q "SELECT state FROM payment_proofs WHERE order_id = ${ORDER_PIX}")" = "pending" ] || fail "loja B decidiu o comprovante da loja A"
[ "$(q "SELECT name || '/' || available FROM menu_items WHERE id = ${ITEM}")" = "Pizza Fuzz/true" ] || fail "loja B mexeu no cardápio de A"
[ "$(q "SELECT active FROM pos_devices WHERE restaurant_id = '${STORE}'")" = "t" ] || fail "loja B desativou a maquininha de A"
[ "$(q "SELECT count(*) FROM holiday_overrides WHERE id = ${HOLIDAY_A}")" = "1" ] || fail "loja B apagou o feriado de A"
[ "$(q "SELECT active FROM coupons WHERE id = ${COUPON_A}")" = "t" ] || fail "loja B desativou o cupom de A"
[ "$(q "SELECT state FROM cash_settlement_intents WHERE id = ${INTENT}")" = "open" ] || fail "loja B conferiu a baixa da loja A"
[ "$(q "SELECT count(*) FROM delivery_proofs WHERE order_id = ${ORDER}")" = "0" ] || fail "entregador B fechou a entrega de A"
[ "$(q "SELECT count(*) FROM card_transactions WHERE order_id = ${ORDER}")" = "0" ] || fail "entregador B lançou venda no pedido de A"
[ "$(q "SELECT count(*) FROM order_messages WHERE order_id = ${ORDER}")" = "0" ] || fail "B escreveu no chat do pedido de A"

echo "OK: papel errado e dono errado não passam em rota nenhuma"
