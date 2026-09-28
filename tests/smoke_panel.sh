#!/usr/bin/env bash
# Smoke test do painel da loja (telas 7.3 e 11.1): fila de validação de Pix,
# imagem do comprovante servida com autorização, resumo do dia, KDS com as
# três colunas e as transições que a loja pode pedir (aceitar, pronto,
# entregue ao motoboy). Também cobre o isolamento entre lojas: uma loja não
# enxerga nem valida nada da outra.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake
export PROOF_STORAGE_DIR="/tmp/smoke-panel-proofs"
rm -rf "$PROOF_STORAGE_DIR"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8102
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-panel-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
RIVAL_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
RIVAL_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"
COURIER_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
RIVAL_CNPJ="$(gen_cnpj)"
STAMP="$(date +%s%N)"

echo "== semear duas lojas, cardápio, chave Pix, logins de loja e um entregador =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Panel Smoke', 'staff-panel-${STAMP}@test.com'),
  ('${RIVAL_USER_ID}', 'restaurant_staff', 'Staff Rival Panel',  'rival-panel-${STAMP}@test.com'),
  ('${COURIER_USER_ID}', 'courier',        'Jonas Panel Smoke',  'courier-panel-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'staff-panel-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at) VALUES
  ('${RESTAURANT_ID}', 'Panel Smoke Restaurant', '${CNPJ}',       '${CITY}', true, now()),
  ('${RIVAL_ID}',      'Panel Rival Restaurant', '${RIVAL_CNPJ}', '${CITY}', true, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order) VALUES
  ('${RESTAURANT_ID}', ARRAY['pix_manual','cash']::payment_method[], 0),
  ('${RIVAL_ID}',      ARRAY['pix_manual','cash']::payment_method[], 0);

INSERT INTO restaurant_credentials (restaurant_id, pix_key)
VALUES ('${RESTAURANT_ID}', 'pagamentos@panel-smoke.com.br');

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash) VALUES
  ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
   '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a'),
  ('${RIVAL_USER_ID}', 'restaurant', '${RIVAL_ID}', '${RIVAL_CNPJ}',
   '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO couriers (id, user_id, city_ibge_code, active)
VALUES ('${COURIER_ID}', '${COURIER_USER_ID}', '${CITY}', true);

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Pizza Calabresa G', 39.20, 'Pizzas', true);
SQL
# senha do hash acima é "senha123" (mesmo hash dos outros smoke tests)
ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-panel-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== cliente entra, monta pedido Pix manual e manda o comprovante =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Marcos P. Silva\"}" | jq -er '.dev_code') || fail "otp_request falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Coronel Quirino","number":"1420","complement":"apto 74","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"13025000","lat":-22.9,"lng":-47.06,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":2,\"notes\":\"sem cebola\"}" >/dev/null
ORDER_ID=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"pix_manual\"}" \
  | jq -er '.order.id') || fail "checkout falhou"
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${ORDER_ID}}" >/dev/null

# A imagem precisa ser diferente a cada execução: o painel mostra "imagem já
# enviada antes" comparando sha256, e uma fixture fixa faria a segunda rodada
# do teste acusar comprovante repetido -- que é o comportamento certo, mas
# não o que este caso quer medir.
php -r '$im = imagecreatetruecolor(64, 64);
        for ($i = 0; $i < 64; $i++) {
          imagesetpixel($im, $i, $i, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        imagejpeg($im, "/tmp/smoke-panel-proof.jpg", 90);'
PROOF_ID=$(curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" \
  -F "order_id=${ORDER_ID}" -F "proof=@/tmp/smoke-panel-proof.jpg;type=image/jpeg" | jq -er '.proof.id') || fail "upload do comprovante falhou"

echo "== login da loja e da loja rival =="
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")
RIVAL_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${RIVAL_CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja rival falhou"
RIVAL_AUTH=(-H "Authorization: Bearer $RIVAL_TOKEN")

echo "== fila de validação traz o que o modal precisa mostrar =="
QUEUE=$(curl -s "$BASE/restaurants/pending_proofs.php" "${STAFF_AUTH[@]}")
[ "$(echo "$QUEUE" | jq -r '.proofs | length')" = "1" ] || fail "fila não trouxe exatamente 1 comprovante: $QUEUE"
[ "$(echo "$QUEUE" | jq -r '.proofs[0].customer_name')" = "Marcos P. Silva" ] || fail "fila sem o nome do cliente: $QUEUE"
# json_agg volta do PDO como texto, não como array já decodificado -- o
# painel trata os dois casos, o teste checa o formato real que chega.
[ "$(echo "$QUEUE" | jq -r '.proofs[0].items | fromjson | .[0].quantity')" = "2" ] || fail "fila sem os itens agregados: $QUEUE"
[ "$(echo "$QUEUE" | jq -r '.proofs[0].complement')" = "apto 74" ] || fail "fila sem o endereço de entrega: $QUEUE"
[ "$(echo "$QUEUE" | jq -r '.proofs[0].same_image_count')" = "0" ] || fail "imagem inédita deveria ter same_image_count=0: $QUEUE"
[ "$(echo "$QUEUE" | jq -r '.proofs[0].verification_deadline')" != "null" ] || fail "fila sem o prazo de expiração: $QUEUE"

echo "== a imagem do comprovante sai com autorização e o MIME real =="
IMG_HEAD=$(curl -s -o /tmp/smoke-panel-out.jpg -w '%{http_code} %{content_type}' \
  "$BASE/restaurants/proof_image.php?id=${PROOF_ID}" "${STAFF_AUTH[@]}")
[ "$IMG_HEAD" = "200 image/jpeg" ] || fail "proof_image não devolveu a imagem: $IMG_HEAD"
[ -s /tmp/smoke-panel-out.jpg ] || fail "proof_image devolveu arquivo vazio"
NOAUTH=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/restaurants/proof_image.php?id=${PROOF_ID}")
[ "$NOAUTH" = "401" ] || fail "proof_image sem token deveria dar 401, deu $NOAUTH"

echo "== resumo do dia conta o Pix pendente =="
STATS=$(curl -s "$BASE/restaurants/stats.php" "${STAFF_AUTH[@]}")
[ "$(echo "$STATS" | jq -r '.stats.pix_pending')" = "1" ] || fail "resumo não contou o Pix pendente: $STATS"
[ "$(echo "$STATS" | jq -r '.stats.pix_approved')" = "0" ] || fail "resumo já contou Pix aprovado antes da hora: $STATS"

echo "== loja rival não enxerga nada disto =="
[ "$(curl -s "$BASE/restaurants/pending_proofs.php" "${RIVAL_AUTH[@]}" | jq -r '.proofs | length')" = "0" ] \
  || fail "loja rival enxergou comprovante alheio"
[ "$(curl -s "$BASE/restaurants/proof_image.php?id=${PROOF_ID}" "${RIVAL_AUTH[@]}" | jq -r '.code')" = "proof_not_found" ] \
  || fail "loja rival baixou imagem alheia"
[ "$(curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" "${RIVAL_AUTH[@]}" \
   -d "{\"proof_id\":${PROOF_ID},\"decision\":\"approve\"}" | jq -r '.code')" = "proof_not_found" ] \
  || fail "loja rival aprovou comprovante alheio"
[ "$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=kds" "${RIVAL_AUTH[@]}" | jq -r '.code')" = "restaurant_not_found" ] \
  || fail "loja rival leu a fila de pedidos alheia"
[ "$(curl -s "$BASE/restaurants/pending_proofs.php" "${AUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "cliente conseguiu abrir a fila de validação"

echo "== aprovar o comprovante manda o pedido pra cozinha =="
APPROVE=$(curl -s -X POST "$BASE/restaurants/approve_pix.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"proof_id\":${PROOF_ID},\"decision\":\"approve\",\"counted_amount\":78.40}")
[ "$(echo "$APPROVE" | jq -r '.order.status')" = "paid" ] || fail "aprovação não levou o pedido pra paid: $APPROVE"
[ "$(curl -s "$BASE/restaurants/pending_proofs.php" "${STAFF_AUTH[@]}" | jq -r '.proofs | length')" = "0" ] \
  || fail "comprovante aprovado continuou na fila"
[ "$(curl -s "$BASE/restaurants/stats.php" "${STAFF_AUTH[@]}" | jq -r '.stats.pix_approved')" = "1" ] \
  || fail "resumo não contou o Pix aprovado"

echo "== KDS mostra a comanda com itens, observação e cronômetro do status =="
KDS=$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=kds" "${STAFF_AUTH[@]}")
[ "$(echo "$KDS" | jq -r '.orders | length')" = "1" ] || fail "KDS não trouxe o pedido pago: $KDS"
[ "$(echo "$KDS" | jq -r '.orders[0].items | fromjson | .[0].name')" = "Pizza Calabresa G" ] || fail "KDS sem os itens da comanda: $KDS"
[ "$(echo "$KDS" | jq -r '.orders[0].items | fromjson | .[0].notes')" = "sem cebola" ] || fail "KDS sem a observação do item: $KDS"
[ "$(echo "$KDS" | jq -r '.orders[0].status_since')" != "null" ] || fail "KDS sem status_since pro cronômetro: $KDS"

echo "== aceitar e marcar pronto: paid -> preparing -> ready =="
[ "$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
   -d "{\"order_id\":${ORDER_ID},\"to\":\"preparing\"}" | jq -r '.order.status')" = "preparing" ] || fail "aceitar não levou pra preparing"
[ "$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
   -d "{\"order_id\":${ORDER_ID},\"to\":\"ready\"}" | jq -r '.order.status')" = "ready" ] || fail "pronto não levou pra ready"

echo "== com entregador designado, a loja registra a passagem da sacola (ready -> delivering) =="
psql_run -c "UPDATE orders SET courier_id = '${COURIER_ID}' WHERE id = ${ORDER_ID}"
[ "$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=kds" "${STAFF_AUTH[@]}" | jq -r '.orders[0].courier_name')" = "Jonas Panel Smoke" ] \
  || fail "KDS não trouxe o nome do entregador designado"
[ "$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
   -d "{\"order_id\":${ORDER_ID},\"to\":\"delivering\"}" | jq -r '.order.status')" = "delivering" ] || fail "entrega ao motoboy não levou pra delivering"
[ "$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=kds" "${STAFF_AUTH[@]}" | jq -r '.orders | length')" = "0" ] \
  || fail "pedido que saiu pra entrega continuou ocupando o KDS"

echo "== transição ilegal continua barrada pelo banco (409), não pelo PHP =="
ILLEGAL=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"preparing\"}")
[ "$(echo "$ILLEGAL" | jq -r '.code')" = "illegal_transition" ] || fail "delivering -> preparing deveria ser ilegal: $ILLEGAL"

echo "== 'Pedidos recentes' mostra o pedido fora do KDS =="
RECENT=$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=recent" "${STAFF_AUTH[@]}")
[ "$(echo "$RECENT" | jq -r '.orders[0].status')" = "delivering" ] || fail "scope=recent não trouxe o pedido entregue ao motoboy: $RECENT"
[ "$(curl -s "$BASE/restaurants/orders.php?id=${RESTAURANT_ID}&scope=nope" "${STAFF_AUTH[@]}" | jq -r '.code')" = "invalid_scope" ] \
  || fail "scope inválido não foi barrado"

echo "OK: painel da loja (telas 7.3 e 11.1) passou no smoke test"
