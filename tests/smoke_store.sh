#!/usr/bin/env bash
# Smoke test da loja operando a si mesma (Fase 11.2 a 11.4): pausar com
# motivo e volta automática, tempo de preparo informado ao cliente, cardápio
# (esgotar num toque, publicar item com variações) e horário/feriado com o
# job que abre e fecha a loja sozinho.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8108
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-store-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
OTHER_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
OTHER_CNPJ="$(gen_cnpj)"
STAMP="$(date +%s%N)"

echo "== semear duas lojas (a segunda existe só pra provar o isolamento) =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
VALUES ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Store Smoke', 'staff-store-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'staff-store-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, is_open, approved_at, lat, lng) VALUES
  ('${RESTAURANT_ID}', 'Store Smoke Restaurant', '${CNPJ}',       '${CITY}', 'Pizza', true, now(), -23.5505, -46.6333),
  ('${OTHER_ID}',      'Loja Vizinha Smoke',     '${OTHER_CNPJ}', '${CITY}', 'Pizza', true, now(), -23.5505, -46.6333);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available) VALUES
  ('${RESTAURANT_ID}', 'Pizza Store Smoke', 42.00, 'Pizzas', true),
  ('${OTHER_ID}',      'Pizza da Vizinha',  40.00, 'Pizzas', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")
OTHER_ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${OTHER_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-store-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
AUTH=(-H "Authorization: Bearer $TOKEN")

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Store\"}" | jq -er '.dev_code')
CUSTOMER=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
CAUTH=(-H "Authorization: Bearer $CUSTOMER")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d '{"street":"Rua Store","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}' \
  | jq -er '.id')

echo "== 11.2: pausa curta esconde a loja do app e barra o checkout =="
STATUS=$(curl -s "$BASE/restaurants/pause_status.php" "${AUTH[@]}")
[ "$(echo "$STATUS" | jq -r '.pause')" = "null" ] || fail "loja nasceu pausada: $STATUS"
[ "$(echo "$STATUS" | jq -r '.reasons | length')" = "4" ] || fail "motivos fechados sumiram: $STATUS"
[ "$(echo "$STATUS" | jq -r '.store.prep_minutes')" = "30" ] || fail "tempo de preparo padrão errado: $STATUS"

NO_REASON=$(curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"action":"pause","minutes":30}')
[ "$(echo "$NO_REASON" | jq -r '.code')" = "reason_required" ] || fail "pausou sem motivo: $NO_REASON"

BAD_MIN=$(curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"action":"pause","reason":"busy_kitchen","minutes":45}')
[ "$(echo "$BAD_MIN" | jq -r '.code')" = "invalid_minutes" ] || fail "aceitou pausa de 45 min: $BAD_MIN"

PAUSE=$(curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"action":"pause","reason":"busy_kitchen","minutes":30}')
[ "$(echo "$PAUSE" | jq -r '.paused')" = "true" ] || fail "não pausou: $PAUSE"
# is_open continua true: a loja não fechou, está pausada -- quem barra é o carimbo.
[ "$(echo "$PAUSE" | jq -r '.store.is_open')" = "true" ] || fail "pausa curta fechou a loja: $PAUSE"
[ "$(query "SELECT count(*) FROM store_pauses WHERE restaurant_id='${RESTAURANT_ID}' AND ended_at IS NULL")" = "1" ] \
  || fail "pausa não foi registrada no histórico"

LIST=$(curl -s "$BASE/restaurants/list.php?city_ibge_code=${CITY}&open_only=1")
[ "$(echo "$LIST" | jq -r "[.restaurants[] | select(.id == \"${RESTAURANT_ID}\")] | length")" = "0" ] \
  || fail "loja pausada continuou na lista de abertos: $LIST"

curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
BLOCKED=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"change_for\":100}")
[ "$(echo "$BLOCKED" | jq -r '.code')" = "store_paused" ] || fail "checkout passou com a loja pausada: $BLOCKED"

echo "== voltar devolve a loja ao horário, e o histórico fecha =="
RESUME=$(curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"action":"resume"}')
[ "$(echo "$RESUME" | jq -r '.paused')" = "false" ] || fail "não voltou: $RESUME"
[ "$(query "SELECT count(*) FROM store_pauses WHERE restaurant_id='${RESTAURANT_ID}' AND ended_at IS NULL")" = "0" ] \
  || fail "pausa continuou aberta depois do resume"
[ "$(curl -s "$BASE/restaurants/pause_status.php" "${AUTH[@]}" | jq -r '.paused_seconds_today >= 0')" = "true" ] \
  || fail "tempo pausado do dia não foi contado"

echo "== fechar por hoje fecha de verdade e não reabre no minuto seguinte =="
CLOSE=$(curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"action":"close_today","reason":"technical"}')
[ "$(echo "$CLOSE" | jq -r '.store.is_open')" = "false" ] || fail "fechar por hoje não fechou: $CLOSE"
[ "$(echo "$CLOSE" | jq -r '.pause.until')" = "null" ] || fail "fechar por hoje não pode ter relógio de volta: $CLOSE"
psql_run -c "SELECT apply_business_hours();" >/dev/null
[ "$(query "SELECT is_open FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "f" ] \
  || fail "o job de horário reabriu uma loja fechada por hoje"
curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"action":"resume"}' >/dev/null

echo "== tempo de preparo vira a previsão que o cliente lê =="
BAD_PREP=$(curl -s -X POST "$BASE/restaurants/prep_time.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"prep_minutes":600}')
[ "$(echo "$BAD_PREP" | jq -r '.code')" = "invalid_prep_minutes" ] || fail "aceitou 600 min de preparo: $BAD_PREP"
curl -s -X POST "$BASE/restaurants/prep_time.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"prep_minutes":45,"prep_auto_bump":true}' >/dev/null
SHOW=$(curl -s "$BASE/restaurants/show.php?id=${RESTAURANT_ID}")
[ "$(echo "$SHOW" | jq -r '.prep.effective')" = "45" ] || fail "cliente não vê o tempo informado: $SHOW"
[ "$(echo "$SHOW" | jq -r '.prep.bumped')" = "false" ] || fail "fila vazia não pode aumentar o preparo: $SHOW"

echo "== 11.3: esgotar é um toque, e só no cardápio da própria loja =="
SOLD_OUT=$(curl -s -X POST "$BASE/restaurants/menu_availability.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"menu_item_id\":${ITEM_ID},\"available\":false}")
[ "$(echo "$SOLD_OUT" | jq -r '.item.available')" = "false" ] || fail "não esgotou: $SOLD_OUT"
[ "$(echo "$SOLD_OUT" | jq -r '.item.sold_out_at')" != "null" ] || fail "esgotado sem carimbo de quando: $SOLD_OUT"
BACK=$(curl -s -X POST "$BASE/restaurants/menu_availability.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"menu_item_id\":${ITEM_ID},\"available\":true}")
[ "$(echo "$BACK" | jq -r '.item.sold_out_at')" = "null" ] || fail "voltar ao cardápio não limpou o carimbo: $BACK"

INTRUDER=$(curl -s -X POST "$BASE/restaurants/menu_availability.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"menu_item_id\":${OTHER_ITEM_ID},\"available\":false}")
[ "$(echo "$INTRUDER" | jq -r '.code')" = "item_not_found" ] || fail "esgotou item da loja vizinha: $INTRUDER"
[ "$(query "SELECT available FROM menu_items WHERE id=${OTHER_ITEM_ID}")" = "t" ] || fail "item da vizinha mudou"

echo "== publicar item com variações, e o preço continuar sendo do servidor =="
PUBLISHED=$(curl -s -X POST "$BASE/restaurants/menu_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"name":"Pizza Publicada","description":"Molho, muçarela e manjericão","category":"Pizzas","price":42.90,
       "variants":[{"group_name":"Tamanho","name":"Média","price_delta":0,"required":true,"max_selections":1},
                   {"group_name":"Tamanho","name":"Grande","price_delta":10.00,"required":true,"max_selections":1}]}')
NEW_ITEM=$(echo "$PUBLISHED" | jq -er '.item.id') || fail "não publicou: $PUBLISHED"
[ "$(echo "$PUBLISHED" | jq -r '.variants | length')" = "2" ] || fail "variações não foram gravadas: $PUBLISHED"
[ "$(echo "$PUBLISHED" | jq -r '.edge_purged')" = "false" ] || fail "não podemos dizer que limpamos cache que não limpamos"

NO_NAME=$(curl -s -X POST "$BASE/restaurants/menu_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"name":"","price":10}')
[ "$(echo "$NO_NAME" | jq -r '.code')" = "invalid_item" ] || fail "publicou item sem nome: $NO_NAME"

BAD_VARIANT=$(curl -s -X POST "$BASE/restaurants/menu_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"name":"Item Ruim","price":10,"variants":[{"group_name":"","name":""}]}')
[ "$(echo "$BAD_VARIANT" | jq -r '.code')" = "invalid_variant" ] || fail "aceitou variação sem nome: $BAD_VARIANT"

# Republicar substitui a lista inteira de variações, não empilha.
curl -s -X POST "$BASE/restaurants/menu_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"id\":${NEW_ITEM},\"name\":\"Pizza Publicada\",\"price\":44.90,\"category\":\"Pizzas\",
       \"variants\":[{\"group_name\":\"Tamanho\",\"name\":\"Única\",\"price_delta\":0}]}" >/dev/null
[ "$(query "SELECT count(*) FROM item_variants WHERE menu_item_id=${NEW_ITEM}")" = "1" ] \
  || fail "republicar empilhou variações em vez de substituir"

ADMIN_MENU=$(curl -s "$BASE/restaurants/menu_admin.php" "${AUTH[@]}")
[ "$(echo "$ADMIN_MENU" | jq -r "[.items[] | select(.id == ${OTHER_ITEM_ID})] | length")" = "0" ] \
  || fail "cardápio da loja vizinha vazou pro painel: $ADMIN_MENU"
[ "$(echo "$ADMIN_MENU" | jq -r '.items | length')" = "2" ] || fail "cardápio da própria loja incompleto: $ADMIN_MENU"
[ "$(echo "$ADMIN_MENU" | jq -r '.categories[0].name')" = "Pizzas" ] || fail "categorias não saíram dos itens: $ADMIN_MENU"

echo "== 11.4: horário salvo pra vários dias de uma vez =="
SAVE=$(curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"days":[1,2,3,4,5],"shifts":[
        {"shift":"lunch","opens":"11:00","closes":"15:00","last_order":"14:30","active":true},
        {"shift":"dinner","opens":"18:00","closes":"23:30","last_order":"23:00","active":true}]}')
[ "$(echo "$SAVE" | jq -r '.hours | length')" = "10" ] || fail "seg a sex deveria gravar 10 linhas: $SAVE"

LATE=$(curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"days":[6],"shifts":[{"shift":"dinner","opens":"18:00","closes":"23:00","last_order":"23:30","active":true}]}')
[ "$(echo "$LATE" | jq -r '.code')" = "last_order_after_close" ] || fail "aceitou último pedido depois de fechar: $LATE"

# Turno que atravessa a meia-noite é legítimo, e o último pedido de madrugada também.
CROSS=$(curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"days":[6],"shifts":[{"shift":"dinner","opens":"18:00","closes":"01:00","last_order":"00:30","active":true}]}')
[ "$(echo "$CROSS" | jq -r '.code')" = "null" ] || fail "recusou turno que vira a madrugada: $CROSS"

echo "== o job abre e fecha a loja sozinho, pelo horário =="
# Horário que cobre o momento atual (no fuso de São Paulo) em todos os dias.
NOW_LOCAL=$(query "SELECT to_char(timezone('America/Sao_Paulo', now()), 'HH24:MI')")
OPENS=$(query "SELECT to_char(timezone('America/Sao_Paulo', now()) - interval '1 hour', 'HH24:MI')")
CLOSES=$(query "SELECT to_char(timezone('America/Sao_Paulo', now()) + interval '2 hours', 'HH24:MI')")
LAST=$(query "SELECT to_char(timezone('America/Sao_Paulo', now()) + interval '1 hour', 'HH24:MI')")
curl -s -X POST "$BASE/restaurants/hours_save.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"days\":[0,1,2,3,4,5,6],\"shifts\":[{\"shift\":\"dinner\",\"opens\":\"${OPENS}\",\"closes\":\"${CLOSES}\",\"last_order\":\"${LAST}\",\"active\":true}]}" >/dev/null
psql_run -c "SELECT apply_business_hours();" >/dev/null
[ "$(query "SELECT is_open FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "t" ] \
  || fail "dentro do horário (${OPENS}–${CLOSES}, agora ${NOW_LOCAL}) a loja deveria abrir sozinha"

# Feriado de hoje fecha a loja mesmo dentro do horário semanal.
TODAY=$(query "SELECT timezone('America/Sao_Paulo', now())::date")
HOLIDAY=$(curl -s -X POST "$BASE/restaurants/holiday.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"day\":\"${TODAY}\",\"closed\":true,\"note\":\"Feriado de teste\"}")
HOLIDAY_ID=$(echo "$HOLIDAY" | jq -er '.holiday.id') || fail "não cadastrou o feriado: $HOLIDAY"
[ "$(query "SELECT is_open FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "f" ] \
  || fail "feriado de hoje não fechou a loja"

# ...e removê-lo reabre, porque o horário volta a mandar.
curl -s -X POST "$BASE/restaurants/holiday.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"action\":\"remove\",\"id\":${HOLIDAY_ID}}" >/dev/null
[ "$(query "SELECT is_open FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "t" ] \
  || fail "remover o feriado não devolveu a loja ao horário"

HOURS=$(curl -s "$BASE/restaurants/hours.php" "${AUTH[@]}")
[ "$(echo "$HOURS" | jq -r '.histogram | length')" = "24" ] || fail "histograma precisa das 24 horas: $HOURS"
[ "$(echo "$HOURS" | jq -r '.holidays | length')" = "0" ] || fail "feriado removido continuou aparecendo: $HOURS"

echo "== nada disso é acessível a quem não é a loja =="
for route in pause_status.php menu_admin.php hours.php; do
  [ "$(curl -s "$BASE/restaurants/${route}" "${CAUTH[@]}" | jq -r '.code')" = "forbidden" ] \
    || fail "cliente acessou ${route}"
done
[ "$(curl -s -X POST "$BASE/restaurants/pause.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
   -d '{"action":"close_today","reason":"technical"}' | jq -r '.code')" = "forbidden" ] \
  || fail "cliente conseguiu fechar a loja"
[ "$(query "SELECT is_open FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "t" ] || fail "a loja fechou por ação de cliente"

echo "== 11.1: foto do item -- recodificada, sem EXIF, só da própria loja, servida pública =="
PHOTO="/tmp/store-photo-${STAMP}.png"
php -r '$i=imagecreatetruecolor(2000,1200);imagefill($i,0,0,imagecolorallocate($i,200,40,30));imagepng($i,$argv[1]);' "$PHOTO"
UP=$(curl -s -X POST "$BASE/restaurants/menu_photo.php" "${AUTH[@]}" -F "menu_item_id=${ITEM_ID}" -F "photo=@${PHOTO};type=image/png")
KEY=$(echo "$UP" | jq -er '.photo_key') || fail "foto não subiu: $UP"
[ "$(query "SELECT photo_key FROM menu_items WHERE id=${ITEM_ID}")" = "$KEY" ] || fail "photo_key não gravado"
curl -s -D /tmp/store-photo-h.txt "$BASE/restaurants/menu_photo.php?key=${KEY}" -o /tmp/store-photo-out.jpg
grep -qi 'content-type: image/jpeg' /tmp/store-photo-h.txt || fail "foto não veio como JPEG"
grep -qi 'immutable' /tmp/store-photo-h.txt || fail "foto sem cache longo"
[ "$(php -r '[$w]=getimagesize($argv[1]);echo $w;' /tmp/store-photo-out.jpg)" = "900" ] || fail "foto não foi reduzida pra 900 px"
[ "$(curl -s "$BASE/restaurants/menu.php?id=${RESTAURANT_ID}" | jq -r "[.. | objects | select(.id? == ${ITEM_ID}) | .photo_key][0]")" = "$KEY" ] \
  || fail "o cardápio público não traz a foto"
[ "$(curl -s -X POST "$BASE/restaurants/menu_photo.php" "${AUTH[@]}" -F "menu_item_id=${OTHER_ITEM_ID}" -F "photo=@${PHOTO};type=image/png" | jq -r '.code')" = "menu_item_not_found" ] \
  || fail "loja pôs foto em item de outra loja"
echo "não sou imagem" >/tmp/store-fake-${STAMP}.png
[ "$(curl -s -X POST "$BASE/restaurants/menu_photo.php" "${AUTH[@]}" -F "menu_item_id=${ITEM_ID}" -F "photo=@/tmp/store-fake-${STAMP}.png;type=image/png" | jq -r '.code')" = "invalid_file_type" ] \
  || fail "arquivo que finge ser imagem passou"
[ "$(curl -s "$BASE/restaurants/menu_photo.php?key=../../.env" | jq -r '.code')" = "invalid_key" ] || fail "caminho arbitrário aceito na leitura"

echo "OK: loja operando a si mesma (Fase 11.2 a 11.4) passou no smoke test"
