#!/usr/bin/env bash
# Smoke test da tela 6.3 — LGPD (migração 026):
#
#  - "Baixar meus dados": o arquivo tem conta, endereços, cartões (só
#    bandeira/final), pedidos e itens, e NÃO tem segredo (hash de sessão,
#    token do cartão no Mercado Pago);
#  - "Excluir conta": barrada com pedido em andamento, exige confirmação
#    escrita e aceite explícito de perder saldo de carteira; excluir
#    anonimiza (nome, CPF, telefone, e-mail, endereço), apaga cartões e push,
#    revoga sessões -- e o pedido continua existindo pro histórico fiscal;
#  - o mesmo telefone pode abrir uma conta nova depois;
#  - de carona, as outras entradas do Perfil (2.4/2.5): recibo do pedido
#    (orders/receipt.php) e "Repetir" (orders/reorder.php, preço de hoje,
#    item indisponível pulado).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8121
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-privacy-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

RESTAURANT_ID="$(gen_uuid)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"
STAMP="$(date +%s%N)"

echo "== semear loja =="
psql_run <<SQL
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Privacidade Smoke', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);
INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);
INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Privado', 30.00, 'Pratos', true),
       ('${RESTAURANT_ID}', 'Sobremesa Sazonal', 12.00, 'Doces', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}' AND name='Prato Privado'")
SEASONAL_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}' AND name='Sobremesa Sazonal'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-privacy-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Titular Dos Dados\"}" | jq -er '.dev_code')
LOGIN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")
ACCESS=$(echo "$LOGIN" | jq -er '.access_token'); REFRESH=$(echo "$LOGIN" | jq -er '.refresh_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
USER_ID=$(query "SELECT id FROM users WHERE phone='${PHONE}'")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Secreta","number":"42","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01310100","lat":-23.561234,"lng":-46.655678,"is_default":true}' | jq -er '.id')
curl -s -X POST "$BASE/cards/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"card_token":"5031433215406351","kind":"credit"}' | jq -er '.card.id' >/dev/null || fail "cartão não salvou"

curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${SEASONAL_ID},\"quantity\":2,\"notes\":\"sem calda\"}" >/dev/null
ORDER_ID=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\"}" | jq -er '.order.id')
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID}}" >/dev/null

echo "== exportar: tudo que é da pessoa, nada que é segredo =="
curl -s -D /tmp/smoke-privacy-h.txt "$BASE/profile/export.php" "${AUTH[@]}" >/tmp/smoke-privacy-export.json
grep -qi 'content-disposition: attachment' /tmp/smoke-privacy-h.txt || fail "exportação não veio como anexo"
EXPORT=$(cat /tmp/smoke-privacy-export.json)
[ "$(echo "$EXPORT" | jq -r '.account.full_name')" = "Titular Dos Dados" ] || fail "conta fora da exportação"
[ "$(echo "$EXPORT" | jq -r '.addresses[0].street')" = "Rua Secreta" ] || fail "endereço fora da exportação"
[ "$(echo "$EXPORT" | jq -r '.saved_cards[0].last4')" = "6351" ] || fail "cartão fora da exportação"
[ "$(echo "$EXPORT" | jq -r ".orders[] | select(.id == ${ORDER_ID}) | .status")" = "paid" ] || fail "pedido fora da exportação"
[ "$(echo "$EXPORT" | jq -r '.order_items[0].name')" = "Prato Privado" ] || fail "itens fora da exportação"
for secret in refresh_hash mp_card_id code_hash mp_customer_id; do
  grep -q "$secret" /tmp/smoke-privacy-export.json && fail "exportação vazou ${secret}"
done
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/profile/export.php")" = "401" ] || fail "exportação sem login"

echo "== excluir: pedido em andamento impede =="
CHECK=$(curl -s "$BASE/profile/delete_account.php" "${AUTH[@]}")
[ "$(echo "$CHECK" | jq -r '.blockers[0].code')" = "active_order" ] || fail "pedido em andamento não apareceu como impedimento: $CHECK"
[ "$(curl -s -X POST "$BASE/profile/delete_account.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"confirm":"EXCLUIR"}' | jq -r '.code')" = "active_order" ] || fail "excluiu com pedido em andamento"

for to in preparing ready delivering delivered; do
  psql_run -c "SELECT advance_order(${ORDER_ID}, '${to}', NULL, 'system')" >/dev/null
done

echo "== 2.5: recibo do pedido, só pro dono =="
RECEIPT=$(curl -s "$BASE/orders/receipt.php?id=${ORDER_ID}" "${AUTH[@]}")
[ "$(echo "$RECEIPT" | jq -r '.store.cnpj')" = "${CNPJ}" ] || fail "recibo sem o CNPJ da loja: $RECEIPT"
[ "$(echo "$RECEIPT" | jq -r '.items | length')" = "2" ] || fail "recibo sem os itens"
[ "$(echo "$RECEIPT" | jq -r '.fiscal_notice | contains("nota fiscal")')" = "true" ] || fail "recibo não avisa que não é nota fiscal"
OTHER_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
OC=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$OTHER_PHONE\",\"full_name\":\"Outra Pessoa\"}" | jq -er '.dev_code')
OTHER=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$OTHER_PHONE\",\"code\":\"$OC\"}" | jq -er '.access_token')
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/orders/receipt.php?id=${ORDER_ID}" -H "Authorization: Bearer $OTHER")" = "404" ] \
  || fail "outra pessoa leu o recibo (404: nem confirma que o pedido existe)"

echo "== 2.4: repetir pedido -- preço de hoje, indisponível pulado, observação mantida =="
psql_run -c "UPDATE menu_items SET price = 33.00 WHERE id=${ITEM_ID}; UPDATE menu_items SET available = false WHERE id=${SEASONAL_ID}"
AGAIN=$(curl -s -X POST "$BASE/orders/reorder.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"order_id\":${ORDER_ID}}")
[ "$(echo "$AGAIN" | jq -r '.added | length')" = "1" ] || fail "repetir não trouxe o item disponível: $AGAIN"
[ "$(echo "$AGAIN" | jq -r '.added[0].unit_price')" = "33" ] || fail "repetir usou o preço antigo: $AGAIN"
[ "$(echo "$AGAIN" | jq -r '.skipped[0].name')" = "Sobremesa Sazonal" ] || fail "item indisponível não foi avisado: $AGAIN"
[ "$(echo "$AGAIN" | jq -r '.order.status')" = "cart" ] || fail "repetir não caiu no carrinho"
[ "$(curl -s -X POST "$BASE/orders/reorder.php" -H "Content-Type: application/json" -H "Authorization: Bearer $OTHER" \
  -d "{\"order_id\":${ORDER_ID}}" | jq -r '.code')" = "order_not_found" ] || fail "outra pessoa repetiu o pedido"
psql_run -c "UPDATE menu_items SET available = true WHERE id=${SEASONAL_ID}"
AGAIN2=$(curl -s -X POST "$BASE/orders/reorder.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"order_id\":${ORDER_ID}}")
[ "$(echo "$AGAIN2" | jq -r '[.order.id] | .[0]')" = "$(echo "$AGAIN" | jq -r '.order.id')" ] || fail "repetir de novo abriu outro carrinho"
[ "$(query "SELECT notes FROM order_items WHERE order_id=$(echo "$AGAIN2" | jq -r '.order.id') AND name_snapshot='Sobremesa Sazonal'")" = "sem calda" ] \
  || fail "a observação do item não voltou"

echo "== excluir: confirmação escrita e saldo de carteira =="
[ "$(curl -s -X POST "$BASE/profile/delete_account.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"confirm":"sim"}' | jq -r '.code')" = "confirmation_required" ] || fail "excluiu sem digitar EXCLUIR"
psql_run -c "INSERT INTO wallet_credits (user_id, order_id, amount, state, expires_at) VALUES ('${USER_ID}', ${ORDER_ID}, 12.00, 'accepted', now() + interval '30 days')"
[ "$(curl -s "$BASE/profile/delete_account.php" "${AUTH[@]}" | jq -r '.wallet_balance')" = "12" ] || fail "saldo não informado antes de excluir"
[ "$(curl -s -X POST "$BASE/profile/delete_account.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"confirm":"EXCLUIR"}' | jq -r '.code')" = "wallet_balance" ] || fail "excluiu jogando fora o saldo sem aceite"

echo "== excluir: anonimiza e revoga =="
DONE=$(curl -s -X POST "$BASE/profile/delete_account.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"confirm":"EXCLUIR","forfeit_wallet":true}')
[ "$(echo "$DONE" | jq -r '.deleted')" = "true" ] || fail "exclusão não aconteceu: $DONE"
ROW=$(query "SELECT full_name || '|' || COALESCE(phone,'-') || '|' || COALESCE(cpf,'-') || '|' || email || '|' || blocked || '|' || (deleted_at IS NOT NULL) FROM users WHERE id='${USER_ID}'")
[ "$ROW" = "Conta excluída|-|-|excluida-${USER_ID}@anon.invalid|true|true" ] || fail "usuário não foi anonimizado: $ROW"
ADDR=$(query "SELECT street || '|' || COALESCE(number,'-') || '|' || postal_code || '|' || lat FROM addresses WHERE id=${ADDR_ID}")
[ "$ADDR" = "Endereço removido|-|01310000|-23.560000" ] || fail "endereço do pedido não foi anonimizado: $ADDR"
[ "$(query "SELECT count(*) FROM saved_cards WHERE user_id='${USER_ID}'")" = "0" ] || fail "cartões ficaram"
[ "$(query "SELECT status FROM orders WHERE id=${ORDER_ID}")" = "delivered" ] || fail "o pedido sumiu (registro fiscal)"
[ "$(query "SELECT count(*) FROM audit_log WHERE action='account.deleted' AND target='users:${USER_ID}'")" = "1" ] || fail "exclusão sem trilha de auditoria"
REFRESHED=$(curl -s -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" -d "{\"refresh_token\":\"$REFRESH\"}")
[ "$(echo "$REFRESHED" | jq -r '.access_token // empty')" = "" ] || fail "sessão da conta excluída ainda renova: $REFRESHED"

echo "== o mesmo telefone abre uma conta nova, sem nada da antiga =="
CODE2=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Nova Pessoa\"}" | jq -er '.dev_code') || fail "telefone ficou preso à conta excluída"
NEW=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE2\"}" | jq -er '.access_token')
NEW_ORDERS=$(curl -s "$BASE/orders/list.php" -H "Authorization: Bearer $NEW" | jq -r '.orders | length')
[ "$NEW_ORDERS" = "0" ] || fail "conta nova herdou pedidos da excluída"

echo "smoke_privacy OK"
