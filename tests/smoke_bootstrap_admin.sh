#!/usr/bin/env bash
# Smoke test do primeiro deploy (bin/bootstrap_admin.php).
#
# Precisa de um banco RECÉM-MIGRADO, sem nenhum usuário -- exatamente o
# cenário do primeiro deploy. Por isso roda como a PRIMEIRA suíte do CI (um
# segundo banco não serve: o pg_cron só existe no banco de cron.database_name).
#
#  - sem argumentos, explica o uso e não mexe em nada;
#  - cria o admin fundador e a política v1 com os padrões da migração 003;
#  - rodar de novo é recusado (não cria segundo admin nem mexe na política);
#  - o admin criado entra pelo OTP no painel da plataforma.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco recém-migrado e vazio}"
: "${JWT_SECRET:=ci-test-secret}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8127
FRESH="$DATABASE_URL"

fail() { echo "FALHOU: $1" >&2; exit 1; }
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

[ "$(psql "$FRESH" -tAc "SELECT count(*) FROM users")" = "0" ] \
  || fail "o banco já tem usuários: esta suíte simula o primeiro deploy e roda primeiro, num banco recém-migrado"
export JWT_SECRET APP_ENV=development FUU_ENV_FILE=/dev/null

echo "== sem argumentos: uso, nada criado =="
set +e; php "$ROOT/bin/bootstrap_admin.php" >/dev/null 2>&1; CODE=$?; set -e
[ "$CODE" = "2" ] || fail "sem argumentos não explicou o uso (saída $CODE)"
[ "$(psql "$FRESH" -tAc "SELECT count(*) FROM users")" = "0" ] || fail "criou algo sem argumentos"

echo "== primeira execução: admin + política v1 com os padrões =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
php "$ROOT/bin/bootstrap_admin.php" --name="Fundador Teste" --phone="$PHONE" >/tmp/smoke-boot.log || fail "bootstrap falhou: $(cat /tmp/smoke-boot.log)"
[ "$(psql "$FRESH" -tAc "SELECT role FROM users WHERE phone='${PHONE}'")" = "admin" ] || fail "admin não criado"
[ "$(psql "$FRESH" -tAc "SELECT version || '|' || commission_bps || '|' || cash_ceiling || '|' || array_length(enabled_methods, 1) FROM platform_policies")" = "1|800|300.00|5" ] \
  || fail "política v1 fora dos padrões: $(psql "$FRESH" -tAc "SELECT * FROM platform_policies")"
[ "$(psql "$FRESH" -tAc "SELECT password_hash IS NULL FROM users WHERE phone='${PHONE}'")" = "t" ] || fail "admin com senha"

echo "== segunda execução: recusada, nada muda =="
set +e; php "$ROOT/bin/bootstrap_admin.php" --name="Outro" --phone="11988887777" >/dev/null 2>&1; CODE=$?; set -e
[ "$CODE" = "1" ] || fail "rodou de novo sem recusar"
[ "$(psql "$FRESH" -tAc "SELECT count(*) FROM users WHERE role='admin'") $(psql "$FRESH" -tAc "SELECT count(*) FROM platform_policies")" = "1 1" ] \
  || fail "a segunda execução mexeu no banco"

echo "== o fundador entra pelo OTP e vê o painel =="
DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-boot-server.log 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:${PORT}/" && break; sleep 0.2; done
BASE="http://127.0.0.1:${PORT}/api/v1"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" -d "{\"purpose\":\"login\",\"phone\":\"$PHONE\"}" | jq -er '.dev_code')
TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/reports.php" -H "Authorization: Bearer $TOKEN")" = "200" ] || fail "fundador não entrou no painel"

echo "smoke_bootstrap_admin OK"
