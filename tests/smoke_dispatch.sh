#!/usr/bin/env bash
# Smoke test da tela 15.1 -- "Sem entregador disponível": o relógio que começa
# quando o pedido fica pronto sem ninguém pra levar, as três saídas (turbinar,
# retirar, cancelar) e o cancelamento automático com devolução integral.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8107
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"
# O frete não é mais mandado pelo cliente (14.3): é a tarifa base da
# política semeada abaixo, e o teste confere que ela chegou ao pedido.
FREIGHT="6.90"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-dispatch-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"
COURIER_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
CPF="$(gen_cpf)"
ACCESS_CODE="135791"
STAMP="$(date +%s%N)"

echo "== semear loja com coordenada, um entregador e o cardápio =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_USER_ID}',   'restaurant_staff', 'Staff Dispatch Smoke', 'staff-dispatch-${STAMP}@test.com'),
  ('${COURIER_USER_ID}', 'courier',          'Entregador Tardio',    'tardio-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cancel_fee, no_courier_timeout, delivery_base_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         15.00, interval '15 min', 6.90, id
  FROM users WHERE email = 'staff-dispatch-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Dispatch Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO couriers (id, user_id, city_ibge_code, active)
VALUES ('${COURIER_ID}', '${COURIER_USER_ID}', '${CITY}', true);

INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash)
VALUES ('${COURIER_USER_ID}', 'courier', '${COURIER_ID}', '${CPF}',
  encode(sha256('${ACCESS_CODE}'::bytea), 'hex'));

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Dispatch Smoke', 40.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-dispatch-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Dispatch\"}" | jq -er '.dev_code') || fail "otp falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

# Endereço a ~1,4 km da loja: é o que faz "· 1,2 km de você" ter de onde sair.
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Dispatch","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5630,"lng":-46.6333,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

# Pedido pago e levado até 'ready' -- o estado em que a 15.1 existe.
ready_order() {
  local method="$1" extra=""
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  [ "$method" = "cash" ] && extra=',"change_for":100.00'
  local id
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"${method}\"}" \
    | jq -er '.order.id') || fail "checkout $method falhou"
  local body='{"order_id":'"$id"'}'
  [ "$method" = "mp_card" ] && body='{"order_id":'"$id"',"card_token":"APRO-token-dispatch"}'
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "$body" >/dev/null
  for to in preparing ready; do
    curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
      -d "{\"order_id\":${id},\"to\":\"${to}\"}" >/dev/null
  done
  echo "$id"
}

echo "== pedido pronto sem entregador começa o relógio e vira oferta =="
O_CASH=$(ready_order cash)
[ "$(query "SELECT delivery_fee FROM orders WHERE id=${O_CASH}")" = "${FREIGHT}" ] \
  || fail "o frete do pedido não veio da tarifa da política"
[ "$(query "SELECT no_courier_since IS NOT NULL FROM orders WHERE id=${O_CASH}")" = "t" ] \
  || fail "o relógio da 15.1 não começou quando o pedido ficou pronto"
[ "$(query "SELECT count(*) FROM offers WHERE order_id=${O_CASH} AND state='open'")" = "1" ] \
  || fail "pedido pronto não virou oferta aberta"

DISPATCH=$(curl -s "$BASE/orders/dispatch_status.php?id=${O_CASH}" "${AUTH[@]}")
[ "$(echo "$DISPATCH" | jq -r '.searching')" = "true" ] || fail "dispatch_status não está procurando: $DISPATCH"
[ "$(echo "$DISPATCH" | jq -r '.harder_than_usual')" = "false" ] || fail "ficou 'difícil' no primeiro segundo: $DISPATCH"
[ "$(echo "$DISPATCH" | jq -r '.timeout_seconds')" = "900" ] || fail "prazo não veio da política: $DISPATCH"
[ "$(echo "$DISPATCH" | jq -r '.auto_cancel_in > 800')" = "true" ] || fail "contagem pro auto-cancel errada: $DISPATCH"
[ "$(echo "$DISPATCH" | jq -r '.options.pickup.refund')" = "6.9" ] || fail "retirada não devolve o frete: $DISPATCH"
[ "$(echo "$DISPATCH" | jq -r '.options.pickup.distance_km > 0')" = "true" ] || fail "distância até a loja não calculou: $DISPATCH"
[ "$(echo "$DISPATCH" | jq -r '.options.cancel.payer')" = "platform" ] || fail "quem paga a falha de despacho somos nós: $DISPATCH"

echo "== turbinar o frete: cliente paga a mais e o entregador VÊ o bônus =="
[ "$(echo "$DISPATCH" | jq -r '.options.boost.available')" = "true" ] || fail "dinheiro deveria poder turbinar: $DISPATCH"
BOOST=$(curl -s -X POST "$BASE/orders/dispatch_action.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_CASH},\"action\":\"boost\"}")
[ "$(echo "$BOOST" | jq -r '.boosted')" = "4" ] || fail "turbo não aplicou: $BOOST"
[ "$(echo "$BOOST" | jq -r '.order.surge_fee')" = "4.00" ] || fail "surge_fee não subiu: $BOOST"
[ "$(query "SELECT bonus FROM offers WHERE order_id=${O_CASH} AND state='open'")" = "4.00" ] \
  || fail "o bônus não chegou na oferta -- turbinar sem o entregador ver não é turbinar"
# 40,00 do prato + 6,90 de frete + 4,00 de turbo: o turbo entra no total
# porque é coluna gerada, não um número somado na mão em algum lugar.
[ "$(echo "$BOOST" | jq -r '.order.total')" = "50.90" ] || fail "total não somou o turbo: $BOOST"

echo "== pedido já pago não turbina (não há segunda cobrança) =="
O_CARD=$(ready_order mp_card)
D_CARD=$(curl -s "$BASE/orders/dispatch_status.php?id=${O_CARD}" "${AUTH[@]}")
[ "$(echo "$D_CARD" | jq -r '.options.boost.available')" = "false" ] || fail "cartão não pode turbinar: $D_CARD"
[ "$(echo "$D_CARD" | jq -r '.options.boost.reason')" != "null" ] || fail "opção desabilitada sem motivo escrito: $D_CARD"
DENIED=$(curl -s -X POST "$BASE/orders/dispatch_action.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_CARD},\"action\":\"boost\"}")
[ "$(echo "$DENIED" | jq -r '.code')" = "already_paid" ] || fail "servidor deixou turbinar pedido pago: $DENIED"

echo "== retirar na loja: frete zera, oferta morre e o frete pago volta =="
PICKUP=$(curl -s -X POST "$BASE/orders/dispatch_action.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_CARD},\"action\":\"pickup\"}")
[ "$(echo "$PICKUP" | jq -r '.order.pickup_by_customer')" = "true" ] || fail "não marcou retirada: $PICKUP"
[ "$(echo "$PICKUP" | jq -r '.order.delivery_fee')" = "0.00" ] || fail "frete não zerou: $PICKUP"
[ "$(echo "$PICKUP" | jq -r '.order.total')" = "40.00" ] || fail "total não caiu o frete: $PICKUP"
[ "$(echo "$PICKUP" | jq -r '.refunded_amount')" = "6.9" ] || fail "frete pago não voltou: $PICKUP"
[ "$(echo "$PICKUP" | jq -r '.refund.amount')" = "6.90" ] || fail "reembolso do frete não foi gravado: $PICKUP"
[ "$(query "SELECT count(*) FROM offers WHERE order_id=${O_CARD} AND state='open'")" = "0" ] \
  || fail "oferta continuou aberta depois de virar retirada"
[ "$(query "SELECT no_courier_since FROM orders WHERE id=${O_CARD}")" = "" ] \
  || fail "o relógio continuou correndo depois da retirada"
# Devolução PARCIAL não desfaz a cobrança: o cliente continua tendo pago a comida.
[ "$(query "SELECT status FROM payments WHERE order_id=${O_CARD}")" = "approved" ] \
  || fail "devolver o frete marcou o pagamento inteiro como estornado"
[ "$(curl -s "$BASE/orders/dispatch_status.php?id=${O_CARD}" "${AUTH[@]}" | jq -r '.searching')" = "false" ] \
  || fail "pedido de retirada continuou 'procurando entregador'"

echo "== retirada é fechada pela loja no balcão, sem entregador no meio =="
for to in delivering delivered; do
  RES=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
    -d "{\"order_id\":${O_CARD},\"to\":\"${to}\"}")
  [ "$(echo "$RES" | jq -r '.order.status')" = "$to" ] || fail "loja não conseguiu registrar ${to}: $RES"
done
# ...e isso NÃO vale pra pedido com entrega: quem confirma que chegou é quem chegou.
O_GUARD=$(ready_order cash)
GUARD=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_GUARD},\"to\":\"delivered\"}")
[ "$(echo "$GUARD" | jq -r '.code')" = "forbidden" ] || fail "loja fechou entrega que não era retirada: $GUARD"

echo "== entregador aceitando para o relógio =="
COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') || fail "login do entregador falhou"
COURIER_AUTH=(-H "Authorization: Bearer $COURIER_TOKEN")
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"action":"start"}' >/dev/null
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"lat":-23.5505,"lng":-46.6333}' >/dev/null  # na porta da loja: dentro do raio da 1ª rodada
OFFER_ID=$(curl -s "$BASE/couriers/offers.php" "${COURIER_AUTH[@]}" \
  | jq -er ".offers[] | select(.order_id == ${O_GUARD}) | .offer_id") || fail "oferta do pedido não apareceu pro entregador"
curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" -d "{\"offer_id\":${OFFER_ID}}" >/dev/null
[ "$(query "SELECT no_courier_since FROM orders WHERE id=${O_GUARD}")" = "" ] \
  || fail "aceitar a corrida não parou o relógio da 15.1"
[ "$(curl -s "$BASE/orders/dispatch_status.php?id=${O_GUARD}" "${AUTH[@]}" | jq -r '.searching')" = "false" ] \
  || fail "tela continuou procurando depois de alguém aceitar"
DENIED2=$(curl -s -X POST "$BASE/orders/dispatch_action.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_GUARD},\"action\":\"pickup\"}")
[ "$(echo "$DENIED2" | jq -r '.code')" = "courier_assigned" ] || fail "deixou virar retirada com entregador a caminho: $DENIED2"

echo "== cancelar por falta de entregador: integral, sem taxa, por nossa conta =="
O_LOST=$(ready_order mp_card)
QUOTE=$(curl -s "$BASE/orders/cancel_quote.php?id=${O_LOST}" "${AUTH[@]}")
[ "$(echo "$QUOTE" | jq -r '.can_cancel')" = "true" ] || fail "pedido pronto sem entregador tem que poder cancelar: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.no_courier')" = "true" ] || fail "cotação não reconheceu a causa 15.1: $QUOTE"
# A cozinha já começou (o pedido está pronto!) e mesmo assim a taxa é zero:
# a culpa não é de quem pediu.
[ "$(echo "$QUOTE" | jq -r '.quote.fee')" = "0" ] || fail "cobrou taxa de quem esperou entregador: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.amount')" = "46.9" ] || fail "devolução não foi integral: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.payer')" = "platform" ] || fail "a comida já feita é paga por nós: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.reasons | length')" = "1" ] || fail "não se pergunta motivo a quem não teve culpa: $QUOTE"

CANCEL=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_LOST},\"to\":\"cancelled\",\"reason\":\"Nenhum entregador aceitou a corrida\"}")
[ "$(echo "$CANCEL" | jq -r '.order.status')" = "cancelled" ] || fail "não cancelou pedido pronto: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.cause')" = "no_courier" ] || fail "causa gravada errada: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.payer')" = "platform" ] || fail "pagador gravado errado: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.amount')" = "46.90" ] || fail "valor do estorno errado: $CANCEL"

echo "== passados os 15 min, o sistema cancela sozinho e devolve tudo =="
O_AUTO=$(ready_order mp_card)
# O relógio é empurrado pra trás em vez de esperar 15 minutos de verdade --
# é o mesmo carimbo que o processo lê.
psql_run -c "UPDATE orders SET no_courier_since = now() - interval '16 minutes' WHERE id = ${O_AUTO};"
SWEEP=$(php "$ROOT/bin/auto_cancel_no_courier.php")
echo "$SWEEP" | grep -q "cancelado apos 16 min" || fail "varredura não cancelou o pedido vencido: $SWEEP"
[ "$(query "SELECT status FROM orders WHERE id=${O_AUTO}")" = "cancelled" ] || fail "pedido vencido continuou pronto"
[ "$(query "SELECT cause FROM refunds WHERE order_id=${O_AUTO}")" = "no_courier" ] || fail "estorno automático com causa errada"
[ "$(query "SELECT amount FROM refunds WHERE order_id=${O_AUTO}")" = "46.90" ] || fail "estorno automático não foi integral"
[ "$(query "SELECT payer FROM refunds WHERE order_id=${O_AUTO}")" = "platform" ] || fail "estorno automático cobrado da loja"
[ "$(query "SELECT actor_kind FROM order_events WHERE order_id=${O_AUTO} AND to_status='cancelled'")" = "system" ] \
  || fail "o cancelamento automático tem que aparecer como do sistema na linha do tempo"
[ "$(query "SELECT count(*) FROM offers WHERE order_id=${O_AUTO} AND state='open'")" = "0" ] \
  || fail "oferta continuou aberta em pedido cancelado"

echo "== a varredura não encosta em quem ainda está dentro do prazo =="
O_SAFE=$(ready_order cash)
php "$ROOT/bin/auto_cancel_no_courier.php" >/dev/null
[ "$(query "SELECT status FROM orders WHERE id=${O_SAFE}")" = "ready" ] || fail "varredura cancelou pedido dentro do prazo"
# Rodar duas vezes no mesmo pedido vencido não cria segundo estorno.
php "$ROOT/bin/auto_cancel_no_courier.php" >/dev/null
[ "$(query "SELECT count(*) FROM refunds WHERE order_id=${O_AUTO}")" = "1" ] || fail "varredura duplicou o reembolso"

echo "== pedido dos outros não tem tela de despacho =="
PHONE2="119$(( RANDOM % 90000000 + 10000000 ))"
CODE2=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Intruso Dispatch\"}" | jq -er '.dev_code')
ACCESS2=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"code\":\"$CODE2\"}" | jq -er '.access_token')
[ "$(curl -s "$BASE/orders/dispatch_status.php?id=${O_CASH}" -H "Authorization: Bearer $ACCESS2" | jq -r '.code')" = "order_not_found" ] \
  || fail "outro cliente viu o despacho de pedido alheio"
[ "$(curl -s -X POST "$BASE/orders/dispatch_action.php" -H "Content-Type: application/json" \
   -H "Authorization: Bearer $ACCESS2" -d "{\"order_id\":${O_CASH},\"action\":\"pickup\"}" | jq -r '.code')" = "order_not_found" ] \
  || fail "outro cliente mexeu no despacho de pedido alheio"
[ "$(curl -s -X POST "$BASE/orders/dispatch_action.php" -H "Content-Type: application/json" \
   "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_CASH},\"action\":\"pickup\"}" | jq -r '.code')" = "forbidden" ] \
  || fail "a loja escolheu a saída no lugar do cliente"

echo "OK: despacho sem entregador (Fase 15.1) passou no smoke test"
