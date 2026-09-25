#!/usr/bin/env bash
# Smoke test das pontas de dinheiro que faltavam:
#
#  - executor de reembolsos (13.4 → gateway): automático no cartão, falha
#    gravada e retentativa, confirmação humana no Pix manual;
#  - exportação contábil em CSV (12.3);
#  - foto da ocorrência servida só pro admin (13.3);
#  - maquininha do próprio entregador (9.6): vira dívida como espécie.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8117
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-money-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

RESTAURANT_ID="$(gen_uuid)"; STAFF_USER_ID="$(gen_uuid)"; ADMIN_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"; COURIER_USER_ID="$(gen_uuid)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"; CPF="$(php "$ROOT/tests/support/random_cpf.php")"
ACCESS_CODE="424242"; STAMP="$(date +%s%N)"
ADMIN_PHONE="1197$(( (RANDOM << 15 | RANDOM) % 9000000 + 1000000 ))"
NSU_OWN=$(( $(date +%s) % 900000 + 100000 ))

echo "== dinheiro em centavos (lib/core/money.php, COE-02): meio centavo, negativo, texto do banco, soma longa =="
OUT=$(php -r '
require $argv[1];
$ok = money_cents("2.675") === 268 && money_cents(2.675) === 268 && money_cents("1.005") === 101
   && money_cents("-0.05") === -5 && money_cents("12") === 1200 && money_cents("12.3") === 1230 && money_cents(null) === 0
   && money_str(123456) === "1234.56" && money_str(-5) === "-0.05" && money_str(7) === "0.07"
   && money_sum(array_fill(0, 10000, "0.10")) === 1000.0 && money_sum(["0.1", "0.2"]) === 0.3;
echo $ok ? "ok" : "falhou";' "$ROOT/lib/core/money.php")
[ "$OUT" = "ok" ] || fail "helpers de dinheiro erraram a conta"

echo "== semear loja, entregador, admin e política SEM maquininha própria =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF_USER_ID}',   'restaurant_staff', 'Staff Dinheiro', NULL, 'staff-money-${STAMP}@test.com'),
  ('${ADMIN_USER_ID}',   'admin',            'Admin Dinheiro', '${ADMIN_PHONE}', 'admin-money-${STAMP}@test.com'),
  ('${COURIER_USER_ID}', 'courier',          'Entregador Dinheiro', NULL, 'courier-money-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cancel_fee, delivery_base_fee, allow_courier_own_pos, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         0, 5.00, false, id
  FROM users WHERE email = 'staff-money-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Dinheiro Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','pix_manual','cash','pos_machine']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO couriers (id, user_id, city_ibge_code, active)
VALUES ('${COURIER_ID}', '${COURIER_USER_ID}', '${CITY}', true);
INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash)
VALUES ('${COURIER_USER_ID}', 'courier', '${COURIER_ID}', '${CPF}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex'));

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Dinheiro', 40.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-money-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Dinheiro\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua do Dinheiro","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5630,"lng":-46.6333,"is_default":true}' | jq -er '.id')
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")
COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token')
COURIER_AUTH=(-H "Authorization: Bearer $COURIER_TOKEN")
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" -d '{"action":"start"}' >/dev/null
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"lat":-23.5505,"lng":-46.6333}' >/dev/null  # na porta da loja: dentro do raio da 1ª rodada
ADMIN_CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$ADMIN_CODE\"}" | jq -er '.access_token')
ADMIN_AUTH=(-H "Authorization: Bearer $ADMIN_TOKEN")

# Pedido pago em preparo e cancelado pelo cliente: nasce um reembolso pendente.
cancelled_paid_order() {
  local method="$1"
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  local id
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"${method}\"}" | jq -er '.order.id')
  if [ "$method" = "mp_card" ]; then
    curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
      -d "{\"order_id\":${id},\"card_token\":\"APRO-money\"}" >/dev/null
  else
    # Pix manual: pula o comprovante e aprova direto -- o que importa aqui é
    # o reembolso depois, não a validação (coberta em smoke_payments).
    psql_run -c "INSERT INTO payments (order_id, provider, provider_ref, amount, status) VALUES (${id}, 'offline', NULL, (SELECT total FROM orders WHERE id=${id}), 'approved')"
    psql_run -c "SELECT advance_order(${id}, 'paid', NULL, 'system')" >/dev/null
  fi
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
    -d "{\"order_id\":${id},\"to\":\"preparing\"}" >/dev/null
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"order_id\":${id},\"to\":\"cancelled\",\"reason\":\"Pedi por engano\"}" | jq -er '.refund.id'
}

decide() {
  curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
    -d "{\"refund_id\":$1,\"action\":\"refund\",\"fee_adjustment\":\"keep\"}" >/dev/null
}

echo "== executor: cartão decidido vira estorno confirmado pelo gateway =="
R_CARD=$(cancelled_paid_order mp_card) || fail "cancelamento no cartão não gerou reembolso"
decide "$R_CARD"
[ "$(query "SELECT state FROM refunds WHERE id=${R_CARD}")" = "sent" ] || fail "decisão não deixou o reembolso em 'sent'"
php "$ROOT/bin/execute_refunds.php" >/tmp/smoke-money-exec.log 2>&1 || fail "executor quebrou: $(cat /tmp/smoke-money-exec.log)"
[ "$(query "SELECT state FROM refunds WHERE id=${R_CARD}")" = "done" ] || fail "o executor não concluiu o estorno do cartão"
[ "$(query "SELECT provider_ref LIKE 'fake-refund-%' FROM refunds WHERE id=${R_CARD}")" = "t" ] \
  || fail "o id do estorno no gateway não foi gravado"
php "$ROOT/bin/execute_refunds.php" >>/tmp/smoke-money-exec.log 2>&1
[ "$(query "SELECT attempts FROM refunds WHERE id=${R_CARD}")" = "1" ] || fail "rodar de novo executou o mesmo estorno outra vez"

echo "== executor: recusa do gateway é gravada, e esgota em 'failed' =="
R_FAIL=$(cancelled_paid_order mp_card)
decide "$R_FAIL"
psql_run -c "UPDATE payments SET provider_ref = 'FAIL-${STAMP}' WHERE id = (SELECT payment_id FROM refunds WHERE id=${R_FAIL})"
for _ in 1 2; do php "$ROOT/bin/execute_refunds.php" >>/tmp/smoke-money-exec.log 2>&1; done
[ "$(query "SELECT state || ':' || attempts FROM refunds WHERE id=${R_FAIL}")" = "sent:2" ] \
  || fail "a falha não ficou pra nova tentativa: $(query "SELECT state, attempts FROM refunds WHERE id=${R_FAIL}")"
[ "$(query "SELECT last_error LIKE '%recusou%' FROM refunds WHERE id=${R_FAIL}")" = "t" ] || fail "o motivo da falha não foi gravado"
php "$ROOT/bin/execute_refunds.php" >>/tmp/smoke-money-exec.log 2>&1
[ "$(query "SELECT state FROM refunds WHERE id=${R_FAIL}")" = "failed" ] || fail "três recusas não viraram 'failed'"
INFLIGHT=$(curl -s "$BASE/admin/refunds.php" "${ADMIN_AUTH[@]}")
[ "$(echo "$INFLIGHT" | jq -r "[.inflight[] | select(.id == ${R_FAIL})][0].state")" = "failed" ] \
  || fail "o reembolso falho não aparece na fila em execução: $INFLIGHT"

echo "== executor: o admin tenta de novo depois de corrigir =="
psql_run -c "UPDATE payments SET provider_ref = 'OK-${STAMP}' WHERE id = (SELECT payment_id FROM refunds WHERE id=${R_FAIL})"
RETRY=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_FAIL},\"action\":\"execute\"}")
[ "$(echo "$RETRY" | jq -r '.refund.state')" = "done" ] || fail "tentar de novo não concluiu: $RETRY"

echo "== Pix manual: dinheiro na chave da loja, confirmação é humana e exige referência =="
R_PIX=$(cancelled_paid_order pix_manual)
decide "$R_PIX"
php "$ROOT/bin/execute_refunds.php" >>/tmp/smoke-money-exec.log 2>&1
[ "$(query "SELECT state FROM refunds WHERE id=${R_PIX}")" = "sent" ] || fail "o executor mexeu num Pix que não é nosso"
NOREF=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_PIX},\"action\":\"confirm_manual\"}")
[ "$(echo "$NOREF" | jq -r '.code')" = "provider_ref_required" ] || fail "confirmou Pix sem identificador: $NOREF"
MANUAL=$(curl -s -X POST "$BASE/admin/refunds.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"refund_id\":${R_PIX},\"action\":\"confirm_manual\",\"provider_ref\":\"E2E-${STAMP}\"}")
[ "$(echo "$MANUAL" | jq -r '.refund.state')" = "done" ] || fail "confirmação manual não fechou: $MANUAL"

echo "== 12.3: CSV contábil só pro admin, com ; e vírgula decimal =="
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/export.php?kind=ledger" "${STAFF_AUTH[@]}")" = "403" ] \
  || fail "a loja baixou o livro da plataforma"
TODAY=$(TZ=America/Sao_Paulo date +%F)   # o dia do negócio é o de Brasília
curl -s -D /tmp/smoke-money-h.txt "$BASE/admin/export.php?kind=orders&from=${TODAY}&to=${TODAY}" "${ADMIN_AUTH[@]}" >/tmp/smoke-money-orders.csv
grep -qi 'content-type: text/csv' /tmp/smoke-money-h.txt || fail "export não respondeu CSV"
head -c 3 /tmp/smoke-money-orders.csv | od -An -tx1 | grep -q 'ef bb bf' || fail "CSV sem BOM (o Excel troca os acentos)"
head -1 /tmp/smoke-money-orders.csv | grep -q 'pedido;data_hora;restaurante' || fail "cabeçalho do CSV errado: $(head -1 /tmp/smoke-money-orders.csv)"
grep -q ';45,00;' /tmp/smoke-money-orders.csv || fail "total não saiu com vírgula decimal: $(sed -n 2p /tmp/smoke-money-orders.csv)"
curl -s "$BASE/admin/export.php?kind=ledger&from=${TODAY}&to=${TODAY}" "${ADMIN_AUTH[@]}" >/tmp/smoke-money-ledger.csv
grep -q ';refund;' /tmp/smoke-money-ledger.csv || fail "o livro exportado não tem os estornos do dia"
BAD=$(curl -s "$BASE/admin/export.php?kind=tudo" "${ADMIN_AUTH[@]}")
[ "$(echo "$BAD" | jq -r '.code')" = "invalid_kind" ] || fail "export aceitou tipo inventado: $BAD"

echo "== 13.3: foto da ocorrência só pro admin =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_INC=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${O_INC}}" >/dev/null
for to in preparing ready; do
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_INC},\"to\":\"${to}\"}" >/dev/null
done
OFFER=$(curl -s "$BASE/couriers/offers.php" "${COURIER_AUTH[@]}" | jq -er ".offers[] | select(.order_id == ${O_INC}) | .offer_id")
curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" -d "{\"offer_id\":${OFFER}}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_INC},\"to\":\"delivering\"}" >/dev/null
PHOTO="/tmp/money-${STAMP}.jpg"
php -r '$i=imagecreatetruecolor(200,150);imagestring($i,5,10,60,$argv[2],imagecolorallocate($i,255,255,255));imagejpeg($i,$argv[1]);' "$PHOTO" "M-${STAMP}"
KEY=$(curl -s -X POST "$BASE/couriers/incident_photo.php" "${COURIER_AUTH[@]}" -F "order_id=${O_INC}" -F "photo=@${PHOTO};type=image/jpeg" | jq -er '.photo_storage_key')
INC=$(curl -s -X POST "$BASE/couriers/incident.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O_INC},\"action\":\"open\",\"kind\":\"bad_address\",\"photo_storage_key\":\"${KEY}\"}" | jq -er '.incident.id')
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/incident_photo.php?id=${INC}" "${STAFF_AUTH[@]}")" = "403" ] \
  || fail "a loja viu a foto da porta do cliente"
curl -s -D /tmp/smoke-money-ph.txt -o /tmp/smoke-money-photo.bin "$BASE/admin/incident_photo.php?id=${INC}" "${ADMIN_AUTH[@]}"
grep -qi 'content-type: image/jpeg' /tmp/smoke-money-ph.txt || fail "a foto não veio como imagem"
cmp -s "$PHOTO" /tmp/smoke-money-photo.bin || fail "o arquivo servido não é a foto enviada"
grep -qi 'cache-control: private, no-store' /tmp/smoke-money-ph.txt || fail "foto privada saiu cacheável"

echo "== livro do pedido: espécie entregue deve a parte da loja, a baixa paga, sobra comissão + frete =="
store_balance() { query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='store_receivable' AND party_id='${RESTAURANT_ID}'"; }
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_CASH=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"tip\":3.00}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${O_CASH}}" >/dev/null
for to in preparing ready; do
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_CASH},\"to\":\"${to}\"}" >/dev/null
done
# Resolve a ocorrência aberta antes, pra este entregador ficar livre.
psql_run -c "UPDATE orders SET courier_id='${COURIER_ID}' WHERE id=${O_CASH}"
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_CASH},\"to\":\"delivering\"}" >/dev/null
read -r T G C F TIP <<<"$(query "SELECT total, subtotal - commission, commission, delivery_fee, tip FROM orders WHERE id=${O_CASH}" | tr '|' ' ')"
BEFORE=$(store_balance)
CODE_CASH=$(query "SELECT delivery_code FROM orders WHERE id=${O_CASH}")
curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O_CASH},\"delivery_code\":\"${CODE_CASH}\"}" >/dev/null
AFTER_DELIVERY=$(store_balance)
[ "$(echo "$AFTER_DELIVERY - $BEFORE" | bc)" = "-${G}" ] \
  || fail "entregar em espécie não lançou a parte da loja como devida (${BEFORE} -> ${AFTER_DELIVERY}, G=${G})"
[ "$(query "SELECT amount FROM ledger_entries WHERE origin_id='tip:${O_CASH}' AND account='courier_payable'")" = "${TIP}" ] \
  || fail "a gorjeta não foi pro entregador"
CASH_NOW=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")
INTENT=$(curl -s -X POST "$BASE/couriers/settle_intent.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"method\":\"in_person\"}")
SCODE=$(echo "$INTENT" | jq -er '.code') || fail "baixa não gerou código: $INTENT"
SETTLE=$(curl -s -X POST "$BASE/restaurants/confirm_settlement.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"code\":\"${SCODE}\",\"counted_amount\":${CASH_NOW}}")
[ "$(echo "$SETTLE" | jq -r '.settled')" = "true" ] || fail "baixa não confirmou: $SETTLE"
AFTER_SETTLE=$(store_balance)
[ "$(echo "$AFTER_SETTLE - $BEFORE" | bc)" = "$(echo "$T - $G" | bc)" ] \
  || fail "depois da baixa a loja devia nos dever T−G (comissão + frete + gorjeta): ${BEFORE} -> ${AFTER_SETTLE}"
[ "$(echo "$T - $G == $C + $F + $TIP" | bc)" = "1" ] || fail "T−G não é comissão + frete + gorjeta (${T} ${G} ${C} ${F} ${TIP})"

echo "== livro do pedido: cartão entregue é repasse que devemos à loja =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_ONLINE=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"mp_card\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
  -d "{\"order_id\":${O_ONLINE},\"card_token\":\"APRO-money\"}" >/dev/null
for to in preparing ready; do
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_ONLINE},\"to\":\"${to}\"}" >/dev/null
done
psql_run -c "UPDATE orders SET courier_id='${COURIER_ID}' WHERE id=${O_ONLINE}"
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_ONLINE},\"to\":\"delivering\"}" >/dev/null
G2=$(query "SELECT subtotal - commission FROM orders WHERE id=${O_ONLINE}")
B2=$(store_balance)
curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O_ONLINE},\"delivery_code\":\"$(query "SELECT delivery_code FROM orders WHERE id=${O_ONLINE}")\"}" >/dev/null
[ "$(echo "$(store_balance) - $B2" | bc)" = "-${G2}" ] || fail "pedido no cartão não virou repasse devido à loja"

echo "== 9.6: maquininha própria bloqueada quando a política não libera =="
DENY=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"action":"register_own","label":"MINHA-1","acquirer":"sumup"}')
[ "$(echo "$DENY" | jq -r '.code')" = "own_pos_not_allowed" ] || fail "cadastrou máquina própria sem a política liberar: $DENY"

echo "== 9.6: liberada, a venda na máquina própria vira dívida como espécie =="
psql_run <<SQL
INSERT INTO platform_policies (version, enabled_methods, cancel_fee, delivery_base_fee, allow_courier_own_pos, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         0, 5.00, true, '${STAFF_USER_ID}';
SQL
OWN=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"action":"register_own","label":"MINHA-1","acquirer":"sumup"}')
[ "$(echo "$OWN" | jq -r '.device.courier_id')" = "${COURIER_ID}" ] || fail "a máquina não ficou no nome do entregador: $OWN"
[ "$(echo "$OWN" | jq -r '.device.restaurant_id')" = "null" ] || fail "máquina própria ganhou loja como dona: $OWN"

curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
O_POS=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"pos_machine\",\"machine_kind\":\"credit\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${O_POS}}" >/dev/null
for to in preparing ready; do
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${O_POS},\"to\":\"${to}\"}" >/dev/null
done
psql_run -c "UPDATE orders SET courier_id='${COURIER_ID}' WHERE id=${O_POS}"
TOTAL_POS=$(query "SELECT total FROM orders WHERE id=${O_POS}")
CASH_BEFORE=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")
SALE=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O_POS},\"amount\":${TOTAL_POS}}")
[ "$(echo "$SALE" | jq -r '.own_device')" = "true" ] || fail "a venda não usou a máquina própria: $SALE"
CASH_AFTER=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")
[ "$(echo "$CASH_AFTER - $CASH_BEFORE" | bc)" = "$TOTAL_POS" ] \
  || fail "a venda na máquina própria não virou dívida (${CASH_BEFORE} -> ${CASH_AFTER})"
curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O_POS},\"nsu\":\"${NSU_OWN}\",\"amount\":${TOTAL_POS}}" >/dev/null
[ "$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")" = "$CASH_AFTER" ] \
  || fail "completar o NSU cobrou a dívida duas vezes"
RECON=$(curl -s "$BASE/restaurants/reconciliation.php?day=$(TZ=America/Sao_Paulo date +%F)" "${STAFF_AUTH[@]}")
[ "$(echo "$RECON" | jq -r "[.rows[] | select(.order_id == ${O_POS})] | length")" = "0" ] \
  || fail "venda da máquina do entregador apareceu na conciliação da loja: $RECON"

rm -f "$PHOTO"
echo
echo "smoke_money OK"
