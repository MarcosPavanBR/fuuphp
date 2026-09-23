#!/usr/bin/env bash
# Smoke test do carrinho incremental (Fase 3): adicionar item com variação,
# recálculo de subtotal, item indisponível barrado, troca de loja com
# carrinho vazio permitida, com carrinho cheio barrada, remoção e
# atualização de quantidade.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8096
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3509502"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-cart-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

R1="$(gen_uuid)"
R2="$(gen_uuid)"

echo "== semear duas lojas e um cardápio com grupo de variação obrigatório =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
  SELECT gen_random_uuid(), 'admin', 'Admin Cart Smoke', 'admin-cart-smoke@test.com'
  WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin-cart-smoke@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'admin-cart-smoke@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, category, approved_at)
VALUES
  ('${R1}', 'Loja Cart Smoke 1', '$(gen_cnpj)', '${CITY}', true, 'Lanches', now()),
  ('${R2}', 'Loja Cart Smoke 2', '$(gen_cnpj)', '${CITY}', true, 'Pizza', now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order)
VALUES
  ('${R1}', ARRAY['cash']::payment_method[], 0),
  ('${R2}', ARRAY['cash']::payment_method[], 0);

INSERT INTO menu_items (id, restaurant_id, name, price, available) VALUES
  (900001, '${R1}', 'Smoke Burger', 30.00, true),
  (900002, '${R1}', 'Smoke Indisponível', 20.00, false),
  (900003, '${R2}', 'Smoke Pizza', 40.00, true);

INSERT INTO item_variants (id, menu_item_id, group_name, name, price_delta, max_selections, required)
VALUES (900001, 900001, 'Ponto', 'Mal passado', 5.00, 1, true);
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-cart-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${R1}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cart Smoke\"}" | jq -er '.dev_code') || fail "otp_request falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

echo "== carrinho vazio no início =="
EMPTY=$(curl -s "$BASE/cart/show.php?restaurant_id=${R1}" "${AUTH[@]}")
[ "$(echo "$EMPTY" | jq -r '.order')" = "null" ] || fail "carrinho não começou vazio: $EMPTY"

echo "== item indisponível barrado =="
UNAVAIL=$(curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${R1}\",\"menu_item_id\":900002,\"quantity\":1}")
[ "$(echo "$UNAVAIL" | jq -r '.code')" = "item_unavailable" ] || fail "item indisponível não foi barrado: $UNAVAIL"

echo "== adicionar item com variação: preço soma certo =="
ADD1=$(curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${R1}\",\"menu_item_id\":900001,\"quantity\":1,\"variant_ids\":[900001]}")
[ "$(echo "$ADD1" | jq -r '.order.subtotal')" = "35.00" ] || fail "preço com variação errado (esperava 35.00): $ADD1"
ORDER_ID=$(echo "$ADD1" | jq -r '.order.id')
ITEM1_ID=$(echo "$ADD1" | jq -r '.items[0].id')

echo "== trocar de loja com carrinho CHEIO precisa dar 409 =="
CONFLICT=$(curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${R2}\",\"menu_item_id\":900003,\"quantity\":1}")
[ "$(echo "$CONFLICT" | jq -r '.code')" = "cart_restaurant_conflict" ] || fail "troca de loja com carrinho cheio não foi barrada: $CONFLICT"

echo "== segundo item na mesma loja: subtotal acumula =="
ADD2=$(curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${R1}\",\"menu_item_id\":900001,\"quantity\":2,\"variant_ids\":[900001]}")
[ "$(echo "$ADD2" | jq -r '.order.subtotal')" = "105.00" ] || fail "subtotal não acumulou certo (esperava 105.00): $ADD2"
ITEM2_ID=$(echo "$ADD2" | jq -r '.items[1].id')

echo "== update_quantity recalcula subtotal =="
UPD=$(curl -s -X POST "$BASE/cart/update_quantity.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_item_id\":${ITEM2_ID},\"quantity\":1}")
[ "$(echo "$UPD" | jq -r '.order.subtotal')" = "70.00" ] || fail "update_quantity não recalculou certo (esperava 70.00): $UPD"

echo "== remove_item tira o item e recalcula =="
REM=$(curl -s -X POST "$BASE/cart/remove_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_item_id\":${ITEM2_ID}}")
[ "$(echo "$REM" | jq -r '.order.subtotal')" = "35.00" ] || fail "remove_item não recalculou certo (esperava 35.00): $REM"
[ "$(echo "$REM" | jq '.items | length')" = "1" ] || fail "remove_item não tirou a linha certa: $REM"

echo "== esvaziar o carrinho e trocar de loja funciona sem erro =="
curl -s -X POST "$BASE/cart/remove_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_item_id\":${ITEM1_ID}}" >/dev/null
SWITCH=$(curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${R2}\",\"menu_item_id\":900003,\"quantity\":1}")
[ "$(echo "$SWITCH" | jq -r '.order.restaurant_id')" = "$R2" ] || fail "não trocou de loja com carrinho vazio: $SWITCH"

echo "OK: módulo de carrinho (Fase 3) passou no smoke test"
