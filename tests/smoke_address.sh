#!/usr/bin/env bash
# Smoke test do endereço com área e taxa (Fase 14.3): ponto de referência,
# cobertura validada no servidor, taxa calculada ANTES de salvar e o frete
# que o checkout passa a calcular sozinho -- o último número do dinheiro que
# ainda vinha do cliente.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8110
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-address-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
NOGEO_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
ADMIN_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
NOGEO_CNPJ="$(gen_cnpj)"
ADMIN_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"

# Tarifa do teste: R$ 4,00 de base + R$ 1,50/km, raio de 5 km. Os números são
# do teste, não do produto -- no banco a tarifa nasce zerada de propósito.
echo "== semear política com tarifa, uma loja com coordenada e uma sem =="
psql_run <<SQL
-- O admin entra pelo mesmo OTP do cliente: não existe conta de parceiro
-- 'platform' (partner_accounts.kind só aceita restaurant/courier).
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Address Smoke', NULL, 'staff-address-${STAMP}@test.com'),
  ('${ADMIN_USER_ID}', 'admin',            'Admin Address Smoke', '${ADMIN_PHONE}', 'admin-address-${STAMP}@test.com');

INSERT INTO platform_policies
  (version, enabled_methods, delivery_base_fee, delivery_per_km, delivery_max_km, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         4.00, 1.50, 5.0, id
  FROM users WHERE email = 'staff-address-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng) VALUES
  ('${RESTAURANT_ID}', 'Address Smoke Restaurant', '${CNPJ}',       '${CITY}', true, now(), -23.5505, -46.6333),
  -- Loja sem coordenada: existe de verdade no cadastro (lat/lng é nullable
  -- desde a 010) e a tela precisa saber o que fazer com ela.
  ('${NOGEO_ID}',      'Loja Sem Coordenada',      '${NOGEO_CNPJ}', '${CITY}', true, now(), NULL, NULL);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order) VALUES
  ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0),
  ('${NOGEO_ID}',      ARRAY['mp_card','cash']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available) VALUES
  ('${RESTAURANT_ID}', 'Prato Address Smoke', 30.00, 'Pratos', true),
  ('${NOGEO_ID}',      'Prato Sem Coordenada', 30.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")
NOGEO_ITEM=$(query "SELECT id FROM menu_items WHERE restaurant_id='${NOGEO_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-address-server.log 2>&1 &
SERVER_PID=$!
# Na saída, com ou sem falha: exceções de política do teste encerradas, pra
# não vazar pros outros testes da mesma cidade.
cleanup() {
  kill "$SERVER_PID" 2>/dev/null || true
  psql "$DATABASE_URL" -qc "UPDATE policy_overrides SET expires_at = now()
    WHERE reason LIKE 'smoke: %' AND scope_id IN ('${CITY}','${RESTAURANT_ID}')
      AND (expires_at IS NULL OR expires_at > now())" >/dev/null 2>&1 || true
}
trap cleanup EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Endereço\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")

echo "== ponto de referência é salvo e volta na lista =="
NEAR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Perto","number":"10","complement":"apto 74","reference":"Portão cinza ao lado da padaria",
       "city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000",
       "lat":-23.5600,"lng":-46.6333,"is_default":true}' | jq -er '.id') || fail "endereço perto não criou"
[ "$(query "SELECT reference FROM addresses WHERE id=${NEAR_ID}")" = "Portão cinza ao lado da padaria" ] \
  || fail "ponto de referência não foi gravado"
[ "$(curl -s "$BASE/addresses/list.php" "${AUTH[@]}" | jq -r ".addresses[] | select(.id == ${NEAR_ID}) | .reference")" \
   = "Portão cinza ao lado da padaria" ] || fail "referência não volta na listagem"

echo "== taxa aparece ANTES de salvar, calculada no servidor =="
# ~1,06 km da loja: 4,00 + 1,50 x 1,1 = 5,65 (a distância é medida, não dada)
QUOTE=$(curl -s "$BASE/addresses/quote.php?lat=-23.5600&lng=-46.6333&restaurant_id=${RESTAURANT_ID}" "${AUTH[@]}")
[ "$(echo "$QUOTE" | jq -r '.quote.in_area')" = "true" ] || fail "endereço perto deveria estar na área: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.distance_km > 0.9 and .quote.distance_km < 1.3')" = "true" ] \
  || fail "distância medida fora do esperado: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.quote.fee > 5 and .quote.fee < 6')" = "true" ] || fail "taxa não bate com a tarifa: $QUOTE"
[ "$(echo "$QUOTE" | jq -r '.tariff.max_km')" = "5" ] || fail "raio da política não veio: $QUOTE"
# Tempo até chegar = preparo da loja + viagem: com ~1 km, mais que o preparo
# sozinho e bem menos de uma hora.
[ "$(echo "$QUOTE" | jq -r '.eta_minutes > 0 and .eta_minutes < 60')" = "true" ] || fail "estimativa de chegada fora do esperado: $QUOTE"

echo "== endereço fora do raio é recusado, com o motivo escrito =="
FAR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Longe","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000",
       "lat":-23.6500,"lng":-46.6333}' | jq -er '.id') || fail "endereço longe não criou"
FARQ=$(curl -s "$BASE/addresses/quote.php?address_id=${FAR_ID}&restaurant_id=${RESTAURANT_ID}" "${AUTH[@]}")
[ "$(echo "$FARQ" | jq -r '.quote.in_area')" = "false" ] || fail "11 km deveria estar fora do raio de 5: $FARQ"
[ "$(echo "$FARQ" | jq -r '.quote.fee')" = "null" ] || fail "fora da área não tem taxa: $FARQ"
[ "$(echo "$FARQ" | jq -r '.quote.reason')" != "null" ] || fail "recusa sem motivo escrito: $FARQ"

echo "== sem loja escolhida, a pergunta é se a praça atende =="
CITYQ=$(curl -s "$BASE/addresses/quote.php?lat=-23.5600&lng=-46.6333&city_ibge_code=${CITY}" "${AUTH[@]}")
[ "$(echo "$CITYQ" | jq -r '.scope')" = "city" ] || fail "escopo errado: $CITYQ"
[ "$(echo "$CITYQ" | jq -r '.in_area')" = "true" ] || fail "praça deveria cobrir esse ponto: $CITYQ"
[ "$(echo "$CITYQ" | jq -r '.stores_covering > 0')" = "true" ] || fail "nenhuma loja cobrindo: $CITYQ"
[ "$(echo "$CITYQ" | jq -r '.nearest.distance_km > 0')" = "true" ] || fail "loja mais perto não veio: $CITYQ"
# Loja sem coordenada não conta como cobertura nem como fora: não dá pra medir.
[ "$(echo "$CITYQ" | jq -r '.stores_in_city > .stores_covering')" = "true" ] \
  || fail "loja sem coordenada foi contada como cobertura: $CITYQ"

echo "== o checkout calcula o frete e ignora o que o cliente mandar =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${NEAR_ID},\"payment_method\":\"cash\",\"change_for\":100,\"delivery_fee\":0}")
ORDER_ID=$(echo "$ORDER" | jq -er '.order.id') || fail "checkout falhou: $ORDER"
FEE=$(echo "$ORDER" | jq -r '.order.delivery_fee')
[ "$FEE" != "0.00" ] || fail "cliente conseguiu forjar frete grátis: $ORDER"
[ "$(echo "$ORDER" | jq -r '.order.total > 35')" = "true" ] || fail "total sem o frete calculado: $ORDER"
# A mesma conta da cotação, refeita pelo checkout.
[ "$FEE" = "$(echo "$QUOTE" | jq -r '.quote.fee | tostring + (if (. * 100 % 100) == 0 then ".00" else "" end)' 2>/dev/null || echo "$FEE")" ] \
  || [ "$(echo "$ORDER" | jq -r '.order.delivery_fee | tonumber > 5 and tonumber < 6')" = "true" ] \
  || fail "frete do pedido diferente da cotação: $ORDER / $QUOTE"

echo "== checkout em endereço fora do raio é barrado =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
OUT=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${FAR_ID},\"payment_method\":\"cash\",\"change_for\":100}")
[ "$(echo "$OUT" | jq -r '.code')" = "out_of_delivery_area" ] || fail "pedido fora do raio passou: $OUT"

echo "== loja sem coordenada cobra só a base, e diz por quê =="
NOGEOQ=$(curl -s "$BASE/addresses/quote.php?address_id=${NEAR_ID}&restaurant_id=${NOGEO_ID}" "${AUTH[@]}")
[ "$(echo "$NOGEOQ" | jq -r '.quote.distance_km')" = "null" ] || fail "mediu distância sem coordenada: $NOGEOQ"
[ "$(echo "$NOGEOQ" | jq -r '.quote.fee')" = "4" ] || fail "sem distância, cobra só a base: $NOGEOQ"
[ "$(echo "$NOGEOQ" | jq -r '.quote.in_area')" = "true" ] || fail "sem medir, não dá pra dizer que está fora: $NOGEOQ"
[ "$(echo "$NOGEOQ" | jq -r '.quote.reason')" != "null" ] || fail "sem motivo escrito: $NOGEOQ"
[ "$(echo "$NOGEOQ" | jq -r '.eta_minutes')" = "null" ] || fail "sem distância não há estimativa, e inventou uma: $NOGEOQ"

echo "== endereço de outra pessoa não é cotável =="
PHONE2="119$(( RANDOM % 90000000 + 10000000 ))"
CODE2=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Intruso Endereço\"}" | jq -er '.dev_code')
ACCESS2=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"code\":\"$CODE2\"}" | jq -er '.access_token')
[ "$(curl -s "$BASE/addresses/quote.php?address_id=${NEAR_ID}&restaurant_id=${RESTAURANT_ID}" \
   -H "Authorization: Bearer $ACCESS2" | jq -r '.code')" = "address_not_found" ] \
  || fail "outro cliente cotou endereço alheio"

echo "== a tarifa é política versionada: muda no admin e vale no próximo pedido =="
ADMIN_CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code') || fail "otp do admin falhou"
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$ADMIN_CODE\"}" | jq -er '.access_token') \
  || fail "login do admin falhou"
NEWPOL=$(curl -s -X POST "$BASE/admin/policy.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"delivery_base_fee":9.90,"delivery_per_km":0,"delivery_max_km":null}')
[ "$(echo "$NEWPOL" | jq -r '.policy.delivery_base_fee')" = "9.90" ] || fail "admin não publicou a tarifa: $NEWPOL"
[ "$(echo "$NEWPOL" | jq -r '.policy.delivery_max_km')" = "null" ] || fail "raio em branco deveria virar sem limite: $NEWPOL"

# Agora o endereço longe entra (sem raio) e paga 9,90 fixo.
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
ORDER2=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${FAR_ID},\"payment_method\":\"cash\",\"change_for\":100}")
[ "$(echo "$ORDER2" | jq -r '.order.delivery_fee')" = "9.90" ] || fail "tarifa nova não valeu: $ORDER2"

# ...e o pedido ANTERIOR continua com a tarifa do dia dele, congelada.
[ "$(query "SELECT delivery_fee FROM orders WHERE id=${ORDER_ID}")" = "$FEE" ] \
  || fail "mudar a política reescreveu o frete de um pedido antigo"
[ "$(query "SELECT (policy_snapshot->>'delivery_base_fee')::numeric = 4.00 FROM orders WHERE id=${ORDER_ID}")" = "t" ] \
  || fail "o snapshot do pedido antigo não guardou a tarifa da época"

echo "== tarifa negativa e raio zero são recusados =="
[ "$(curl -s -X POST "$BASE/admin/policy.php" -H "Content-Type: application/json" \
   -H "Authorization: Bearer $ADMIN_TOKEN" -d '{"delivery_base_fee":-1}' | jq -r '.code')" = "invalid_amount" ] \
  || fail "aceitou tarifa negativa"
[ "$(curl -s -X POST "$BASE/admin/policy.php" -H "Content-Type: application/json" \
   -H "Authorization: Bearer $ADMIN_TOKEN" -d '{"delivery_max_km":0}' | jq -r '.code')" = "invalid_max_km" ] \
  || fail "aceitou raio zero (que seria 'não entregamos em lugar nenhum')"

echo "== exceções pela aba Políticas: praça vale pra loja da cidade; a da loja ganha; a mais nova ganha =="
# Exceção de praça só em cidade cadastrada. Desligada: não aparece no app
# nem mexe na lista pública que outros testes conferem.
psql_run -c "INSERT INTO service_cities (ibge_code, name, uf, lat, lng, active)
             VALUES ('${CITY}', 'São Paulo', 'SP', -23.55, -46.63, false) ON CONFLICT (ibge_code) DO NOTHING"
OV() { curl -s -w ' %{http_code}' -X POST "$BASE/admin/policy_overrides.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -d "$1"; }
BASEFEE() { curl -s "$BASE/addresses/quote.php?lat=-23.5600&lng=-46.6333&restaurant_id=$1" "${AUTH[@]}" | jq -r '.tariff.base'; }
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/policy_overrides.php" -H "Content-Type: application/json" \
  "${AUTH[@]}" -d '{}')" = "403" ] || fail "cliente mexeu em exceção de política"
R=$(OV '{"scope":"city","scope_id":"'"$CITY"'","patch":{"commission_bps":5000},"reason":"comissão alta demais"}')
[ "$(echo "${R% *}" | jq -r '.fields["patch.commission_bps"]')" = "de 0 a 3000" ] || fail "comissão acima de 30% aceita: $R"
R=$(OV '{"scope":"city","scope_id":"'"$CITY"'","patch":{"cash_ceiling":1},"reason":"campo que não pode"}')
[ "${R##* }" = "422" ] || fail "exceção de campo fora da lista aceita: $R"
R=$(OV '{"scope":"restaurant","scope_id":"'"$RESTAURANT_ID"'","patch":{"delivery_base_fee":6},"reason":"x"}')
[ "$(echo "${R% *}" | jq -r '.fields.reason')" != "null" ] || fail "exceção sem motivo aceita: $R"
R=$(OV '{"scope":"city","scope_id":"'"$CITY"'","patch":{"delivery_base_fee":9},"reason":"smoke: praça"}');       [ "${R##* }" = "201" ] || fail "criar exceção da praça: $R"; OV_CITY=$(echo "${R% *}" | jq -r '.id')
R=$(OV '{"scope":"restaurant","scope_id":"'"$RESTAURANT_ID"'","patch":{"delivery_base_fee":8},"reason":"smoke: loja, antiga"}'); [ "${R##* }" = "201" ] || fail "criar exceção da loja: $R"
R=$(OV '{"scope":"restaurant","scope_id":"'"$RESTAURANT_ID"'","patch":{"delivery_base_fee":6,"delivery_per_km":""},"reason":"smoke: loja, nova"}'); [ "${R##* }" = "201" ] || fail "criar exceção da loja: $R"
[ "$(echo "${R% *}" | jq -c '.patch')" = '{"delivery_base_fee":6}' ] || fail "campo em branco entrou na exceção: $R"
[ "$(query "SELECT count(*) FROM audit_log WHERE action='policy_override.created' AND actor_id='${ADMIN_USER_ID}'")" = "3" ] || fail "exceção sem audit_log"
R=$(BASEFEE "$RESTAURANT_ID"); [ "$R" = "6" ] || fail "a exceção mais nova da loja devia valer (6), veio $R"
R=$(BASEFEE "$NOGEO_ID");      [ "$R" = "9" ] || fail "a exceção da praça não chegou na loja da cidade (9), veio $R"
R=$(curl -s "$BASE/addresses/quote.php?lat=-23.5600&lng=-46.6333&city_ibge_code=${CITY}" "${AUTH[@]}" | jq -r '.tariff.base')
[ "$R" = "9" ] || fail "\"esse endereço é atendido?\" mostrou a tarifa sem a exceção da cidade (9): $R"
LIST=$(curl -s "$BASE/admin/policy_overrides.php" -H "Authorization: Bearer $ADMIN_TOKEN")
[ "$(echo "$LIST" | jq --arg c "$CITY" --arg r "$RESTAURANT_ID" '[.overrides[] | select(.live and (.scope_id == $c or .scope_id == $r))] | length')" = "3" ] \
  || fail "lista de exceções: $(echo "$LIST" | jq -c '.overrides')"
echo "== encerrar a da praça: a loja da cidade volta à política da plataforma =="
R=$(OV '{"id":'"$OV_CITY"',"action":"end"}'); [ "${R##* }" = "200" ] || fail "encerrar exceção: $R"
R=$(BASEFEE "$NOGEO_ID"); [ "$R" != "9" ] || fail "exceção encerrada continuou valendo"
R=$(OV '{"id":'"$OV_CITY"',"action":"end"}'); [ "${R##* }" = "404" ] || fail "encerrou duas vezes: $R"

echo "OK: endereço, área e frete no servidor (Fase 14.3) passou no smoke test"
