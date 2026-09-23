#!/usr/bin/env bash
# Smoke test do pedido agendado (Fase 14.4): faixas nascidas do horário da
# loja, vaga limitada pela capacidade que ELA declarou, o CHECK do banco
# segurando a última vaga, e a cozinha não vendo o pedido antes da hora.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8111
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-schedule-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
STAMP="$(date +%s%N)"

# A loja abre das 00:00 às 23:59 todos os dias pra que sempre exista faixa
# futura hoje, independentemente da hora em que o teste roda.
echo "== semear loja aberta o dia todo, sem agendamento ligado =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
VALUES ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Schedule Smoke', 'staff-schedule-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'staff-schedule-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, prep_minutes, slot_capacity)
VALUES ('${RESTAURANT_ID}', 'Schedule Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), 30, 0);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO business_hours (restaurant_id, dow, shift, opens, closes, last_order, active)
SELECT '${RESTAURANT_ID}', d, 'dinner', '00:00', '23:59', '23:59', true FROM generate_series(0, 6) d;

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Schedule Smoke', 35.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-schedule-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

new_customer() { # -> "TOKEN ADDR"
  local phone="119$(( RANDOM % 90000000 + 10000000 ))"
  local code token addr
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"$phone\",\"full_name\":\"Cliente Agenda\"}" | jq -er '.dev_code')
  token=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"$phone\",\"code\":\"$code\"}" | jq -er '.access_token')
  addr=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $token" \
    -d '{"street":"Rua Agenda","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.55,"lng":-46.63,"is_default":true}' | jq -er '.id')
  echo "$token $addr"
}

read -r TOKEN ADDR <<<"$(new_customer)"
AUTH=(-H "Authorization: Bearer $TOKEN")

STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

echo "== capacidade zero é 'não aceito agendamento', e a tela diz isso =="
SLOTS0=$(curl -s "$BASE/orders/slots.php?restaurant_id=${RESTAURANT_ID}" "${AUTH[@]}")
[ "$(echo "$SLOTS0" | jq -r '.scheduling_enabled')" = "false" ] || fail "loja sem capacidade não pode oferecer agendamento: $SLOTS0"
[ "$(echo "$SLOTS0" | jq -r '[.slots[] | length] | add')" = "0" ] || fail "ofereceu faixa com capacidade zero: $SLOTS0"

echo "== a loja liga o agendamento declarando a capacidade (11.4) =="
BADCAP=$(curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"slot_capacity":900}')
[ "$(echo "$BADCAP" | jq -r '.code')" = "invalid_slot_capacity" ] || fail "aceitou capacidade absurda: $BADCAP"

CAP=$(curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"slot_capacity":2}')
[ "$(echo "$CAP" | jq -r '.slot_capacity')" = "2" ] || fail "não salvou a capacidade: $CAP"

echo "== as faixas nascem do horário da loja, e só as futuras aparecem =="
SLOTS=$(curl -s "$BASE/orders/slots.php?restaurant_id=${RESTAURANT_ID}" "${AUTH[@]}")
[ "$(echo "$SLOTS" | jq -r '.scheduling_enabled')" = "true" ] || fail "agendamento não ligou: $SLOTS"
[ "$(echo "$SLOTS" | jq -r '.days | length')" = "4" ] || fail "deveria oferecer 4 dias: $SLOTS"
TODAY=$(echo "$SLOTS" | jq -r '.days[0].day')
COUNT=$(echo "$SLOTS" | jq -r ".slots[\"${TODAY}\"] | length")
[ "$COUNT" -gt 0 ] || fail "nenhuma faixa hoje, com a loja aberta o dia todo: $SLOTS"
# Toda faixa oferecida começa no futuro.
[ "$(echo "$SLOTS" | jq -r "[.slots[\"${TODAY}\"][] | .start] | map(. > (now | todate)) | all")" = "true" ] \
  || fail "ofereceu faixa que já começou: $SLOTS"
[ "$(echo "$SLOTS" | jq -r ".slots[\"${TODAY}\"][0].free")" = "2" ] || fail "vaga inicial deveria ser a capacidade: $SLOTS"

SLOT_START=$(echo "$SLOTS" | jq -r ".slots[\"${TODAY}\"][0].start")
SLOT_END=$(echo "$SLOTS" | jq -r ".slots[\"${TODAY}\"][0].end")

place() { # $1 token, $2 addr, $3 json do slot ("null" pra sem agendamento)
  local token="$1" addr="$2" slot="$3" body
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $token" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  body="{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${addr},\"payment_method\":\"cash\",\"change_for\":100"
  [ "$slot" != "null" ] && body="${body},\"slot\":${slot}"
  body="${body}}"
  curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $token" -d "$body"
}

echo "== agendar reserva a vaga e grava o range no pedido =="
ORDER=$(place "$TOKEN" "$ADDR" "{\"start\":\"${SLOT_START}\",\"end\":\"${SLOT_END}\"}")
ORDER_ID=$(echo "$ORDER" | jq -er '.order.id') || fail "não agendou: $ORDER"
[ "$(echo "$ORDER" | jq -r '.order.scheduled_for')" != "null" ] || fail "pedido saiu sem faixa: $ORDER"
[ "$(query "SELECT taken FROM delivery_slots WHERE restaurant_id='${RESTAURANT_ID}'")" = "1" ] \
  || fail "a vaga não foi reservada em delivery_slots"
AFTER=$(curl -s "$BASE/orders/slots.php?restaurant_id=${RESTAURANT_ID}" "${AUTH[@]}")
[ "$(echo "$AFTER" | jq -r ".slots[\"${TODAY}\"][0].free")" = "1" ] || fail "vaga não caiu na listagem: $AFTER"

echo "== faixa fora do horizonte e faixa já passada são recusadas =="
# Esta loja abre 24h: 03:00 de 2030 SERIA uma faixa válida do horário dela.
# Quem barra é o horizonte de 4 dias, e ele tem que estar no servidor --
# a tela nunca ofereceria, mas a requisição direta passaria.
BOGUS=$(place "$TOKEN" "$ADDR" '{"start":"2030-01-01T03:00:00+00:00","end":"2030-01-01T03:30:00+00:00"}')
[ "$(echo "$BOGUS" | jq -r '.code')" = "slot_too_far" ] || fail "aceitou agendamento para 2030: $BOGUS"

PAST_START=$(query "SELECT to_char(now() - interval '2 hours', 'YYYY-MM-DD\"T\"HH24:MI:SSOF:00')")
PAST_END=$(query "SELECT to_char(now() - interval '90 minutes', 'YYYY-MM-DD\"T\"HH24:MI:SSOF:00')")
PAST=$(place "$TOKEN" "$ADDR" "{\"start\":\"${PAST_START}\",\"end\":\"${PAST_END}\"}")
[ "$(echo "$PAST" | jq -r '.code')" = "slot_unavailable" ] || fail "aceitou faixa que já passou: $PAST"

echo "== a última vaga é do primeiro que chegar; o resto vê 'esgotado' =="
read -r TOKEN2 ADDR2 <<<"$(new_customer)"
read -r TOKEN3 ADDR3 <<<"$(new_customer)"
O2=$(place "$TOKEN2" "$ADDR2" "{\"start\":\"${SLOT_START}\",\"end\":\"${SLOT_END}\"}")
[ "$(echo "$O2" | jq -r '.order.id')" != "null" ] || fail "segundo agendamento deveria caber: $O2"
O3=$(place "$TOKEN3" "$ADDR3" "{\"start\":\"${SLOT_START}\",\"end\":\"${SLOT_END}\"}")
[ "$(echo "$O3" | jq -r '.code')" = "slot_full" ] || fail "terceiro passou da capacidade: $O3"
[ "$(query "SELECT taken <= capacity FROM delivery_slots WHERE restaurant_id='${RESTAURANT_ID}'")" = "t" ] \
  || fail "delivery_slots passou da capacidade"
FULL=$(curl -s "$BASE/orders/slots.php?restaurant_id=${RESTAURANT_ID}" "${AUTH[@]}")
[ "$(echo "$FULL" | jq -r ".slots[\"${TODAY}\"][0].free")" = "0" ] || fail "faixa cheia não apareceu esgotada: $FULL"

echo "== a cozinha não vê o pedido agendado antes da hora =="
psql_run -c "SELECT advance_order(${ORDER_ID}, 'paid', NULL, 'system');" >/dev/null
KDS=$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=kds" "${STAFF_AUTH[@]}")
FAR=$(query "SELECT lower(scheduled_for) > now() + interval '45 minutes' FROM orders WHERE id=${ORDER_ID}")
if [ "$FAR" = "t" ]; then
  [ "$(echo "$KDS" | jq -r "[.orders[] | select(.id == ${ORDER_ID})] | length")" = "0" ] \
    || fail "pedido agendado pra daqui a horas apareceu na fila da cozinha: $KDS"
fi

# ...e aparece quando a faixa chega (empurrando o carimbo pra agora).
psql_run -c "UPDATE orders SET scheduled_for = tstzrange(now() + interval '5 minutes', now() + interval '35 minutes') WHERE id = ${ORDER_ID};"
KDS2=$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=kds" "${STAFF_AUTH[@]}")
[ "$(echo "$KDS2" | jq -r "[.orders[] | select(.id == ${ORDER_ID})] | length")" = "1" ] \
  || fail "pedido na hora da faixa não entrou na fila: $KDS2"
[ "$(echo "$KDS2" | jq -r "[.orders[] | select(.id == ${ORDER_ID})][0].scheduled_start")" != "null" ] \
  || fail "a cozinha não recebeu a hora combinada: $KDS2"

echo "== pedido agendado e não preparado cancela sem taxa =="
QUOTE=$(curl -s "$BASE/orders/cancel_quote.php?id=${ORDER_ID}" "${AUTH[@]}")
[ "$(echo "$QUOTE" | jq -r '.can_cancel')" = "true" ] || fail "agendado deveria poder cancelar: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.fee')" = "0" ] || fail "cancelar antes do preparo tem que ser de graça: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.free_cancel')" = "true" ] || fail "free_cancel deveria ser true: $QUOTE"

echo "== 'assim que ficar pronto' continua sendo o caminho normal =="
read -r TOKEN4 ADDR4 <<<"$(new_customer)"
NOW_ORDER=$(place "$TOKEN4" "$ADDR4" "null")
[ "$(echo "$NOW_ORDER" | jq -r '.order.scheduled_for')" = "null" ] || fail "pedido sem faixa não pode sair agendado: $NOW_ORDER"

echo "== loja que desliga o agendamento recusa a faixa =="
curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"slot_capacity":0}' >/dev/null
read -r TOKEN5 ADDR5 <<<"$(new_customer)"
OFF=$(place "$TOKEN5" "$ADDR5" "{\"start\":\"${SLOT_START}\",\"end\":\"${SLOT_END}\"}")
[ "$(echo "$OFF" | jq -r '.code')" = "scheduling_disabled" ] || fail "agendou em loja com agendamento desligado: $OFF"

echo "OK: pedido agendado (Fase 14.4) passou no smoke test"
