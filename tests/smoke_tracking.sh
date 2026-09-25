#!/usr/bin/env bash
# Smoke test do acompanhamento pós-pedido (migração 011 + Fase 5 das
# telas): orders/show.php com a linha do tempo (order_events), o SSE de
# orders/track.php (snapshot imediato + evento ao vivo quando
# advance_order() roda em outro processo) e reviews/create.php (gate por
# status='delivered', uma avaliação por pedido).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8100
BASE="http://127.0.0.1:${PORT}/api/v1"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-tracking-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"

echo "== semear loja, política e cardápio =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
  SELECT gen_random_uuid(), 'admin', 'Admin Tracking Smoke', 'admin-tracking-smoke@test.com'
  WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin-tracking-smoke@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'admin-tracking-smoke@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at)
VALUES ('${RESTAURANT_ID}', 'Tracking Smoke Restaurant', '${CNPJ}', '3509502', true, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['cash']::payment_method[], 0);

INSERT INTO menu_items (restaurant_id, name, price, available)
VALUES ('${RESTAURANT_ID}', 'Prato Tracking Smoke', 40.00, true);
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-tracking-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== signup, endereço, carrinho, checkout e pagamento (dinheiro) =="
PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
OTHER_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Tracking Smoke\"}" | jq -er '.dev_code') || fail "otp_request falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Tracking","city":"Campinas","city_ibge_code":"3509502","state":"SP","postal_code":"13070000","lat":-22.9,"lng":-47.06,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null

ORDER_ID=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\"}" | jq -er '.order.id') || fail "checkout falhou"

KEY=$(gen_uuid)
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID}}" >/dev/null

echo "== orders/show.php devolve a linha do tempo (order_events) =="
SHOW=$(curl -s "$BASE/orders/show.php?id=${ORDER_ID}" "${AUTH[@]}")
EVENTS_COUNT=$(echo "$SHOW" | jq '.events | length')
[ "$EVENTS_COUNT" -ge 2 ] || fail "esperava pelo menos 2 eventos (cart->pending_payment, pending_payment->paid), veio $EVENTS_COUNT: $SHOW"
[ "$(echo "$SHOW" | jq -r '.events[-1].to_status')" = "paid" ] || fail "último evento não é 'paid': $SHOW"

echo "== ticket do acompanhamento ao vivo (SEG-03): só deste pedido, e não vale como access token =="
TICKET=$(curl -s -X POST "$BASE/orders/track_ticket.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID}}" | jq -er '.ticket') || fail "ticket não saiu"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/orders/show.php?id=${ORDER_ID}" -H "Authorization: Bearer $TICKET")" = "401" ] \
  || fail "o ticket do SSE abriu uma rota comum como se fosse access token"
[ "$(curl -s -o /dev/null -w '%{http_code}' -m 4 "$BASE/orders/track.php?id=$((ORDER_ID + 100000))&ticket=${TICKET}")" = "401" ] \
  || fail "ticket de um pedido abriu o acompanhamento de outro"
[ "$(curl -s -o /dev/null -w '%{http_code}' -m 4 "$BASE/orders/track.php?id=${ORDER_ID}&token=${ACCESS}")" = "401" ] \
  || fail "access token na URL ainda é aceito no SSE"
OTHER=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/orders/track_ticket.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"${OTHER_PHONE}\",\"code\":\"$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
      -d "{\"purpose\":\"signup\",\"phone\":\"${OTHER_PHONE}\",\"full_name\":\"Outro Cliente\"}" | jq -r '.dev_code')\"}" | jq -r '.access_token')" \
  -d "{\"order_id\":${ORDER_ID}}")
[ "$OTHER" = "403" ] || [ "$OTHER" = "404" ] || fail "outro cliente ganhou ticket do pedido alheio ($OTHER)"

echo "== orders/track.php (SSE): snapshot imediato tem o status atual =="
SNAPSHOT_FILE="/tmp/smoke-tracking-snapshot.txt"
timeout 4 curl -s -N "$BASE/orders/track.php?id=${ORDER_ID}&ticket=${TICKET}" --output "$SNAPSHOT_FILE" || true
grep -q '"status":"paid"' "$SNAPSHOT_FILE" || fail "snapshot inicial do SSE não trouxe status=paid: $(cat "$SNAPSHOT_FILE")"

echo "== orders/track.php (SSE): evento ao vivo quando advance_order roda em outro processo =="
LIVE_FILE="/tmp/smoke-tracking-live.txt"
( timeout 8 curl -s -N "$BASE/orders/track.php?id=${ORDER_ID}&ticket=${TICKET}" --output "$LIVE_FILE" ) &
CURL_PID=$!
sleep 2
psql_run -c "SELECT advance_order(${ORDER_ID}, 'preparing', NULL, 'system');" >/dev/null
wait "$CURL_PID" || true
grep -q '"to_status":"preparing"' "$LIVE_FILE" || fail "SSE não recebeu o evento ao vivo de 'preparing': $(cat "$LIVE_FILE")"

echo "== reviews/create.php: avaliar antes de 'delivered' é barrado (409) =="
EARLY=$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"rating\":5}")
[ "$(echo "$EARLY" | jq -r '.code')" = "order_not_delivered" ] || fail "avaliação antes de delivered não foi barrada: $EARLY"

echo "== avança até 'delivered' direto no banco (sem entregador: o foco aqui é a avaliação) =="
psql_run -c "SELECT advance_order(${ORDER_ID}, 'ready', NULL, 'system');" >/dev/null
psql_run -c "SELECT advance_order(${ORDER_ID}, 'delivering', NULL, 'system');" >/dev/null
psql_run -c "SELECT advance_order(${ORDER_ID}, 'delivered', NULL, 'system');" >/dev/null

echo "== reviews/create.php: gorjeta sem entregador é recusada (a cobrança está em smoke_payment_changes) =="
NOCOURIER=$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"rating\":5,\"courier_tip\":5}")
[ "$(echo "$NOCOURIER" | jq -r '.code')" = "tip_no_courier" ] || fail "gorjeta sem entregador passou: $NOCOURIER"

echo "== reviews/create.php: avaliação de verdade =="
REVIEW=$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"rating\":5,\"tags\":[\"Comida quente\",\"Chegou rápido\"],\"comment\":\"Muito bom!\"}")
[ "$(echo "$REVIEW" | jq -r '.review.rating')" = "5" ] || fail "avaliação não gravou a nota certa: $REVIEW"
[ "$(echo "$REVIEW" | jq -r '.review.tip_state')" = "none" ] || fail "avaliação sem gorjeta ficou com estado de gorjeta: $REVIEW"

echo "== reviews/create.php: segunda avaliação do mesmo pedido é barrada (409) =="
DUPLICATE=$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"rating\":3}")
[ "$(echo "$DUPLICATE" | jq -r '.code')" = "already_reviewed" ] || fail "avaliação duplicada não foi barrada: $DUPLICATE"

echo "OK: acompanhamento pós-pedido (Fase 5: timeline, SSE, avaliação) passou no smoke test"
