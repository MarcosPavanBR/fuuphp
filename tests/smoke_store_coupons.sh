#!/usr/bin/env bash
# Smoke test do cupom criado pela própria loja (tela 15.3, migração 031):
# "cupom de loja ela cria sozinha no painel, dentro do teto que você liberar
# aqui".
#
#  - sem teto liberado a loja não cria; o admin libera (com auditoria);
#  - o teto vale sobre o orçamento dos cupons vivos: passar dele dá 409, e
#    desligar um cupom devolve o espaço;
#  - a loja só desliga cupom que ELA criou, da loja DELA;
#  - o cupom vale só nesta loja, é pago por ela (livro: store_receivable) e
#    o banco recusa cupom "da loja" pago por outro;
#  - baixar o teto abaixo do comprometido não mata os cupons vivos.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8131
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-store-coupons-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

STORE="$(gen_uuid)"; RIVAL="$(gen_uuid)"
STAFF="$(gen_uuid)"; RIVAL_STAFF="$(gen_uuid)"; ADMIN_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"; RIVAL_CNPJ="$(gen_cnpj)"
ADMIN_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"
N=$(( RANDOM % 900000 + 100000 ))
C1="VOLTA${N}"; C2="SEXTA${N}"; C3="FRETE${N}"; PLAT="PLAT${N}"

echo "== semear duas lojas, admin e um cupom da loja criado pela plataforma =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF}',       'restaurant_staff', 'Balcão Cupom',  NULL, 'staff-coupon-${STAMP}@test.com'),
  ('${RIVAL_STAFF}', 'restaurant_staff', 'Balcão Rival',  NULL, 'rival-coupon-${STAMP}@test.com'),
  ('${ADMIN_ID}',    'admin',            'Admin Cupom',   '${ADMIN_PHONE}', 'admin-coupon-${STAMP}@test.com');
INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], '${ADMIN_ID}';
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at) VALUES
  ('${STORE}', 'Cantina Cupom ${STAMP}', '${CNPJ}',       '${CITY}', true, now()),
  ('${RIVAL}', 'Rival Cupom ${STAMP}',   '${RIVAL_CNPJ}', '${CITY}', true, now());
INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order) VALUES
  ('${STORE}', ARRAY['cash']::payment_method[], 0), ('${RIVAL}', ARRAY['cash']::payment_method[], 0);
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash) VALUES
  ('${STAFF}', 'restaurant', '${STORE}', '${CNPJ}', '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a'),
  ('${RIVAL_STAFF}', 'restaurant', '${RIVAL}', '${RIVAL_CNPJ}', '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO menu_items (restaurant_id, name, price, category, available) VALUES
  ('${STORE}', 'Lasanha Cupom', 60.00, 'Massas', true),
  ('${RIVAL}', 'Prato Rival',   60.00, 'Pratos', true);
INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer, budget_cap, starts_at, created_by)
VALUES ('${PLAT}', 'fixed', 5.00, 0, '${STORE}', 'all', 'store', 100.00, now(), '${ADMIN_ID}');
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-store-coupons-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${STORE}" && break
  sleep 0.2
done

staff_login() {
  curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
    -d "{\"kind\":\"restaurant\",\"login_code\":\"$1\",\"secret\":\"senha123\"}" | jq -er '.access_token'
}
SAUTH=(-H "Authorization: Bearer $(staff_login "$CNPJ")") || fail "login da loja falhou"
RAUTH=(-H "Authorization: Bearer $(staff_login "$RIVAL_CNPJ")") || fail "login da rival falhou"
coupon() {  # coupon CODE CAP [DAYS] [KIND] [VALUE]
  curl -s -X POST "$BASE/restaurants/coupons.php" "${SAUTH[@]}" -H "Content-Type: application/json" \
    -d "{\"action\":\"create\",\"code\":\"$1\",\"kind\":\"${4:-fixed}\",\"value\":${5:-10},\"min_order\":30,\"audience\":\"all\",\"budget_cap\":$2,\"days\":${3:-14}}"
}

echo "== sem teto liberado, a loja não cria =="
[ "$(curl -s "$BASE/restaurants/coupons.php" "${SAUTH[@]}" | jq -r '.budget.limit')" = "0" ] || fail "teto inicial não é zero"
[ "$(coupon "$C1" 200 | jq -r '.code')" = "store_coupons_disabled" ] || fail "loja criou cupom sem teto"

echo "== admin libera R\$ 300 pra loja (busca por nome, auditoria) =="
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
AAUTH=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$CODE\"}" | jq -er '.access_token')")
FOUND=$(curl -s "$BASE/admin/store_coupon_limits.php?q=Cantina%20Cupom%20${STAMP}" "${AAUTH[@]}")
[ "$(echo "$FOUND" | jq -r '.stores[0].id')" = "$STORE" ] || fail "busca da loja no admin falhou: $FOUND"
R=$(curl -s -X POST "$BASE/admin/store_coupon_limits.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"restaurant_id\":\"${STORE}\",\"limit\":300}")
[ "$(echo "$R" | jq -r '.budget.available')" = "300" ] || fail "teto não foi liberado: $R"
R=$(curl -s -X POST "$BASE/admin/store_coupon_limits.php" "${SAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"restaurant_id\":\"${STORE}\",\"limit\":99999}")
[ "$(echo "$R" | jq -r '.code')" = "forbidden" ] || fail "loja mexeu no próprio teto: $R"
[ "$(psql "$DATABASE_URL" -tAc "SELECT after->>'coupon_budget_limit' FROM audit_log WHERE action='restaurant.coupon_limit' AND target='restaurants:${STORE}'")" = "300" ] \
  || fail "liberação do teto não foi auditada"

echo "== projeção antes de criar, sem gravar =="
DRY=$(curl -s -X POST "$BASE/restaurants/coupons.php" "${SAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"action\":\"create\",\"code\":\"${C1}\",\"kind\":\"fixed\",\"value\":10,\"min_order\":30,\"audience\":\"all\",\"budget_cap\":200,\"days\":14,\"dry_run\":true}")
[ "$(echo "$DRY" | jq -r '.dry_run')" = "true" ] || fail "dry_run não respondeu: $DRY"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM coupons WHERE code='${C1}'")" = "0" ] || fail "dry_run gravou cupom"

echo "== validação: prazo fora de 1..90, código ruim =="
[ "$(coupon "$C1" 200 0 | jq -r '.fields.days')" != "null" ] || fail "prazo 0 aceito"
[ "$(coupon "$C1" 200 91 | jq -r '.fields.days')" != "null" ] || fail "prazo 91 aceito"
[ "$(coupon "x" 200 | jq -r '.fields.code')" != "null" ] || fail "código inválido aceito"

echo "== cria dentro do teto; passar do que sobra dá 409 =="
R=$(coupon "$C1" 200); [ "$(echo "$R" | jq -r '.budget.available')" = "100" ] || fail "primeiro cupom: $R"
R=$(coupon "$C2" 150); [ "$(echo "$R" | jq -r '.code')" = "over_store_limit" ] || fail "passou do teto: $R"
R=$(coupon "$C2" 100 7 percent 15); [ "$(echo "$R" | jq -r '.budget.available')" = "0" ] || fail "segundo cupom: $R"
ROW=$(psql "$DATABASE_URL" -tAc "SELECT payer || '|' || restaurant_id || '|' || created_by_store || '|' || (ends_at::date - now()::date) FROM coupons WHERE code='${C1}'")
[ "$ROW" = "store|${STORE}|true|14" ] || fail "cupom gravado errado: $ROW"

echo "== a lista da loja mostra os dela e o da plataforma, com quem criou =="
LIST=$(curl -s "$BASE/restaurants/coupons.php" "${SAUTH[@]}")
[ "$(echo "$LIST" | jq -r '.coupons | length')" = "3" ] || fail "lista errada: $LIST"
[ "$(echo "$LIST" | jq -r ".coupons[] | select(.code == \"${PLAT}\") | .created_by_store")" = "false" ] || fail "cupom da plataforma aparece como da loja"

echo "== desligar: só o que ELA criou, da loja DELA; desligar devolve espaço =="
PLAT_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM coupons WHERE code='${PLAT}'")
C1_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM coupons WHERE code='${C1}'")
off() { curl -s -X POST "$BASE/restaurants/coupons.php" "$@" -H "Content-Type: application/json"; }
[ "$(off "${SAUTH[@]}" -d "{\"action\":\"deactivate\",\"coupon_id\":${PLAT_ID}}" | jq -r '.code')" = "coupon_not_found" ] || fail "loja desligou cupom da plataforma"
[ "$(off "${RAUTH[@]}" -d "{\"action\":\"deactivate\",\"coupon_id\":${C1_ID}}" | jq -r '.code')" = "coupon_not_found" ] || fail "rival desligou cupom alheio"
R=$(off "${SAUTH[@]}" -d "{\"action\":\"deactivate\",\"coupon_id\":${C1_ID}}")
[ "$(echo "$R" | jq -r '.budget.available')" = "200" ] || fail "desligar não devolveu o teto: $R"
[ "$(coupon "$C1" 1 | jq -r '.code')" = "code_taken" ] || fail "código repetido aceito"

echo "== cliente usa o cupom da loja: vale só nela e sai do repasse dela =="
R=$(coupon "$C3" 150 30 fixed 12); [ "$(echo "$R" | jq -r '.coupon.code')" = "$C3" ] || fail "terceiro cupom: $R"
PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
OC=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Cupom Loja\"}" | jq -er '.dev_code')
AUTH=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$OC\"}" | jq -er '.access_token')")
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${AUTH[@]}" -d "{\"cpf\":\"$(gen_cpf)\"}" >/dev/null
ADDR=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Cupom","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}' | jq -er '.id')
add_item() {  # add_item LOJA -> id da linha no carrinho
  local item
  item=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='$1'")
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"$1\",\"menu_item_id\":${item},\"quantity\":1}" | jq -er '.items[0].id'
}
# Um carrinho aberto por vez: primeiro o da rival (onde o cupom não vale),
# esvaziado pela API, depois o da loja.
RIVAL_LINE=$(add_item "$RIVAL") || fail "carrinho na rival falhou"
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RIVAL}\",\"code\":\"${C3}\"}" | jq -r '.code')" = "coupon_other_store" ] || fail "cupom da loja valeu na rival"
curl -s -X POST "$BASE/cart/remove_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_item_id\":${RIVAL_LINE}}" >/dev/null
add_item "$STORE" >/dev/null || fail "carrinho na loja falhou"
APPLY=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${STORE}\",\"code\":\"${C3}\"}")
[ "$(echo "$APPLY" | jq -r '.cart.total')" = "48.00" ] || fail "cupom da loja não descontou no carrinho (60 - 12): $APPLY"
ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${STORE}\",\"address_id\":${ADDR},\"payment_method\":\"cash\",\"change_for\":100.00,\"coupon_code\":\"${C3}\"}")
ORDER_ID=$(echo "$ORDER" | jq -er '.order.id') || fail "checkout com cupom da loja falhou: $ORDER"
[ "$(echo "$ORDER" | jq -r '.order.discount')" = "12.00" ] || fail "desconto errado: $ORDER"
LEDGER=$(psql "$DATABASE_URL" -tAc "SELECT account || '|' || amount FROM ledger_entries WHERE origin='coupon' AND order_id=${ORDER_ID} ORDER BY account")
echo "$LEDGER" | grep -q "^store_receivable|12.00$" || fail "desconto não saiu do repasse da loja: $LEDGER"
echo "$LEDGER" | grep -q "platform_expense" && fail "plataforma pagou cupom da loja: $LEDGER"

echo "== baixar o teto abaixo do comprometido não mata cupom vivo, mas trava novos =="
curl -s -X POST "$BASE/admin/store_coupon_limits.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"restaurant_id\":\"${STORE}\",\"limit\":50}" >/dev/null
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM coupons WHERE restaurant_id='${STORE}' AND created_by_store AND active")" = "2" ] \
  || fail "baixar o teto desligou cupom vivo"
[ "$(coupon "NOVO${N}" 10 | jq -r '.code')" = "over_store_limit" ] || fail "criou acima do teto novo"

echo "== o banco recusa cupom 'da loja' pago pela plataforma =="
if psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -qc "INSERT INTO coupons (code, kind, value, restaurant_id, audience, payer, budget_cap, starts_at, created_by, created_by_store)
     VALUES ('BAD${N}', 'fixed', 5, '${STORE}', 'all', 'platform', 10, now(), '${STAFF}', true)" 2>/dev/null; then
  fail "CHECK store_coupon_is_store_paid não barrou"
fi

echo "smoke_store_coupons OK"
