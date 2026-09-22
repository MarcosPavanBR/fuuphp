#!/usr/bin/env bash
# Smoke test de duas pontas do pagamento que ficavam "registradas, não feitas":
#
#  - trocar a forma de pagamento de um pedido em 'pending_payment'
#    (payments/change_method.php): Pix manual sem comprovante vira cartão no
#    MESMO pedido; com comprovante, ou com cartão já enviado, a troca é
#    recusada;
#  - gorjeta da avaliação (tela 5.5, migração 025) COBRADA no cartão do
#    pedido e lançada pro entregador; recusa do cartão não derruba a nota;
#    pedido sem cartão não aceita gorjeta;
#  - Pix emitido uma vez por pedido (voltar e escolher Pix de novo reaproveita
#    o mesmo QR), status do Mercado Pago traduzido pro CHECK de payments;
#  - loja em atraso de repasse ("somente online") recusa espécie no checkout
#    e na tela 4.1 (restaurants/show.php).
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
# Geografia própria, longe das outras suítes: o despacho não pode achar
# entregador de outro teste.
LAT=-23.80; LNG=-46.90

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-paychg-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

RESTAURANT_ID="$(gen_uuid)"; STAFF_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"; COURIER_USER_ID="$(gen_uuid)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"; CPF="$(php "$ROOT/tests/support/random_cpf.php")"
ACCESS_CODE="424242"; STAMP="$(date +%s%N)"

echo "== semear loja (com chave Pix), entregador e cardápio =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF_USER_ID}',   'restaurant_staff', 'Staff Troca', NULL, 'staff-paychg-${STAMP}@test.com'),
  ('${COURIER_USER_ID}', 'courier',          'Entregador Troca', NULL, 'courier-paychg-${STAMP}@test.com');

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Troca Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), ${LAT}, ${LNG});

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','pix_auto','pix_manual','cash']::payment_method[], 200.00, 0);
INSERT INTO restaurant_credentials (restaurant_id, pix_key) VALUES ('${RESTAURANT_ID}', 'troca-${STAMP}@pix.test');

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO couriers (id, user_id, city_ibge_code, active)
VALUES ('${COURIER_ID}', '${COURIER_USER_ID}', '${CITY}', true);
INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash)
VALUES ('${COURIER_USER_ID}', 'courier', '${COURIER_ID}', '${CPF}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex'));

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Troca', 40.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-paychg-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Troca\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"street\":\"Rua da Troca\",\"city\":\"São Paulo\",\"city_ibge_code\":\"3550308\",\"state\":\"SP\",\"postal_code\":\"01001000\",\"lat\":-23.805,\"lng\":${LNG},\"is_default\":true}" | jq -er '.id')
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")
COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token')
COURIER_AUTH=(-H "Authorization: Bearer $COURIER_TOKEN")
curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" -d '{"action":"start"}' >/dev/null
curl -s -X POST "$BASE/couriers/position.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"lat\":${LAT},\"lng\":${LNG}}" >/dev/null

checkout() {
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"$1\"}" | jq -er '.order.id'
}
pay() { # pay <order> [json extra]
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
    -d "{\"order_id\":$1${2:+,$2}}"
}
change() { # change <order> <method> [json extra]
  curl -s -X POST "$BASE/payments/change_method.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"order_id\":$1,\"payment_method\":\"$2\"${3:+,$3}}"
}
proof_image() {
  php -r '$i=imagecreatetruecolor(300,200);imagestring($i,5,10,90,$argv[2],imagecolorallocate($i,255,255,255));imagejpeg($i,$argv[1]);' "$1" "$2"
}
# Leva um pedido pago até 'delivered' pelas rotas reais (loja → entregador).
deliver() {
  local id="$1"
  for to in preparing ready; do
    curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${id},\"to\":\"${to}\"}" >/dev/null
  done
  psql_run -c "UPDATE orders SET courier_id='${COURIER_ID}' WHERE id=${id}"
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" -d "{\"order_id\":${id},\"to\":\"delivering\"}" >/dev/null
  local code
  code=$(query "SELECT delivery_code FROM orders WHERE id=${id}")
  curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" \
    -d "{\"order_id\":${id},\"delivery_code\":\"${code}\"}" >/dev/null
  [ "$(query "SELECT status FROM orders WHERE id=${id}")" = "delivered" ] || fail "pedido ${id} não chegou a 'delivered'"
}

echo "== troca: Pix manual com QR gerado e sem comprovante vira cartão no MESMO pedido =="
O1=$(checkout pix_manual)
pay "$O1" >/dev/null
[ "$(query "SELECT verification_deadline IS NOT NULL FROM orders WHERE id=${O1}")" = "t" ] || fail "Pix não pôs prazo"
CH=$(change "$O1" mp_card)
[ "$(echo "$CH" | jq -r '.order.payment_method')" = "mp_card" ] || fail "troca pra cartão não aconteceu: $CH"
[ "$(echo "$CH" | jq -r '.order.id')" = "$O1" ] || fail "a troca criou outro pedido"
[ "$(query "SELECT verification_deadline IS NULL FROM orders WHERE id=${O1}")" = "t" ] || fail "o prazo do Pix ficou no pedido de cartão"
[ "$(query "SELECT status || ':' || status_detail FROM payments WHERE order_id=${O1}")" = "rejected:method_changed" ] \
  || fail "o QR antigo não foi descartado: $(query "SELECT status, status_detail FROM payments WHERE order_id=${O1}")"
[ "$(query "SELECT meta->>'event' FROM order_events WHERE order_id=${O1} ORDER BY id DESC LIMIT 1")" = "payment_method_changed" ] \
  || fail "a troca não ficou na trilha do pedido"
PAID=$(pay "$O1" '"card_token":"APRO-troca"')
[ "$(echo "$PAID" | jq -r '.order.status')" = "paid" ] || fail "cartão depois da troca não pagou: $PAID"

echo "== troca: comprovante de QR descartado não entra =="
O2=$(checkout pix_manual)
pay "$O2" >/dev/null
change "$O2" cash >/dev/null
change "$O2" pix_manual >/dev/null
proof_image "/tmp/paychg-a-${STAMP}.jpg" "A-${STAMP}"
DEAD=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" -F "order_id=${O2}" -F "proof=@/tmp/paychg-a-${STAMP}.jpg;type=image/jpeg")
[ "$(echo "$DEAD" | jq -r '.code')" = "payment_not_started" ] || fail "comprovante entrou num QR descartado: $DEAD"

echo "== troca: com comprovante enviado, recusada =="
pay "$O2" >/dev/null
proof_image "/tmp/paychg-b-${STAMP}.jpg" "B-${STAMP}"
curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" -F "order_id=${O2}" -F "proof=@/tmp/paychg-b-${STAMP}.jpg;type=image/jpeg" | jq -er '.proof.id' >/dev/null \
  || fail "comprovante do QR novo não entrou"
LOCK=$(change "$O2" mp_card)
# O upload já tira o pedido de 'pending_payment' (vai pra conferência da
# loja), então a recusa vem antes da checagem de comprovante -- que fica no
# endpoint como segunda trava.
case "$(echo "$LOCK" | jq -r '.code')" in
  order_not_awaiting_payment|payment_method_locked) ;;
  *) fail "trocou com comprovante na mão da loja: $LOCK" ;;
esac
[ "$(query "SELECT payment_method FROM orders WHERE id=${O2}")" = "pix_manual" ] || fail "o método mudou mesmo recusado"

echo "== troca: cartão já enviado ao Mercado Pago trava o método =="
O6=$(checkout mp_card)
psql_run -c "INSERT INTO payments (order_id, provider, provider_ref, amount, status) VALUES (${O6}, 'mercadopago', 'inproc-${STAMP}', (SELECT total FROM orders WHERE id=${O6}), 'in_process')"
[ "$(change "$O6" cash | jq -r '.code')" = "payment_method_locked" ] || fail "trocou com cartão em análise no gateway"

echo "== troca: método que a loja não aceita, e maquininha sem tipo =="
O3=$(checkout pix_manual)
[ "$(change "$O3" pos_machine | jq -r '.code')" = "payment_method_not_allowed" ] || fail "aceitou maquininha que a loja não tem"
CASH=$(change "$O3" cash '"change_for":100')
[ "$(echo "$CASH" | jq -r '.order.change_for')" = "100.00" ] || fail "troco não foi gravado na troca pra dinheiro: $CASH"

echo "== gorjeta: cobrada no cartão do pedido e lançada pro entregador =="
deliver "$O1"
REVIEW=$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O1},\"rating\":5,\"courier_tip\":5}")
[ "$(echo "$REVIEW" | jq -r '.review.tip_state')" = "charged" ] || fail "gorjeta não foi cobrada: $REVIEW"
[ "$(echo "$REVIEW" | jq -r '.review.tip_provider_ref | startswith("fake-tip-")')" = "true" ] || fail "referência da cobrança não gravada"
[ "$(query "SELECT amount FROM ledger_entries WHERE origin_id='review_tip:${O1}' AND account='courier_payable' AND party_id='${COURIER_ID}'")" = "5.00" ] \
  || fail "a gorjeta não foi pro livro do entregador"
[ "$(query "SELECT count(*) FROM payments WHERE order_id=${O1} AND status='approved'")" = "1" ] \
  || fail "a gorjeta virou um segundo pagamento aprovado do pedido"

echo "== gorjeta: cartão recusa, a nota fica e nada entra no livro =="
O4=$(checkout mp_card)
pay "$O4" '"card_token":"APRO-troca2"' >/dev/null
deliver "$O4"
psql_run -c "UPDATE payments SET provider_ref = 'FAIL-${STAMP}' WHERE order_id=${O4} AND status='approved'"
REFUSED=$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O4},\"rating\":4,\"courier_tip\":10}")
[ "$(echo "$REFUSED" | jq -r '.review.rating')" = "4" ] || fail "recusa do cartão derrubou a avaliação: $REFUSED"
[ "$(echo "$REFUSED" | jq -r '.review.tip_state')" = "failed" ] || fail "recusa não ficou registrada: $REFUSED"
[ "$(query "SELECT count(*) FROM ledger_entries WHERE origin_id='review_tip:${O4}'")" = "0" ] || fail "gorjeta recusada entrou no livro"

echo "== gorjeta: pedido sem cartão não aceita, e teto de sanidade =="
O5=$(checkout cash)
pay "$O5" >/dev/null
deliver "$O5"
[ "$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O5},\"rating\":5,\"courier_tip\":5}" | jq -r '.code')" = "tip_requires_card" ] \
  || fail "gorjeta aceita em pedido pago em dinheiro"
[ "$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O5},\"rating\":5,\"courier_tip\":5000}" | jq -r '.code')" = "invalid_courier_tip" ] \
  || fail "gorjeta de R\$ 5.000 passou"
[ "$(curl -s -X POST "$BASE/reviews/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O5},\"rating\":5}" | jq -r '.review.tip_state')" = "none" ] \
  || fail "avaliação sem gorjeta em pedido de dinheiro não passou"

echo "== Pix: escolher de novo reaproveita o MESMO QR, manual e automático =="
O7=$(checkout pix_manual)
FIRST=$(pay "$O7")
AGAIN=$(pay "$O7")
[ "$(echo "$AGAIN" | jq -r '.payment.id')" = "$(echo "$FIRST" | jq -r '.payment.id')" ] || fail "Pix manual emitiu um segundo QR: $AGAIN"
[ "$(echo "$AGAIN" | jq -r '.pix_copy_paste')" = "$(echo "$FIRST" | jq -r '.pix_copy_paste')" ] || fail "o copia-e-cola mudou entre as duas chamadas"
[ "$(echo "$AGAIN" | jq -r '.reused')" = "true" ] || fail "reaproveitamento não foi sinalizado"
O8=$(checkout pix_auto)
A1=$(pay "$O8"); A2=$(pay "$O8")
[ "$(echo "$A2" | jq -r '.payment.provider_ref')" = "$(echo "$A1" | jq -r '.payment.provider_ref')" ] || fail "Pix automático emitiu segunda cobrança no MP: $A2"
[ "$(echo "$A2" | jq -r '.pix_copy_paste')" = "$(echo "$A1" | jq -r '.pix_copy_paste')" ] || fail "QR do Pix automático mudou"
[ "$(query "SELECT count(*) FROM payments WHERE order_id=${O8}")" = "1" ] || fail "mais de uma cobrança Pix no pedido"

echo "== Pix automático não troca de método depois de emitido =="
[ "$(change "$O8" mp_card | jq -r '.code')" = "payment_method_locked" ] || fail "trocou com Pix automático vivo no MP"

echo "== webhook: status do MP traduzido ('pending' fica em análise, 'cancelled' recusa) =="
REF8=$(echo "$A1" | jq -r '.payment.provider_ref')
PENDING=$(curl -s -X POST "$BASE/payments/webhook_mercadopago.php" -H "Content-Type: application/json" \
  -d "{\"type\":\"payment\",\"data\":{\"id\":\"${REF8}\"},\"status\":\"pending\"}")
[ "$(echo "$PENDING" | jq -r '.status')" = "in_process" ] || fail "'pending' do MP não virou in_process: $PENDING"
CANCEL=$(curl -s -X POST "$BASE/payments/webhook_mercadopago.php" -H "Content-Type: application/json" \
  -d "{\"type\":\"payment\",\"data\":{\"id\":\"${REF8}\"},\"status\":\"cancelled\"}")
[ "$(echo "$CANCEL" | jq -r '.status')" = "rejected" ] || fail "'cancelled' do MP (Pix expirado) quebrou: $CANCEL"
[ "$(query "SELECT status FROM orders WHERE id=${O8}")" = "rejected" ] || fail "Pix expirado no MP não recusou o pedido"

echo "== loja em atraso de repasse: somente online, no checkout e na 4.1 =="
psql_run -c "UPDATE restaurants SET online_only_until = now() + interval '3 days' WHERE id='${RESTAURANT_ID}'"
SHOW=$(curl -s "$BASE/restaurants/show.php?id=${RESTAURANT_ID}")
[ "$(echo "$SHOW" | jq -r '.payment_methods | index("cash")')" = "null" ] || fail "4.1 ainda oferece dinheiro pra loja em atraso: $SHOW"
[ "$(echo "$SHOW" | jq -r '.payment_methods | index("mp_card") != null')" = "true" ] || fail "a trava tirou também o cartão: $SHOW"
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
BLOCKED=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\"}")
[ "$(echo "$BLOCKED" | jq -r '.code')" = "payment_method_not_allowed" ] || fail "checkout aceitou espécie em loja em atraso: $BLOCKED"
psql_run -c "UPDATE restaurants SET online_only_until = NULL WHERE id='${RESTAURANT_ID}'"

echo "smoke_payment_changes OK"
