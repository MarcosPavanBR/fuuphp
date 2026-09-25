#!/usr/bin/env bash
# Smoke test do módulo de descoberta (migração 010): lista de lojas por
# cidade com distância por Haversine e filtro de categoria, busca de
# produto por trigram, e perfil com estatísticas reais.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8097
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3509502"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-discovery-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }

gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
# CNPJ aleatório com dígito verificador válido -- fixo colidiria com o que
# smoke_ordering.sh semeia quando os dois rodam no mesmo banco, em sequência,
# como o CI faz.
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

NEAR_ID="$(gen_uuid)"
FAR_ID="$(gen_uuid)"
NEAR_CNPJ="$(gen_cnpj)"
FAR_CNPJ="$(gen_cnpj)"

echo "== semear uma loja perto e uma loja longe, com categorias diferentes =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
  SELECT gen_random_uuid(), 'admin', 'Admin Discovery Smoke', 'admin-discovery-smoke@test.com'
  WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin-discovery-smoke@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'admin-discovery-smoke@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, category, lat, lng, approved_at)
VALUES
  ('${NEAR_ID}', 'Loja Perto Smoke', '${NEAR_CNPJ}', '${CITY}', true, 'Lanches', -22.9040, -47.0600, now()),
  ('${FAR_ID}', 'Loja Longe Smoke', '${FAR_CNPJ}', '${CITY}', true, 'Pizza', -23.5505, -46.6333, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES
  ('${NEAR_ID}', ARRAY['cash']::payment_method[], 100.00, 20.00),
  ('${FAR_ID}', ARRAY['cash']::payment_method[], 100.00, 20.00);

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${NEAR_ID}', 'X-Smoke Burger', 30.00, 'Lanches', true);
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-discovery-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/list.php?city_ibge_code=${CITY}" && break
  sleep 0.2
done

echo "== lista ordenada por distância (loja perto primeiro) =="
LIST=$(curl -s "$BASE/restaurants/list.php?city_ibge_code=${CITY}&lat=-22.9040&lng=-47.0600")
FIRST_ID=$(echo "$LIST" | jq -er '.restaurants[0].id') || fail "lista não veio: $LIST"
[ "$FIRST_ID" = "$NEAR_ID" ] || fail "loja perto não veio primeiro: $LIST"
DIST=$(echo "$LIST" | jq -r --arg id "$NEAR_ID" '.restaurants[] | select(.id == $id) | .distance_km')
[ "$DIST" = "0.0" ] || fail "distância da loja perto não bateu (esperava ~0.0, veio $DIST)"

echo "== paginação: 1 por página, sem repetir nem pular; ?ids= acha a loja em qualquer página =="
Q="city_ibge_code=${CITY}&lat=-22.9040&lng=-47.0600&open_only=0"
TOTAL=$(curl -s "$BASE/restaurants/list.php?${Q}&limit=200" | jq '.restaurants | length')
[ "$TOTAL" -ge 2 ] || fail "precisa de 2 lojas pra testar a página (veio $TOTAL)"
P1=$(curl -s "$BASE/restaurants/list.php?${Q}&limit=1")
[ "$(echo "$P1" | jq -c '[(.restaurants|length), .has_more, .next_offset]')" = "[1,true,1]" ] || fail "página 1: $P1"
SEEN=""; OFF=0
while [ "$OFF" != "null" ]; do
  PAGE=$(curl -s "$BASE/restaurants/list.php?${Q}&limit=1&offset=${OFF}")
  SEEN="$SEEN $(echo "$PAGE" | jq -r '.restaurants[].id')"
  OFF=$(echo "$PAGE" | jq -r '.next_offset')
done
[ "$(echo $SEEN | tr ' ' '\n' | sort -u | wc -l)" = "$TOTAL" ] || fail "páginas repetiram ou pularam loja: $SEEN"
[ "$(echo $SEEN | wc -w)" = "$TOTAL" ] || fail "alguma loja saiu em duas páginas: $SEEN"
ONLY=$(curl -s "$BASE/restaurants/list.php?${Q}&limit=1&ids=${FAR_ID},nao-e-uuid")
[ "$(echo "$ONLY" | jq -r '[.restaurants[].id] | join(",")')" = "$FAR_ID" ] || fail "?ids= não trouxe só a favorita: $ONLY"

echo "== relatório de CSP: 204 sempre (lixo, formato antigo e Reporting API), nunca 5xx =="
for body in 'isto não é json' '{"csp-report":{"blocked-uri":"https://evil.example/x.js","effective-directive":"script-src","document-uri":"https://x/?token=abc"}}' \
            '[{"type":"csp-violation","body":{"blockedURL":"inline","effectiveDirective":"script-src-elem","documentURL":"https://x/"}}]'; do
  [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/system/csp_report.php" -H 'Content-Type: application/csp-report' -d "$body")" = "204" ] \
    || fail "csp_report não respondeu 204 pra: $body"
done

echo "== filtro de categoria só devolve a categoria pedida =="
PIZZA=$(curl -s "$BASE/restaurants/list.php?city_ibge_code=${CITY}&category=Pizza")
COUNT=$(echo "$PIZZA" | jq '.restaurants | length')
[ "$COUNT" = "1" ] || fail "filtro de categoria trouxe $COUNT lojas, esperava 1: $PIZZA"
[ "$(echo "$PIZZA" | jq -r '.restaurants[0].id')" = "$FAR_ID" ] || fail "categoria errada veio no filtro: $PIZZA"

echo "== busca de produto por trigram acha o item pelo nome parcial =="
SEARCH=$(curl -s "$BASE/restaurants/search_products.php?city_ibge_code=${CITY}&q=smoke%20burger")
[ "$(echo "$SEARCH" | jq '.products | length')" -ge 1 ] || fail "busca não achou o produto: $SEARCH"
[ "$(echo "$SEARCH" | jq -r '.products[0].restaurant_id')" = "$NEAR_ID" ] || fail "produto veio da loja errada: $SEARCH"

echo "== busca com menos de 2 caracteres é barrada =="
SHORT_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/restaurants/search_products.php?city_ibge_code=${CITY}&q=a")
[ "$SHORT_CODE" = "422" ] || fail "busca curta não foi barrada (veio $SHORT_CODE)"

echo "== perfil autenticado devolve estatísticas reais =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Perfil Smoke\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
PROFILE=$(curl -s "$BASE/profile/show.php" -H "Authorization: Bearer $ACCESS")
[ "$(echo "$PROFILE" | jq -r '.user.full_name')" = "Perfil Smoke" ] || fail "perfil não bateu: $PROFILE"
[ "$(echo "$PROFILE" | jq -r '.stats.orders_count')" = "0" ] || fail "contagem de pedidos errada pra usuário novo: $PROFILE"
echo "$PROFILE" | jq -e 'has("user") and (.user | has("cpf") | not)' >/dev/null || fail "CPF vazou na resposta de perfil: $PROFILE"

echo "OK: módulo de descoberta (restaurants/list, search_products, profile) passou no smoke test"
