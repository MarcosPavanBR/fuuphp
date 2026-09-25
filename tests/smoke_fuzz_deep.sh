#!/usr/bin/env bash
# Fuzz PROFUNDO: entrada ruim depois de achar o registro.
#
# O smoke_fuzz.sh manda lixo com ids que não existem: a rota responde 404 ou
# 422 logo na primeira linha e nunca chega no código que valida (ou não
# valida) o resto. Aqui cada rota recebe um corpo VÁLIDO -- loja, pedido,
# endereço, corrida e cupom que existem e são do usuário -- e cada campo é
# trocado, um de cada vez, por um valor ruim (lista, texto no lugar de
# número, número gigante, caractere nulo, texto de 10 mil letras...).
#
# Nenhuma troca pode dar 5xx. Foi este teste que achou o endereço aceitando
# latitude 999 e gravando "Array" (tests/support/fuzz_deep.php lista o que
# é mandado).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8151
BASE="http://127.0.0.1:${PORT}/api/v1"
LOG=/tmp/smoke-fuzz-deep-server.log

fail() { echo "FALHOU: $1" >&2; tail -20 "$LOG" >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }
gen_phone() { echo "119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"; }

CITY="3550308"
STAMP="$(date +%s%N)"
ADMIN_ID="$(gen_uuid)"; STAFF_ID="$(gen_uuid)"; STORE="$(gen_uuid)"; CNPJ="$(gen_cnpj)"
COURIER_USER="$(gen_uuid)"; COURIER_ID="$(gen_uuid)"; CPF="$(gen_cpf)"
ADMIN_PHONE="$(gen_phone)"; CUST_PHONE="$(gen_phone)"

echo "== semear loja, cardápio, entregador e admin =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${ADMIN_ID}', 'admin', 'Admin Fuzz Fundo', '${ADMIN_PHONE}', 'admin-fdeep-${STAMP}@test.com'),
  ('${STAFF_ID}', 'restaurant_staff', 'Balcão Fuzz Fundo', NULL, 'balcao-fdeep-${STAMP}@test.com'),
  ('${COURIER_USER}', 'courier', 'Entregador Fuzz Fundo', NULL, 'entregador-fdeep-${STAMP}@test.com');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, is_open, approved_at, lat, lng)
  VALUES ('${STORE}', 'Loja Fuzz Fundo', '${CNPJ}', '${CITY}', 'Pizza', true, now(), -23.5505, -46.6333);
INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
  VALUES ('${STORE}', ARRAY['cash','pos_machine','pix_manual','mp_card']::payment_method[], 200.00, 0);
INSERT INTO couriers (id, user_id, city_ibge_code, active) VALUES ('${COURIER_ID}', '${COURIER_USER}', '${CITY}', true);
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash) VALUES
  ('${STAFF_ID}', 'restaurant', '${STORE}', '${CNPJ}', '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash) VALUES
  ('${COURIER_USER}', 'courier', '${COURIER_ID}', '${CPF}', encode(sha256('senha123'::bytea), 'hex'));
INSERT INTO menu_items (restaurant_id, name, price, category, available)
  VALUES ('${STORE}', 'Pizza Fuzz', 40.00, 'Pizzas', true);
INSERT INTO item_variants (menu_item_id, group_name, name, price_delta, required)
  SELECT id, 'Borda', 'Catupiry', 5.00, false FROM menu_items WHERE restaurant_id = '${STORE}';
INSERT INTO pos_devices (restaurant_id, label, acquirer, serial) VALUES ('${STORE}', 'Maquininha Fuzz', 'stone', 'SN-${STAMP}');
SQL
ITEM=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${STORE}'")
VARIANT=$(psql "$DATABASE_URL" -tAc "SELECT v.id FROM item_variants v JOIN menu_items m ON m.id = v.menu_item_id WHERE m.restaurant_id='${STORE}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${PORT}" -t "$ROOT" >"$LOG" 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "$BASE/system/health.php" && break; sleep 0.2; done

post() {  # post TOKEN ROTA JSON
  curl -s -X POST "$BASE/$2" -H "Content-Type: application/json" -H "Authorization: Bearer $1" \
    -H "X-Idempotency-Key: $(gen_uuid)" -d "$3"
}
otp_login() {  # otp_login TELEFONE PROPÓSITO -> JSON do login
  local code
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"$2\",\"phone\":\"$1\",\"full_name\":\"Cliente Fuzz Fundo\"}" | jq -er '.dev_code')
  curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"$2\",\"phone\":\"$1\",\"code\":\"$code\"}"
}
partner() {  # partner TIPO LOGIN
  curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
    -d "{\"kind\":\"$1\",\"login_code\":\"$2\",\"secret\":\"senha123\"}" | jq -er '.access_token'
}

echo "== logins =="
ADMIN=$(otp_login "$ADMIN_PHONE" login | jq -er '.access_token') || fail "login do admin"
CUST_LOGIN=$(otp_login "$CUST_PHONE" signup)
CUST=$(echo "$CUST_LOGIN" | jq -er '.access_token') || fail "login do cliente"
CUST_REFRESH=$(echo "$CUST_LOGIN" | jq -er '.refresh_token')
CUST_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM users WHERE phone='${CUST_PHONE}'")
STAFF=$(partner restaurant "$CNPJ") || fail "login da loja"
COURIER=$(partner courier "$CPF") || fail "login do entregador"

# CPF do cliente: cupom, fidelidade e candidatura a entregador exigem.
CUST_CPF="$(gen_cpf)"
psql_run -c "UPDATE users SET cpf = '${CUST_CPF}' WHERE id = '${CUST_ID}'"

echo "== pedidos de verdade, um em cada estado que as rotas pedem =="
ADDR=$(post "$CUST" addresses/create.php '{"street":"Rua Fuzz","number":"10","city":"São Paulo","city_ibge_code":"'"$CITY"'","state":"SP","postal_code":"01001000","lat":-23.56,"lng":-46.64,"is_default":true}' | jq -er '.id') || fail "endereço"
# Um endereço nunca usado: é o que a edição e a exclusão recebem (o usado vira
# versão nova a cada edição -- migração 043 -- e sumiria do resto do teste).
ADDR2=$(post "$CUST" addresses/create.php '{"street":"Rua Fuzz Dois","city":"São Paulo","city_ibge_code":"'"$CITY"'","state":"SP","postal_code":"01001000","lat":-23.561,"lng":-46.641}' | jq -er '.id') || fail "endereço 2"
post "$COURIER" couriers/shift.php '{"action":"start"}' >/dev/null
post "$COURIER" couriers/position.php '{"lat":-23.5505,"lng":-46.6333}' >/dev/null

checkout() {  # checkout MÉTODO [EXTRA_JSON] -> id do pedido
  post "$CUST" cart/add_item.php "{\"restaurant_id\":\"${STORE}\",\"menu_item_id\":${ITEM},\"quantity\":1}" >/dev/null
  post "$CUST" orders/checkout.php "{\"restaurant_id\":\"${STORE}\",\"address_id\":${ADDR},\"payment_method\":\"$1\"${2:-}}" | jq -er '.order.id'
}
to_courier() {  # to_courier PEDIDO: paga (dinheiro), loja prepara, entregador aceita -> id da oferta
  post "$CUST" payments/pay.php "{\"order_id\":$1}" >/dev/null
  post "$STAFF" orders/status.php "{\"order_id\":$1,\"to\":\"preparing\"}" >/dev/null
  post "$STAFF" orders/status.php "{\"order_id\":$1,\"to\":\"ready\"}" >/dev/null
  local offer
  offer=$(curl -s "$BASE/couriers/offers.php" -H "Authorization: Bearer $COURIER" | jq -er "[.offers[] | select(.order_id == $1)][0].offer_id") || return 1
  post "$COURIER" couriers/accept_offer.php "{\"offer_id\":${offer}}" | jq -e '.offer.state == "accepted"' >/dev/null || return 1
  echo "$offer"
}

# Entregue (pra avaliação): o código de entrega sai do banco, como o cliente leria no app.
ORDER_DONE=$(checkout cash ',"change_for":100') || fail "checkout do pedido entregue"
to_courier "$ORDER_DONE" >/dev/null || fail "corrida do pedido entregue"
post "$STAFF" orders/status.php "{\"order_id\":${ORDER_DONE},\"to\":\"delivering\"}" >/dev/null
DCODE=$(psql "$DATABASE_URL" -tAc "SELECT delivery_code FROM orders WHERE id=${ORDER_DONE}")
post "$COURIER" couriers/deliver.php "{\"order_id\":${ORDER_DONE},\"delivery_code\":\"${DCODE}\"}" >/dev/null
[ "$(psql "$DATABASE_URL" -tAc "SELECT status FROM orders WHERE id=${ORDER_DONE}")" = "delivered" ] || fail "pedido não foi entregue"

# Em rota (pra entrega, ocorrência, retirada e maquininha do entregador).
ORDER=$(checkout cash ',"change_for":100') || fail "checkout"
OFFER=$(to_courier "$ORDER") || fail "corrida"
post "$STAFF" orders/status.php "{\"order_id\":${ORDER},\"to\":\"delivering\"}" >/dev/null

# Aguardando pagamento (pra trocar a forma e pagar com cartão).
ORDER_PAY=$(checkout mp_card) || fail "checkout no cartão"

# Pix manual com comprovante pendente na fila da loja.
ORDER_PIX=$(checkout pix_manual) || fail "checkout no Pix manual"
psql_run <<SQL
INSERT INTO payments (order_id, provider, amount, status) VALUES (${ORDER_PIX}, 'offline', 47.00, 'created');
INSERT INTO payment_proofs (payment_id, order_id, restaurant_id, storage_key, sha256)
  SELECT id, ${ORDER_PIX}, '${STORE}', 'pix/fuzz-${STAMP}.jpg', repeat('a', 64) FROM payments WHERE order_id = ${ORDER_PIX};
SQL

# Um carrinho aberto, pras rotas de item de carrinho.
CART_ITEM=$(post "$CUST" cart/add_item.php "{\"restaurant_id\":\"${STORE}\",\"menu_item_id\":${ITEM},\"quantity\":1,\"variant_ids\":[${VARIANT}]}" | jq -er '.items[0].id') || fail "carrinho aberto"

echo "== o que o cliente tem de seu, e o que o admin tem pra decidir =="
PENDING_STORE="$(gen_uuid)"
psql_run <<SQL
INSERT INTO saved_cards (user_id, mp_card_id, brand, last4, kind, exp_month, exp_year)
  VALUES ('${CUST_ID}', 'card-fdeep-${STAMP}', 'visa', '4242', 'credit', 12, 2035);
INSERT INTO wallet_credits (user_id, order_id, amount, state, expires_at)
  VALUES ('${CUST_ID}', ${ORDER_DONE}, 5.00, 'offered', now() + interval '30 days');
INSERT INTO loyalty_rewards (label, kind, value, cost) VALUES ('Brinde Fuzz', 'fixed', 5, 1);
INSERT INTO loyalty_entries (user_id, points, origin, origin_id, memo)
  VALUES ('${CUST_ID}', 500, 'adjustment', 'fdeep-${STAMP}', 'pontos do fuzz');
INSERT INTO refunds (order_id, refund_key, amount, channel, payer, cause)
  VALUES (${ORDER_DONE}, gen_random_uuid(), 10.00, 'none', 'platform', 'wrong_item');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category)
  VALUES ('${PENDING_STORE}', 'Loja Aguardando Fuzz', '$(gen_cnpj)', '${CITY}', 'Pizza');
SQL

# Ids pro admin: o registro mais novo de cada tabela (o admin enxerga tudo).
last() { psql "$DATABASE_URL" -tAc "SELECT coalesce(max(id)::text, '1') FROM $1"; }
export FUZZ_BASE="$BASE" FUZZ_LOG="$LOG" FUZZ_STORE="$STORE" FUZZ_ITEM="$ITEM" FUZZ_VARIANT="$VARIANT" \
  FUZZ_ADDR="$ADDR" FUZZ_ADDR2="$ADDR2" FUZZ_ORDER="$ORDER" FUZZ_ORDER_DONE="$ORDER_DONE" FUZZ_ORDER_PAY="$ORDER_PAY" \
  FUZZ_OFFER="$OFFER" FUZZ_CART_ITEM="$CART_ITEM" FUZZ_CITY="$CITY" FUZZ_CPF="$CUST_CPF" FUZZ_PHONE="$CUST_PHONE" \
  FUZZ_CUST="$CUST" FUZZ_CUST_REFRESH="$CUST_REFRESH" FUZZ_STAFF="$STAFF" FUZZ_COURIER="$COURIER" FUZZ_ADMIN="$ADMIN" \
  FUZZ_CARD="$(psql "$DATABASE_URL" -tAc "SELECT id FROM saved_cards WHERE user_id='${CUST_ID}'")" \
  FUZZ_CREDIT="$(psql "$DATABASE_URL" -tAc "SELECT id FROM wallet_credits WHERE user_id='${CUST_ID}'")" \
  FUZZ_REWARD="$(last loyalty_rewards)" FUZZ_PENDING_STORE="$PENDING_STORE" \
  FUZZ_POS="$(psql "$DATABASE_URL" -tAc "SELECT id FROM pos_devices WHERE restaurant_id='${STORE}'")" \
  FUZZ_STAFF_ACCOUNT="$(psql "$DATABASE_URL" -tAc "SELECT id FROM partner_accounts WHERE user_id='${STAFF_ID}'")" \
  FUZZ_PROOF="$(psql "$DATABASE_URL" -tAc "SELECT id FROM payment_proofs WHERE order_id=${ORDER_PIX}")" \
  FUZZ_REFUND="$(psql "$DATABASE_URL" -tAc "SELECT max(id) FROM refunds WHERE order_id=${ORDER_DONE}")" \
  FUZZ_BANNER="$(last promo_banners)" FUZZ_APP_ERROR="$(last app_errors)" FUZZ_OVERRIDE="$(last policy_overrides)" \
  FUZZ_APPLICATION="$(psql "$DATABASE_URL" -tAc "SELECT id FROM courier_applications ORDER BY created_at DESC LIMIT 1")" \
  FUZZ_DISPUTE="$(last disputes)" FUZZ_INCIDENT="$(last delivery_incidents)" FUZZ_PAYOUT="$(last payouts)" \
  FUZZ_SETTLEMENT_PROOF="$(last settlement_proofs)" FUZZ_INTENT="$(last cash_settlement_intents)"

echo "== cada campo de cada rota, um valor ruim por vez =="
php "$ROOT/tests/support/fuzz_deep.php" || fail "alguma rota deu 5xx com entrada ruim (lista acima)"

echo "OK: fuzz profundo sem nenhum 5xx"
