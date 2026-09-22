#!/usr/bin/env bash
# Smoke test das telas 13.3 e 13.4.
#
#  13.3 -- ocorrência na entrega: prova obrigatória (foto, GPS, tentativas),
#          os 10 min de espera pra "cliente não atende", e a garantia de que
#          "você recebe a corrida integral nas duas saídas".
#  13.4 -- console de reembolso do admin: a fila por método, o ajuste da taxa
#          (perdoar/metade/manter), o estorno que MOVE o livro, e o crédito
#          em carteira como oferta que a pessoa pode recusar.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8113
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-incident-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
ADMIN_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"
COURIER_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
CPF="$(gen_cpf)"
ACCESS_CODE="246813"
STAMP="$(date +%s%N)"
ADMIN_PHONE="1197$(( RANDOM % 9000000 + 1000000 ))"
FREIGHT="6.00"

echo "== semear loja, entregador, admin e política com taxa de cancelamento =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF_USER_ID}',   'restaurant_staff', 'Staff Incident Smoke', NULL, 'staff-inc-${STAMP}@test.com'),
  ('${ADMIN_USER_ID}',   'admin',            'Admin Incident Smoke', '${ADMIN_PHONE}', 'admin-inc-${STAMP}@test.com'),
  ('${COURIER_USER_ID}', 'courier',          'Entregador Ocorrência', NULL, 'courier-inc-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cancel_fee, delivery_base_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         15.00, 6.00, id
  FROM users WHERE email = 'staff-inc-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Incident Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);

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
VALUES ('${RESTAURANT_ID}', 'Prato Incident Smoke', 60.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-incident-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Ocorrência\"}" | jq -er '.dev_code') || fail "otp falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")
USER_ID=$(query "SELECT id FROM users WHERE phone='${PHONE}'")

ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua da Ocorrência","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5630,"lng":-46.6333,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') || fail "login do entregador falhou"
COURIER_AUTH=(-H "Authorization: Bearer $COURIER_TOKEN")
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"action":"start"}' >/dev/null
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"lat":-23.5505,"lng":-46.6333}' >/dev/null  # na porta da loja: dentro do raio da 1ª rodada

ADMIN_CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code') || fail "otp do admin falhou"
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$ADMIN_CODE\"}" | jq -er '.access_token') \
  || fail "login do admin falhou"
ADMIN_AUTH=(-H "Authorization: Bearer $ADMIN_TOKEN")

# Um pedido em 'delivering', com o entregador deste teste na corrida.
delivering_order() {
  local method="$1" extra=""
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  [ "$method" = "cash" ] && extra=',"change_for":100.00'
  local id
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"${method}\"${extra}}" \
    | jq -er '.order.id') || fail "checkout $method falhou"
  local body='{"order_id":'"$id"'}'
  [ "$method" = "mp_card" ] && body='{"order_id":'"$id"',"card_token":"APRO-token-incident"}'
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "$body" >/dev/null
  for to in preparing ready; do
    curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
      -d "{\"order_id\":${id},\"to\":\"${to}\"}" >/dev/null
  done
  local offer
  offer=$(curl -s "$BASE/couriers/offers.php" "${COURIER_AUTH[@]}" \
    | jq -er ".offers[] | select(.order_id == ${id}) | .offer_id") || fail "oferta não apareceu"
  curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" -d "{\"offer_id\":${offer}}" >/dev/null
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
    -d "{\"order_id\":${id},\"to\":\"delivering\"}" >/dev/null
  echo "$id"
}

# A prova é obrigatória e o upload é real: gera uma foto de verdade, com um
# carimbo diferente a cada corrida (foto repetida é sinal de fraude, e o
# endpoint registra isso -- ver smoke abaixo).
make_photo() {
  php -r '
    $img = imagecreatetruecolor(320, 240);
    imagefilledrectangle($img, 0, 0, 320, 240, imagecolorallocate($img, 30, 30, 40));
    imagestring($img, 5, 12, 110, $argv[2], imagecolorallocate($img, 255, 255, 255));
    imagejpeg($img, $argv[1]);
  ' "$1" "$2"
}

upload_photo() {
  local order="$1" file="$2"
  curl -s -X POST "$BASE/couriers/incident_photo.php" "${COURIER_AUTH[@]}" \
    -F "order_id=${order}" -F "photo=@${file};type=image/jpeg"
}

echo "== 13.3: sem foto não abre ocorrência =="
O1=$(delivering_order cash)
NOPHOTO=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"open\",\"kind\":\"customer_absent\"}")
[ "$(echo "$NOPHOTO" | jq -r '.code')" = "photo_required" ] || fail "abriu ocorrência sem prova: $NOPHOTO"

PHOTO1="/tmp/incident-${STAMP}-1.jpg"
make_photo "$PHOTO1" "OC1-${STAMP}"
UP1=$(upload_photo "$O1" "$PHOTO1")
KEY1=$(echo "$UP1" | jq -er '.photo_storage_key') || fail "upload da foto falhou: $UP1"
[ "$(echo "$UP1" | jq -r '.reused')" = "false" ] || fail "foto nova marcada como reaproveitada: $UP1"

echo "== 13.3: 'cliente não atende' exige ligação registrada =="
NOCALL=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"open\",\"kind\":\"customer_absent\",\"photo_storage_key\":\"${KEY1}\"}")
[ "$(echo "$NOCALL" | jq -r '.code')" = "call_required" ] || fail "'não atende' passou sem ligação: $NOCALL"

echo "== 13.3: tentativas viram rastro com hora, GPS e IP =="
curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"arrive\",\"lat\":-23.5630,\"lng\":-46.6333}" >/dev/null
for _ in 1 2; do
  curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
    -d "{\"order_id\":${O1},\"action\":\"call\",\"lat\":-23.5630,\"lng\":-46.6333}" >/dev/null
done
curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"bell\"}" >/dev/null
# Chegar duas vezes não reinicia o relógio dos 10 min.
curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"arrive\"}" >/dev/null
[ "$(query "SELECT count(*) FROM delivery_attempts WHERE order_id=${O1} AND kind='arrival'")" = "1" ] \
  || fail "marcou chegada duas vezes -- o relógio dos 10 min reiniciaria"
[ "$(query "SELECT count(*) FROM delivery_attempts WHERE order_id=${O1} AND kind='call'")" = "2" ] \
  || fail "as ligações não viraram rastro"
[ "$(query "SELECT count(*) FROM delivery_attempts WHERE order_id=${O1} AND ip IS NOT NULL")" != "0" ] \
  || fail "o rastro não gravou inet -- o chip da tela diz 'geo + inet'"
[ "$(query "SELECT count(*) FROM delivery_attempts WHERE order_id=${O1} AND lat IS NOT NULL")" = "3" ] \
  || fail "o rastro não gravou GPS"

CTX=$(curl -s "$BASE/couriers/incident.php?order_id=${O1}" "${COURIER_AUTH[@]}")
[ "$(echo "$CTX" | jq -r '.context.call_attempts')" = "2" ] || fail "contexto não conta as ligações: $CTX"
[ "$(echo "$CTX" | jq -r '.context.wait_minutes_required')" = "10" ] || fail "o prazo da tela não é 10 min: $CTX"
[ "$(echo "$CTX" | jq -r '.guaranteed_fee')" = "6" ] || fail "a corrida garantida não é o frete do pedido: $CTX"

echo "== 13.3: 'espere 10 min no local' é regra do servidor, não texto =="
EARLY=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"open\",\"kind\":\"customer_absent\",\"photo_storage_key\":\"${KEY1}\"}")
[ "$(echo "$EARLY" | jq -r '.code')" = "wait_not_satisfied" ] || fail "deixou abrir antes dos 10 min: $EARLY"

# Envelhece a chegada em 11 min: é o mesmo que esperar, sem esperar.
psql_run -c "UPDATE delivery_attempts SET created_at = created_at - interval '11 min'
              WHERE order_id=${O1} AND kind='arrival'"

OPEN=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"open\",\"kind\":\"customer_absent\",\"photo_storage_key\":\"${KEY1}\",\"lat\":-23.5630,\"lng\":-46.6333}")
INCIDENT_ID=$(echo "$OPEN" | jq -er '.incident.id') || fail "ocorrência não abriu: $OPEN"
[ "$(echo "$OPEN" | jq -r '.incident.call_attempts')" = "2" ] || fail "ligações não foram pra ocorrência: $OPEN"
[ "$(echo "$OPEN" | jq -r '.incident.photo_key')" = "$KEY1" ] || fail "a foto não ficou na ocorrência: $OPEN"
[ "$(query "SELECT waited >= interval '10 min' FROM delivery_incidents WHERE id=${INCIDENT_ID}")" = "t" ] \
  || fail "o tempo no local não foi gravado"
[ "$(query "SELECT count(*) FROM disputes WHERE order_id=${O1} AND kind='not_delivered' AND state='open'")" = "1" ] \
  || fail "a ocorrência não virou disputa -- o chip da tela diz '→ disputes'"
[ "$(query "SELECT count(*) FROM order_events WHERE order_id=${O1} AND meta->>'event'='delivery_incident'")" = "1" ] \
  || fail "o cliente não fica sabendo que alguém esteve na porta"

echo "== 13.3: ocorrência não muda o status sozinha; a sacola ainda está com ele =="
[ "$(query "SELECT status FROM orders WHERE id=${O1}")" = "delivering" ] \
  || fail "abrir ocorrência mudou o status do pedido"
DUP=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"open\",\"kind\":\"bad_address\",\"photo_storage_key\":\"${KEY1}\"}")
[ "$(echo "$DUP" | jq -r '.code')" = "incident_already_open" ] || fail "abriu duas ocorrências no mesmo pedido: $DUP"

echo "== 13.3: pedido de outro entregador não é visível =="
OTHER=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O1},\"action\":\"call\"}")
[ "$(echo "$OTHER" | jq -r '.code')" = "forbidden" ] || fail "cliente conseguiu mexer na ocorrência: $OTHER"

echo "== 13.3/13.4: o suporte libera, e a corrida é paga nas duas saídas =="
PAYABLE_BEFORE=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_payable' AND party_id='${COURIER_ID}'")
QUEUE=$(curl -s "$BASE/admin/incidents.php" "${ADMIN_AUTH[@]}")
[ "$(echo "$QUEUE" | jq -r "[.queue[] | select(.id == ${INCIDENT_ID})] | length")" = "1" ] \
  || fail "a ocorrência não apareceu na fila do admin: $QUEUE"
[ "$(echo "$QUEUE" | jq -r "[.queue[] | select(.id == ${INCIDENT_ID})][0].attempts | length")" = "4" ] \
  || fail "a fila do admin não mostra o rastro de tentativas: $QUEUE"

# Dinheiro: nada foi cobrado, então não há reembolso -- só a corrida garantida.
RESOLVE=$(curl -s -X POST "$BASE/admin/incidents.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"incident_id\":${INCIDENT_ID},\"resolution\":\"returned\"}")
[ "$(echo "$RESOLVE" | jq -r '.order.status')" = "cancelled" ] || fail "devolver à loja não encerrou o pedido: $RESOLVE"
[ "$(echo "$RESOLVE" | jq -r '.courier_compensation')" = "6" ] || fail "a corrida garantida não foi paga: $RESOLVE"
PAYABLE_AFTER=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_payable' AND party_id='${COURIER_ID}'")
[ "$(echo "$PAYABLE_AFTER - $PAYABLE_BEFORE" | bc)" = "6.00" ] \
  || fail "o livro não creditou o frete da corrida garantida (${PAYABLE_BEFORE} -> ${PAYABLE_AFTER})"
[ "$(query "SELECT origin FROM ledger_entries WHERE origin_id='incident:${INCIDENT_ID}'")" = "compensation" ] \
  || fail "o crédito da corrida garantida não entrou como compensação"
[ "$(query "SELECT state FROM disputes WHERE order_id=${O1}")" = "resolved" ] || fail "a disputa ficou aberta"
AGAIN=$(curl -s -X POST "$BASE/admin/incidents.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"incident_id\":${INCIDENT_ID},\"resolution\":\"discarded\"}")
[ "$(echo "$AGAIN" | jq -r '.code')" = "already_resolved" ] || fail "resolveu a mesma ocorrência duas vezes: $AGAIN"

echo "== 13.3: foto repetida em outra corrida vira sinal de fraude =="
O2=$(delivering_order cash)
curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O2},\"photo_storage_key\":\"${KEY1}\",\"photo_sha256\":\"$(echo "$UP1" | jq -r '.sha256')\"}" >/dev/null
O3=$(delivering_order cash)
UP3=$(upload_photo "$O3" "$PHOTO1")
[ "$(echo "$UP3" | jq -r '.reused')" = "true" ] || fail "a mesma foto em outra corrida passou sem sinal: $UP3"
[ "$(query "SELECT count(*) FROM fraud_signals WHERE kind='proof_reuse' AND order_id=${O3}")" = "1" ] \
  || fail "o sinal de fraude não foi registrado"

echo "== 13.4: a fila mostra cada método com como devolver, prazo e quem paga =="
# Um cancelamento em preparo, no cartão: é o caso da tela ("#C71A04 Cliente
# cancelou em preparo · taxa R$ 15,00 · a estornar R$ 63,40").
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_CARD=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
  -d "{\"order_id\":${O_CARD},\"card_token\":\"APRO-token-incident\"}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_CARD},\"to\":\"preparing\"}" >/dev/null
CANCEL=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_CARD},\"to\":\"cancelled\",\"reason\":\"Demora acima do previsto\"}")
REFUND_ID=$(echo "$CANCEL" | jq -er '.refund.id') || fail "cancelamento não gerou reembolso: $CANCEL"
# 60,00 + 6,00 de frete = 66,00; taxa de 15,00 => 51,00 a estornar.
[ "$(echo "$CANCEL" | jq -r '.refund.amount')" = "51.00" ] || fail "estorno com taxa errado: $CANCEL"
[ "$(query "SELECT fee FROM refunds WHERE id=${REFUND_ID}")" = "15.00" ] || fail "a taxa não ficou gravada no reembolso"

RQ=$(curl -s "$BASE/admin/refunds.php" "${ADMIN_AUTH[@]}")
ROW=$(echo "$RQ" | jq -r "[.queue[] | select(.id == ${REFUND_ID})][0]")
[ "$(echo "$ROW" | jq -r '.how')" = "Estorno automático na API" ] || fail "coluna COMO DEVOLVER errada: $ROW"
[ "$(echo "$ROW" | jq -r '.eta')" = "até 2 faturas" ] || fail "coluna PRAZO errada: $ROW"
[ "$(echo "$ROW" | jq -r '.payer_label')" = "LOJA" ] || fail "coluna QUEM PAGA errada: $ROW"
[ "$(echo "$ROW" | jq -r '.cause_rule | length > 0')" = "true" ] || fail "a regra de quem paga não veio escrita: $ROW"
[ "$(echo "$RQ" | jq -r '.count > 0')" = "true" ] || fail "cabeçalho da fila sem contagem: $RQ"
[ "$(echo "$RQ" | jq -r '.amount_in_analysis > 0')" = "true" ] || fail "cabeçalho da fila sem valor em análise: $RQ"

echo "== 13.4: só admin entra nesta fila =="
[ "$(curl -s "$BASE/admin/refunds.php" "${AUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "cliente abriu a fila de reembolso"

echo "== 13.4: perdoar a taxa aumenta o estorno, e a loja não paga por dinheiro que não recebeu =="
STORE_BEFORE=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='store_receivable' AND party_id='${RESTAURANT_ID}'")
DECIDE=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${REFUND_ID},\"action\":\"refund\",\"fee_adjustment\":\"forgive\",\"note\":\"Loja anunciou 25 min e estava com 41.\"}")
[ "$(echo "$DECIDE" | jq -r '.refund.amount')" = "66.00" ] || fail "perdoar a taxa não devolveu tudo: $DECIDE"
[ "$(echo "$DECIDE" | jq -r '.refund.fee')" = "0.00" ] || fail "a taxa não foi perdoada: $DECIDE"
[ "$(echo "$DECIDE" | jq -r '.refund.state')" = "sent" ] || fail "o estorno não saiu: $DECIDE"
[ "$(echo "$DECIDE" | jq -r '.how')" = "Estorno automático na API" ] || fail "rota do estorno errada: $DECIDE"
STORE_AFTER=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='store_receivable' AND party_id='${RESTAURANT_ID}'")
# O pedido nunca foi entregue: a parte da loja nunca foi lançada, e o
# dinheiro está com a plataforma, que estorna. Com a taxa perdoada, a loja
# fica sem a taxa e com a comida perdida -- cobrá-la dos R$ 66 do estorno
# seria fazê-la pagar por dinheiro que nunca passou por ela (a primeira
# versão fazia isso; ver lib/payments/refunds.php, refund_ledger).
[ "$(echo "$STORE_AFTER - $STORE_BEFORE" | bc)" = "0" ] \
  || fail "o estorno de pedido não entregue cobrou a loja (${STORE_BEFORE} -> ${STORE_AFTER})"
[ "$(query "SELECT status FROM payments WHERE order_id=${O_CARD}")" = "refunded" ] \
  || fail "o pagamento não foi marcado como estornado"
[ "$(query "SELECT status FROM orders WHERE id=${O_CARD}")" = "refunded" ] || fail "o pedido não virou 'refunded'"

echo "== 13.4: decidir de novo não paga duas vezes (idempotente por refund_key) =="
BOOKED=$(query "SELECT count(*) FROM ledger_entries WHERE origin='refund' AND origin_id=(SELECT refund_key::text FROM refunds WHERE id=${REFUND_ID})")
TWICE=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${REFUND_ID},\"action\":\"refund\",\"fee_adjustment\":\"keep\"}")
[ "$(echo "$TWICE" | jq -r '.code')" = "already_decided" ] || fail "decidiu o mesmo reembolso duas vezes: $TWICE"
[ "$(query "SELECT count(*) FROM ledger_entries WHERE origin='refund' AND origin_id=(SELECT refund_key::text FROM refunds WHERE id=${REFUND_ID})")" = "${BOOKED}" ] \
  || fail "o livro ganhou um segundo lançamento pela mesma chave"

echo "== 13.4: metade da taxa é metade mesmo =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_HALF=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
  -d "{\"order_id\":${O_HALF},\"card_token\":\"APRO-token-incident\"}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_HALF},\"to\":\"preparing\"}" >/dev/null
R_HALF=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_HALF},\"to\":\"cancelled\",\"reason\":\"Pedi por engano\"}" | jq -er '.refund.id')
D_HALF=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_HALF},\"action\":\"refund\",\"fee_adjustment\":\"half\"}")
[ "$(echo "$D_HALF" | jq -r '.refund.fee')" = "7.50" ] || fail "metade da taxa não é metade: $D_HALF"
[ "$(echo "$D_HALF" | jq -r '.refund.amount')" = "58.50" ] || fail "estorno com meia taxa errado: $D_HALF"

echo "== 13.4: crédito em carteira é OFERTA -- e recusar devolve o estorno =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_W=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
  -d "{\"order_id\":${O_W},\"card_token\":\"APRO-token-incident\"}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_W},\"to\":\"preparing\"}" >/dev/null
R_W=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_W},\"to\":\"cancelled\",\"reason\":\"Demora acima do previsto\"}" | jq -er '.refund.id')

OFFER=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_W},\"action\":\"wallet_offer\",\"fee_adjustment\":\"forgive\",\"bonus\":10}")
CREDIT_ID=$(echo "$OFFER" | jq -er '.offer.id') || fail "oferta de crédito não criou: $OFFER"
[ "$(echo "$OFFER" | jq -r '.offer.bonus')" = "10.00" ] || fail "o bônus não é o '+ R$ 10' da tela: $OFFER"
[ "$(echo "$OFFER" | jq -r '.refund.state')" = "pending" ] \
  || fail "oferta fechou o reembolso sozinha -- crédito não pode ser imposto: $OFFER"
# Enquanto não aceita, não há saldo nem lançamento: é proposta, não dinheiro.
[ "$(curl -s "$BASE/profile/wallet.php" "${AUTH[@]}" | jq -r '.balance')" = "0" ] \
  || fail "oferta pendente já virou saldo"
[ "$(query "SELECT count(*) FROM ledger_entries WHERE origin='refund' AND origin_id=(SELECT refund_key::text FROM refunds WHERE id=${R_W})")" = "0" ] \
  || fail "oferta não aceita já custou dinheiro no livro"

DUPOFF=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_W},\"action\":\"wallet_offer\"}")
[ "$(echo "$DUPOFF" | jq -r '.code')" = "offer_already_open" ] || fail "empilhou duas ofertas no mesmo reembolso: $DUPOFF"

WOFFERS=$(curl -s "$BASE/profile/wallet.php" "${AUTH[@]}")
[ "$(echo "$WOFFERS" | jq -r '.offers | length')" = "1" ] || fail "a oferta não chegou ao cliente: $WOFFERS"

DECLINE=$(curl -s -X POST "$BASE/profile/wallet.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"credit_id\":${CREDIT_ID},\"decision\":\"decline\"}")
[ "$(echo "$DECLINE" | jq -r '.restored_channel')" = "gateway" ] || fail "recusar não devolveu o estorno ao cartão: $DECLINE"
[ "$(query "SELECT channel FROM refunds WHERE id=${R_W}")" = "gateway" ] || fail "o canal não voltou pro método"
[ "$(query "SELECT state FROM refunds WHERE id=${R_W}")" = "pending" ] || fail "recusar tirou o reembolso da fila"

echo "== 13.4: aceitar vira saldo, lança o custo e o bônus é por nossa conta =="
OFFER2=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_W},\"action\":\"wallet_offer\",\"fee_adjustment\":\"forgive\",\"bonus\":10}")
CREDIT2=$(echo "$OFFER2" | jq -er '.offer.id') || fail "segunda oferta não criou: $OFFER2"
PLAT_BEFORE=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='platform_expense' AND party_id='${RESTAURANT_ID}'")
ACCEPT=$(curl -s -X POST "$BASE/profile/wallet.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"credit_id\":${CREDIT2},\"decision\":\"accept\"}")
[ "$(echo "$ACCEPT" | jq -r '.balance')" = "76" ] || fail "saldo não é estorno + bônus: $ACCEPT"
[ "$(query "SELECT state FROM refunds WHERE id=${R_W}")" = "done" ] || fail "o reembolso não fechou ao virar crédito"
# A cobrança original NÃO é desfeita: é por isso que crédito custa menos.
[ "$(query "SELECT status FROM payments WHERE order_id=${O_W}")" = "approved" ] \
  || fail "crédito em carteira estornou o cartão também"
PLAT_AFTER=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='platform_expense' AND party_id='${RESTAURANT_ID}'")
[ "$(echo "$PLAT_AFTER - $PLAT_BEFORE" | bc)" = "10.00" ] \
  || fail "o bônus não saiu do nosso bolso (${PLAT_BEFORE} -> ${PLAT_AFTER})"

echo "== 13.4: o saldo entra sozinho no próximo pedido =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
SPEND=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\"}")
# O pedido custa 66,00 e o saldo é 76,00: entra o que cabe, e o troco fica
# como crédito -- saldo maior que a conta não pode evaporar.
[ "$(echo "$SPEND" | jq -r '.wallet_applied')" = "66" ] || fail "o crédito não entrou no pedido: $SPEND"
[ "$(echo "$SPEND" | jq -r '.order.discount')" = "66.00" ] || fail "o crédito não virou desconto: $SPEND"
[ "$(echo "$SPEND" | jq -r '.order.total')" = "0.00" ] || fail "o total não caiu o crédito: $SPEND"
[ "$(echo "$SPEND" | jq -r '.wallet_balance')" = "10" ] || fail "o troco do crédito se perdeu: $SPEND"
[ "$(query "SELECT state FROM wallet_credits WHERE id=${CREDIT2}")" = "spent" ] || fail "o crédito não foi marcado como gasto"
[ "$(query "SELECT order_id_spent FROM wallet_credits WHERE id=${CREDIT2}")" = "$(echo "$SPEND" | jq -r '.order.id')" ] \
  || fail "o crédito gasto não aponta o pedido onde foi gasto"

echo "== 13.4: 'quem paga' inclui o dinheiro, que não devolve nada =="
O_CASH2=$(delivering_order cash)
PHOTO2="/tmp/incident-${STAMP}-2.jpg"
make_photo "$PHOTO2" "OC2-${STAMP}"
KEY2=$(upload_photo "$O_CASH2" "$PHOTO2" | jq -er '.photo_storage_key')
INC2=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O_CASH2},\"action\":\"open\",\"kind\":\"bad_address\",\"photo_storage_key\":\"${KEY2}\"}" \
  | jq -er '.incident.id') || fail "ocorrência de endereço errado não abriu"
# Endereço errado não pede espera de 10 min: esperar não conserta endereço.
RES2=$(curl -s -X POST "$BASE/admin/incidents.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"incident_id\":${INC2},\"resolution\":\"discarded\",\"refund\":true,\"refund_payer\":\"platform\"}")
[ "$(echo "$RES2" | jq -r '.refund')" = "null" ] \
  || fail "pedido em dinheiro gerou estorno -- nada foi cobrado: $RES2"
[ "$(echo "$RES2" | jq -r '.courier_compensation')" = "6" ] || fail "descartar não pagou a corrida: $RES2"

echo "== 13.3: 'entregar assim mesmo' devolve a corrida ao caminho normal =="
O4=$(delivering_order cash)
PHOTO3="/tmp/incident-${STAMP}-3.jpg"
make_photo "$PHOTO3" "OC3-${STAMP}"
KEY3=$(upload_photo "$O4" "$PHOTO3" | jq -er '.photo_storage_key')
INC3=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O4},\"action\":\"open\",\"kind\":\"unsafe_area\",\"photo_storage_key\":\"${KEY3}\"}" \
  | jq -er '.incident.id') || fail "ocorrência de área sem segurança não abriu"
[ "$(query "SELECT risk FROM disputes WHERE order_id=${O4}")" = "high" ] \
  || fail "área sem segurança devia entrar como risco alto"
RES3=$(curl -s -X POST "$BASE/admin/incidents.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"incident_id\":${INC3},\"resolution\":\"delivered\"}")
[ "$(echo "$RES3" | jq -r '.order.status')" = "delivering" ] || fail "liberar pra entregar mudou o status: $RES3"
[ "$(query "SELECT count(*) FROM ledger_entries WHERE origin_id='incident:${INC3}'")" = "0" ] \
  || fail "liberar pra entregar já pagou a corrida -- ela seria paga duas vezes"
CODE4=$(query "SELECT delivery_code FROM orders WHERE id=${O4}")
DONE=$(curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O4},\"delivery_code\":\"${CODE4}\"}")
[ "$(echo "$DONE" | jq -r '.order.status')" = "delivered" ] || fail "entrega liberada não fechou: $DONE"
[ "$(query "SELECT count(*) FROM ledger_entries WHERE order_id=${O4} AND account='courier_payable'")" = "1" ] \
  || fail "a corrida foi paga duas vezes depois da ocorrência"

rm -f "$PHOTO1" "$PHOTO2" "$PHOTO3"
echo
echo "smoke_incident OK"
