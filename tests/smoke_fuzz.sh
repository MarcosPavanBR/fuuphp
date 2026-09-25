#!/usr/bin/env bash
# Smoke test de entrada ruim em TODAS as rotas (auditoria de 25/09/2026).
#
# Manda parâmetros malformados (id que não é uuid, número que não é número,
# corpo com tipos errados) pra cada rota de api/v1, como anônimo, cliente,
# loja, entregador e admin. Nenhuma pode responder 5xx: entrada ruim é 4xx,
# com mensagem. Um 500 aqui é um erro de banco vazando (ex.: uuid inválido
# chegando no PostgreSQL) -- foram 6 rotas assim quando este teste nasceu.
#
# Rota nova entra sozinha (o teste lista a pasta). orders/track.php (SSE)
# fica de fora: segura a conexão por 25 s.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8149
BASE="http://127.0.0.1:${PORT}/api/v1"

fail() { echo "FALHOU: $1" >&2; tail -20 /tmp/smoke-fuzz-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

STAMP="$(date +%s%N)"
ADMIN_ID="$(gen_uuid)"; STAFF_ID="$(gen_uuid)"; STORE="$(gen_uuid)"; CNPJ="$(gen_cnpj)"
COURIER_USER="$(gen_uuid)"; COURIER_ID="$(gen_uuid)"
ADMIN_PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CUST_PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CPF="$(php -r '$n=[];for($i=0;$i<9;$i++)$n[]=random_int(0,9);for($t=9;$t<11;$t++){$s=0;for($i=0;$i<$t;$i++)$s+=$n[$i]*(($t+1)-$i);$n[]=((10*$s)%11)%10;}echo implode("",$n);')"

psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${ADMIN_ID}', 'admin', 'Admin Fuzz', '${ADMIN_PHONE}', 'admin-fuzz-${STAMP}@test.com'),
  ('${STAFF_ID}', 'restaurant_staff', 'Balcão Fuzz', NULL, 'balcao-fuzz-${STAMP}@test.com'),
  ('${COURIER_USER}', 'courier', 'Entregador Fuzz', NULL, 'entregador-fuzz-${STAMP}@test.com');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, is_open, approved_at)
  VALUES ('${STORE}', 'Loja Fuzz', '${CNPJ}', '3550308', 'Pizza', true, now());
INSERT INTO couriers (id, user_id, city_ibge_code, active) VALUES ('${COURIER_ID}', '${COURIER_USER}', '3550308', true);
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash) VALUES
  ('${STAFF_ID}', 'restaurant', '${STORE}', '${CNPJ}', '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
-- Entregador entra com CPF + código de acesso (formato antigo, SHA-256: o
-- primeiro login regrava em bcrypt, como em smoke_courier.sh).
INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash) VALUES
  ('${COURIER_USER}', 'courier', '${COURIER_ID}', '${CPF}', encode(sha256('senha123'::bytea), 'hex'));
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-fuzz-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/system/health.php" && break; sleep 0.2; done

otp_login() {  # otp_login TELEFONE PROPÓSITO
  local code
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"$2\",\"phone\":\"$1\",\"full_name\":\"Cliente Fuzz\"}" | jq -er '.dev_code')
  curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"$2\",\"phone\":\"$1\",\"code\":\"$code\"}" | jq -er '.access_token'
}
partner() {  # partner TIPO LOGIN
  curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
    -d "{\"kind\":\"$1\",\"login_code\":\"$2\",\"secret\":\"senha123\"}" | jq -er '.access_token'
}
ADMIN=$(otp_login "$ADMIN_PHONE" login) || fail "login do admin"
CUST=$(otp_login "$CUST_PHONE" signup) || fail "login do cliente"
STAFF=$(partner restaurant "$CNPJ") || fail "login da loja"
COURIER=$(partner courier "$CPF") || fail "login do entregador"

# Cada parâmetro que as rotas leem, com um valor que não serve pra ele.
QUERY='id=x&restaurant_id=x&order_id=x&city_ibge_code=x&address_id=x&key=x&image=x&proof_id=x&kind=x&ref_id=x&q=%27%22&from=x&to=x&day=x&scope=x&limit=x&offset=-1&ids=x,y&lat=x&lng=x&columns=x&status=x&type=x&token=x&month=x&week=x'
BODY='{"id":"x","restaurant_id":"x","order_id":"x","address_id":"x","proof_id":"x","menu_item_id":"x","item_id":"x","amount":"x","action":"x","code":"x","phone":"x","status":"x","items":"x","variant_ids":"x","scope":"x","scope_id":"x","patch":"x","slot":"x","lat":"x","lng":"x","quantity":"x","payment_method":"x","reason":"x","decision":"x","intent_id":"x","coupon_code":[],"kind":"x","favorite":"x","position":"x","refresh_token":["x"]}'
# O mesmo, com tipo trocado ao contrário: lista e objeto onde se espera texto
# ou número (PHP estoura com TypeError se a rota fizer (string) ou trim()).
BODY2='{"id":[1],"restaurant_id":{"a":1},"order_id":[],"address_id":{},"proof_id":[1],"menu_item_id":[],"amount":{"v":1},"action":["x"],"code":[1],"phone":{"n":1},"email":[1],"full_name":{"x":1},"cpf":[1],"status":[],"items":{"a":1},"notes":[1],"reason":{"r":1},"decision":[],"coupon_code":{"c":1},"kind":[],"favorite":[],"title":[],"name":{},"street":[],"number":{},"secret":[],"login_code":{},"purpose":[],"channel":{},"tip":"9e999","change_for":"-1","quantity":1e30,"lat":"NaN","lng":"Infinity"}'
KEY="00000000-0000-4000-8000-$(printf '%012d' "$RANDOM")"

echo "== todas as rotas, com entrada ruim, como anônimo, cliente, loja, entregador e admin: nenhum 5xx =="
BAD=""
COUNT=0
for f in $(find "$ROOT/api/v1" -name '*.php' ! -name guard.php | sort); do
  route=${f#"$ROOT"/api/v1/}
  [ "$route" = "orders/track.php" ] && continue
  for token in "" "$CUST" "$STAFF" "$COURIER" "$ADMIN"; do
    auth=(); [ -n "$token" ] && auth=(-H "Authorization: Bearer $token")
    get=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "$BASE/$route?$QUERY" "${auth[@]}")
    post=$(curl -s -o /dev/null -w '%{http_code}' -m 10 -X POST "$BASE/$route" "${auth[@]}" \
      -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY" -d "$BODY")
    post2=$(curl -s -o /dev/null -w '%{http_code}' -m 10 -X POST "$BASE/$route" "${auth[@]}" \
      -H "Content-Type: application/json" -H "X-Idempotency-Key: $KEY" -d "$BODY2")
    COUNT=$((COUNT + 3))
    [ "$post2" -ge 500 ] && BAD="$BAD\n  POST $route [tipos trocados] (${token:+com login}${token:-anônimo}) -> $post2"
    [ "$get" -ge 500 ] && BAD="$BAD\n  GET  $route (${token:+com login}${token:-anônimo}) -> $get"
    [ "$post" -ge 500 ] && BAD="$BAD\n  POST $route (${token:+com login}${token:-anônimo}) -> $post"
  done
done
[ -z "$BAD" ] || fail "rota respondeu 5xx pra entrada ruim:$(echo -e "$BAD" | sed -E 's/\(com login[^)]*\)/(com login)/')"
echo "   $COUNT chamadas, nenhum 5xx"

echo "OK: entrada ruim não derruba rota nenhuma"
