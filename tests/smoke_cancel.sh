#!/usr/bin/env bash
# Smoke test do caminho do erro (Fase 13): cancelamento pelo cliente com e sem
# taxa, recusa pela loja depois de aceitar, e o reembolso que cada forma de
# pagamento gera -- canal, valor e quem paga (a tabela da tela 13.4).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8103
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"
CANCEL_FEE="15.00"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-cancel-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
STAMP="$(date +%s%N)"

echo "== semear loja com taxa de cancelamento configurada na política =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
VALUES ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Cancel Smoke', 'staff-cancel-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cancel_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         ${CANCEL_FEE}, id
  FROM users WHERE email = 'staff-cancel-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at)
VALUES ('${RESTAURANT_ID}', 'Cancel Smoke Restaurant', '${CNPJ}', '${CITY}', true, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','pix_manual','cash','pos_machine']::payment_method[], 200.00, 0);

INSERT INTO restaurant_credentials (restaurant_id, pix_key)
VALUES ('${RESTAURANT_ID}', 'pagamentos@cancel-smoke.com.br');

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Cancel Smoke', 50.00, 'Pratos', true);
SQL
ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-cancel-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Cancel Smoke\"}" | jq -er '.dev_code') || fail "otp falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Cancel Smoke","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

# Abre um pedido pago pelo método pedido e devolve o id.
paid_order() {
  local method="$1" extra=""
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  [ "$method" = "cash" ] && extra=',"change_for":100.00'
  [ "$method" = "pos_machine" ] && extra=',"machine_kind":"credit"'
  local id
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"${method}\"${extra}}" \
    | jq -er '.order.id') || fail "checkout $method falhou"
  local body='{"order_id":'"$id"'}'
  [ "$method" = "mp_card" ] && body='{"order_id":'"$id"',"card_token":"APRO-token-cancel"}'
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "$body" >/dev/null
  echo "$id"
}

cancel() { # $1 order, $2 motivo
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"order_id\":$1,\"to\":\"cancelled\",\"reason\":\"$2\"}"
}

echo "== motivo é obrigatório pra desfazer pedido =="
O_NOREASON=$(paid_order cash)
NOREASON=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_NOREASON},\"to\":\"cancelled\"}")
[ "$(echo "$NOREASON" | jq -r '.code')" = "reason_required" ] || fail "cancelamento sem motivo passou: $NOREASON"

echo "== antes da cozinha começar, cancelar é de graça (cartão) =="
O_CARD=$(paid_order mp_card)
QUOTE=$(curl -s "$BASE/orders/cancel_quote.php?id=${O_CARD}" "${AUTH[@]}")
[ "$(echo "$QUOTE" | jq -r '.can_cancel')" = "true" ] || fail "quote disse que não dá pra cancelar pedido pago: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.free_cancel')" = "true" ] || fail "quote cobrou taxa antes do preparo: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.fee')" = "0" ] || fail "taxa deveria ser zero antes do preparo: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.amount')" = "50" ] || fail "estorno deveria ser o total: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.channel')" = "gateway" ] || fail "cartão deveria estornar pelo gateway: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.reasons | length')" = "4" ] || fail "quote sem os motivos fechados: $QUOTE"

CANCEL=$(cancel "$O_CARD" "Pedi por engano")
[ "$(echo "$CANCEL" | jq -r '.order.status')" = "cancelled" ] || fail "não cancelou: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.channel')" = "gateway" ] || fail "reembolso do cartão não saiu pelo gateway: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.amount')" = "50.00" ] || fail "valor do reembolso errado: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.payer')" = "store" ] || fail "pagador errado: $CANCEL"
[ "$(echo "$CANCEL" | jq -r '.refund.cause')" = "customer_cancel" ] || fail "causa errada: $CANCEL"
[ "$(psql "$DATABASE_URL" -tAc "SELECT status FROM payments WHERE order_id=${O_CARD}")" = "refunded" ] \
  || fail "pagamento não virou refunded"
[ "$(psql "$DATABASE_URL" -tAc "SELECT cancel_reason FROM orders WHERE id=${O_CARD}")" = "Pedi por engano" ] \
  || fail "motivo não foi gravado"

echo "== depois de 'em preparo', entra a taxa e o estorno diminui (Pix) =="
O_PIX=$(paid_order pix_manual)
PROOF_DIR="/tmp/smoke-cancel-proofs"; export PROOF_STORAGE_DIR="$PROOF_DIR"; rm -rf "$PROOF_DIR"
psql_run -c "SELECT advance_order(${O_PIX}, 'paid', NULL, 'system');" >/dev/null
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_PIX},\"to\":\"preparing\"}" >/dev/null
QUOTE2=$(curl -s "$BASE/orders/cancel_quote.php?id=${O_PIX}" "${AUTH[@]}")
[ "$(echo "$QUOTE2" | jq -r '.quote.free_cancel')" = "false" ] || fail "cancelar em preparo deveria ter taxa: $QUOTE2"
[ "$(echo "$QUOTE2" | jq -r '.quote.fee')" = "15" ] || fail "taxa não veio da política: $QUOTE2"
[ "$(echo "$QUOTE2" | jq -r '.quote.amount')" = "35" ] || fail "estorno deveria ser total menos taxa: $QUOTE2"
[ "$(echo "$QUOTE2" | jq -r '.quote.channel')" = "pix_return" ] || fail "Pix deveria voltar pra chave do pagador: $QUOTE2"

CANCEL2=$(cancel "$O_PIX" "Demora acima do previsto")
[ "$(echo "$CANCEL2" | jq -r '.refund.amount')" = "35.00" ] || fail "reembolso com taxa errado: $CANCEL2"
[ "$(echo "$CANCEL2" | jq -r '.refund.channel')" = "pix_return" ] || fail "canal do Pix errado: $CANCEL2"

echo "== dinheiro não gera reembolso nenhum (não houve cobrança) =="
O_CASH=$(paid_order cash)
QUOTE3=$(curl -s "$BASE/orders/cancel_quote.php?id=${O_CASH}" "${AUTH[@]}")
[ "$(echo "$QUOTE3" | jq -r '.quote.channel')" = "none" ] || fail "dinheiro deveria ter canal none: $QUOTE3"
[ "$(echo "$QUOTE3" | jq -r '.quote.amount')" = "0" ] || fail "dinheiro não deveria estornar valor: $QUOTE3"
[ "$(echo "$QUOTE3" | jq -r '.quote.payer')" = "platform" ] || fail "compensação do entregador é nossa: $QUOTE3"
CANCEL3=$(cancel "$O_CASH" "Não quero mais")
[ "$(echo "$CANCEL3" | jq -r '.order.status')" = "cancelled" ] || fail "cancelamento em dinheiro falhou: $CANCEL3"
[ "$(echo "$CANCEL3" | jq -r '.refund')" = "null" ] || fail "dinheiro criou linha de reembolso: $CANCEL3"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM refunds WHERE order_id=${O_CASH}")" = "0" ] \
  || fail "refunds ganhou linha de valor zero"

echo "== maquininha cancela na adquirente =="
O_POS=$(paid_order pos_machine)
QUOTE4=$(curl -s "$BASE/orders/cancel_quote.php?id=${O_POS}" "${AUTH[@]}")
[ "$(echo "$QUOTE4" | jq -r '.quote.channel')" = "acquirer_void" ] || fail "maquininha deveria cancelar na adquirente: $QUOTE4"

echo "== loja recusando pedido já aceito: culpa dela, sem taxa pro cliente =="
psql_run -c "SELECT advance_order(${O_POS}, 'preparing', NULL, 'system');" >/dev/null
REJECT=$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_POS},\"to\":\"cancelled\",\"reason\":\"acabou o insumo\"}")
[ "$(echo "$REJECT" | jq -r '.order.status')" = "cancelled" ] || fail "loja não conseguiu desfazer: $REJECT"
[ "$(echo "$REJECT" | jq -r '.refund.cause')" = "store_reject" ] || fail "causa deveria ser store_reject: $REJECT"
[ "$(echo "$REJECT" | jq -r '.refund.amount')" = "50.00" ] || fail "loja recusando não pode cobrar taxa do cliente: $REJECT"
[ "$(echo "$REJECT" | jq -r '.refund.payer')" = "store" ] || fail "recusa da loja sai do repasse dela: $REJECT"

echo "== taxa de recusa conta o ATO da loja, não o status final =="
IMPACT=$(curl -s "$BASE/orders/cancel_quote.php?id=$(paid_order cash)" "${STAFF_AUTH[@]}")
[ "$(echo "$IMPACT" | jq -r '.store_impact.reject_rate')" != "null" ] || fail "quote da loja sem store_impact: $IMPACT"
# a recusa acima virou 'cancelled' (preparing -> rejected é ilegal), e mesmo
# assim precisa aparecer na taxa
[ "$(echo "$IMPACT" | jq -r '.store_impact.reject_rate > 0')" = "true" ] \
  || fail "recusa da loja não entrou na taxa de recusa: $IMPACT"
[ "$(echo "$IMPACT" | jq -r '.quote.fee')" = "0" ] || fail "loja recusando não pode ver taxa de cancelamento: $IMPACT"

echo "== cancelar duas vezes não cria dois reembolsos =="
AGAIN=$(cancel "$O_POS" "de novo")
[ "$(echo "$AGAIN" | jq -r '.code')" = "illegal_transition" ] || fail "segundo cancelamento não foi barrado: $AGAIN"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM refunds WHERE order_id=${O_POS}")" = "1" ] \
  || fail "reembolso duplicado"

# 404 e não 403 de propósito: a resposta não conta nem que o pedido existe.
echo "== pedido de outra pessoa não é cancelável nem consultável =="
PHONE2="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE2=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Intruso Cancel\"}" | jq -er '.dev_code')
ACCESS2=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"code\":\"$CODE2\"}" | jq -er '.access_token')
O_OTHER=$(paid_order cash)
[ "$(curl -s "$BASE/orders/cancel_quote.php?id=${O_OTHER}" -H "Authorization: Bearer $ACCESS2" | jq -r '.code')" = "order_not_found" ] \
  || fail "outro cliente conseguiu ver a cotação de cancelamento alheia"
[ "$(curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" -H "Authorization: Bearer $ACCESS2" \
   -d "{\"order_id\":${O_OTHER},\"to\":\"cancelled\",\"reason\":\"quero cancelar o pedido dos outros\"}" | jq -r '.code')" = "order_not_found" ] \
  || fail "outro cliente conseguiu cancelar pedido alheio"

echo "OK: caminho do erro (Fase 13) passou no smoke test"
