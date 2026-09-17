#!/usr/bin/env bash
# Smoke test do módulo catalog+ordering+checkout (migrações 002-004): sobe
# o servidor PHP embutido, semeia uma loja/cardápio/staff via psql e roda o
# fluxo ponta a ponta — inclusive os dois casos que mais importam:
# transição ilegal barrada (409) e papel sem permissão barrado (403).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8098
BASE="http://127.0.0.1:${PORT}/api/v1"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-ordering-server.log >&2 2>/dev/null || true; exit 1; }

# psql precisa de host/porta/usuário separados -- reaproveita o DATABASE_URL.
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }

RESTAURANT_ID="$(php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/')"
STAFF_USER_ID="$(php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/')"
CNPJ="11222333000181"

echo "== semear loja, política, cardápio e login de loja =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
  SELECT '${STAFF_USER_ID}', 'restaurant_staff', 'Staff Smoke', 'staff-smoke@test.com'
  WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'staff-smoke@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT 1, ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users ORDER BY created_at LIMIT 1
  ON CONFLICT (version) DO NOTHING;

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, commission_bps)
VALUES ('${RESTAURANT_ID}', 'Smoke Test Restaurant', '${CNPJ}', '3550308', true, 800);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['cash','mp_card']::payment_method[], 100.00, 20.00);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Pizza Smoke', 45.00, 'Pizzas', true)
RETURNING id \gset item1_
INSERT INTO item_variants (menu_item_id, group_name, name, price_delta, required)
VALUES (:item1_id, 'Borda', 'Catupiry', 6.00, false);
SQL
# senha do hash acima é "senha123"

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-ordering-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")
VARIANT_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM item_variants WHERE menu_item_id=${ITEM_ID}")

echo "== GET restaurante + cardápio (público) =="
MENU=$(curl -s "$BASE/restaurants/menu.php?id=${RESTAURANT_ID}")
[ "$(echo "$MENU" | jq '.items | length')" = "1" ] || fail "cardápio não veio como esperado: $MENU"

echo "== signup do cliente =="
PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
REQ=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Smoke\"}")
CODE=$(echo "$REQ" | jq -er '.dev_code') || fail "otp_request falhou: $REQ"
LOGIN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")
ACCESS=$(echo "$LOGIN" | jq -er '.access_token') || fail "login falhou: $LOGIN"

echo "== criar endereço =="
ADDR=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d '{"street":"Rua Smoke","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}')
ADDR_ID=$(echo "$ADDR" | jq -er '.id') || fail "endereço não criou: $ADDR"

echo "== checkout abaixo do mínimo precisa dar below_minimum_order =="
BELOW=$(curl -s -X POST "$BASE/orders/create.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"change_for\":5,\"items\":[{\"menu_item_id\":${ITEM_ID},\"quantity\":1}]}")
# um item já passa do mínimo (R$45 > R$20) -- então testa é o troco menor que o subtotal:
[ "$(echo "$BELOW" | jq -r '.code')" = "invalid_change_for" ] || fail "troco menor que subtotal não foi barrado: $BELOW"

echo "== checkout válido, com variação (preço tem que somar certo) =="
ORDER=$(curl -s -X POST "$BASE/orders/create.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\",\"delivery_fee\":8,\"items\":[{\"menu_item_id\":${ITEM_ID},\"quantity\":2,\"variant_ids\":[${VARIANT_ID}]}]}")
ORDER_ID=$(echo "$ORDER" | jq -er '.order.id') || fail "checkout não criou pedido: $ORDER"
[ "$(echo "$ORDER" | jq -r '.order.status')" = "pending_payment" ] || fail "pedido não avançou pra pending_payment: $ORDER"
[ "$(echo "$ORDER" | jq -r '.order.subtotal')" = "102.00" ] || fail "subtotal errado (esperava 102.00, 2x(45+6)): $ORDER"
[ "$(echo "$ORDER" | jq -r '.order.total')" = "110.00" ] || fail "total errado (esperava 110.00 = 102+8 frete): $ORDER"

echo "== login de loja =="
STAFF=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}")
STAFF_TOKEN=$(echo "$STAFF" | jq -er '.access_token') || fail "login de loja falhou: $STAFF"

echo "== loja pular direto pra 'ready' a partir de pending_payment precisa dar 409 =="
ILLEGAL=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"ready\"}")
[ "$(echo "$ILLEGAL" | jq -r '.code')" = "illegal_transition" ] || fail "transição ilegal não foi barrada: $ILLEGAL"

echo "== cliente pedir 'preparing' precisa dar 403 (papel não autorizado) =="
FORBIDDEN=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"preparing\"}")
[ "$(echo "$FORBIDDEN" | jq -r '.code')" = "forbidden" ] || fail "cliente conseguiu pedir transição de loja: $FORBIDDEN"

echo "== simula pagamento aprovado (pending_payment -> paid) e confere fila KDS =="
psql_run -c "SELECT advance_order(${ORDER_ID}, 'paid', NULL, 'system');" >/dev/null
KDS=$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}" -H "Authorization: Bearer $STAFF_TOKEN")
echo "$KDS" | jq -e ".orders[] | select(.id == ${ORDER_ID})" >/dev/null || fail "pedido pago não apareceu na fila KDS: $KDS"

echo "== loja avança paid -> preparing -> ready =="
P1=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"preparing\"}")
[ "$(echo "$P1" | jq -r '.order.status')" = "preparing" ] || fail "não avançou pra preparing: $P1"
P2=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"ready\"}")
[ "$(echo "$P2" | jq -r '.order.status')" = "ready" ] || fail "não avançou pra ready: $P2"

echo "OK: módulo catalog+ordering+checkout passou no smoke test"
