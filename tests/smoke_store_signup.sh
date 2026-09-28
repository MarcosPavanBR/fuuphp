#!/usr/bin/env bash
# Smoke test do cadastro de loja pela própria loja (restaurants/signup.php,
# migração 033) e da chave Pix no painel (restaurants/pix_key.php).
#
#  - dado ruim volta 422 com o campo: CNPJ inválido, chave CPF, sem aceite,
#    cidade que a plataforma não atende (migração 036);
#  - a loja nasce em análise: fora da lista e do carrinho (409
#    store_not_available) mesmo aberta; o balcão já entra no painel;
#  - CNPJ e e-mail repetidos: 409; 3 cadastros por IP em 24 h;
#  - chave Pix: CNPJ de outra loja e CPF recusados; troca vai pro audit_log;
#  - o admin vê contato, endereço e chave (com "confira a titularidade") e,
#    aprovando, a loja passa a vender e o IP do cadastro é apagado.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8139
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-store-signup-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
q() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

ADMIN_ID="$(gen_uuid)"
ADMIN_PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"
CNPJ="$(gen_cnpj)"
EMAIL="dono-${STAMP}@loja.test"

psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email)
VALUES ('${ADMIN_ID}', 'admin', 'Admin Cadastro', '${ADMIN_PHONE}', 'admin-signup-${STAMP}@test.com');
INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], '${ADMIN_ID}';
-- A cidade do teste é atendida (aba Cidades); a de 9999999 não existe.
INSERT INTO service_cities (ibge_code, name, uf, lat, lng, neighborhoods)
VALUES ('${CITY}', 'São Paulo', 'SP', -23.5505, -46.6333, ARRAY['Pinheiros'])
ON CONFLICT (ibge_code) DO UPDATE SET active = true;
-- Os limites de cadastro por IP contam só as últimas 24 h; o teste começa do zero.
UPDATE restaurants SET signup_ip = NULL WHERE signup_ip IS NOT NULL;
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-store-signup-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "$BASE/restaurants/list.php?city_ibge_code=${CITY}" && break
  sleep 0.2
done

signup() {  # signup CNPJ EMAIL [PIX] [ACCEPT]
  curl -s -X POST "$BASE/restaurants/signup.php" -H "Content-Type: application/json" -d "{
    \"name\":\"Cantina Cadastro ${STAMP}\",\"cnpj\":\"$1\",\"category\":\"Pizza\",\"city_ibge_code\":\"${SIGNUP_CITY:-$CITY}\",
    \"address\":\"Rua das Flores, 100, Centro\",\"contact_name\":\"Dona Nonna\",\"contact_phone\":\"11987654321\",
    \"email\":\"$2\",\"password\":\"senha-forte-123\",\"pix_key\":\"${3:-}\",\"lat\":-23.55,\"lng\":-46.63,
    \"accept_terms\":${4:-true}}"
}

echo "== dado ruim: 422 com o campo certo =="
[ "$(signup 11111111111111 "$EMAIL" | jq -r '.fields.cnpj')" != "null" ] || fail "CNPJ inválido aceito"
[ "$(signup "$CNPJ" "$EMAIL" "529.982.247-25" | jq -r '.fields.pix_key')" != "null" ] || fail "chave CPF aceita"
[ "$(signup "$CNPJ" "$EMAIL" "" false | jq -r '.fields.accept_terms')" != "null" ] || fail "cadastro sem aceite dos termos"
[ "$(SIGNUP_CITY=9999999 signup "$CNPJ" "$EMAIL" | jq -r '.fields.city_ibge_code')" = "cidade ainda não atendida" ] \
  || fail "cadastro aceito em cidade que a plataforma não atende"
[ "$(q "SELECT count(*) FROM restaurants WHERE cnpj='${CNPJ}'")" = "0" ] || fail "cadastro recusado deixou loja no banco"

echo "== cadastro válido: loja em análise, conta do balcão, chave e aceite gravados =="
R=$(signup "$CNPJ" "$EMAIL" "pix@cantina.test")
STORE=$(echo "$R" | jq -er '.restaurant_id') || fail "cadastro falhou: $R"
[ "$(echo "$R" | jq -r '.state')" = "review" ] || fail "loja não nasceu em análise: $R"
[ "$(echo "$R" | jq -r '.pix_ownership_checked')" = "false" ] || fail "chave e-mail marcada como conferida: $R"
[ "$(q "SELECT approved_at IS NULL AND signup_ip IS NOT NULL FROM restaurants WHERE id='${STORE}'")" = "t" ] || fail "loja nasceu aprovada ou sem IP"
[ "$(q "SELECT count(*) FROM consents c JOIN partner_accounts pa ON pa.user_id = c.user_id WHERE pa.restaurant_id='${STORE}'")" = "2" ] \
  || fail "aceite dos termos não gravado"
[ "$(q "SELECT pix_key FROM restaurant_credentials WHERE restaurant_id='${STORE}'")" = "pix@cantina.test" ] || fail "chave Pix não gravada"

echo "== repetido: CNPJ e e-mail =="
[ "$(signup "$CNPJ" "outro-${STAMP}@loja.test" | jq -r '.code')" = "cnpj_taken" ] || fail "CNPJ repetido aceito"
[ "$(signup "$(gen_cnpj)" "$EMAIL" | jq -r '.code')" = "email_taken" ] || fail "e-mail repetido aceito"

echo "== em análise: fora da lista e do carrinho, mesmo aberta e com cardápio =="
psql_run -c "UPDATE restaurants SET is_open = true WHERE id='${STORE}'" \
         -c "INSERT INTO menu_items (restaurant_id, name, price, category, available) VALUES ('${STORE}', 'Pizza Cadastro', 50, 'Pizzas', true)"
ITEM=$(q "SELECT id FROM menu_items WHERE restaurant_id='${STORE}'")
curl -s "$BASE/restaurants/list.php?city_ibge_code=${CITY}" | jq -e ".restaurants[] | select(.id == \"${STORE}\")" >/dev/null \
  && fail "loja em análise apareceu na lista"
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
C=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Curioso\"}" | jq -er '.dev_code')
CAUTH=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$C\"}" | jq -er '.access_token')")
add_item() {
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
    -d "{\"restaurant_id\":\"${STORE}\",\"menu_item_id\":${ITEM},\"quantity\":1}"
}
[ "$(add_item | jq -r '.code')" = "store_not_available" ] || fail "loja em análise aceitou item no carrinho"

echo "== o balcão já entra e o painel diz 'em análise' =="
LOGIN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha-forte-123\"}")
SAUTH=(-H "Authorization: Bearer $(echo "$LOGIN" | jq -er '.access_token')") || fail "login da loja nova falhou: $LOGIN"
[ "$(curl -s "$BASE/restaurants/pause_status.php" "${SAUTH[@]}" | jq -r '.store.approval')" = "review" ] || fail "painel não disse 'em análise'"

echo "== chave Pix no painel: CNPJ de outra loja e CPF recusados; troca auditada =="
pix() { curl -s -X POST "$BASE/restaurants/pix_key.php" "${SAUTH[@]}" -H "Content-Type: application/json" -d "{\"pix_key\":\"$1\"}"; }
[ "$(pix "$(gen_cnpj)" | jq -r '.code')" = "invalid_pix_key" ] || fail "chave CNPJ de outra loja aceita"
[ "$(pix "529.982.247-25" | jq -r '.code')" = "invalid_pix_key" ] || fail "chave CPF aceita no painel"
R=$(pix "$CNPJ"); [ "$(echo "$R" | jq -r '.ownership_checked')" = "true" ] || fail "chave CNPJ da loja não conferida: $R"
[ "$(curl -s "$BASE/restaurants/pix_key.php" "${SAUTH[@]}" | jq -r '.pix_key')" = "$CNPJ" ] || fail "chave nova não aparece"
[ "$(q "SELECT (before->>'pix_key') || '>' || (after->>'pix_key') FROM audit_log WHERE action='restaurant.pix_key_changed' AND target='restaurants:${STORE}'")" \
  = "pix@cantina.test>${CNPJ}" ] || fail "troca de chave não auditada"

echo "== limite: 3 cadastros por IP em 24 h =="
signup "$(gen_cnpj)" "b-${STAMP}@loja.test" | jq -e '.restaurant_id' >/dev/null || fail "2º cadastro do IP recusado"
signup "$(gen_cnpj)" "c-${STAMP}@loja.test" | jq -e '.restaurant_id' >/dev/null || fail "3º cadastro do IP recusado"
[ "$(signup "$(gen_cnpj)" "d-${STAMP}@loja.test" | jq -r '.code')" = "too_many_signups" ] || fail "4º cadastro do IP passou"

echo "== admin: fila mostra contato, endereço e chave; aprovando, a loja vende =="
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
AAUTH=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$CODE\"}" | jq -er '.access_token')")
QUEUE=$(curl -s "$BASE/admin/restaurants.php" "${AAUTH[@]}" | jq ".pending[] | select(.id == \"${STORE}\")")
[ "$(echo "$QUEUE" | jq -r '.contact_name + "|" + .address_text + "|" + .pix_key + "|" + (.pix_key_checked|tostring) + "|" + (.has_location|tostring)')" \
  = "Dona Nonna|Rua das Flores, 100, Centro|${CNPJ}|true|true" ] || fail "fila do admin sem os dados do cadastro: $QUEUE"
R=$(curl -s -X POST "$BASE/admin/restaurants.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"restaurant_id\":\"${STORE}\",\"decision\":\"approve\"}")
echo "$R" | jq -e '.online_only_days' >/dev/null || fail "aprovação falhou: $R"
[ "$(q "SELECT signup_ip IS NULL FROM restaurants WHERE id='${STORE}'")" = "t" ] || fail "IP do cadastro não apagado na aprovação"
[ "$(curl -s "$BASE/restaurants/pause_status.php" "${SAUTH[@]}" | jq -r '.store.approval')" = "approved" ] || fail "painel não viu a aprovação"
add_item | jq -e '.items | length == 1' >/dev/null || fail "loja aprovada não aceitou item: $(add_item)"

# Não deixa lojas em análise desta suíte na fila das próximas.
psql_run -c "UPDATE restaurants SET rejected_at = now(), rejection_reason = 'teste', signup_ip = NULL
             WHERE approved_at IS NULL AND rejected_at IS NULL AND name = 'Cantina Cadastro ${STAMP}'"

echo "smoke_store_signup OK"
