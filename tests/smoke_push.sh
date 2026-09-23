#!/usr/bin/env bash
# Smoke test das telas 7.1 (fila de upload offline) e 7.2 (push).
#
#  7.1 -- o comprovante é idempotente por UUID: o reenvio da fila devolve o
#         MESMO comprovante, nunca um segundo.
#  7.2 -- chave VAPID gerada e assinando um JWT que confere; assinatura por
#         aparelho com preferência por tipo; a outbox vira "Pix confirmado"
#         e "saiu para entrega"; o relógio vira "faltam 5 min"; o service
#         worker busca o texto pelo endpoint e cada aviso sai uma vez só.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake
export PUSH_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8121
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"
STAMP="$(date +%s%N)"
export VAPID_PRIVATE_KEY_FILE="/tmp/smoke-push-${STAMP}/private.pem"
export PROOF_STORAGE_DIR="/tmp/smoke-push-${STAMP}/proofs"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-push-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

RESTAURANT_ID="$(gen_uuid)"; STAFF_USER_ID="$(gen_uuid)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"

echo "== semear loja com chave Pix =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Push', 'staff-push-${STAMP}@test.com');
INSERT INTO platform_policies (version, enabled_methods, delivery_base_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], 5.00, id
  FROM users WHERE email = 'staff-push-${STAMP}@test.com';
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Push Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);
INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['pix_manual','mp_card']::payment_method[], 200.00, 0);
INSERT INTO restaurant_credentials (restaurant_id, pix_key) VALUES ('${RESTAURANT_ID}', 'push-${STAMP}@loja.test');
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Push', 45.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-push-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; rm -rf "/tmp/smoke-push-${STAMP}"' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== 7.2: sem chave VAPID o push se declara desligado =="
[ "$(curl -s "$BASE/push/config.php" | jq -r '.enabled')" = "false" ] || fail "push ligado sem chave"

echo "== 7.2: a chave gerada assina um JWT que confere =="
php "$ROOT/bin/generate_vapid_keys.php" >/tmp/smoke-push-keys.log 2>&1 || fail "gerar chave falhou: $(cat /tmp/smoke-push-keys.log)"
[ "$(stat -c '%a' "$VAPID_PRIVATE_KEY_FILE")" = "600" ] || fail "chave privada sem permissão 0600"
php "$ROOT/bin/generate_vapid_keys.php" >/tmp/smoke-push-keys2.log 2>&1
grep -q "Já existe chave" /tmp/smoke-push-keys2.log || fail "rodar de novo sobrescreveria a chave"
CONFIG=$(curl -s "$BASE/push/config.php")
[ "$(echo "$CONFIG" | jq -r '.enabled')" = "true" ] || fail "com chave, o push devia estar ligado: $CONFIG"
[ "$(php "$ROOT/tests/support/verify_vapid.php" "https://fcm.googleapis.com/fcm/send/abc")" = "ok" ] \
  || fail "o JWT VAPID não confere"

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Push\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
USER_ID=$(query "SELECT id FROM users WHERE phone='${PHONE}'")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua do Push","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5630,"lng":-46.6333,"is_default":true}' | jq -er '.id')
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')

echo "== 7.2: assinatura do aparelho, com preferência por tipo =="
ENDPOINT="https://fcm.googleapis.com/fcm/send/smoke-${STAMP}"
SUB="{\"endpoint\":\"${ENDPOINT}\",\"keys\":{\"p256dh\":\"BExemploDeChave\",\"auth\":\"segredo\"}}"
BADSUB=$(curl -s -X POST "$BASE/push/subscribe.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"subscription":{"endpoint":"http://inseguro"}}')
[ "$(echo "$BADSUB" | jq -r '.code')" = "invalid_subscription" ] || fail "aceitou endpoint sem https: $BADSUB"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/push/subscribe.php" -H "Content-Type: application/json" -d "{\"subscription\":${SUB}}")" = "401" ] \
  || fail "assinou push sem login"
OK=$(curl -s -X POST "$BASE/push/subscribe.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"action\":\"subscribe\",\"subscription\":${SUB},\"prefs\":{\"status\":true,\"payment\":true,\"promotion\":false}}")
[ "$(echo "$OK" | jq -r '.subscribed')" = "true" ] || fail "assinatura não gravou: $OK"
[ "$(echo "$OK" | jq -r '.subscription.want_promotion')" = "false" ] || fail "promoção devia nascer desligada: $OK"

echo "== 7.1: comprovante idempotente por UUID =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"pix_manual\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER}}" >/dev/null

echo "== 7.2: 'faltam 5 min' nasce do relógio, uma vez só =="
psql_run -c "UPDATE orders SET verification_deadline = now() + interval '4 minutes' WHERE id=${ORDER}"
php "$ROOT/bin/push_worker.php" >/tmp/smoke-push-worker.log 2>&1 || fail "worker quebrou: $(cat /tmp/smoke-push-worker.log)"
php "$ROOT/bin/push_worker.php" >>/tmp/smoke-push-worker.log 2>&1
[ "$(query "SELECT count(*) FROM notifications WHERE order_id=${ORDER} AND kind='proof_deadline'")" = "1" ] \
  || fail "o aviso de prazo não nasceu, ou nasceu duas vezes"
[ "$(query "SELECT title FROM notifications WHERE order_id=${ORDER} AND kind='proof_deadline'")" = "Faltam 5 min para expirar" ] \
  || fail "texto do aviso de prazo não é o da tela"

UPLOAD_KEY=$(gen_uuid)
php -r '$i=imagecreatetruecolor(64,64);imagefill($i,0,0,imagecolorallocate($i,20,120,200));imagestring($i,2,2,2,$argv[1],0);imagejpeg($i,$argv[2]);' "P${STAMP}" /tmp/smoke-push-proof.jpg
FIRST=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" -H "X-Idempotency-Key: ${UPLOAD_KEY}" \
  -F "order_id=${ORDER}" -F "proof=@/tmp/smoke-push-proof.jpg;type=image/jpeg")
PROOF_ID=$(echo "$FIRST" | jq -er '.proof.id') || fail "upload falhou: $FIRST"
AGAIN=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" -H "X-Idempotency-Key: ${UPLOAD_KEY}" \
  -F "order_id=${ORDER}" -F "proof=@/tmp/smoke-push-proof.jpg;type=image/jpeg")
[ "$(echo "$AGAIN" | jq -r '.replayed')" = "true" ] || fail "o reenvio da fila não foi reconhecido: $AGAIN"
[ "$(echo "$AGAIN" | jq -r '.proof.id')" = "$PROOF_ID" ] || fail "o reenvio criou outro comprovante"
[ "$(query "SELECT count(*) FROM payment_proofs WHERE order_id=${ORDER}")" = "1" ] || fail "dois comprovantes pro mesmo envio"
BADKEY=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" -H "X-Idempotency-Key: nao-e-uuid" \
  -F "order_id=${ORDER}" -F "proof=@/tmp/smoke-push-proof.jpg;type=image/jpeg")
[ "$(echo "$BADKEY" | jq -r '.code')" = "invalid_idempotency_key" ] || fail "aceitou chave que não é UUID: $BADKEY"

echo "== 7.2: a aprovação do Pix vira 'Pix confirmado' pela outbox =="
curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"proof_id\":${PROOF_ID},\"decision\":\"approve\",\"counted_amount\":$(query "SELECT total FROM orders WHERE id=${ORDER}")}" >/dev/null
[ "$(query "SELECT count(*) FROM outbox WHERE topic='order.paid' AND payload->>'order_id'='${ORDER}' AND published_at IS NULL")" = "1" ] \
  || fail "a aprovação não gravou na outbox"
php "$ROOT/bin/push_worker.php" >>/tmp/smoke-push-worker.log 2>&1
[ "$(query "SELECT title FROM notifications WHERE order_id=${ORDER} AND kind='payment_approved'")" = "Pix confirmado 🎉" ] \
  || fail "a aprovação não virou 'Pix confirmado'"
[ "$(query "SELECT count(*) FROM outbox WHERE published_at IS NULL")" = "0" ] || fail "o worker deixou outbox pendente"
[ "$(query "SELECT last_push_at IS NOT NULL FROM push_subscriptions WHERE endpoint='${ENDPOINT}'")" = "t" ] \
  || fail "o aparelho não foi acordado"

echo "== 7.2: o service worker busca pelo endpoint, e cada aviso sai uma vez =="
PENDING=$(curl -s -X POST "$BASE/push/pending.php" -H "Content-Type: application/json" -d "{\"endpoint\":\"${ENDPOINT}\"}")
[ "$(echo "$PENDING" | jq -r '.notifications | length')" = "2" ] || fail "o SW não recebeu os dois avisos: $PENDING"
[ "$(curl -s -X POST "$BASE/push/pending.php" -H "Content-Type: application/json" -d "{\"endpoint\":\"${ENDPOINT}\"}" | jq -r '.notifications | length')" = "0" ] \
  || fail "o mesmo aviso foi entregue duas vezes"
[ "$(curl -s -X POST "$BASE/push/pending.php" -H "Content-Type: application/json" -d '{"endpoint":"https://desconhecido/x"}' | jq -r '.notifications | length')" = "0" ] \
  || fail "endpoint desconhecido recebeu avisos"

echo "== 6.3: desligar 'status' neste aparelho segura o 'saiu para entrega' =="
curl -s -X POST "$BASE/push/subscribe.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"action\":\"prefs\",\"subscription\":${SUB},\"prefs\":{\"status\":false,\"payment\":true,\"promotion\":false}}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"order_id\":${ORDER},\"to\":\"preparing\"}" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $STAFF_TOKEN" \
  -d "{\"order_id\":${ORDER},\"to\":\"ready\"}" >/dev/null
psql_run -c "SELECT advance_order(${ORDER}, 'delivering', NULL, 'system')" >/dev/null
php "$ROOT/bin/push_worker.php" >>/tmp/smoke-push-worker.log 2>&1
[ "$(query "SELECT body LIKE 'Chega em torno de %' FROM notifications WHERE order_id=${ORDER} AND kind='out_for_delivery'")" = "t" ] \
  || fail "'saiu para entrega' não nasceu com a hora prevista"
[ "$(curl -s -X POST "$BASE/push/pending.php" -H "Content-Type: application/json" -d "{\"endpoint\":\"${ENDPOINT}\"}" | jq -r '.notifications | length')" = "0" ] \
  || fail "o aparelho recebeu status mesmo com status desligado"

echo "== 7.2: desligar o push apaga a assinatura =="
curl -s -X POST "$BASE/push/subscribe.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"action\":\"unsubscribe\",\"subscription\":${SUB}}" >/dev/null
[ "$(query "SELECT count(*) FROM push_subscriptions WHERE endpoint='${ENDPOINT}'")" = "0" ] || fail "a assinatura continuou"

echo
echo "smoke_push OK"
