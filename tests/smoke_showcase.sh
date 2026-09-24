#!/usr/bin/env bash
# Smoke test da vitrine da Home (migração 037, decisão 39): logo e categoria
# da loja, banners, lojas favoritas e "Queridinhos".
#
#  - logo: só a equipe sobe, da própria loja; arquivo que não é imagem volta
#    422; sai recortado em quadrado de 400 px; a leitura é pública e recusa
#    caminho arbitrário;
#  - categoria: a loja troca pra uma da lista (vai pro audit_log); texto livre
#    volta 422; a Home recebe só as categorias com loja aprovada na cidade;
#  - banners: só admin cria; loja do link tem de ser aprovada e da cidade; o
#    fim não pode vir antes do começo; a lista pública mostra só o que está no
#    ar, na cidade (ou em todas), na ordem; desligar tira; apagar some; tudo
#    auditado;
#  - favoritas: marcar e desmarcar é idempotente; loja não aprovada não entra;
#    sai no "baixar meus dados";
#  - queridinhos: contam só pedido ENTREGUE, só de loja aprovada e aberta, no
#    máximo 2 itens por loja, do mais pedido pro menos.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8145
BASE="http://127.0.0.1:${PORT}/api/v1"
TMP="$(mktemp -d)"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-showcase-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
q() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
# PNG sólido LARGURA x ALTURA no arquivo dado.
png() { php -r '$i=imagecreatetruecolor((int)$argv[2],(int)$argv[3]);imagefill($i,0,0,imagecolorallocate($i,200,40,30));imagepng($i,$argv[1]);' "$1" "$2" "$3"; }

STAMP="$(date +%s%N)"
# Códigos com o prefixo da UF certo e que nenhum município usa (x98xxx).
CITY="3598$(( RANDOM % 900 + 100 ))"
OTHER_CITY="3198$(( RANDOM % 900 + 100 ))"
ADMIN_ID="$(gen_uuid)"; ADMIN_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CUST_ID="$(gen_uuid)"; CUST_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
STAFF_ID="$(gen_uuid)"
A="$(gen_uuid)"; B="$(gen_uuid)"; C="$(gen_uuid)"; D="$(gen_uuid)"; E="$(gen_uuid)"
A_CNPJ="$(gen_cnpj)"

echo "== semear: cidade, lojas (aberta, aberta, fechada, em análise, de outra cidade) e vendas =="
psql_run <<SQL
INSERT INTO service_cities (ibge_code, name, uf, lat, lng, neighborhoods) VALUES
  ('${CITY}', 'Vitrine SP', 'SP', -22.9, -47.06, ARRAY['Centro']),
  ('${OTHER_CITY}', 'Vitrine MG', 'MG', -19.9, -43.9, ARRAY['Centro']);
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${ADMIN_ID}', 'admin', 'Admin Vitrine', '${ADMIN_PHONE}', 'admin-vitrine-${STAMP}@test.com'),
  ('${CUST_ID}', 'customer', 'Cliente Vitrine', '${CUST_PHONE}', 'cliente-vitrine-${STAMP}@test.com'),
  ('${STAFF_ID}', 'restaurant_staff', 'Balcão Vitrine', NULL, 'balcao-vitrine-${STAMP}@test.com');
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, is_open, approved_at) VALUES
  ('${A}', 'Pizzaria Vitrine',   '${A_CNPJ}',      '${CITY}',       'Pizza',            true,  now()),
  ('${B}', 'Burger Vitrine',     '$(gen_cnpj)',    '${CITY}',       'Hamburgueria',     true,  now()),
  ('${C}', 'Doceria Fechada',    '$(gen_cnpj)',    '${CITY}',       'Doces',            false, now()),
  ('${D}', 'Moda em Análise',    '$(gen_cnpj)',    '${CITY}',       'Moda e presentes', true,  NULL),
  ('${E}', 'Açaí de Outra Cidade','$(gen_cnpj)',   '${OTHER_CITY}', 'Açaí e sorvetes',  true,  now());
INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_ID}', 'restaurant', '${A}', '${A_CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');
INSERT INTO menu_items (restaurant_id, name, price, available) VALUES
  ('${A}', 'Pizza Campeã', 50, true), ('${A}', 'Pizza Vice', 45, true), ('${A}', 'Pizza Terceira', 40, true),
  ('${B}', 'Burger Duplo', 38, true), ('${C}', 'Bolo da Fechada', 30, true);
SQL
item() { q "SELECT id FROM menu_items WHERE name = '$1' AND restaurant_id IN ('${A}','${B}','${C}')"; }
sell() {  # sell STATUS RESTAURANTE ITEM QTD
  psql_run -c "WITH o AS (INSERT INTO orders (user_id, restaurant_id, status, subtotal)
                          VALUES ('${CUST_ID}', '$2', '$1', 10) RETURNING id)
               INSERT INTO order_items (order_id, menu_item_id, name_snapshot, unit_price, quantity)
               SELECT o.id, $3, 'x', 10, $4 FROM o"
}
sell delivered "$A" "$(item 'Pizza Campeã')" 9
sell delivered "$A" "$(item 'Pizza Vice')" 7
sell delivered "$A" "$(item 'Pizza Terceira')" 6
sell delivered "$B" "$(item 'Burger Duplo')" 5
sell delivered "$C" "$(item 'Bolo da Fechada')" 20
sell cancelled "$B" "$(item 'Burger Duplo')" 50

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-showcase-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$TMP"' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null "$BASE/system/health.php" && break; sleep 0.2; done

login() {  # login PHONE -> token
  local code
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"login\",\"phone\":\"$1\"}" | jq -er '.dev_code')
  curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"login\",\"phone\":\"$1\",\"code\":\"$code\"}" | jq -er '.access_token'
}
ADMIN=(-H "Authorization: Bearer $(login "$ADMIN_PHONE")")
CUST=(-H "Authorization: Bearer $(login "$CUST_PHONE")")
STAFF=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${A_CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')")

echo "== logo: equipe sobe, sai quadrado de 400 px, leitura pública e só no formato da chave =="
png "$TMP/logo.png" 1000 600
echo "não sou imagem" > "$TMP/fake.png"
[ "$(curl -s -X POST "$BASE/restaurants/logo.php" "${CUST[@]}" -F "logo=@$TMP/logo.png;type=image/png" | jq -r '.code')" = "forbidden" ] \
  || fail "cliente subiu logo de loja"
[ "$(curl -s -X POST "$BASE/restaurants/logo.php" "${STAFF[@]}" -F "logo=@$TMP/fake.png;type=image/png" | jq -r '.code')" = "invalid_file_type" ] \
  || fail "texto aceito como logo"
LOGO=$(curl -s -X POST "$BASE/restaurants/logo.php" "${STAFF[@]}" -F "logo=@$TMP/logo.png;type=image/png" | jq -er '.logo_key') \
  || fail "logo não subiu"
[ "$(q "SELECT logo_key FROM restaurants WHERE id='${A}'")" = "$LOGO" ] || fail "logo não gravado na loja"
curl -s -o "$TMP/logo-out.jpg" "$BASE/restaurants/logo.php?key=${LOGO}"
[ "$(php -r '[$w,$h]=getimagesize($argv[1]);echo "$w x $h";' "$TMP/logo-out.jpg")" = "400 x 400" ] || fail "logo não saiu quadrado de 400 px"
[ "$(curl -s "$BASE/restaurants/logo.php?key=../../.env" | jq -r '.code')" = "invalid_key" ] || fail "caminho arbitrário aceito na leitura do logo"
[ "$(curl -s "$BASE/restaurants/show.php?id=${A}" | jq -r '.restaurant.logo_key // .logo_key')" = "$LOGO" ] || fail "loja não devolve o logo"

echo "== categoria: só da lista, auditada; a Home recebe só as que têm loja aprovada na cidade =="
[ "$(curl -s -X POST "$BASE/restaurants/store_profile.php" "${STAFF[@]}" -H "Content-Type: application/json" \
   -d '{"category":"Qualquer Coisa"}' | jq -r '.code')" = "invalid_category" ] || fail "categoria livre aceita"
curl -s -X POST "$BASE/restaurants/store_profile.php" "${STAFF[@]}" -H "Content-Type: application/json" \
  -d '{"category":"Marmitas"}' | jq -e '.category == "Marmitas"' >/dev/null || fail "troca de categoria falhou"
[ "$(q "SELECT after->>'category' FROM audit_log WHERE action='restaurant.category_changed' AND target='restaurants:${A}'")" = "Marmitas" ] \
  || fail "troca de categoria sem audit_log"
CATS=$(curl -s "$BASE/restaurants/list.php?city_ibge_code=${CITY}&open_only=0" | jq -c '.categories')
[ "$CATS" = '["Hamburgueria","Marmitas","Doces"]' ] || fail "categorias da Home erradas (sem a da loja em análise, na ordem da lista): $CATS"

echo "== banners: só admin; validação; lista pública só com o que está no ar =="
png "$TMP/banner.png" 1600 600
banner() {  # banner TITULO CIDADE LOJA INICIO FIM ORDEM  -> "corpo código"
  curl -s -w ' %{http_code}' -X POST "$BASE/admin/banners.php" "${ADMIN[@]}" -F "image=@$TMP/banner.png;type=image/png" \
    -F "title=$1" -F "city_ibge_code=$2" -F "restaurant_id=$3" -F "starts_on=$4" -F "ends_on=$5" -F "position=$6"
}
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/banners.php" "${CUST[@]}" -F "title=x")" = "403" ] \
  || fail "cliente criou banner"
TODAY=$(TZ=America/Sao_Paulo date +%F)
TOMORROW=$(TZ=America/Sao_Paulo date -d tomorrow +%F)
R=$(banner "Loja de fora" "$CITY" "$E" "" "" 0);   [ "$(echo "${R% *}" | jq -r '.fields.restaurant_id')" = "a loja é de outra cidade" ] || fail "link pra loja de outra cidade aceito: $R"
R=$(banner "Em análise" "$CITY" "$D" "" "" 0);     [ "$(echo "${R% *}" | jq -r '.fields.restaurant_id')" = "loja aprovada não encontrada" ] || fail "link pra loja não aprovada aceito: $R"
R=$(banner "Ao contrário" "$CITY" "" "$TOMORROW" "$TODAY" 0); [ "$(echo "${R% *}" | jq -r '.fields.ends_on')" != "null" ] || fail "fim antes do começo aceito: $R"
R=$(banner "Segundo" "$CITY" "" "" "" 2);            [ "${R##* }" = "201" ] || fail "criar banner: $R"; SECOND=$(echo "${R% *}" | jq -r '.id')
R=$(banner "Primeiro com loja" "$CITY" "$A" "$TODAY" "$TODAY" 1); [ "${R##* }" = "201" ] || fail "criar banner com loja: $R"; FIRST=$(echo "${R% *}" | jq -r '.id')
R=$(banner "Todas as cidades" "" "" "" "" 3);        [ "${R##* }" = "201" ] || fail "criar banner geral: $R"; ALL=$(echo "${R% *}" | jq -r '.id')
R=$(banner "Amanhã" "$CITY" "" "$TOMORROW" "" 0);  [ "${R##* }" = "201" ] || fail "criar banner agendado: $R"; LATER=$(echo "${R% *}" | jq -r '.id')
R=$(banner "Só em MG" "$OTHER_CITY" "" "" "" 0);   [ "${R##* }" = "201" ] || fail "criar banner de MG: $R"
LIVE=$(curl -s "$BASE/banners/list.php?city_ibge_code=${CITY}")
MINE=$(echo "$LIVE" | jq -c "[.banners[] | select(.id == ${FIRST} or .id == ${SECOND} or .id == ${ALL} or .id == ${LATER}) | .id]")
[ "$MINE" = "[${FIRST},${SECOND},${ALL}]" ] || fail "banners no ar errados ou fora de ordem (agendado não entra): $MINE"
[ "$(echo "$LIVE" | jq -r ".banners[] | select(.id == ${FIRST}) | .restaurant_id")" = "$A" ] || fail "banner sem o link da loja"
echo "$LIVE" | jq -e '.banners | all(.title != "Só em MG")' >/dev/null || fail "banner de outra cidade apareceu"
KEY=$(echo "$LIVE" | jq -r ".banners[] | select(.id == ${FIRST}) | .image_key")
[ "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$BASE/banners/image.php?key=${KEY}")" = "200 image/jpeg" ] || fail "imagem do banner não serve"
curl -s -X POST "$BASE/admin/banners.php" "${ADMIN[@]}" -H "Content-Type: application/json" -d "{\"id\":${SECOND},\"action\":\"toggle\"}" >/dev/null
curl -s "$BASE/banners/list.php?city_ibge_code=${CITY}" | jq -e ".banners | all(.id != ${SECOND})" >/dev/null || fail "banner desligado continua no ar"
curl -s -X POST "$BASE/admin/banners.php" "${ADMIN[@]}" -H "Content-Type: application/json" -d "{\"id\":${ALL},\"action\":\"delete\"}" >/dev/null
[ "$(q "SELECT count(*) FROM promo_banners WHERE id=${ALL}")" = "0" ] || fail "banner apagado continua no banco"
[ "$(q "SELECT count(*) FROM audit_log WHERE target IN ('promo_banners:${SECOND}','promo_banners:${ALL}')")" = "4" ] \
  || fail "criar/desligar/apagar banner sem audit_log"

echo "== favoritas: idempotente, só loja aprovada, no 'baixar meus dados' =="
fav() { curl -s -X POST "$BASE/profile/favorites.php" "${CUST[@]}" -H "Content-Type: application/json" -d "{\"restaurant_id\":\"$1\",\"favorite\":$2}"; }
fav "$A" true >/dev/null; fav "$A" true >/dev/null; fav "$B" true >/dev/null
[ "$(fav "$D" true | jq -r '.code')" = "restaurant_not_found" ] || fail "favoritou loja não aprovada"
[ "$(curl -s "$BASE/profile/favorites.php" "${CUST[@]}" | jq -r '.restaurant_ids | length')" = "2" ] || fail "favoritar duas vezes duplicou"
fav "$B" false >/dev/null; fav "$B" false >/dev/null
[ "$(curl -s "$BASE/profile/favorites.php" "${CUST[@]}" | jq -c '.restaurant_ids')" = "[\"${A}\"]" ] || fail "desmarcar favorita falhou"
curl -s "$BASE/profile/export.php" "${CUST[@]}" | jq -e '.favorite_restaurants[0].name == "Pizzaria Vitrine"' >/dev/null \
  || fail "favorita fora do 'baixar meus dados'"

echo "== queridinhos: só entregue, só loja aberta e aprovada, até 2 por loja, do mais pedido =="
POP=$(curl -s "$BASE/restaurants/popular_items.php?city_ibge_code=${CITY}" | jq -c '[.items[] | "\(.name):\(.sold)"]')
[ "$POP" = '["Pizza Campeã:9","Pizza Vice:7","Burger Duplo:5"]' ] \
  || fail "queridinhos errados (cancelado conta? loja fechada entra? mais de 2 por loja?): $POP"
[ "$(curl -s "$BASE/restaurants/popular_items.php?city_ibge_code=${OTHER_CITY}" | jq -r '.items | length')" = "0" ] \
  || fail "queridinhos sem venda deveriam vir vazios"

echo "OK: vitrine da Home (logo, categoria, banners, favoritas, queridinhos)"
