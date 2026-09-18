#!/usr/bin/env bash
# Smoke test do app do entregador (Fase 8) e da baixa de espécie (Fase 9):
# turno, oferta de corrida com aceite atômico, coleta com troco calculado no
# servidor, entrega com prova e lançamento no livro, e a baixa de caixa
# confirmada pela loja com os dois lançamentos na mesma transação.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8104
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-courier-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"
COURIER_USER_ID="$(gen_uuid)"
RIVAL_COURIER_ID="$(gen_uuid)"
RIVAL_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
CPF="$(gen_cpf)"
RIVAL_CPF="$(gen_cpf)"
ACCESS_CODE="246813"
STAMP="$(date +%s%N)"

echo "== semear loja, dois entregadores e o cardápio =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_USER_ID}',   'restaurant_staff', 'Staff Courier Smoke', 'staff-courier-${STAMP}@test.com'),
  ('${COURIER_USER_ID}', 'courier',          'Jonas R. Souza',      'jonas-${STAMP}@test.com'),
  ('${RIVAL_USER_ID}',   'courier',          'Rival do Jonas',      'rival-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cash_ceiling, delivery_base_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         300.00, 7.00, id
  FROM users WHERE email = 'staff-courier-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Courier Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO couriers (id, user_id, city_ibge_code, active) VALUES
  ('${COURIER_ID}',       '${COURIER_USER_ID}', '${CITY}', true),
  ('${RIVAL_COURIER_ID}', '${RIVAL_USER_ID}',   '${CITY}', true);

INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash) VALUES
  ('${COURIER_USER_ID}', 'courier', '${COURIER_ID}',       '${CPF}',       encode(sha256('${ACCESS_CODE}'::bytea), 'hex')),
  ('${RIVAL_USER_ID}',   'courier', '${RIVAL_COURIER_ID}', '${RIVAL_CPF}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex'));

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Courier Smoke', 60.00, 'Pratos', true);
SQL
ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-courier-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== login de entregador é CPF + código (sem senha) =="
COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') || fail "login do entregador falhou"
CAUTH=(-H "Authorization: Bearer $COURIER_TOKEN")
RIVAL_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${RIVAL_CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') || fail "login do rival falhou"
RAUTH=(-H "Authorization: Bearer $RIVAL_TOKEN")
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
SAUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

echo "== sem turno aberto não há oferta nenhuma =="
[ "$(curl -s "$BASE/couriers/offers.php" "${CAUTH[@]}" | jq -r '.code')" = "no_open_shift" ] \
  || fail "listou oferta pra entregador fora de turno"

echo "== abrir turno (e abrir duas vezes dá 409) =="
[ "$(curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
   -d '{"action":"start"}' | jq -r '.shift.ended_at')" = "null" ] || fail "turno não abriu"
[ "$(curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
   -d '{"action":"start"}' | jq -r '.code')" = "shift_already_open" ] || fail "abriu dois turnos"
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${RAUTH[@]}" -d '{"action":"start"}' >/dev/null

echo "== me.php traz saldo zerado, teto da política e nenhuma corrida =="
ME=$(curl -s "$BASE/couriers/me.php" "${CAUTH[@]}")
[ "$(echo "$ME" | jq -r '.balances.cash')" = "0" ] || fail "saldo inicial não era zero: $ME"
[ "$(echo "$ME" | jq -r '.balances.cash_ceiling')" = "300" ] || fail "teto de espécie não veio da política: $ME"
[ "$(echo "$ME" | jq -r '.current_order')" = "null" ] || fail "apareceu corrida do nada: $ME"

echo "== cliente faz um pedido em dinheiro e a loja prepara =="
PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Courier Smoke\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua do Entregador","number":"42","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.56,"lng":-46.64,"is_default":true}' \
  | jq -er '.id')
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
ORDER_ID=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"change_for\":100.00}" \
  | jq -er '.order.id') || fail "checkout falhou"
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${ORDER_ID}}" >/dev/null

# A checagem é sobre ESTE pedido, não sobre a fila inteira: outras suítes
# rodam no mesmo banco e na mesma praça, e podem ter deixado corrida pronta.
echo "== enquanto não fica pronto, este pedido não vira oferta =="
[ "$(curl -s "$BASE/couriers/offers.php" "${CAUTH[@]}" | jq -r "[.offers[] | select(.order_id == ${ORDER_ID})] | length")" = "0" ] \
  || fail "ofereceu corrida de pedido que nem foi preparado"

curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"preparing\"}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"ready\"}" >/dev/null

echo "== pedido pronto vira oferta, com ganho, distância e troco na mesma tela =="
OFFERS=$(curl -s "$BASE/couriers/offers.php" "${CAUTH[@]}" | jq -c "[.offers[] | select(.order_id == ${ORDER_ID})]")
[ "$(echo "$OFFERS" | jq -r 'length')" = "1" ] || fail "pedido pronto não virou oferta: $OFFERS"
OFFER_ID=$(echo "$OFFERS" | jq -er '.[0].offer_id')
[ "$(echo "$OFFERS" | jq -r '.[0].fee')" = "7.00" ] || fail "ganho da corrida não é o frete: $OFFERS"
[ "$(echo "$OFFERS" | jq -r '.[0].payment_method')" = "cash" ] || fail "oferta sem forma de pagamento: $OFFERS"
[ "$(echo "$OFFERS" | jq -r '.[0].change_for')" = "100.00" ] || fail "oferta sem o troco: $OFFERS"
[ "$(echo "$OFFERS" | jq -r '.[0].distance_km')" != "null" ] || fail "oferta sem distância: $OFFERS"

echo "== dois entregadores aceitando: só um leva =="
FIRST=$(curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"offer_id\":${OFFER_ID}}")
[ "$(echo "$FIRST" | jq -r '.offer.state')" = "accepted" ] || fail "aceite falhou: $FIRST"
SECOND=$(curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" "${RAUTH[@]}" \
  -d "{\"offer_id\":${OFFER_ID}}")
[ "$(echo "$SECOND" | jq -r '.code')" = "offer_taken" ] || fail "dois entregadores pegaram a mesma corrida: $SECOND"
[ "$(psql "$DATABASE_URL" -tAc "SELECT courier_id FROM orders WHERE id=${ORDER_ID}")" = "${COURIER_ID}" ] \
  || fail "orders.courier_id não foi atribuído"

echo "== aceitar de novo a MESMA corrida não é erro (retry de rede) =="
[ "$(curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
   -d "{\"offer_id\":${OFFER_ID}}" | jq -r '.already_mine')" = "true" ] || fail "retry do próprio aceite deu erro"

echo "== a oferta some da lista dos outros =="
[ "$(curl -s "$BASE/couriers/offers.php" "${RAUTH[@]}" | jq -r "[.offers[] | select(.order_id == ${ORDER_ID})] | length")" = "0" ] \
  || fail "corrida aceita continuou na vitrine"

echo "== coleta: o troco vem calculado pelo servidor =="
PICKUP=$(curl -s -X POST "$BASE/couriers/pickup.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"event\":\"arrived_at_store\",\"lat\":-23.5505,\"lng\":-46.6333}")
[ "$(echo "$PICKUP" | jq -r '.change_due')" = "33" ] || fail "troco errado (esperava 100 - 67 = 33): $PICKUP"
[ "$(echo "$PICKUP" | jq -r '.items | length')" = "1" ] || fail "coleta sem os itens: $PICKUP"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM order_events WHERE order_id=${ORDER_ID} AND actor_kind='courier'")" = "1" ] \
  || fail "chegada não virou evento com carimbo"

echo "== entregar antes da loja passar a sacola é barrado =="
EARLY=$(curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${CAUTH[@]}" -d "{\"order_id\":${ORDER_ID},\"delivery_code\":\"0000\"}")
[ "$(echo "$EARLY" | jq -r '.code')" = "order_not_in_delivery" ] || fail "entregou pedido que nem saiu da loja: $EARLY"

curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"delivering\"}" >/dev/null

echo "== entrega sem prova nenhuma é barrada =="
[ "$(curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
   -H "X-Idempotency-Key: $(gen_uuid)" "${CAUTH[@]}" -d "{\"order_id\":${ORDER_ID}}" | jq -r '.code')" = "proof_required" ] \
  || fail "entregou sem prova"

echo "== código errado do cliente é barrado =="
REAL_CODE=$(psql "$DATABASE_URL" -tAc "SELECT delivery_code FROM orders WHERE id=${ORDER_ID}")
WRONG=$(( (10#$REAL_CODE + 1) % 10000 ))
WRONG=$(printf '%04d' "$WRONG")
[ "$(curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
   -H "X-Idempotency-Key: $(gen_uuid)" "${CAUTH[@]}" -d "{\"order_id\":${ORDER_ID},\"delivery_code\":\"${WRONG}\"}" | jq -r '.code')" = "wrong_delivery_code" ] \
  || fail "código errado passou"

echo "== entrega com o código certo: espécie e frete entram no livro juntos =="
KEY="$(gen_uuid)"
DELIVER=$(curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: ${KEY}" "${CAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"delivery_code\":\"${REAL_CODE}\",\"lat\":-23.56,\"lng\":-46.64}")
[ "$(echo "$DELIVER" | jq -r '.order.status')" = "delivered" ] || fail "entrega não fechou: $DELIVER"
[ "$(echo "$DELIVER" | jq -r '.balances.cash')" = "67" ] || fail "espécie não entrou no saldo (esperava o bruto 67): $DELIVER"
[ "$(echo "$DELIVER" | jq -r '.balances.payable')" = "7" ] || fail "frete não entrou como a receber: $DELIVER"
[ "$(psql "$DATABASE_URL" -tAc "SELECT kind FROM delivery_proofs WHERE order_id=${ORDER_ID}")" = "code" ] \
  || fail "prova de entrega não foi gravada"

echo "== repetir a entrega com a mesma chave devolve a mesma resposta =="
REPLAY=$(curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: ${KEY}" "${CAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"delivery_code\":\"${REAL_CODE}\",\"lat\":-23.56,\"lng\":-46.64}")
[ "$(echo "$REPLAY" | jq -r '.balances.cash')" = "67" ] || fail "replay não bateu: $REPLAY"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM ledger_entries WHERE order_id=${ORDER_ID}")" = "2" ] \
  || fail "replay duplicou lançamento no livro"

echo "== ganhos: livro de lançamentos, não campo de saldo =="
EARN=$(curl -s "$BASE/couriers/earnings.php" "${CAUTH[@]}")
[ "$(echo "$EARN" | jq -r '.entries | length')" = "2" ] || fail "livro sem os dois lançamentos: $EARN"
[ "$(echo "$EARN" | jq -r '.today.rides_today')" = "1" ] || fail "contagem de corridas do dia errada: $EARN"

echo "== fechar turno com corrida em andamento é barrado (não há corrida agora, então fecha) =="
[ "$(curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
   -d '{"action":"end"}' | jq -r '.shift.ended_at')" != "null" ] || fail "turno não fechou"
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${CAUTH[@]}" -d '{"action":"start"}' >/dev/null

echo "== Fase 9: intenção de baixa gera código de 6 dígitos, só o hash fica =="
INTENT=$(curl -s -X POST "$BASE/couriers/settle_intent.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"method\":\"in_person\"}")
SETTLE_CODE=$(echo "$INTENT" | jq -er '.code') || fail "baixa não gerou código: $INTENT"
[ "${#SETTLE_CODE}" = "6" ] || fail "código de baixa não tem 6 dígitos: $SETTLE_CODE"
[ "$(echo "$INTENT" | jq -r '.intent.amount')" = "67.00" ] || fail "valor da baixa não é o saldo: $INTENT"
[ "$(psql "$DATABASE_URL" -tAc "SELECT code_hash = encode(sha256('${SETTLE_CODE}'::bytea),'hex') FROM cash_settlement_intents WHERE id=$(echo "$INTENT" | jq -r '.intent.id')")" = "t" ] \
  || fail "o banco não guardou o hash do código"

echo "== duas baixas abertas ao mesmo tempo são barradas =="
[ "$(curl -s -X POST "$BASE/couriers/settle_intent.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"method\":\"in_person\"}" | jq -r '.code')" = "intent_already_open" ] \
  || fail "abriu duas baixas ao mesmo tempo"

echo "== valor contado diferente abre ocorrência e NÃO lança nada =="
DIVERGE=$(curl -s -X POST "$BASE/restaurants/confirm_settlement.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"code\":\"${SETTLE_CODE}\",\"counted_amount\":60.00}")
[ "$(echo "$DIVERGE" | jq -r '.settled')" = "false" ] || fail "divergência foi tratada como baixa boa: $DIVERGE"
[ "$(echo "$DIVERGE" | jq -r '.intent.state')" = "disputed" ] || fail "divergência não virou ocorrência: $DIVERGE"
[ "$(echo "$DIVERGE" | jq -r '.courier_cash_balance')" = "67" ] || fail "saldo mexeu numa divergência: $DIVERGE"

echo "== baixa certa: os dois lançamentos nascem juntos =="
INTENT2=$(curl -s -X POST "$BASE/couriers/settle_intent.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"method\":\"in_person\"}")
CODE2=$(echo "$INTENT2" | jq -er '.code')
OK=$(curl -s -X POST "$BASE/restaurants/confirm_settlement.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"code\":\"${CODE2}\",\"counted_amount\":67.00}")
[ "$(echo "$OK" | jq -r '.settled')" = "true" ] || fail "baixa certa não foi confirmada: $OK"
[ "$(echo "$OK" | jq -r '.courier_cash_balance')" = "0" ] || fail "saldo do entregador não zerou: $OK"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM ledger_entries WHERE origin='cash_settlement' AND origin_id='$(echo "$INTENT2" | jq -r '.intent.id')'")" = "2" ] \
  || fail "a baixa não gerou os dois lançamentos"
[ "$(psql "$DATABASE_URL" -tAc "SELECT confirmed_by IS NOT NULL FROM cash_settlement_intents WHERE id=$(echo "$INTENT2" | jq -r '.intent.id')")" = "t" ] \
  || fail "baixa sem o nome de quem confirmou"

echo "== código de baixa de outra loja não confere =="
[ "$(curl -s -X POST "$BASE/restaurants/confirm_settlement.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
   -d "{\"code\":\"000000\",\"counted_amount\":10.00}" | jq -r '.code')" = "intent_not_found" ] \
  || fail "código inventado foi aceito"

echo "== cliente não acessa rota de entregador =="
[ "$(curl -s "$BASE/couriers/me.php" "${AUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "cliente entrou na área do entregador"

echo "OK: app do entregador (Fase 8) e baixa de espécie (Fase 9) passaram no smoke test"
