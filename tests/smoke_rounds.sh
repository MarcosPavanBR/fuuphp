#!/usr/bin/env bash
# Smoke test da Fase 15 -- rodadas do despacho, raio crescente, surge e
# `dispatch_attempts`.
#
# Três entregadores em turno: um na porta da loja, um a ~10 km, e um sem
# posição (GPS desligado). A loja fica ~25 km das lojas das outras suítes
# (-23.70, -46.80): entregadores que elas deixaram em turno na mesma praça
# não entram na contagem de candidatos da primeira rodada.
#
# O pedido fica pronto e ninguém aceita; o tempo é envelhecido no banco pra
# atravessar as rodadas sem esperar minutos.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8119
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-rounds-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

RESTAURANT_ID="$(gen_uuid)"; STAFF_USER_ID="$(gen_uuid)"; STAMP="$(date +%s%N)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"
declare -A CID CUID CPFS
for who in near far blind; do
  CID[$who]="$(gen_uuid)"; CUID[$who]="$(gen_uuid)"; CPFS[$who]="$(php "$ROOT/tests/support/random_cpf.php")"
done
ACCESS_CODE="515151"

echo "== semear loja e três entregadores =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Rodadas', 'staff-rounds-${STAMP}@test.com'),
  ('${CUID[near]}',  'courier', 'Perto da Loja', 'near-${STAMP}@test.com'),
  ('${CUID[far]}',   'courier', 'Longe da Loja', 'far-${STAMP}@test.com'),
  ('${CUID[blind]}', 'courier', 'Sem GPS',       'blind-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, delivery_base_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], 7.00, id
  FROM users WHERE email = 'staff-rounds-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Rodadas Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.7000, -46.8000);
INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO couriers (id, user_id, city_ibge_code, active) VALUES
  ('${CID[near]}', '${CUID[near]}', '${CITY}', true),
  ('${CID[far]}', '${CUID[far]}', '${CITY}', true),
  ('${CID[blind]}', '${CUID[blind]}', '${CITY}', true);
INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash) VALUES
  ('${CUID[near]}', 'courier', '${CID[near]}', '${CPFS[near]}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex')),
  ('${CUID[far]}', 'courier', '${CID[far]}', '${CPFS[far]}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex')),
  ('${CUID[blind]}', 'courier', '${CID[blind]}', '${CPFS[blind]}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex'));
INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Rodadas', 50.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-rounds-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

declare -A TOKEN
for who in near far blind; do
  TOKEN[$who]=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
    -d "{\"kind\":\"courier\",\"login_code\":\"${CPFS[$who]}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') || fail "login $who"
done
auth() { echo "Authorization: Bearer ${TOKEN[$1]}"; }

echo "== posição só com turno aberto, e só coordenada válida =="
NOSHIFT=$(curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" -H "$(auth near)" -d '{"lat":-23.55,"lng":-46.63}')
[ "$(echo "$NOSHIFT" | jq -r '.code')" = "no_open_shift" ] || fail "gravou posição fora do turno: $NOSHIFT"
for who in near far blind; do
  curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" -H "$(auth $who)" -d '{"action":"start"}' >/dev/null
done
BAD=$(curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" -H "$(auth near)" -d '{"lat":123,"lng":-46.63}')
[ "$(echo "$BAD" | jq -r '.code')" = "invalid_position" ] || fail "aceitou latitude impossível: $BAD"
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" -H "$(auth near)" -d '{"lat":-23.7000,"lng":-46.8000}' >/dev/null
# ~10 km ao sul da loja.
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" -H "$(auth far)" -d '{"lat":-23.7900,"lng":-46.8000}' >/dev/null
[ "$(query "SELECT count(*) FROM courier_positions WHERE courier_id IN ('${CID[near]}','${CID[far]}')")" = "2" ] \
  || fail "posições não foram gravadas"

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Rodadas\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d '{"street":"Rua das Rodadas","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.7025,"lng":-46.8000,"is_default":true}' | jq -er '.id')
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')

curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" \
  -H "Authorization: Bearer $ACCESS" -d "{\"order_id\":${ORDER},\"card_token\":\"APRO-rounds\"}" >/dev/null
for to in preparing ready; do
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
    -d "{\"order_id\":${ORDER},\"to\":\"${to}\"}" >/dev/null
done

sees() { curl -s "$BASE/couriers/offers.php" -H "$(auth $1)" | jq -r "[.offers[] | select(.order_id == ${ORDER})] | length"; }
age() { psql_run -c "UPDATE orders SET no_courier_since = now() - interval '$1 seconds' WHERE id=${ORDER}"; }

echo "== rodada 1: raio de 2 km -- só quem está perto vê =="
[ "$(query "SELECT round || ':' || radius_km || ':' || candidates FROM dispatch_attempts WHERE order_id=${ORDER}")" = "1:2.00:1" ] \
  || fail "a primeira rodada não foi gravada com o raio e o candidato certos: $(query "SELECT * FROM dispatch_attempts WHERE order_id=${ORDER}")"
[ "$(sees near)" = "1" ] || fail "o entregador na porta não viu a corrida"
[ "$(sees far)" = "0" ] || fail "o entregador a 10 km viu a corrida na primeira rodada"
[ "$(sees blind)" = "0" ] || fail "sem GPS viu a corrida antes da última rodada -- desligar o GPS daria prioridade"

echo "== rodada 3 (pulando a 2): raio de 7 km e surge de R\$ 2 =="
age 160
[ "$(sees far)" = "0" ] || fail "10 km ainda está fora do raio de 7 km"
[ "$(query "SELECT string_agg(round::text, ',' ORDER BY round) FROM dispatch_attempts WHERE order_id=${ORDER}")" = "1,2,3" ] \
  || fail "a rodada pulada não ficou no registro de 'por que não achou'"
[ "$(query "SELECT bonus FROM offers WHERE order_id=${ORDER} AND state='open'")" = "2.00" ] \
  || fail "o surge da rodada 3 não chegou na oferta"

echo "== rodada 4: a praça inteira, surge de R\$ 4 no total =="
age 280
php "$ROOT/bin/dispatch_rounds.php" >/tmp/smoke-rounds-cron.log 2>&1 || fail "o cron das rodadas quebrou"
grep -q "rodadas abertas: " /tmp/smoke-rounds-cron.log || fail "o cron não disse o que fez"
[ "$(query "SELECT max(round) FROM dispatch_attempts WHERE order_id=${ORDER}")" = "4" ] || fail "o cron não abriu a rodada 4"
[ "$(query "SELECT bonus FROM offers WHERE order_id=${ORDER} AND state='open'")" = "4.00" ] \
  || fail "o surge não somou a diferença (esperado 4,00): $(query "SELECT bonus FROM offers WHERE order_id=${ORDER}")"
[ "$(sees far)" = "1" ] || fail "na última rodada o entregador longe devia ver"
[ "$(sees blind)" = "1" ] || fail "na última rodada quem está sem GPS devia ver"
php "$ROOT/bin/dispatch_rounds.php" >>/tmp/smoke-rounds-cron.log 2>&1
[ "$(query "SELECT count(*) FROM dispatch_attempts WHERE order_id=${ORDER}")" = "4" ] || fail "rodar o cron de novo duplicou rodada"

echo "== o bônus é do entregador; o surge que o cliente não pagou é despesa nossa =="
OFFER=$(curl -s "$BASE/couriers/offers.php" -H "$(auth far)" | jq -er ".offers[] | select(.order_id == ${ORDER}) | .offer_id")
[ "$(curl -s "$BASE/couriers/offers.php" -H "$(auth far)" | jq -r ".offers[] | select(.order_id == ${ORDER}) | .dispatch_round")" = "4" ] \
  || fail "a oferta não diz em que rodada está"
curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" \
  -H "$(auth far)" -d "{\"offer_id\":${OFFER}}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"order_id\":${ORDER},\"to\":\"delivering\"}" >/dev/null
curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" -H "$(auth far)" \
  -d "{\"order_id\":${ORDER},\"delivery_code\":\"$(query "SELECT delivery_code FROM orders WHERE id=${ORDER}")\"}" >/dev/null
[ "$(query "SELECT amount FROM ledger_entries WHERE origin_id='bonus:${ORDER}' AND account='courier_payable'")" = "4.00" ] \
  || fail "o bônus da oferta não foi pago ao entregador"
[ "$(query "SELECT amount FROM ledger_entries WHERE origin_id='surge:${ORDER}' AND account='platform_expense'")" = "4.00" ] \
  || fail "o surge da plataforma não entrou como despesa"

echo
echo "smoke_rounds OK"
