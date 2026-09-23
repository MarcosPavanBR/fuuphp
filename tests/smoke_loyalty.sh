#!/usr/bin/env bash
# Smoke test da tela 2.3 — Fidelidade (migração 029):
#
#  - pontos nascem na ENTREGA (1 por real de subtotal), nunca no pagamento;
#  - saldo = soma dos lançamentos; a tela reproduz o mock ("1.240 de 1.500 ·
#    Faltam 260 pontos para o cupom de R$ 20");
#  - trocar sem saldo é recusado; trocar com saldo cria um cupom PESSOAL que
#    só o dono consegue usar, e desconta como qualquer cupom;
#  - estorno de pedido que deu ponto devolve os pontos na proporção.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8123
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-loyalty-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

RESTAURANT_ID="$(gen_uuid)"; STAFF_ID="$(gen_uuid)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"; STAMP="$(date +%s%N)"

echo "== semear loja que aceita dinheiro =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES ('${STAFF_ID}', 'restaurant_staff', 'Staff Pontos', 'staff-loyalty-${STAMP}@test.com');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Pontos Smoke', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);
INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['cash','mp_card']::payment_method[], 300.00, 0);
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}', '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Pizza Pontos', 64.00, 'Pizzas', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-loyalty-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

STAFF=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')")

customer() { # customer <nome> -> imprime o token (com CPF e endereço)
  local phone code token
  phone="119$(( RANDOM % 90000000 + 10000000 ))"
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"$phone\",\"full_name\":\"$1\"}" | jq -er '.dev_code')
  token=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"$phone\",\"code\":\"$code\"}" | jq -er '.access_token')
  curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" -H "Authorization: Bearer $token" \
    -d "{\"cpf\":\"$(php "$ROOT/tests/support/random_cpf.php")\"}" >/dev/null
  curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" -H "Authorization: Bearer $token" \
    -d '{"street":"Rua dos Pontos","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.552,"lng":-46.6333,"is_default":true}' >/dev/null
  echo "$token"
}
TOKEN_A=$(customer "Ana Pontos"); A=(-H "Authorization: Bearer $TOKEN_A")
TOKEN_B=$(customer "Bia Pontos"); B=(-H "Authorization: Bearer $TOKEN_B")
USER_A=$(query "SELECT id FROM users WHERE full_name='Ana Pontos' ORDER BY created_at DESC LIMIT 1")

order_cash() { # order_cash <auth...> -> id do pedido pago em dinheiro (2 pizzas = R$ 128 de itens)
  local addr id
  addr=$(curl -s "$BASE/addresses/list.php" "$@" | jq -r '.addresses[0].id')
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "$@" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":2}" >/dev/null
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "$@" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${addr},\"payment_method\":\"cash\"}" | jq -er '.order.id')
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "$@" \
    -d "{\"order_id\":${id}}" >/dev/null
  echo "$id"
}
# Retirada no balcão: a loja entrega na mão e registra 'delivered' (15.1).
deliver_pickup() {
  psql_run -c "UPDATE orders SET pickup_by_customer = true WHERE id=$1"
  for to in preparing ready delivering delivered; do
    curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF[@]}" -d "{\"order_id\":$1,\"to\":\"${to}\"}" >/dev/null
  done
  [ "$(query "SELECT status FROM orders WHERE id=$1")" = "delivered" ] || fail "pedido $1 não foi entregue"
}

echo "== pagar não dá ponto; entregar dá (1 por real de itens, frete fora) =="
O1=$(order_cash "${A[@]}")
[ "$(curl -s "$BASE/profile/loyalty.php" "${A[@]}" | jq -r '.balance')" = "0" ] || fail "ponto nasceu no pagamento"
deliver_pickup "$O1"
L=$(curl -s "$BASE/profile/loyalty.php" "${A[@]}")
[ "$(echo "$L" | jq -r '.balance')" = "128" ] || fail "entrega de R\$ 128 em itens não deu 128 pontos: $L"
[ "$(echo "$L" | jq -r '.history[0].memo')" = "Pedido #$(query "SELECT public_code FROM orders WHERE id=${O1}")" ] || fail "histórico sem o pedido: $L"
[ "$(curl -s "$BASE/profile/show.php" "${A[@]}" | jq -r '.stats.loyalty_points')" = "128" ] || fail "perfil sem os pontos"
# Rodar o acerto da entrega de novo não dá ponto de novo (origem única).
php -r 'require $argv[1]; $pdo = db(); ledger_order_delivered($pdo, fetch_order($pdo, (int) $argv[2]), null);
  loyalty_earn_for_order($pdo, fetch_order($pdo, (int) $argv[2]));' "$ROOT/lib/bootstrap.php" "$O1"
[ "$(query "SELECT count(*) FROM loyalty_entries WHERE origin='order' AND order_id=${O1}")" = "1" ] || fail "ponto em dobro"

echo "== sem saldo, a troca é recusada; a tela mostra a meta =="
[ "$(echo "$L" | jq -r '.goal.label')" = "R\$ 10 de desconto" ] || fail "meta errada com 128 pontos: $L"
R10=$(echo "$L" | jq -r '.rewards[] | select(.cost == 800) | .id')
[ "$(curl -s -X POST "$BASE/profile/loyalty.php" -H "Content-Type: application/json" "${A[@]}" -d "{\"reward_id\":${R10}}" | jq -r '.code')" = "not_enough_points" ] \
  || fail "trocou sem saldo"

echo "== com 1.240 pontos, o mock: 'Faltam 260 pontos para o cupom de R\$ 20' =="
psql_run -c "INSERT INTO loyalty_entries (user_id, points, origin, origin_id, memo) VALUES ('${USER_A}', 1112, 'adjustment', 'smoke-${STAMP}', 'Ajuste de teste')"
L=$(curl -s "$BASE/profile/loyalty.php" "${A[@]}")
[ "$(echo "$L" | jq -r '.balance')" = "1240" ] || fail "saldo não é a soma dos lançamentos: $L"
[ "$(echo "$L" | jq -r '.goal.cost')" = "1500" ] || fail "meta com 1.240 não é a de 1.500: $L"
[ "$(echo "$L" | jq -r '.goal.value')" = "20.00" ] || fail "meta não é o cupom de R\$ 20: $L"

echo "== trocar cria um cupom pessoal; o saldo desconta =="
REDEEM=$(curl -s -X POST "$BASE/profile/loyalty.php" -H "Content-Type: application/json" "${A[@]}" -d "{\"reward_id\":${R10}}")
CODE=$(echo "$REDEEM" | jq -er '.coupon.code') || fail "troca falhou: $REDEEM"
[ "$(echo "$REDEEM" | jq -r '.summary.balance')" = "440" ] || fail "saldo depois da troca errado: $REDEEM"
[ "$(echo "$REDEEM" | jq -r '.summary.history[0].memo')" = "Cupom R\$ 10" ] || fail "histórico sem a troca: $REDEEM"
[ "$(query "SELECT owner_user_id = '${USER_A}' AND payer = 'platform' FROM coupons WHERE code='${CODE}'")" = "t" ] || fail "cupom não é pessoal"

echo "== o cupom de pontos só vale pro dono =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${B[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
OTHER=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${B[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CODE}\"}")
[ "$(echo "$OTHER" | jq -r '.code')" = "coupon_audience" ] || fail "outra pessoa usou o cupom de pontos: $OTHER"
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${A[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
MINE=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${A[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CODE}\"}")
[ "$(echo "$MINE" | jq -r '.coupon.discount')" = "10" ] || fail "o dono não conseguiu usar o cupom: $MINE"

echo "== estorno de pedido entregue devolve os pontos na proporção =="
O2=$(order_cash "${B[@]}")
deliver_pickup "$O2"
USER_B=$(query "SELECT user_id FROM orders WHERE id=${O2}")
BEFORE=$(query "SELECT COALESCE(SUM(points),0) FROM loyalty_entries WHERE user_id='${USER_B}'")
EXPECTED=$(query "SELECT floor(subtotal)::int FROM orders WHERE id=${O2}")   # o carrinho de B já tinha 1 pizza
[ "$BEFORE" = "$EXPECTED" ] || fail "B não ganhou os pontos do pedido: $BEFORE (esperado $EXPECTED)"
# Estorno de metade do valor (ex.: item errado, tela 13.4), pela mesma função que o console usa.
php -r 'require $argv[1]; $pdo = db(); $o = fetch_order($pdo, (int) $argv[2]);
  record_refund($pdo, $o, ["amount" => round((float) $o["total"] / 2, 2), "fee" => 0, "channel" => "wallet_credit",
    "payer" => "platform", "cause" => "wrong_item"], null, true);' "$ROOT/lib/bootstrap.php" "$O2"
[ "$(query "SELECT COALESCE(SUM(points),0) FROM loyalty_entries WHERE user_id='${USER_B}'")" = "$(( EXPECTED - EXPECTED / 2 ))" ] \
  || fail "estorno de metade não devolveu metade dos pontos: $(query "SELECT points, memo FROM loyalty_entries WHERE user_id='${USER_B}'")"

echo "smoke_loyalty OK"
