#!/usr/bin/env bash
# Smoke test da conta (Fase 6): CRUD de endereços (com troca de padrão e
# bloqueio de apagar endereço já usado num pedido) e cartões salvos via
# Mercado Pago (migração 012, MERCADOPAGO_MODE=fake) -- customer
# reaproveitado entre cartões, troca de padrão, remoção.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8101
BASE="http://127.0.0.1:${PORT}/api/v1"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-account-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"

echo "== semear loja mínima (só pra testar o bloqueio de apagar endereço em uso) =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
  SELECT gen_random_uuid(), 'admin', 'Admin Account Smoke', 'admin-account-smoke@test.com'
  WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin-account-smoke@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'admin-account-smoke@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at)
VALUES ('${RESTAURANT_ID}', 'Account Smoke Restaurant', '${CNPJ}', '3509502', true, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['cash']::payment_method[], 0);

INSERT INTO menu_items (restaurant_id, name, price, available)
VALUES ('${RESTAURANT_ID}', 'Prato Account Smoke', 20.00, true);
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-account-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== signup =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Account Smoke\"}" | jq -er '.dev_code') || fail "otp_request falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

echo "== addresses/create: dois endereços, o primeiro como padrão =="
ADDR1_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"label":"Casa","street":"Rua A","number":"100","neighborhood":"Centro","city":"Campinas","city_ibge_code":"3509502","state":"SP","postal_code":"13010000","lat":-22.9,"lng":-47.06,"is_default":true}' \
  | jq -er '.id') || fail "endereço 1 não criou"
ADDR2_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"label":"Trabalho","street":"Av B","number":"200","neighborhood":"Cambuí","city":"Campinas","city_ibge_code":"3509502","state":"SP","postal_code":"13020000","lat":-22.9,"lng":-47.06}' \
  | jq -er '.id') || fail "endereço 2 não criou"

echo "== addresses/update: renomear e trocar padrão pro segundo =="
UPD=$(curl -s -X POST "$BASE/addresses/update.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"id\":${ADDR2_ID},\"label\":\"Trabalho novo\",\"is_default\":true}")
[ "$(echo "$UPD" | jq -r '.address.label')" = "Trabalho novo" ] || fail "rótulo não atualizou: $UPD"
[ "$(echo "$UPD" | jq -r '.address.is_default')" = "true" ] || fail "não virou padrão: $UPD"

echo "== addresses/list: só um padrão, é o segundo =="
LIST=$(curl -s "$BASE/addresses/list.php" "${AUTH[@]}")
DEFAULT_COUNT=$(echo "$LIST" | jq '[.addresses[] | select(.is_default == true)] | length')
[ "$DEFAULT_COUNT" = "1" ] || fail "deveria ter exatamente 1 endereço padrão, veio $DEFAULT_COUNT: $LIST"
[ "$(echo "$LIST" | jq -r '.addresses[] | select(.is_default == true) | .id')" = "$ADDR2_ID" ] || fail "padrão errado: $LIST"

echo "== addresses/update: id de outro usuário (inexistente aqui) dá 404 =="
NOTFOUND=$(curl -s -X POST "$BASE/addresses/update.php" -H "Content-Type: application/json" "${AUTH[@]}" -d '{"id":999999,"label":"x"}')
[ "$(echo "$NOTFOUND" | jq -r '.code')" = "address_not_found" ] || fail "update de endereço inexistente não deu 404: $NOTFOUND"

echo "== endereço usado em pedido não muda por baixo do pedido (migração 043) =="
ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
USED_ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR1_ID},\"payment_method\":\"cash\"}" | jq -er '.order.id') \
  || fail "checkout pra usar o endereço falhou"
OLD_STREET=$(psql "$DATABASE_URL" -tAc "SELECT street FROM addresses WHERE id=${ADDR1_ID}")
EDIT=$(curl -s -X POST "$BASE/addresses/update.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"id\":${ADDR1_ID},\"street\":\"Rua Nova Depois do Pedido\"}")
NEW_ADDR_ID=$(echo "$EDIT" | jq -er '.address.id') || fail "editar endereço usado falhou: $EDIT"
[ "$(echo "$EDIT" | jq -r '.replaced_id')" = "${ADDR1_ID}" ] || fail "editar endereço usado não criou versão nova: $EDIT"
[ "$NEW_ADDR_ID" != "${ADDR1_ID}" ] || fail "a versão nova tem o mesmo id"
[ "$(psql "$DATABASE_URL" -tAc "SELECT a.street FROM orders o JOIN addresses a ON a.id = o.address_id WHERE o.id=${USED_ORDER}")" = "$OLD_STREET" ] \
  || fail "o pedido já feito passou a apontar pra rua nova"
LIST2=$(curl -s "$BASE/addresses/list.php" "${AUTH[@]}")
[ "$(echo "$LIST2" | jq "[.addresses[] | select(.id == ${ADDR1_ID})] | length")" = "0" ] || fail "a versão antiga continuou na lista: $LIST2"
[ "$(echo "$LIST2" | jq "[.addresses[] | select(.id == ${NEW_ADDR_ID})] | length")" = "1" ] || fail "a versão nova não apareceu na lista: $LIST2"
[ "$(curl -s -X POST "$BASE/addresses/update.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"id\":${ADDR1_ID},\"label\":\"x\"}" | jq -r '.code')" = "address_not_found" ] \
  || fail "deu pra editar a versão arquivada"

echo "== apagar endereço usado arquiva: some da lista, o pedido continua apontando pra ele =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
ORDER2=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${NEW_ADDR_ID},\"payment_method\":\"cash\"}" | jq -er '.order.id') \
  || fail "checkout com o endereço novo falhou"
IN_USE=$(curl -s -X POST "$BASE/addresses/delete.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"id\":${NEW_ADDR_ID}}")
[ "$(echo "$IN_USE" | jq -r '.archived')" = "true" ] || fail "apagar endereço usado não arquivou: $IN_USE"
[ "$(curl -s "$BASE/addresses/list.php" "${AUTH[@]}" | jq "[.addresses[] | select(.id == ${NEW_ADDR_ID})] | length")" = "0" ] \
  || fail "o arquivado continuou na lista"
[ "$(psql "$DATABASE_URL" -tAc "SELECT address_id FROM orders WHERE id=${ORDER2}")" = "${NEW_ADDR_ID}" ] || fail "o pedido perdeu o endereço"
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
[ "$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${NEW_ADDR_ID},\"payment_method\":\"cash\"}" | jq -r '.code')" = "address_not_found" ] \
  || fail "endereço arquivado recebeu pedido novo"

echo "== endereço: tipo, tamanho e faixa conferidos antes do banco =="
BAD=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":["x"],"city":"São Paulo","city_ibge_code":"x","state":"SP","postal_code":"01001000","lat":999,"lng":-46.6}')
[ "$(echo "$BAD" | jq -r '.fields | has("street") and has("city_ibge_code") and has("lat")')" = "true" ] \
  || fail "endereço com rua em lista, IBGE inválido e latitude 999 passou: $BAD"

echo "== addresses/delete: endereço sem pedido apaga de verdade =="
DELETED=$(curl -s -X POST "$BASE/addresses/delete.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"id\":${ADDR2_ID}}")
[ "$(echo "$DELETED" | jq -r '.deleted')" = "true" ] || fail "endereço sem pedido não apagou: $DELETED"
[ "$(echo "$DELETED" | jq -r '.archived')" = "false" ] || fail "endereço sem pedido foi só arquivado: $DELETED"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM addresses WHERE id=${ADDR2_ID}")" = "0" ] || fail "a linha continuou no banco"

echo "== cards/create: primeiro cartão vira padrão sozinho =="
CARD1=$(curl -s -X POST "$BASE/cards/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"card_token":"5031433215406351","kind":"credit"}')
CARD1_ID=$(echo "$CARD1" | jq -er '.card.id') || fail "cartão 1 não salvou: $CARD1"
[ "$(echo "$CARD1" | jq -r '.card.is_default')" = "true" ] || fail "primeiro cartão devia ser padrão: $CARD1"
[ "$(echo "$CARD1" | jq -r '.card.last4')" = "6351" ] || fail "last4 errado: $CARD1"

echo "== cards/create: segundo cartão não é padrão, mesmo mp_customer_id reaproveitado =="
CARD2=$(curl -s -X POST "$BASE/cards/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"card_token":"4000123456780244","kind":"debit"}')
CARD2_ID=$(echo "$CARD2" | jq -er '.card.id') || fail "cartão 2 não salvou: $CARD2"
[ "$(echo "$CARD2" | jq -r '.card.is_default')" = "false" ] || fail "segundo cartão não devia ser padrão: $CARD2"
CUSTOMER_COUNT=$(psql "$DATABASE_URL" -tAc "SELECT count(DISTINCT mp_customer_id) FROM users WHERE mp_customer_id IS NOT NULL AND id = (SELECT user_id FROM saved_cards WHERE id=${CARD1_ID})")
[ "$CUSTOMER_COUNT" = "1" ] || fail "deveria ter só 1 mp_customer_id pro usuário"

echo "== cards/update: trocar padrão pro segundo =="
curl -s -X POST "$BASE/cards/update.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"id\":${CARD2_ID},\"is_default\":true}" >/dev/null
LIST_CARDS=$(curl -s "$BASE/cards/list.php" "${AUTH[@]}")
DEFAULT_CARD_COUNT=$(echo "$LIST_CARDS" | jq '[.cards[] | select(.is_default == true)] | length')
[ "$DEFAULT_CARD_COUNT" = "1" ] || fail "deveria ter exatamente 1 cartão padrão: $LIST_CARDS"
[ "$(echo "$LIST_CARDS" | jq -r '.cards[] | select(.is_default == true) | .id')" = "$CARD2_ID" ] || fail "padrão errado: $LIST_CARDS"

echo "== cards/delete =="
DEL_CARD=$(curl -s -X POST "$BASE/cards/delete.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"id\":${CARD1_ID}}")
[ "$(echo "$DEL_CARD" | jq -r '.deleted')" = "true" ] || fail "cartão não apagou: $DEL_CARD"
REMAINING=$(curl -s "$BASE/cards/list.php" "${AUTH[@]}" | jq '.cards | length')
[ "$REMAINING" = "1" ] || fail "esperava 1 cartão restante, veio $REMAINING"

echo "OK: conta (Fase 6: endereços e cartões) passou no smoke test"
