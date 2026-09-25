#!/usr/bin/env bash
# Smoke test da troca de aparelho de parceiro pelo suporte
# (api/v1/admin/partner_devices.php).
#
# O login de loja fica preso ao primeiro aparelho; o segundo recebe
# device_mismatch ("Peça ao suporte para liberar a troca"). O admin acha a
# conta pelo CNPJ, libera com motivo, as sessões do aparelho antigo morrem
# (o refresh dele é recusado), o aparelho novo entra e vira o confiável, e a
# liberação fica no audit_log. Cliente comum não libera nada. E o limite de
# tentativas do login de parceiro (migração 032).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8129
BASE="http://127.0.0.1:${PORT}/api/v1"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-partner-device-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }

ADMIN_ID="$(gen_uuid)"
STAFF_ID="$(gen_uuid)"
STORE="$(gen_uuid)"
CNPJ="$(php "$ROOT/tests/support/random_cnpj.php")"
ADMIN_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"

echo "== semear admin e uma loja com login de balcão =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${ADMIN_ID}', 'admin', 'Admin Suporte', '${ADMIN_PHONE}', 'admin-device-${STAMP}@test.com'),
  ('${STAFF_ID}', 'restaurant_staff', 'Balcão Aparelho', NULL, 'staff-device-${STAMP}@test.com');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at)
VALUES ('${STORE}', 'Pizzaria Tablet Quebrado ${STAMP}', '${CNPJ}', '3550308', true, now());
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_ID}', 'restaurant', '${STORE}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
SQL
# senha do hash acima é "senha123"

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-partner-device-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${STORE}" && break
  sleep 0.2
done

store_login() {
  curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
    -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\",\"device_id\":\"$1\"}"
}

echo "== tablet A entra e vira o aparelho confiável; tablet B é barrado =="
A=$(store_login "tablet-A-${STAMP}")
A_REFRESH=$(echo "$A" | jq -er '.refresh_token') || fail "login do tablet A falhou: $A"
B=$(store_login "tablet-B-${STAMP}")
[ "$(echo "$B" | jq -r '.code')" = "device_mismatch" ] || fail "tablet B entrou sem liberação: $B"

echo "== admin entra =="
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code') || fail "otp do admin falhou"
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login do admin falhou"
AAUTH=(-H "Authorization: Bearer $ADMIN_TOKEN")

echo "== busca pelo começo do CNPJ e pelo nome =="
FOUND=$(curl -s "$BASE/admin/partner_devices.php?q=${CNPJ:0:8}" "${AAUTH[@]}")
ACCOUNT=$(echo "$FOUND" | jq -er ".accounts[] | select(.login_code == \"${CNPJ}\") | .id") || fail "busca por CNPJ não achou: $FOUND"
[ "$(echo "$FOUND" | jq -r ".accounts[] | select(.id == \"${ACCOUNT}\") | .device_bound")" = "true" ] || fail "conta não aparece presa: $FOUND"
BYNAME=$(curl -s "$BASE/admin/partner_devices.php?q=tablet%20quebrado%20${STAMP}" "${AAUTH[@]}")
[ "$(echo "$BYNAME" | jq -r '.accounts | length')" = "1" ] || fail "busca por nome errada: $BYNAME"
SHORT=$(curl -s "$BASE/admin/partner_devices.php?q=ab" "${AAUTH[@]}")
[ "$(echo "$SHORT" | jq -r '.code')" = "query_too_short" ] || fail "busca curta aceita: $SHORT"

echo "== cliente comum não libera; id malformado e motivo vazio são recusados =="
PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
C=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Curioso\"}" | jq -er '.dev_code')
CUSTOMER=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$C\"}" | jq -er '.access_token')
R=$(curl -s -X POST "$BASE/admin/partner_devices.php" -H "Authorization: Bearer $CUSTOMER" -H "Content-Type: application/json" \
  -d "{\"partner_account_id\":\"${ACCOUNT}\",\"reason\":\"quero entrar\"}")
[ "$(echo "$R" | jq -r '.code')" = "forbidden" ] || fail "cliente liberou aparelho: $R"
R=$(curl -s -X POST "$BASE/admin/partner_devices.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d '{"partner_account_id":"nao-e-uuid","reason":"tablet quebrou"}')
[ "$(echo "$R" | jq -r '.code')" = "invalid_request" ] || fail "id malformado não virou 422: $R"
R=$(curl -s -X POST "$BASE/admin/partner_devices.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"partner_account_id\":\"${ACCOUNT}\",\"reason\":\" \"}")
[ "$(echo "$R" | jq -r '.code')" = "reason_required" ] || fail "liberou sem motivo: $R"

echo "== admin libera com motivo: sessões do tablet A encerradas =="
R=$(curl -s -X POST "$BASE/admin/partner_devices.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"partner_account_id\":\"${ACCOUNT}\",\"reason\":\"tablet A quebrou, dono confirmou por telefone\"}")
[ "$(echo "$R" | jq -r '.released')" = "true" ] || fail "liberação falhou: $R"
[ "$(echo "$R" | jq -r '.sessions_revoked')" -ge 1 ] || fail "nenhuma sessão encerrada: $R"
OLD=$(curl -s -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" -d "{\"refresh_token\":\"${A_REFRESH}\"}")
echo "$OLD" | jq -e '.access_token' >/dev/null && fail "refresh do tablet A ainda vale depois da liberação: $OLD"

echo "== liberar de novo: já está livre (409) =="
R=$(curl -s -X POST "$BASE/admin/partner_devices.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"partner_account_id\":\"${ACCOUNT}\",\"reason\":\"de novo, por engano\"}")
[ "$(echo "$R" | jq -r '.code')" = "no_device_bound" ] || fail "segunda liberação não deu 409: $R"

echo "== tablet B entra e vira o confiável; o A agora é barrado =="
store_login "tablet-B-${STAMP}" | jq -e '.access_token' >/dev/null || fail "tablet B não entrou depois da liberação"
[ "$(store_login "tablet-A-${STAMP}" | jq -r '.code')" = "device_mismatch" ] || fail "tablet A voltou a entrar"

echo "== renovação: o tablet da cozinha continua sendo a loja depois dos 15 min (migração 039) =="
B=$(store_login "tablet-B-${STAMP}")
B_REFRESH=$(echo "$B" | jq -er '.refresh_token') || fail "login do tablet B: $B"
R=$(curl -s -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" -d "{\"refresh_token\":\"${B_REFRESH}\"}")
NEW_ACCESS=$(echo "$R" | jq -er '.access_token') || fail "refresh da loja falhou: $R"
B_REFRESH=$(echo "$R" | jq -r '.refresh_token')
# O payload do JWT vem sem padding; completa pra o base64 ler.
pad() { local p; p=$(echo "$1" | cut -d. -f2 | tr '_-' '/+'); while [ $(( ${#p} % 4 )) -ne 0 ]; do p="$p="; done; echo "$p" | base64 -d; }
[ "$(pad "$NEW_ACCESS" | jq -r '.restaurant_id')" = "$STORE" ] || fail "token renovado perdeu o restaurant_id: $(pad "$NEW_ACCESS")"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/restaurants/orders.php?id=${STORE}" -H "Authorization: Bearer $NEW_ACCESS")" = "200" ] \
  || fail "painel recusou o token renovado"

echo "== sessão de antes da 039 (sem claims) reconstrói pelo vínculo da loja =="
psql_run -c "UPDATE sessions SET claims = '{}' WHERE refresh_hash = encode(sha256('${B_REFRESH}'::bytea), 'hex')"
R=$(curl -s -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" -d "{\"refresh_token\":\"${B_REFRESH}\"}")
[ "$(pad "$(echo "$R" | jq -r '.access_token')" | jq -r '.restaurant_id')" = "$STORE" ] || fail "sessão antiga não reconstruiu a loja: $R"
B_REFRESH=$(echo "$R" | jq -r '.refresh_token')

echo "== conta bloqueada não renova (403), e desbloqueada volta =="
psql_run -c "UPDATE users SET blocked = true WHERE id = '${STAFF_ID}'"
R=$(curl -s -w ' %{http_code}' -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" -d "{\"refresh_token\":\"${B_REFRESH}\"}")
psql_run -c "UPDATE users SET blocked = false WHERE id = '${STAFF_ID}'"
[ "${R##* }" = "403" ] || fail "conta bloqueada renovou: $R"

echo "== sair revoga o refresh no servidor =="
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/logout.php" -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"${B_REFRESH}\"}")" = "204" ] || fail "logout não respondeu 204"
R=$(curl -s -w ' %{http_code}' -X POST "$BASE/auth/refresh.php" -H "Content-Type: application/json" -d "{\"refresh_token\":\"${B_REFRESH}\"}")
[ "${R##* }" = "401" ] || fail "refresh continuou valendo depois de sair: $R"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/logout.php" -H "Content-Type: application/json" -d '{}')" = "204" ] \
  || fail "sair sem token deveria ser 204"

echo "== auditoria guarda quem, o aparelho antigo e o motivo =="
AUDIT=$(psql "$DATABASE_URL" -tAc "SELECT actor_id || '|' || (before->>'device_id') || '|' || (after->>'reason')
  FROM audit_log WHERE action = 'partner.device_released' AND target = 'partner_accounts:${ACCOUNT}'")
[ "$AUDIT" = "${ADMIN_ID}|tablet-A-${STAMP}|tablet A quebrou, dono confirmou por telefone" ] || fail "auditoria errada: '$AUDIT'"

echo "== limite de tentativas (migração 032): acertar zera; 5 erros travam até a senha certa =="
wrong() {
  curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
    -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"errada\",\"device_id\":\"tablet-B-${STAMP}\"}" | jq -r '.code'
}
for i in 1 2 3; do [ "$(wrong)" = "invalid_credentials" ] || fail "erro $i não deu 401"; done
store_login "tablet-B-${STAMP}" | jq -e '.access_token' >/dev/null || fail "senha certa recusada depois de 3 erros"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM partner_login_failures WHERE login_code='${CNPJ}'")" = "0" ] \
  || fail "acertar a senha não zerou os erros"
for i in 1 2 3 4 5; do [ "$(wrong)" = "invalid_credentials" ] || fail "erro $i (de 5) não deu 401"; done
[ "$(store_login "tablet-B-${STAMP}" | jq -r '.code')" = "login_locked" ] || fail "6ª tentativa, mesmo com a senha certa, não travou"
# Conta que não existe também conta erro (e dá a mesma resposta que senha errada).
GHOST="$(php "$ROOT/tests/support/random_cnpj.php")"
R=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${GHOST}\",\"secret\":\"x\"}")
[ "$(echo "$R" | jq -r '.code')" = "invalid_credentials" ] || fail "conta inexistente respondeu diferente: $R"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM partner_login_failures WHERE login_code='${GHOST}'")" = "1" ] \
  || fail "erro em conta inexistente não foi contado"
# Destrava pra não sobrar estado pras próximas suítes.
psql_run -c "DELETE FROM partner_login_failures WHERE login_code IN ('${CNPJ}', '${GHOST}')"

echo "smoke_partner_device OK"
