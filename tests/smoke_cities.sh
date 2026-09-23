#!/usr/bin/env bash
# Smoke test das cidades atendidas (migração 036): cities/list.php (público,
# telas 1.2 e 1.3) e admin/cities.php (aba Cidades).
#
#  - só admin mexe; dado ruim volta 422 com o campo (IBGE, UF, lat/lng
#    trocados, sem bairro);
#  - criar dá 201, salvar de novo dá 200, e as duas vão pro audit_log;
#  - a lista pública mostra só cidade ligada, agrupada por UF, e conta só
#    loja aprovada -- nenhum número de vitrine;
#  - desligar some com a cidade do app sem apagar nada.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8143
BASE="http://127.0.0.1:${PORT}/api/v1"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-cities-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
q() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

ADMIN_ID="$(gen_uuid)"
ADMIN_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CUSTOMER_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"
# Códigos de 7 dígitos que nenhum município usa (começam com 99), pra não
# esbarrar nas cidades das outras suítes.
CITY="99$(( RANDOM % 90000 + 10000 ))"
OTHER="98$(( RANDOM % 90000 + 10000 ))"

psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email)
VALUES ('${ADMIN_ID}', 'admin', 'Admin Cidades', '${ADMIN_PHONE}', 'admin-cities-${STAMP}@test.com'),
       ('$(gen_uuid)', 'customer', 'Cliente Cidades', '${CUSTOMER_PHONE}', 'cliente-cities-${STAMP}@test.com');
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-cities-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "$BASE/cities/list.php" && break
  sleep 0.2
done

login() {  # login PHONE -> token
  local code
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"login\",\"phone\":\"$1\"}" | jq -er '.dev_code')
  curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"login\",\"phone\":\"$1\",\"code\":\"$code\"}" | jq -er '.access_token'
}
ADMIN=(-H "Authorization: Bearer $(login "$ADMIN_PHONE")")
save() {  # save JSON -> "corpo código"
  curl -s -w ' %{http_code}' -X POST "$BASE/admin/cities.php" "${ADMIN[@]}" -H "Content-Type: application/json" -d "$1"
}
city_json() {  # city_json IBGE NOME UF LAT LNG ATIVA
  echo "{\"ibge_code\":\"$1\",\"name\":\"$2\",\"uf\":\"$3\",\"lat\":$4,\"lng\":$5,\"neighborhoods\":[\"Centro\",\"Vila Nova\"],\"active\":$6}"
}

echo "== só admin mexe nas cidades =="
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/cities.php")" = "401" ] || fail "rota do admin aberta sem login"
CUSTOMER=(-H "Authorization: Bearer $(login "$CUSTOMER_PHONE")")
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/cities.php" "${CUSTOMER[@]}")" = "403" ] || fail "cliente mexeu nas cidades"

echo "== dado ruim: 422 com o campo =="
R=$(save "{\"ibge_code\":\"123\",\"name\":\"X\",\"uf\":\"ZZ\",\"lat\":-47.06,\"lng\":-22.9,\"neighborhoods\":[]}")
[ "${R##* }" = "422" ] || fail "cidade inválida aceita: $R"
for f in ibge_code uf lat lng neighborhoods; do
  [ "$(echo "${R% *}" | jq -r ".fields.${f}")" != "null" ] || fail "sem erro no campo ${f}: $R"
done

echo "== criar 201, salvar de novo 200, as duas auditadas =="
R=$(save "$(city_json "$CITY" "Cidade Teste ${STAMP}" SP -22.9056 -47.0608 true)")
[ "${R##* }" = "201" ] || fail "criar cidade: $R"
R=$(save "$(city_json "$OTHER" "Outra Teste ${STAMP}" MG -19.9167 -43.9345 true)")
[ "${R##* }" = "201" ] || fail "criar segunda cidade: $R"
R=$(save "$(city_json "$CITY" "Cidade Teste ${STAMP}" SP -22.9056 -47.0608 true)")
[ "${R##* }" = "200" ] || fail "salvar cidade existente: $R"
[ "$(q "SELECT count(*) FROM audit_log WHERE action='service_city.saved' AND target='service_cities:${CITY}'")" = "2" ] \
  || fail "mudança de cidade sem audit_log"
[ "$(q "SELECT array_to_string(neighborhoods, '|') FROM service_cities WHERE ibge_code='${CITY}'")" = "Centro|Vila Nova" ] \
  || fail "bairros gravados errado"

echo "== lista pública: contagem real (só loja aprovada), agrupada por UF =="
psql_run <<SQL
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at) VALUES
  ('$(gen_uuid)', 'Aprovada ${STAMP}',  '$(gen_cnpj)', '${CITY}', true, now()),
  ('$(gen_uuid)', 'Aprovada2 ${STAMP}', '$(gen_cnpj)', '${CITY}', false, now()),
  ('$(gen_uuid)', 'Em análise ${STAMP}', '$(gen_cnpj)', '${CITY}', true, NULL);
SQL
L=$(curl -s "$BASE/cities/list.php")
[ "$(echo "$L" | jq -r ".states[].cities[] | select(.ibge == \"${CITY}\") | .stores")" = "2" ] \
  || fail "contagem de lojas não é a real (2 aprovadas): $L"
[ "$(echo "$L" | jq -r ".states[].cities[] | select(.ibge == \"${OTHER}\") | .stores")" = "0" ] || fail "cidade sem loja com contagem: $L"
[ "$(echo "$L" | jq -r ".states[] | select(.cities[].ibge == \"${CITY}\") | .uf + \"|\" + .name")" = "SP|São Paulo" ] \
  || fail "cidade fora do estado certo: $L"
[ "$(echo "$L" | jq -r ".states[].cities[] | select(.ibge == \"${CITY}\") | .neighborhoods | join(\"|\")")" = "Centro|Vila Nova" ] \
  || fail "bairros fora da lista pública: $L"

echo "== desligar tira do app, sem apagar =="
R=$(save "$(city_json "$OTHER" "Outra Teste ${STAMP}" MG -19.9167 -43.9345 false)")
[ "${R##* }" = "200" ] || fail "desligar cidade: $R"
curl -s "$BASE/cities/list.php" | jq -e ".states[].cities[] | select(.ibge == \"${OTHER}\")" >/dev/null \
  && fail "cidade desligada continua no app"
curl -s "$BASE/admin/cities.php" "${ADMIN[@]}" | jq -e ".cities[] | select(.ibge == \"${OTHER}\" and .active == false)" >/dev/null \
  || fail "cidade desligada sumiu do admin"

echo "OK: cidades atendidas"
