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

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-courier-server.log 2>&1 &
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
# O código foi semeado no formato antigo (SHA-256): o primeiro acerto troca
# por bcrypt (migração 034), e o mesmo código continua entrando.
[ "$(psql "$DATABASE_URL" -tAc "SELECT left(access_code_hash, 4) FROM partner_accounts WHERE kind='courier' AND login_code='${CPF}'")" = '$2y$' ] \
  || fail "código antigo não foi convertido pra bcrypt no login"
AGAIN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}")
echo "$AGAIN" | jq -e '.access_token' >/dev/null || fail "depois de virar bcrypt o código não entra mais"
# Renovação (migração 039): o entregador renovado continua sendo ele.
RENEWED=$(curl -s -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"$(echo "$AGAIN" | jq -r '.refresh_token')\"}" | jq -er '.access_token') || fail "refresh do entregador falhou"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/couriers/me.php" -H "Authorization: Bearer $RENEWED")" = "200" ] \
  || fail "token renovado do entregador perdeu o courier_id"
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
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d '{"lat":-23.5505,"lng":-46.6333}' >/dev/null  # na porta da loja: dentro do raio da 1ª rodada
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${RAUTH[@]}" -d '{"action":"start"}' >/dev/null
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" "${RAUTH[@]}" \
  -d '{"lat":-23.5505,"lng":-46.6333}' >/dev/null  # na porta da loja: dentro do raio da 1ª rodada

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
# Três lançamentos por entrega em espécie: a espécie com o entregador, o
# frete dele e a parte da loja que passa a ser devida (lib/ledger/order_ledger.php).
# O replay não pode acrescentar nenhum.
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM ledger_entries WHERE order_id=${ORDER_ID}")" = "3" ] \
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

echo "== 9.5: baixa por Pix -- loja sem chave Pix manda pro balcão =="
cash_in() { # espécie nova na mão do entregador (como se tivesse entregue um pedido em dinheiro)
  psql_run -c "INSERT INTO ledger_entries (account, party_id, amount, origin, origin_id, memo)
               VALUES ('courier_cash', '${COURIER_ID}', $1, 'adjustment', 'smoke-pix-$RANDOM$RANDOM', 'smoke: espécie')"
}
proof_img() { php -r '$i=imagecreatetruecolor(320,200);imagestring($i,5,10,90,$argv[2],imagecolorallocate($i,255,255,255));imagejpeg($i,$argv[1]);' "$1" "$2"; }
pix_intent() { curl -s -X POST "$BASE/couriers/settle_intent.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"method\":\"pix\"}"; }
send_proof() { curl -s -X POST "$BASE/couriers/settle_proof.php" "${CAUTH[@]}" ${3:+-H "X-Idempotency-Key: $3"} \
  -F "intent_id=$1" -F "proof=@$2;type=image/jpeg"; }
decide() { curl -s -X POST "$BASE/restaurants/settlement_proofs.php" -H "Content-Type: application/json" "${SAUTH[@]}" -d "$1"; }
cash_in 40.00
[ "$(pix_intent | jq -r '.code')" = "store_has_no_pix_key" ] || fail "baixa por Pix sem chave da loja"
psql_run -c "INSERT INTO restaurant_credentials (restaurant_id, pix_key) VALUES ('${RESTAURANT_ID}', 'loja-courier@pix.test')
             ON CONFLICT (restaurant_id) DO UPDATE SET pix_key = EXCLUDED.pix_key"

echo "== 9.5: intenção por Pix -- sem código, com o copia-e-cola da loja e prazo até amanhã =="
PIX=$(pix_intent)
PIX_ID=$(echo "$PIX" | jq -er '.intent.id') || fail "intenção por Pix falhou: $PIX"
[ "$(echo "$PIX" | jq -r '.code')" = "null" ] || fail "baixa por Pix não devia ter código de balcão"
echo "$PIX" | jq -r '.pix_copy_paste' | grep -q '^000201' || fail "sem copia-e-cola BR Code: $PIX"
echo "$PIX" | jq -r '.pix_copy_paste' | grep -q '40.00' || fail "o Pix não tem o valor exato: $PIX"
[ "$(psql "$DATABASE_URL" -tAc "SELECT expires_at > now() + interval '20 hours' FROM cash_settlement_intents WHERE id=${PIX_ID}")" = "t" ] \
  || fail "prazo da baixa por Pix não é até amanhã"
[ "$(curl -s "$BASE/couriers/settle_proof.php" "${CAUTH[@]}" | jq -r '.intent.proof_state')" = "null" ] || fail "estado inicial errado"

echo "== 9.5: comprovante entra na fila da loja; o saldo não mexe até a loja conferir =="
proof_img "/tmp/courier-pix-a.jpg" "A$RANDOM"
KEY_A=$(gen_uuid)
FIRST=$(send_proof "$PIX_ID" /tmp/courier-pix-a.jpg "$KEY_A")
PROOF_A=$(echo "$FIRST" | jq -er '.proof.id') || fail "comprovante não entrou: $FIRST"
[ "$(send_proof "$PIX_ID" /tmp/courier-pix-a.jpg "$KEY_A" | jq -r '.replayed')" = "true" ] || fail "reenvio da fila offline duplicou"
[ "$(send_proof "$PIX_ID" /tmp/courier-pix-a.jpg | jq -r '.code')" = "proof_pending" ] || fail "dois comprovantes na fila ao mesmo tempo"
QUEUE=$(curl -s "$BASE/restaurants/settlement_proofs.php" "${SAUTH[@]}")
[ "$(echo "$QUEUE" | jq -r ".proofs[] | select(.id == ${PROOF_A}) | .amount")" = "40.00" ] || fail "fila da loja sem o comprovante: $QUEUE"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/restaurants/settlement_proofs.php?image=${PROOF_A}" "${SAUTH[@]}")" = "200" ] \
  || fail "a loja não vê a imagem"
[ "$(curl -s "$BASE/couriers/me.php" "${CAUTH[@]}" | jq -r '.balances.cash')" = "40" ] || fail "saldo mexeu antes da conferência"
psql_run -c "UPDATE cash_settlement_intents SET expires_at = now() - interval '1 minute' WHERE id=${PIX_ID}; SELECT expire_cash_settlements();" >/dev/null
[ "$(psql "$DATABASE_URL" -tAc "SELECT state FROM cash_settlement_intents WHERE id=${PIX_ID}")" = "open" ] \
  || fail "baixa com comprovante esperando a loja expirou (culpa da demora da loja caiu no entregador)"
psql_run -c "UPDATE cash_settlement_intents SET expires_at = now() + interval '1 day' WHERE id=${PIX_ID}"

echo "== 9.5: recusa sem motivo não passa; recusa com motivo deixa reenviar =="
[ "$(decide "{\"proof_id\":${PROOF_A},\"decision\":\"reject\"}" | jq -r '.code')" = "reason_required" ] || fail "recusou sem motivo"
decide "{\"proof_id\":${PROOF_A},\"decision\":\"reject\",\"reason\":\"valor não aparece\"}" >/dev/null
[ "$(curl -s "$BASE/couriers/settle_proof.php" "${CAUTH[@]}" | jq -r '.intent.reject_reason')" = "valor não aparece" ] \
  || fail "o entregador não vê o motivo da recusa"
proof_img "/tmp/courier-pix-b.jpg" "B$RANDOM"
PROOF_B=$(send_proof "$PIX_ID" /tmp/courier-pix-b.jpg | jq -er '.proof.id') || fail "reenvio depois da recusa falhou"

echo "== 9.5: aprovada, a baixa nasce igual à do balcão =="
APPROVED=$(decide "{\"proof_id\":${PROOF_B},\"decision\":\"approve\"}")
[ "$(echo "$APPROVED" | jq -r '.courier_cash_balance')" = "0" ] || fail "aprovação não zerou o saldo: $APPROVED"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM ledger_entries WHERE origin='cash_settlement' AND origin_id='${PIX_ID}'")" = "2" ] \
  || fail "baixa por Pix sem os dois lançamentos"
[ "$(decide "{\"proof_id\":${PROOF_B},\"decision\":\"approve\"}" | jq -r '.code')" = "already_decided" ] || fail "aprovou duas vezes"

echo "== 9.5: comprovante falso bloqueia as corridas em dinheiro e abre ocorrência =="
cash_in 25.00
FAKE_ID=$(pix_intent | jq -er '.intent.id')
PROOF_F=$(send_proof "$FAKE_ID" /tmp/courier-pix-b.jpg | jq -er '.proof.id')
[ "$(curl -s "$BASE/restaurants/settlement_proofs.php" "${SAUTH[@]}" | jq -r ".proofs[] | select(.id == ${PROOF_F}) | .seen_before")" = "true" ] \
  || fail "a loja não foi avisada de que o comprovante já apareceu antes"
decide "{\"proof_id\":${PROOF_F},\"decision\":\"reject\",\"reason\":\"comprovante repetido\",\"fraud\":true}" >/dev/null
[ "$(psql "$DATABASE_URL" -tAc "SELECT cash_blocked FROM couriers WHERE id='${COURIER_ID}'")" = "t" ] || fail "fraude não bloqueou o entregador"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM disputes WHERE courier_id='${COURIER_ID}' AND kind='fake_proof'")" = "1" ] || fail "fraude sem ocorrência"
[ "$(psql "$DATABASE_URL" -tAc "SELECT state FROM cash_settlement_intents WHERE id=${FAKE_ID}")" = "disputed" ] || fail "baixa fraudada não ficou em disputa"
psql_run -c "UPDATE couriers SET cash_blocked = false WHERE id='${COURIER_ID}'"

echo "== ESC/POS: comanda com o troco em negrito; o código de entrega NÃO sai no papel =="
TICKET=$(curl -s "$BASE/restaurants/print_queue.php?kind=order_ticket&ref_id=${ORDER_ID}&columns=48" "${SAUTH[@]}")
TICKET_TEXT=$(echo "$TICKET" | jq -r '.job.text')
echo "$TICKET_TEXT" | grep -q "COBRAR NA ENTREGA · DINHEIRO" || fail "comanda sem a forma de pagamento: $TICKET_TEXT"
echo "$TICKET_TEXT" | grep -q "TROCO PARA R\$ 100,00" || fail "comanda sem o troco: $TICKET_TEXT"
echo "$TICKET_TEXT" | grep -q "LEVAR R\$ 33,00 DE TROCO" || fail "comanda sem o troco a levar (100 − 67): $TICKET_TEXT"
DELIVERY_CODE=$(psql "$DATABASE_URL" -tAc "SELECT delivery_code FROM orders WHERE id=${ORDER_ID}")
echo "$TICKET_TEXT" | grep -q "$DELIVERY_CODE" && fail "o código de entrega do cliente saiu impresso"
WIDEST=$(echo "$TICKET_TEXT" | php -r '$m = 0; foreach (file("php://stdin") as $l) { $m = max($m, mb_strlen(rtrim($l, "\n"))); } echo $m;')
[ "$WIDEST" -le 48 ] || fail "comanda passou de 48 colunas ($WIDEST)"
TICKET_HEX=$(echo "$TICKET" | jq -r '.job.escpos_base64' | base64 -d | od -An -tx1 | tr -d ' \n')
[ "${TICKET_HEX:0:10}" = "1b401b7403" ] || fail "bytes não começam com ESC @ + página de código 860: ${TICKET_HEX:0:10}"
[ "${TICKET_HEX: -8}" = "1d564200" ] || fail "bytes não terminam com o corte de papel"
echo "$TICKET_HEX" | grep -q "1b4501" || fail "nada em negrito na comanda"
[ "$(curl -s "$BASE/restaurants/print_queue.php?columns=50" "${SAUTH[@]}" | jq -r '.code')" = "invalid_columns" ] || fail "largura inválida aceita"

echo "== ESC/POS: recibos de baixa na fila da loja, com a mesma assinatura do app do entregador (9.3/9.4) =="
QUEUE=$(curl -s "$BASE/restaurants/print_queue.php?columns=32" "${SAUTH[@]}")
INTENT2_ID=$(echo "$INTENT2" | jq -r '.intent.id')
RECEIPT_TEXT=$(echo "$QUEUE" | jq -r ".jobs[] | select(.kind == \"settlement_receipt\" and .ref_id == ${INTENT2_ID}) | .text")
echo "$RECEIPT_TEXT" | grep -q "BX-${INTENT2_ID}" || fail "recibo da baixa de balcão fora da fila: $QUEUE"
[ "$(echo "$QUEUE" | jq -r "[.jobs[] | select(.kind == \"settlement_receipt\" and .ref_id == ${PIX_ID})] | length")" = "1" ] \
  || fail "recibo da baixa por Pix fora da fila"
COURIER_RECEIPT=$(curl -s "$BASE/couriers/settlements.php?intent_id=${INTENT2_ID}" "${CAUTH[@]}")
SIG=$(echo "$COURIER_RECEIPT" | jq -er '.receipt.signature') || fail "o app do entregador não recebeu o recibo: $COURIER_RECEIPT"
[ "${#SIG}" = "64" ] || fail "assinatura com tamanho errado"
echo "$RECEIPT_TEXT" | tr -d '\n' | grep -q "$SIG" || fail "a assinatura do papel não é a mesma do app"
[ "$(echo "$COURIER_RECEIPT" | jq -r '.receipt.amount')" = "67" ] || fail "recibo com valor errado"
[ "$(echo "$COURIER_RECEIPT" | jq -r '.balances.next_payout')" != "null" ] || fail "recibo sem a data do próximo repasse"
RECEIPT_BYTES=$(echo "$QUEUE" | jq -r ".jobs[] | select(.ref_id == ${INTENT2_ID} and .kind == \"settlement_receipt\") | .escpos_base64" | base64 -d | od -An -tx1 | tr -d ' \n')
echo "$RECEIPT_BYTES" | grep -q "$(printf 'ESP\xc9CIE' | iconv -f latin1 -t CP860 | od -An -tx1 | tr -d ' \n')" \
  || fail "acento não saiu em CP860 (ESPÉCIE)"

echo "== impresso sai da fila; primeira via não duplica; reimpressão fica registrada =="
curl -s -X POST "$BASE/restaurants/print_queue.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"kind\":\"settlement_receipt\",\"ref_id\":${INTENT2_ID}}" >/dev/null
curl -s -X POST "$BASE/restaurants/print_queue.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"kind\":\"settlement_receipt\",\"ref_id\":${INTENT2_ID}}" >/dev/null
curl -s -X POST "$BASE/restaurants/print_queue.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"kind\":\"settlement_receipt\",\"ref_id\":${INTENT2_ID},\"reprint\":true}" >/dev/null
[ "$(curl -s "$BASE/restaurants/print_queue.php?columns=48" "${SAUTH[@]}" | jq -r "[.jobs[] | select(.ref_id == ${INTENT2_ID} and .kind == \"settlement_receipt\")] | length")" = "0" ] \
  || fail "recibo impresso continuou na fila"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FILTER (WHERE NOT reprint) || '/' || count(*) FILTER (WHERE reprint) FROM print_log WHERE kind='settlement_receipt' AND ref_id=${INTENT2_ID}")" = "1/1" ] \
  || fail "vias registradas erradas"

echo "== cliente não acessa rota de entregador =="
[ "$(curl -s "$BASE/couriers/me.php" "${AUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "cliente entrou na área do entregador"

echo "OK: app do entregador (Fase 8) e baixa de espécie (Fase 9) passaram no smoke test"
