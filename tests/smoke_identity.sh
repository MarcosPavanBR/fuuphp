#!/usr/bin/env bash
# Smoke test do módulo identity (migração 001): sobe o servidor PHP embutido
# contra o DATABASE_URL informado, roda o fluxo ponta a ponta e falha se
# qualquer verificação não bater. Espelha o que a especificação pede na
# Parte I §9 para o resto do sistema: nenhum teste solto, tudo no CI.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8099
BASE="http://127.0.0.1:${PORT}/api/v1/auth"

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-identity-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/auth/otp_request.php" && break
  sleep 0.2
done

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-identity-server.log >&2; exit 1; }

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"

echo "== signup + otp_request =="
REQ=$(curl -s -X POST "$BASE/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Smoke Test\"}")
CODE=$(echo "$REQ" | jq -er '.dev_code') || fail "otp_request não devolveu dev_code: $REQ"

echo "== otp_verify com código errado precisa dar otp_invalid =="
WRONG=$(curl -s -X POST "$BASE/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"000000\"}")
[ "$(echo "$WRONG" | jq -r '.code')" = "otp_invalid" ] || fail "código errado não foi rejeitado: $WRONG"

echo "== otp_verify com código certo precisa devolver tokens =="
OK=$(curl -s -X POST "$BASE/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")
ACCESS=$(echo "$OK" | jq -er '.access_token') || fail "otp_verify não devolveu access_token: $OK"
REFRESH=$(echo "$OK" | jq -er '.refresh_token') || fail "otp_verify não devolveu refresh_token: $OK"

echo "== consent sem token precisa dar 401 =="
STATUS=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/consent.php" \
  -H "Content-Type: application/json" -d '{"kind":"terms","version":"1.0"}')
[ "$STATUS" = "401" ] || fail "consent sem token não deu 401 (deu $STATUS)"

echo "== consent com token precisa gravar =="
CONSENT=$(curl -s -X POST "$BASE/consent.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ACCESS" -d '{"kind":"terms","version":"1.0"}')
[ "$(echo "$CONSENT" | jq -r '.recorded')" = "true" ] || fail "consent não gravou: $CONSENT"

echo "== cadastro (tela 10.3): CPF, e-mail e data de nascimento =="
PROFILE_BASE="http://127.0.0.1:${PORT}/api/v1/profile"
CPF="$(php "$ROOT/tests/support/random_cpf.php")"
UPD=$(curl -s -X POST "$PROFILE_BASE/update.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ACCESS" \
  -d "{\"full_name\":\"Smoke Test Silva\",\"email\":\"smoke-$(date +%s%N)@test.com\",\"cpf\":\"$CPF\",\"birth_date\":\"1990-04-20\"}")
[ "$(echo "$UPD" | jq -r '.user.full_name')" = "Smoke Test Silva" ] || fail "profile/update não gravou o nome: $UPD"
[ "$(echo "$UPD" | jq -r '.user.birth_date')" = "1990-04-20" ] || fail "profile/update não gravou a data de nascimento: $UPD"
[ "$(psql "$DATABASE_URL" -tAc "SELECT cpf FROM users WHERE phone='$PHONE'")" = "$CPF" ] || fail "CPF não foi gravado no banco"

echo "== CPF inválido é barrado antes de chegar no banco =="
BAD=$(curl -s -X POST "$PROFILE_BASE/update.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ACCESS" -d '{"cpf":"11111111111"}')
[ "$(echo "$BAD" | jq -r '.code')" = "invalid_cpf" ] || fail "CPF inválido não foi barrado: $BAD"

echo "== data de nascimento no futuro é barrada =="
FUTURE=$(curl -s -X POST "$PROFILE_BASE/update.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ACCESS" -d '{"birth_date":"2099-01-01"}')
[ "$(echo "$FUTURE" | jq -r '.code')" = "invalid_birth_date" ] || fail "data futura não foi barrada: $FUTURE"

echo "== CPF de outra conta dá 409, não 500 =="
PHONE2="119$(( RANDOM % 90000000 + 10000000 ))"
CODE2=$(curl -s -X POST "$BASE/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Outro Smoke\"}" | jq -er '.dev_code')
ACCESS2=$(curl -s -X POST "$BASE/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"code\":\"$CODE2\"}" | jq -er '.access_token')
DUP=$(curl -s -X POST "$PROFILE_BASE/update.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ACCESS2" -d "{\"cpf\":\"$CPF\"}")
[ "$(echo "$DUP" | jq -r '.code')" = "cpf_in_use" ] || fail "CPF repetido não deu 409: $DUP"

echo "== reenvio por WhatsApp (tela 10.2) usa o canal pedido =="
WPP=$(curl -s -X POST "$BASE/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"$PHONE\",\"channel\":\"whatsapp\"}")
[ "$(echo "$WPP" | jq -r '.channel')" = "whatsapp" ] || fail "canal whatsapp não foi respeitado: $WPP"
[ "$(psql "$DATABASE_URL" -tAc "SELECT channel FROM otp_codes WHERE user_id=(SELECT id FROM users WHERE phone='$PHONE') ORDER BY created_at DESC LIMIT 1")" = "whatsapp" ] \
  || fail "canal whatsapp não foi gravado em otp_codes"
BADCH=$(curl -s -X POST "$BASE/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"$PHONE\",\"channel\":\"pombo-correio\"}")
[ "$(echo "$BADCH" | jq -r '.code')" = "invalid_channel" ] || fail "canal inventado não foi barrado: $BADCH"

echo "== refresh roda e devolve par novo =="
ROT=$(curl -s -X POST "$BASE/refresh.php" -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"$REFRESH\"}")
NEW_REFRESH=$(echo "$ROT" | jq -er '.refresh_token') || fail "refresh não rotacionou: $ROT"

echo "== reusar o refresh velho precisa detectar roubo e revogar a família =="
REUSE=$(curl -s -X POST "$BASE/refresh.php" -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"$REFRESH\"}")
[ "$(echo "$REUSE" | jq -r '.code')" = "refresh_reused" ] || fail "reuso não foi detectado: $REUSE"

echo "== o refresh novo (da rotação) também precisa ter sido revogado =="
CASCADE=$(curl -s -X POST "$BASE/refresh.php" -H "Content-Type: application/json" \
  -d "{\"refresh_token\":\"$NEW_REFRESH\"}")
[ "$(echo "$CASCADE" | jq -r '.code')" = "refresh_reused" ] || fail "família não foi revogada em cascata: $CASCADE"

echo "OK: módulo identity passou no smoke test"
