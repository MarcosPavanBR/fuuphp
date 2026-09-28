#!/usr/bin/env bash
# Smoke test da central de ajuda (Fase 14.1): o fluxo automático de cada um
# dos quatro atalhos, respondido do estado REAL do pedido, e o chamado com
# prazo por categoria que nasce só depois disso.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8109
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-help-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
STAMP="$(date +%s%N)"

echo "== semear loja e cardápio =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email)
VALUES ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Help Smoke', 'staff-help-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cancel_fee, delivery_base_fee, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], 10.00, 6.90, id
  FROM users WHERE email = 'staff-help-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, prep_minutes)
VALUES ('${RESTAURANT_ID}', 'Help Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), 40);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','pix_manual','cash']::payment_method[], 200.00, 0);

INSERT INTO restaurant_credentials (restaurant_id, pix_key)
VALUES ('${RESTAURANT_ID}', 'pagamentos@help-smoke.com.br');

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Help Smoke', 50.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-help-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Ajuda\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Ajuda","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}' \
  | jq -er '.id')

STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

paid_order() { # $1 método -> id do pedido pago
  local method="$1" extra=""
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  [ "$method" = "cash" ] && extra=',"change_for":100.00'
  local id
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"${method}\"${extra}}" \
    | jq -er '.order.id')
  local body='{"order_id":'"$id"'}'
  [ "$method" = "mp_card" ] && body='{"order_id":'"$id"',"card_token":"APRO-token-help"}'
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "$body" >/dev/null
  echo "$id"
}

echo "== ajuda sem pedido nenhum não inventa assunto =="
HOME0=$(curl -s "$BASE/support/home.php" "${AUTH[@]}")
[ "$(echo "$HOME0" | jq -r '.subject_order')" = "null" ] || fail "achou pedido onde não há: $HOME0"
[ "$(echo "$HOME0" | jq -r '.topics | length')" = "4" ] || fail "os quatro atalhos sumiram: $HOME0"
# A média de resposta é da plataforma inteira (é o que a frase promete a
# quem ainda não escreveu), então num banco compartilhado com outras suítes
# ela pode existir. O que não pode é vir negativa ou não-numérica.
[ "$(echo "$HOME0" | jq -r '.avg_reply_seconds == null or .avg_reply_seconds >= 0')" = "true" ] \
  || fail "média de resposta inválida: $HOME0"
ANSWER0=$(curl -s "$BASE/support/answer.php?topic=late" "${AUTH[@]}")
[ "$(echo "$ANSWER0" | jq -r '.answer.resolved')" = "false" ] || fail "resolveu sem ter pedido: $ANSWER0"

echo "== atalho inexistente é recusado =="
[ "$(curl -s "$BASE/support/answer.php?topic=descontos" "${AUTH[@]}" | jq -r '.code')" = "invalid_topic" ] \
  || fail "aceitou atalho que não existe"

echo "== 'está atrasado' responde o tempo de preparo REAL da loja =="
O_PREP=$(paid_order cash)
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_PREP},\"to\":\"preparing\"}" >/dev/null
LATE=$(curl -s "$BASE/support/answer.php?topic=late" "${AUTH[@]}")
echo "$LATE" | jq -r '.answer.body' | grep -q "40 min" || fail "não usou o preparo cadastrado da loja: $LATE"
[ "$(echo "$LATE" | jq -r '.sla_minutes')" = "15" ] || fail "SLA de atraso errado: $LATE"

echo "== pedido pronto sem entregador manda pra tela 15.1, e resolve =="
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"order_id\":${O_PREP},\"to\":\"ready\"}" >/dev/null
LATE2=$(curl -s "$BASE/support/answer.php?topic=late" "${AUTH[@]}")
[ "$(echo "$LATE2" | jq -r '.answer.resolved')" = "true" ] || fail "não reconheceu a falta de entregador: $LATE2"
[ "$(echo "$LATE2" | jq -r '.answer.go_to_order')" = "true" ] || fail "não mandou pro pedido: $LATE2"
echo "$LATE2" | jq -r '.answer.body' | grep -qi "retirar na loja" || fail "não ofereceu as saídas da 15.1: $LATE2"

echo "== 'paguei o Pix' olha o comprovante de verdade =="
O_PIX=$(paid_order pix_manual)
PIX1=$(curl -s "$BASE/support/answer.php?topic=pix_pending&order_id=${O_PIX}" "${AUTH[@]}")
echo "$PIX1" | jq -r '.answer.body' | grep -qi "comprovante" || fail "não pediu o comprovante: $PIX1"

PROOF_DIR="/tmp/smoke-help-proofs"; export PROOF_STORAGE_DIR="$PROOF_DIR"; rm -rf "$PROOF_DIR"
php -r '$im = imagecreatetruecolor(300, 500);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240));
        imagestring($im, 5, 20, 40, "Comprovante Pix", imagecolorallocate($im, 20, 20, 20));
        imagejpeg($im, "/tmp/help-proof.jpg", 90);'
curl -s -X POST "$BASE/payments/upload_proof.php" "${AUTH[@]}" \
  -F "order_id=${O_PIX}" -F "proof=@/tmp/help-proof.jpg;type=image/jpeg" >/dev/null
PIX2=$(curl -s "$BASE/support/answer.php?topic=pix_pending&order_id=${O_PIX}" "${AUTH[@]}")
[ "$(echo "$PIX2" | jq -r '.answer.resolved')" = "true" ] || fail "comprovante na fila não resolveu a dúvida: $PIX2"
echo "$PIX2" | jq -r '.answer.body' | grep -qi "esperando a loja" || fail "não disse que está na fila: $PIX2"

echo "== 'onde está meu estorno' sai da tabela de refunds =="
NOREFUND=$(curl -s "$BASE/support/answer.php?topic=refund" "${AUTH[@]}")
echo "$NOREFUND" | jq -r '.answer.title' | grep -qi "não há estorno" || fail "inventou estorno: $NOREFUND"

O_CANCEL=$(paid_order mp_card)
curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${O_CANCEL},\"to\":\"cancelled\",\"reason\":\"Pedi por engano\"}" >/dev/null
REFUND=$(curl -s "$BASE/support/answer.php?topic=refund&order_id=${O_CANCEL}" "${AUTH[@]}")
echo "$REFUND" | jq -r '.answer.title' | grep -q "56,90" || fail "não trouxe o valor real do estorno: $REFUND"
echo "$REFUND" | jq -r '.answer.body' | grep -qi "fatura" || fail "não disse a rota do cartão: $REFUND"
[ "$(echo "$REFUND" | jq -r '.sla_minutes')" = "1440" ] || fail "SLA de estorno não é de um dia: $REFUND"

echo "== abrir chamado exige mensagem e nasce com prazo =="
NOMSG=$(curl -s -X POST "$BASE/support/ticket.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"category":"wrong_item","message":"   "}')
[ "$(echo "$NOMSG" | jq -r '.code')" = "message_required" ] || fail "abriu chamado sem mensagem: $NOMSG"

TICKET=$(curl -s -X POST "$BASE/support/ticket.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"category\":\"wrong_item\",\"order_id\":${O_PIX},\"message\":\"Faltou a bebida\"}")
TICKET_CODE=$(echo "$TICKET" | jq -er '.ticket.code') || fail "não abriu chamado: $TICKET"
echo "$TICKET_CODE" | grep -qE '^T-[0-9]{4,5}$' || fail "código do chamado fora do padrão: $TICKET_CODE"
[ "$(echo "$TICKET" | jq -r '.ticket.state')" = "open" ] || fail "chamado não nasceu aberto: $TICKET"
[ "$(echo "$TICKET" | jq -r '.message_delivered')" = "true" ] || fail "mensagem não foi pra conversa do pedido: $TICKET"
# SLA de item errado: 30 min. Confere no banco que o prazo foi calculado.
SLA_MIN=$(query "SELECT round(EXTRACT(epoch FROM (sla_due_at - created_at)) / 60) FROM tickets WHERE code='${TICKET_CODE}'")
[ "$SLA_MIN" = "30" ] || fail "prazo do chamado deveria ser 30 min, veio ${SLA_MIN}"
[ "$(query "SELECT count(*) FROM order_messages WHERE order_id=${O_PIX} AND body LIKE '%${TICKET_CODE}%'")" = "1" ] \
  || fail "a mensagem do chamado não entrou na conversa do pedido"

echo "== apertar o mesmo atalho duas vezes não abre dois chamados =="
AGAIN=$(curl -s -X POST "$BASE/support/ticket.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"category\":\"wrong_item\",\"order_id\":${O_PIX},\"message\":\"Continua faltando\"}")
[ "$(echo "$AGAIN" | jq -r '.reopened')" = "true" ] || fail "abriu um segundo chamado igual: $AGAIN"
[ "$(echo "$AGAIN" | jq -r '.ticket.code')" = "$TICKET_CODE" ] || fail "não reaproveitou o chamado aberto: $AGAIN"
[ "$(query "SELECT count(*) FROM tickets WHERE user_id=(SELECT user_id FROM orders WHERE id=${O_PIX}) AND category='wrong_item'")" = "1" ] \
  || fail "tickets duplicados no banco"

echo "== a ajuda lista os chamados de quem perguntou =="
HOME=$(curl -s "$BASE/support/home.php" "${AUTH[@]}")
[ "$(echo "$HOME" | jq -r '.tickets | length')" = "1" ] || fail "chamado não apareceu na lista: $HOME"
[ "$(echo "$HOME" | jq -r '.tickets[0].order_code')" != "null" ] || fail "chamado sem o pedido ligado: $HOME"
[ "$(echo "$HOME" | jq -r '.subject_order.in_progress')" = "true" ] || fail "assunto deveria ser o pedido em andamento: $HOME"

echo "== chamado de pedido alheio é barrado =="
PHONE2="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE2=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Intruso Ajuda\"}" | jq -er '.dev_code')
ACCESS2=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"code\":\"$CODE2\"}" | jq -er '.access_token')
[ "$(curl -s "$BASE/support/answer.php?topic=late&order_id=${O_PIX}" -H "Authorization: Bearer $ACCESS2" | jq -r '.code')" = "order_not_found" ] \
  || fail "outro cliente leu o pedido alheio pela ajuda"
[ "$(curl -s -X POST "$BASE/support/ticket.php" -H "Content-Type: application/json" \
   -H "Authorization: Bearer $ACCESS2" -d "{\"category\":\"late\",\"order_id\":${O_PIX},\"message\":\"oi\"}" | jq -r '.code')" = "order_not_found" ] \
  || fail "outro cliente abriu chamado no pedido alheio"
[ "$(curl -s "$BASE/support/home.php" -H "Authorization: Bearer $ACCESS2" | jq -r '.tickets | length')" = "0" ] \
  || fail "chamado de outra pessoa vazou na lista"

echo "== a loja não entra na central de ajuda do cliente =="
[ "$(curl -s "$BASE/support/home.php" "${STAFF_AUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "a loja abriu a central de ajuda do cliente"

echo "OK: central de ajuda (Fase 14.1) passou no smoke test"
