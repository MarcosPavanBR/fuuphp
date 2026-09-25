#!/usr/bin/env bash
# Smoke test do módulo de pagamentos (migração 005 + Fase 4/7.3 do mock):
# checkout do carrinho incremental, os cinco métodos de payment_method,
# idempotência de payments/pay.php, upload+validação humana de comprovante
# de Pix manual (com aprovação dupla barrada por FOR UPDATE) e o webhook
# assíncrono do Pix automático. Roda inteiramente em MERCADOPAGO_MODE=fake
# (Especificação, lib/payments/mercadopago.php): não há conta sandbox real disponível
# neste ambiente, então o cliente HTTP do Mercado Pago é substituído por uma
# simulação no mesmo formato de resposta -- documentado no README.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake
export PROOF_STORAGE_DIR="/tmp/smoke-payments-proofs"
rm -rf "$PROOF_STORAGE_DIR"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8099
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-payments-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
STAFF_EMAIL="staff-payments-smoke-$(date +%s%N)@test.com"

echo "== semear loja, política, cardápio, chave Pix e login de loja =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
VALUES ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Payments Smoke', '${STAFF_EMAIL}');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = '${STAFF_EMAIL}';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, commission_bps, approved_at)
VALUES ('${RESTAURANT_ID}', 'Payments Smoke Restaurant', '${CNPJ}', '${CITY}', true, 800, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], 100.00, 10.00);

INSERT INTO restaurant_credentials (restaurant_id, pix_key)
VALUES ('${RESTAURANT_ID}', 'pagamentos@payments-smoke.com.br');

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Payments Smoke', 50.00, 'Pratos', true);
SQL
# senha do hash acima é "senha123" (mesmo hash reaproveitado do smoke_ordering.sh)
ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-payments-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== signup do cliente e endereço =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Payments Smoke\"}" | jq -er '.dev_code') || fail "otp_request falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Payments Smoke","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

STAFF=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}")
STAFF_TOKEN=$(echo "$STAFF" | jq -er '.access_token') || fail "login de loja falhou: $STAFF"
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

# Monta um carrinho novo (cart/add_item.php) e faz o checkout pro método
# indicado -- devolve o order_id em $ORDER_ID.
open_order_for() {
  local method="$1"; shift
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  local extra="$*"
  local payload="{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"${method}\"${extra:+,${extra}}}"
  local resp
  resp=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "$payload")
  echo "$resp" | jq -er '.order.id' || { echo "checkout falhou: $resp" >&2; return 1; }
}

echo "== checkout sem endereço é barrado (422) =="
NOADDR=$(curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}")
NOADDR_CHECKOUT=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"payment_method\":\"cash\"}")
[ "$(echo "$NOADDR_CHECKOUT" | jq -r '.code')" = "address_id_required" ] || fail "checkout sem endereço não foi barrado: $NOADDR_CHECKOUT"
# esvazia esse carrinho pra não atrapalhar o próximo teste
curl -s -X POST "$BASE/cart/remove_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_item_id\":$(echo "$NOADDR" | jq -r '.items[0].id')}" >/dev/null

echo "== dinheiro (cash): pay.php sem X-Idempotency-Key é barrado (400) =="
CASH_ORDER=$(open_order_for cash '"change_for":60')
NO_KEY=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${CASH_ORDER}}")
[ "$NO_KEY" = "400" ] || fail "pay sem idempotency key não foi barrado (veio $NO_KEY)"

echo "== dinheiro (cash): paga e vai direto pra 'paid' (dinheiro só é conferido na entrega) =="
KEY1=$(gen_uuid)
CASH_PAY=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY1" "${AUTH[@]}" \
  -d "{\"order_id\":${CASH_ORDER}}")
[ "$(echo "$CASH_PAY" | jq -r '.order.status')" = "paid" ] || fail "cash não foi pra paid: $CASH_PAY"
[ "$(echo "$CASH_PAY" | jq -r '.payment.provider')" = "offline" ] || fail "payment.provider errado pro cash: $CASH_PAY"

echo "== replay da mesma X-Idempotency-Key devolve a MESMA resposta, sem cobrar de novo =="
CASH_REPLAY=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY1" "${AUTH[@]}" \
  -d "{\"order_id\":${CASH_ORDER}}")
[ "$(echo "$CASH_REPLAY" | jq -r '.payment.id')" = "$(echo "$CASH_PAY" | jq -r '.payment.id')" ] || fail "replay não devolveu o mesmo payment: $CASH_REPLAY"
PAY_COUNT=$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM payments WHERE order_id=${CASH_ORDER}")
[ "$PAY_COUNT" = "1" ] || fail "replay criou uma segunda cobrança (esperava 1 linha em payments, veio $PAY_COUNT)"

echo "== reusar a mesma chave numa requisição DIFERENTE (mesmo pedido, corpo diferente) dá 409 =="
REUSE=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY1" "${AUTH[@]}" \
  -d "{\"order_id\":${CASH_ORDER},\"noise\":true}")
[ "$(echo "$REUSE" | jq -r '.code')" = "idempotency_key_reused" ] || fail "reuso de chave não foi barrado: $REUSE"

echo "== maquininha (pos_machine): exige machine_kind no checkout, paga e vai pra 'paid' =="
MACHINE_ORDER=$(open_order_for pos_machine '"machine_kind":"credit"')
KEY2=$(gen_uuid)
MACHINE_PAY=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY2" "${AUTH[@]}" \
  -d "{\"order_id\":${MACHINE_ORDER}}")
[ "$(echo "$MACHINE_PAY" | jq -r '.order.status')" = "paid" ] || fail "maquininha não foi pra paid: $MACHINE_PAY"

echo "== cartão aprovado (mp_card, modo fake): vai pra 'paid' com bandeira/final registrados =="
CARD_ORDER=$(open_order_for mp_card)
KEY3=$(gen_uuid)
CARD_PAY=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY3" "${AUTH[@]}" \
  -d "{\"order_id\":${CARD_ORDER},\"card_token\":\"5031433215406351\",\"installments\":1,\"payer_cpf\":\"11144477735\"}")
[ "$(echo "$CARD_PAY" | jq -r '.order.status')" = "paid" ] || fail "cartão aprovado não foi pra paid: $CARD_PAY"
[ "$(echo "$CARD_PAY" | jq -r '.payment.status')" = "approved" ] || fail "payment.status errado pro cartão aprovado: $CARD_PAY"

echo "== cartão recusado (token de teste OTHE...): vai pra 'rejected', resposta HTTP 402 =="
REJ_ORDER=$(open_order_for mp_card)
KEY4=$(gen_uuid)
REJ_HTTP=$(curl -s -o /tmp/smoke-payments-rej.json -w '%{http_code}' -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY4" "${AUTH[@]}" \
  -d "{\"order_id\":${REJ_ORDER},\"card_token\":\"OTHE12345678\",\"installments\":1}")
REJ_PAY=$(cat /tmp/smoke-payments-rej.json)
[ "$REJ_HTTP" = "402" ] || fail "cartão recusado não devolveu 402 (veio $REJ_HTTP): $REJ_PAY"
[ "$(echo "$REJ_PAY" | jq -r '.order.status')" = "rejected" ] || fail "cartão recusado não foi pra rejected: $REJ_PAY"

echo "== Pix automático (pix_auto): pay.php devolve QR e fica 'in_process'; webhook confirma =="
AUTO_ORDER=$(open_order_for pix_auto)
KEY5=$(gen_uuid)
AUTO_PAY=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY5" "${AUTH[@]}" \
  -d "{\"order_id\":${AUTO_ORDER}}")
[ "$(echo "$AUTO_PAY" | jq -r '.order.status')" = "pending_payment" ] || fail "pix_auto não ficou esperando o webhook: $AUTO_PAY"
AUTO_REF=$(echo "$AUTO_PAY" | jq -er '.payment.provider_ref') || fail "pix_auto sem provider_ref: $AUTO_PAY"
[ -n "$(echo "$AUTO_PAY" | jq -r '.pix_copy_paste')" ] || fail "pix_auto sem pix_copy_paste: $AUTO_PAY"

WEBHOOK=$(curl -s -X POST "$BASE/payments/webhook_mercadopago.php" -H "Content-Type: application/json" \
  -d "{\"type\":\"payment\",\"data\":{\"id\":\"${AUTO_REF}\"},\"status\":\"approved\"}")
[ "$(echo "$WEBHOOK" | jq -r '.status')" = "approved" ] || fail "webhook não confirmou o pix_auto: $WEBHOOK"
AUTO_ORDER_STATUS=$(psql "$DATABASE_URL" -tAc "SELECT status FROM orders WHERE id=${AUTO_ORDER}")
[ "$AUTO_ORDER_STATUS" = "paid" ] || fail "pedido pix_auto não avançou pra paid depois do webhook (está '$AUTO_ORDER_STATUS')"

echo "== Pix manual (pix_manual): copia-e-cola vem no formato BR Code (EMV) =="
MANUAL_ORDER=$(open_order_for pix_manual)
KEY6=$(gen_uuid)
MANUAL_PAY=$(curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY6" "${AUTH[@]}" \
  -d "{\"order_id\":${MANUAL_ORDER}}")
COPYPASTE=$(echo "$MANUAL_PAY" | jq -er '.pix_copy_paste') || fail "pix_manual sem pix_copy_paste: $MANUAL_PAY"
[[ "$COPYPASTE" == 000201* ]] || fail "pix_copy_paste não começa com o payload format indicator do BR Code: $COPYPASTE"
[[ "$COPYPASTE" == *"br.gov.bcb.pix"* ]] || fail "pix_copy_paste sem o GUI br.gov.bcb.pix: $COPYPASTE"
DEADLINE=$(psql "$DATABASE_URL" -tAc "SELECT verification_deadline IS NOT NULL FROM orders WHERE id=${MANUAL_ORDER}")
[ "$DEADLINE" = "t" ] || fail "pix_manual não gravou verification_deadline"

echo "== upload do comprovante muda o pedido pra 'pending_verification' =="
php -r '$im = imagecreatetruecolor(64, 64); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 20)); imagejpeg($im, "/tmp/smoke-payments-proof.jpg", 90);'
UPLOAD=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" \
  -F "order_id=${MANUAL_ORDER}" -F "proof=@/tmp/smoke-payments-proof.jpg;type=image/jpeg")
[ "$(echo "$UPLOAD" | jq -r '.order.status')" = "pending_verification" ] || fail "upload de comprovante não avançou pro status certo: $UPLOAD"
PROOF_ID=$(echo "$UPLOAD" | jq -er '.proof.id') || fail "upload sem proof.id: $UPLOAD"
[ -n "$(echo "$UPLOAD" | jq -r '.proof.phash')" ] && [ "$(echo "$UPLOAD" | jq -r '.proof.phash')" != "null" ] || fail "upload sem phash calculado: $UPLOAD"
[ -f "${PROOF_STORAGE_DIR}/$(echo "$UPLOAD" | jq -r '.proof.storage_key')" ] || fail "arquivo do comprovante não foi salvo em disco"

echo "== cliente não consegue aprovar o próprio comprovante (403, papel errado) =="
SELF_APPROVE=$(curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"proof_id\":${PROOF_ID},\"decision\":\"approve\"}")
[ "$(echo "$SELF_APPROVE" | jq -r '.code')" = "forbidden" ] || fail "cliente aprovando o próprio comprovante não foi barrado: $SELF_APPROVE"

echo "== loja aprova o comprovante: pedido vai pra 'paid' =="
APPROVE=$(curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"proof_id\":${PROOF_ID},\"decision\":\"approve\",\"counted_amount\":50.00}")
[ "$(echo "$APPROVE" | jq -r '.order.status')" = "paid" ] || fail "aprovação não levou o pedido pra paid: $APPROVE"

echo "== aprovar de novo o mesmo comprovante é barrado (409, sem aprovação dupla) =="
DOUBLE=$(curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"proof_id\":${PROOF_ID},\"decision\":\"approve\"}")
[ "$(echo "$DOUBLE" | jq -r '.code')" = "proof_already_reviewed" ] || fail "aprovação dupla não foi barrada: $DOUBLE"

echo "== Pix manual recusado pela loja: pedido vai pra 'rejected' com o motivo =="
MANUAL_ORDER2=$(open_order_for pix_manual)
KEY7=$(gen_uuid)
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY7" "${AUTH[@]}" \
  -d "{\"order_id\":${MANUAL_ORDER2}}" >/dev/null
UPLOAD2=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" \
  -F "order_id=${MANUAL_ORDER2}" -F "proof=@/tmp/smoke-payments-proof.jpg;type=image/jpeg")
PROOF_ID2=$(echo "$UPLOAD2" | jq -er '.proof.id') || fail "upload2 sem proof.id: $UPLOAD2"
REJECT=$(curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"proof_id\":${PROOF_ID2},\"decision\":\"reject\",\"reason\":\"valor divergente\"}")
[ "$(echo "$REJECT" | jq -r '.order.status')" = "rejected" ] || fail "recusa não levou o pedido pra rejected: $REJECT"
[ "$(psql "$DATABASE_URL" -tAc "SELECT reject_reason FROM orders WHERE id=${MANUAL_ORDER2}")" = "valor divergente" ] || fail "reject_reason não foi gravado"

echo "OK: módulo de pagamentos (Fase 4 + 7.3) passou no smoke test"
