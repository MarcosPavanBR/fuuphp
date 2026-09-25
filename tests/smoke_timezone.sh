#!/usr/bin/env bash
# Smoke test do fuso por cidade (migração 038, decisão 40).
#
# O Brasil tem quatro fusos. Uma loja de Mato Grosso do Sul (UTC−4) segue o
# relógio DE LÁ, não o de Brasília:
#  - a cidade nova de MS nasce com o fuso de Campo Grande (pela UF); fuso fora
#    da lista volta 422;
#  - abrir/fechar automático: duas lojas com o MESMO horário, montado pra
#    estar aberto agora no relógio de MS -- só a de MS abre (em SP já é uma
#    hora depois);
#  - as faixas de agendamento saem com -04:00, e o "hoje" é o dia de MS;
#  - "fechar por hoje" dura até a meia-noite de MS;
#  - banner da cidade de MS começa e termina na meia-noite de MS.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8147
BASE="http://127.0.0.1:${PORT}/api/v1"
MS_TZ="America/Campo_Grande"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-timezone-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
q() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

STAMP="$(date +%s%N)"
# Prefixo da UF certo (50 = MS, 35 = SP), números que nenhum município usa.
MS_CITY="5097$(( (RANDOM << 15 | RANDOM) % 900 + 100 ))"
SP_CITY="3597$(( (RANDOM << 15 | RANDOM) % 900 + 100 ))"
ADMIN_ID="$(gen_uuid)"; ADMIN_PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CUST_PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
STAFF_ID="$(gen_uuid)"
MS_STORE="$(gen_uuid)"; SP_STORE="$(gen_uuid)"; MS_CNPJ="$(gen_cnpj)"

psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${ADMIN_ID}', 'admin', 'Admin Fuso', '${ADMIN_PHONE}', 'admin-fuso-${STAMP}@test.com'),
  ('$(gen_uuid)', 'customer', 'Cliente Fuso', '${CUST_PHONE}', 'cliente-fuso-${STAMP}@test.com'),
  ('${STAFF_ID}', 'restaurant_staff', 'Balcão Fuso', NULL, 'balcao-fuso-${STAMP}@test.com');
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-timezone-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/system/health.php" && break; sleep 0.2; done

login() {
  local code
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"login\",\"phone\":\"$1\"}" | jq -er '.dev_code')
  curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"login\",\"phone\":\"$1\",\"code\":\"$code\"}" | jq -er '.access_token'
}
ADMIN=(-H "Authorization: Bearer $(login "$ADMIN_PHONE")")
city() {  # city IBGE NOME UF [FUSO] -> "corpo código"
  local tz=""; [ -n "${4:-}" ] && tz=",\"timezone\":\"$4\""
  curl -s -w ' %{http_code}' -X POST "$BASE/admin/cities.php" "${ADMIN[@]}" -H "Content-Type: application/json" \
    -d "{\"ibge_code\":\"$1\",\"name\":\"$2\",\"uf\":\"$3\",\"lat\":-20.4,\"lng\":-54.6,\"neighborhoods\":[\"Centro\"]${tz}}"
}

echo "== cidade de MS nasce no fuso de Campo Grande; fuso fora da lista, 422 =="
R=$(city "$MS_CITY" "Fuso MS ${STAMP}" MS "Europe/Lisbon")
[ "$(echo "${R% *}" | jq -r '.fields.timezone')" = "fuso não aceito" ] || fail "fuso fora da lista aceito: $R"
R=$(city "$MS_CITY" "Fuso MS ${STAMP}" MS); [ "${R##* }" = "201" ] || fail "criar cidade de MS: $R"
[ "$(q "SELECT timezone FROM service_cities WHERE ibge_code='${MS_CITY}'")" = "$MS_TZ" ] || fail "MS não nasceu no fuso de Campo Grande"
R=$(city "$SP_CITY" "Fuso SP ${STAMP}" SP); [ "${R##* }" = "201" ] || fail "criar cidade de SP: $R"
[ "$(q "SELECT timezone FROM service_cities WHERE ibge_code='${SP_CITY}'")" = "America/Sao_Paulo" ] || fail "SP fora do fuso de Brasília"

echo "== abrir/fechar automático no relógio da cidade da loja =="
# Uma janela de 40 min em volta de AGORA no relógio de MS. Em SP já é uma
# hora depois, então a mesma janela lá já fechou. O dia da semana é o do
# começo da janela (se a janela passar da meia-noite, o turno é de "ontem").
psql_run <<SQL
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, is_open, approved_at, slot_capacity) VALUES
  ('${MS_STORE}', 'Loja de MS', '${MS_CNPJ}',   '${MS_CITY}', 'Lanches', false, now(), 3),
  ('${SP_STORE}', 'Loja de SP', '$(gen_cnpj)',  '${SP_CITY}', 'Lanches', false, now(), 3);
INSERT INTO business_hours (restaurant_id, dow, shift, opens, closes, last_order, active)
SELECT r, EXTRACT(dow FROM timezone('${MS_TZ}', now()) - interval '20 minutes')::int, 'dinner',
       (timezone('${MS_TZ}', now()) - interval '20 minutes')::time,
       (timezone('${MS_TZ}', now()) + interval '20 minutes')::time,
       (timezone('${MS_TZ}', now()) + interval '20 minutes')::time, true
  FROM unnest(ARRAY['${MS_STORE}', '${SP_STORE}']::uuid[]) r;
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_ID}', 'restaurant', '${MS_STORE}', '${MS_CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
SELECT apply_business_hours();
SQL
[ "$(q "SELECT is_open FROM restaurants WHERE id='${MS_STORE}'")" = "t" ] || fail "loja de MS não abriu no horário de MS"
[ "$(q "SELECT is_open FROM restaurants WHERE id='${SP_STORE}'")" = "f" ] || fail "loja de SP abriu no horário de MS (deveria seguir Brasília)"

echo "== faixas de agendamento com -04:00 e o \"hoje\" de MS =="
# A janela de 40 min não cabe uma faixa inteira no futuro: pra agendar, a
# loja de MS passa a ter também o dia todo, todos os dias.
psql_run -c "INSERT INTO business_hours (restaurant_id, dow, shift, opens, closes, last_order, active)
             SELECT '${MS_STORE}', d, 'lunch', '00:00', '23:59', '23:59', true FROM generate_series(0, 6) d"
CUST=(-H "Authorization: Bearer $(login "$CUST_PHONE")")
SLOTS=$(curl -s "$BASE/orders/slots.php?restaurant_id=${MS_STORE}" "${CUST[@]}")
[ "$(echo "$SLOTS" | jq -r '.days[0].day')" = "$(TZ=${MS_TZ} date +%F)" ] || fail "\"hoje\" não é o dia de MS: $(echo "$SLOTS" | jq -c '.days[0]')"
ALL=$(echo "$SLOTS" | jq -r '[.slots[][] | .start] | length')
[ "$ALL" -gt 0 ] || fail "nenhuma faixa oferecida: $SLOTS"
echo "$SLOTS" | jq -e '[.slots[][] | .start] | all(endswith("-04:00"))' >/dev/null || fail "faixa sem o deslocamento de MS: $SLOTS"

echo "== \"fechar por hoje\" dura até a meia-noite de MS =="
STAFF=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${MS_CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')")
curl -s -X POST "$BASE/restaurants/pause.php" "${STAFF[@]}" -H "Content-Type: application/json" \
  -d '{"action":"close_today","reason":"technical"}' >/dev/null
[ "$(q "SELECT to_char(pause_until AT TIME ZONE '${MS_TZ}', 'YYYY-MM-DD HH24:MI') FROM restaurants WHERE id='${MS_STORE}'")" \
  = "$(TZ=${MS_TZ} date -d tomorrow +%F) 00:00" ] || fail "fechar por hoje não vai até a meia-noite de MS"

echo "== banner da cidade de MS: as datas são dias de MS =="
TMP="$(mktemp -d)"
php -r '$i=imagecreatetruecolor(1600,600);imagefill($i,0,0,imagecolorallocate($i,200,40,30));imagepng($i,$argv[1]);' "$TMP/b.png"
MS_TOMORROW=$(TZ=${MS_TZ} date -d tomorrow +%F)
R=$(curl -s -w ' %{http_code}' -X POST "$BASE/admin/banners.php" "${ADMIN[@]}" -F "image=@$TMP/b.png;type=image/png" \
  -F "title=Banner MS" -F "city_ibge_code=${MS_CITY}" -F "starts_on=${MS_TOMORROW}" -F "ends_on=${MS_TOMORROW}")
[ "${R##* }" = "201" ] || fail "criar banner de MS: $R"
BID=$(echo "${R% *}" | jq -r '.id')
[ "$(q "SELECT to_char(starts_at AT TIME ZONE '${MS_TZ}', 'YYYY-MM-DD HH24:MI') || ' ' || to_char(ends_at AT TIME ZONE '${MS_TZ}', 'YYYY-MM-DD HH24:MI') FROM promo_banners WHERE id=${BID}")" \
  = "${MS_TOMORROW} 00:00 $(TZ=${MS_TZ} date -d '2 days' +%F) 00:00" ] || fail "banner de MS fora da meia-noite de MS: $(q "SELECT starts_at, ends_at FROM promo_banners WHERE id=${BID}")"
psql_run -c "DELETE FROM promo_banners WHERE id=${BID}"
rm -rf "$TMP"

echo "OK: fuso por cidade"
