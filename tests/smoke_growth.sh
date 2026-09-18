#!/usr/bin/env bash
# Smoke test da Fase 15.2 (entrada de entregador) e 15.3 (cupons e
# campanhas): a regra antifraude da chave Pix, a candidatura que só vai pra
# análise completa, a aprovação que CRIA o entregador e o login dele, e o
# cupom com teto que desativa sozinho e deixa no livro quem pagou.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake
export COURIER_DOC_DIR="/tmp/smoke-growth-docs"
rm -rf "$COURIER_DOC_DIR"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8112
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-growth-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
ADMIN_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
ADMIN_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"

echo "== semear admin e loja =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF_USER_ID}', 'restaurant_staff', 'Staff Growth', NULL, 'staff-growth-${STAMP}@test.com'),
  ('${ADMIN_USER_ID}', 'admin',            'Admin Growth', '${ADMIN_PHONE}', 'admin-growth-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, commission_bps, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], 800, id
  FROM users WHERE email = 'staff-growth-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at)
VALUES ('${RESTAURANT_ID}', 'Growth Smoke Restaurant', '${CNPJ}', '${CITY}', true, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Growth', 60.00, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-growth-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

login_otp() { # $1 telefone, $2 nome -> token
  local phone="$1" name="$2" code
  code=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"$phone\",\"full_name\":\"$name\"}" | jq -er '.dev_code')
  curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
    -d "{\"purpose\":\"signup\",\"phone\":\"$phone\",\"code\":\"$code\"}" | jq -er '.access_token'
}

ADMIN_CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$ADMIN_CODE\"}" | jq -er '.access_token')
AADMIN=(-H "Authorization: Bearer $ADMIN_TOKEN")

# ─────────────────────────── 15.2 ───────────────────────────
CPF="$(gen_cpf)"
OTHER_CPF="$(gen_cpf)"
CAND_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CAND_TOKEN=$(login_otp "$CAND_PHONE" "Jonas Candidato")
CAUTH=(-H "Authorization: Bearer $CAND_TOKEN")
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"cpf\":\"${CPF}\"}" >/dev/null

echo "== 15.2: chave Pix de outra pessoa é recusada na cara =="
BAD=$(curl -s -X POST "$BASE/couriers/apply.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"full_name\":\"Jonas Candidato\",\"cpf\":\"${CPF}\",\"phone\":\"${CAND_PHONE}\",\"vehicle\":\"moto\",\"plate\":\"QQP1B34\",\"pix_key\":\"${OTHER_CPF}\"}")
[ "$(echo "$BAD" | jq -r '.code')" = "pix_key_not_yours" ] || fail "aceitou chave Pix de outro CPF: $BAD"

echo "== moto sem placa não passa (é o que liga o CRLV à pessoa) =="
NOPLATE=$(curl -s -X POST "$BASE/couriers/apply.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"full_name\":\"Jonas\",\"cpf\":\"${CPF}\",\"phone\":\"${CAND_PHONE}\",\"vehicle\":\"moto\",\"pix_key\":\"${CPF}\"}")
[ "$(echo "$NOPLATE" | jq -r '.code')" = "invalid_application" ] || fail "moto sem placa passou: $NOPLATE"

echo "== candidatura com a própria chave é aceita e conferida =="
APP=$(curl -s -X POST "$BASE/couriers/apply.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"full_name\":\"Jonas Candidato\",\"cpf\":\"${CPF}\",\"phone\":\"${CAND_PHONE}\",\"vehicle\":\"moto\",\"plate\":\"QQP1B34\",\"pix_key\":\"${CPF}\"}")
APP_ID=$(echo "$APP" | jq -er '.application.id') || fail "não criou candidatura: $APP"
[ "$(echo "$APP" | jq -r '.pix_ownership_checked')" = "true" ] || fail "não conferiu a titularidade da chave: $APP"
[ "$(echo "$APP" | jq -r '.application.state')" = "draft" ] || fail "candidatura não nasceu rascunho: $APP"

echo "== sem documentos, não vai pra análise =="
EARLY=$(curl -s -X POST "$BASE/couriers/submit_application.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d '{"accept_contract":true}')
[ "$(echo "$EARLY" | jq -r '.code')" = "documents_missing" ] || fail "mandou candidatura vazia pra análise: $EARLY"

echo "== documentos: MIME real, sha256 e o mesmo arquivo barrado em outra candidatura =="
php -r '$im = imagecreatetruecolor(300, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 220, 220, 220));
        imagestring($im, 5, 20, 90, "DOC", imagecolorallocate($im, 10, 10, 10));
        imagejpeg($im, "/tmp/growth-doc.jpg", 90);'
for kind in cnh selfie crlv address_proof; do
  # O carimbo entra na imagem de propósito: o servidor recusa o MESMO arquivo
  # em duas candidaturas (é fraude de CNH reaproveitada), então cada execução
  # do teste precisa de bytes próprios.
  php -r "\$im = imagecreatetruecolor(300, 200);
          imagefill(\$im, 0, 0, imagecolorallocate(\$im, 200, 200, 200));
          imagestring(\$im, 5, 10, 90, '${kind} ${STAMP}', imagecolorallocate(\$im, 0, 0, 0));
          imagejpeg(\$im, '/tmp/growth-${kind}.jpg', 90);"
  RES=$(curl -s -X POST "$BASE/couriers/apply_document.php" "${CAUTH[@]}" \
    -F "kind=${kind}" -F "document=@/tmp/growth-${kind}.jpg;type=image/jpeg")
  [ "$(echo "$RES" | jq -r '.document.kind')" = "$kind" ] || fail "documento ${kind} não subiu: $RES"
done
NOTIMAGE=$(curl -s -X POST "$BASE/couriers/apply_document.php" "${CAUTH[@]}" \
  -F "kind=cnh" -F "document=@${ROOT}/README.md;type=image/jpeg")
[ "$(echo "$NOTIMAGE" | jq -r '.code')" = "invalid_file_type" ] || fail "aceitou README como foto de CNH: $NOTIMAGE"

STATUS=$(curl -s "$BASE/couriers/application.php" "${CAUTH[@]}")
[ "$(echo "$STATUS" | jq -r '.missing | length')" = "0" ] || fail "ainda falta documento: $STATUS"
[ "$(echo "$STATUS" | jq -r '.can_submit')" = "true" ] || fail "deveria poder enviar: $STATUS"
[ "$(echo "$STATUS" | jq -r '.application.face_match')" = "null" ] \
  || fail "não temos match facial — não pode aparecer nota inventada"

echo "== enviar exige aceite do contrato, e o aceite fica registrado =="
NOCONTRACT=$(curl -s -X POST "$BASE/couriers/submit_application.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d '{"accept_contract":false}')
[ "$(echo "$NOCONTRACT" | jq -r '.code')" = "contract_required" ] || fail "enviou sem aceitar contrato: $NOCONTRACT"

SUBMIT=$(curl -s -X POST "$BASE/couriers/submit_application.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d '{"accept_contract":true}')
[ "$(echo "$SUBMIT" | jq -r '.application.state')" = "review" ] || fail "não foi pra análise: $SUBMIT"
[ "$(echo "$SUBMIT" | jq -r '.application.contract_version')" != "null" ] || fail "contrato não foi versionado: $SUBMIT"
[ "$(query "SELECT count(*) FROM consents WHERE user_id=(SELECT id FROM users WHERE cpf='${CPF}') AND version LIKE 'courier-contract-%'")" = "1" ] \
  || fail "aceite do contrato não ficou registrado"

echo "== a fila do admin mostra a candidatura, e cliente nenhum entra nela =="
[ "$(curl -s "$BASE/admin/couriers.php" "${CAUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "candidato leu a fila do admin"
QUEUE=$(curl -s "$BASE/admin/couriers.php" "${AADMIN[@]}")
[ "$(echo "$QUEUE" | jq -r "[.queue[] | select(.id == \"${APP_ID}\")] | length")" = "1" ] || fail "candidatura não está na fila: $QUEUE"
[ "$(echo "$QUEUE" | jq -r "[.queue[] | select(.id == \"${APP_ID}\")][0].documents_count")" = "4" ] \
  || fail "fila não mostra os documentos: $QUEUE"

echo "== recusa exige motivo =="
NONOTE=$(curl -s -X POST "$BASE/admin/couriers.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d "{\"application_id\":\"${APP_ID}\",\"decision\":\"reject\"}")
[ "$(echo "$NONOTE" | jq -r '.code')" = "note_required" ] || fail "recusou sem motivo: $NONOTE"

echo "== aprovar CRIA o entregador e o login dele =="
NOCITY=$(curl -s -X POST "$BASE/admin/couriers.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d "{\"application_id\":\"${APP_ID}\",\"decision\":\"approve\"}")
[ "$(echo "$NOCITY" | jq -r '.code')" = "city_required" ] || fail "aprovou sem praça: $NOCITY"

APPROVE=$(curl -s -X POST "$BASE/admin/couriers.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d "{\"application_id\":\"${APP_ID}\",\"decision\":\"approve\",\"city_ibge_code\":\"${CITY}\"}")
ACCESS_CODE=$(echo "$APPROVE" | jq -er '.access_code') || fail "aprovação sem código de acesso: $APPROVE"
[ "$(echo "$APPROVE" | jq -r '.application.state')" = "approved" ] || fail "não aprovou: $APPROVE"
[ "$(query "SELECT count(*) FROM couriers WHERE id='${APP_ID}'")" = "1" ] || fail "entregador não foi criado"
[ "$(query "SELECT role FROM users WHERE cpf='${CPF}'")" = "courier" ] || fail "papel do usuário não virou courier"

# E o login do app do entregador (Fase 8) funciona com o que a aprovação criou.
COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') \
  || fail "o entregador aprovado não consegue entrar no app dele"
[ "$(curl -s "$BASE/couriers/me.php" -H "Authorization: Bearer $COURIER_TOKEN" | jq -r '.courier.id')" = "$APP_ID" ] \
  || fail "o login do entregador não aponta pra candidatura aprovada"

echo "== candidatura aprovada não volta a ser editada =="
LOCKED=$(curl -s -X POST "$BASE/couriers/apply.php" -H "Content-Type: application/json" "${CAUTH[@]}" \
  -d "{\"full_name\":\"Outro Nome\",\"cpf\":\"${CPF}\",\"phone\":\"${CAND_PHONE}\",\"vehicle\":\"bike\",\"pix_key\":\"${CPF}\"}")
[ "$(echo "$LOCKED" | jq -r '.code')" = "application_locked" ] || fail "editou candidatura aprovada: $LOCKED"

# ─────────────────────────── 15.3 ───────────────────────────
echo "== 15.3: campanha sem teto é recusada =="
NOCAP=$(curl -s -X POST "$BASE/admin/campaigns.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d '{"code":"SEMTETO","kind":"fixed","value":10,"audience":"all","payer":"platform","budget_cap":0}')
[ "$(echo "$NOCAP" | jq -r '.code')" = "invalid_campaign" ] || fail "criou campanha sem teto: $NOCAP"

echo "== projeção antes de criar usa ticket médio e comissão reais =="
DRY=$(curl -s -X POST "$BASE/admin/campaigns.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d '{"code":"PROJETA","kind":"fixed","value":10,"audience":"all","payer":"platform","budget_cap":100,"dry_run":true}')
[ "$(echo "$DRY" | jq -r '.dry_run')" = "true" ] || fail "dry_run não projetou: $DRY"
[ "$(echo "$DRY" | jq -r '.projection.redemptions')" = "10" ] || fail "R$100 de teto com R$10 off dá 10 resgates: $DRY"
[ "$(query "SELECT count(*) FROM coupons WHERE code='PROJETA'")" = "0" ] || fail "dry_run gravou campanha"

echo "== criar campanha com teto pequeno, pra ver o teto agir =="
CREATE=$(curl -s -X POST "$BASE/admin/campaigns.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d "{\"code\":\"GROWTH${RANDOM}\",\"kind\":\"fixed\",\"value\":10,\"min_order\":30,\"audience\":\"all\",\"payer\":\"shared\",\"budget_cap\":10}")
COUPON_CODE=$(echo "$CREATE" | jq -er '.coupon.code') || fail "não criou campanha: $CREATE"
[ "$(echo "$CREATE" | jq -r '.coupon.payer')" = "shared" ] || fail "quem paga não foi gravado: $CREATE"

DUP=$(curl -s -X POST "$BASE/admin/campaigns.php" -H "Content-Type: application/json" "${AADMIN[@]}" \
  -d "{\"code\":\"${COUPON_CODE}\",\"kind\":\"fixed\",\"value\":5,\"audience\":\"all\",\"payer\":\"platform\",\"budget_cap\":50}")
[ "$(echo "$DUP" | jq -r '.code')" = "code_taken" ] || fail "criou duas campanhas com o mesmo código: $DUP"

echo "== o desconto deixa no livro quem pagou, metade de cada =="
BUYER_PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
BUYER=$(login_otp "$BUYER_PHONE" "Cliente Cupom")
BAUTH=(-H "Authorization: Bearer $BUYER")
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${BAUTH[@]}" \
  -d "{\"cpf\":\"$(gen_cpf)\"}" >/dev/null
ADDR=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${BAUTH[@]}" \
  -d '{"street":"Rua Cupom","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.55,"lng":-46.63,"is_default":true}' | jq -er '.id')
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${BAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
APPLY=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${BAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${COUPON_CODE}\"}")
[ "$(echo "$APPLY" | jq -r '.cart.discount')" = "10.00" ] || fail "cupom não aplicou: $APPLY"

ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${BAUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR},\"payment_method\":\"cash\",\"change_for\":100,\"coupon_code\":\"${COUPON_CODE}\"}")
ORDER_ID=$(echo "$ORDER" | jq -er '.order.id') || fail "checkout com cupom falhou: $ORDER"
[ "$(query "SELECT amount FROM ledger_entries WHERE origin='coupon' AND account='store_receivable' AND order_id=${ORDER_ID}")" = "5.00" ] \
  || fail "metade do desconto não foi pro repasse da loja"
[ "$(query "SELECT amount FROM ledger_entries WHERE origin='coupon' AND account='platform_expense' AND order_id=${ORDER_ID}")" = "5.00" ] \
  || fail "metade do desconto não virou despesa da plataforma"

echo "== encostou no teto, o cupom desativa sozinho =="
[ "$(query "SELECT active FROM coupons WHERE code='${COUPON_CODE}'")" = "f" ] \
  || fail "cupom com teto estourado continuou ativo"
LIST=$(curl -s "$BASE/admin/campaigns.php" "${AADMIN[@]}")
[ "$(echo "$LIST" | jq -r "[.coupons[] | select(.code == \"${COUPON_CODE}\")][0].uses")" = "1" ] \
  || fail "uso não apareceu no painel: $LIST"
[ "$(echo "$LIST" | jq -r '.paid_so_far.platform >= 5')" = "true" ] || fail "painel não soma o que saiu do nosso bolso: $LIST"

echo "== e ninguém mais consegue usar =="
BUYER2=$(login_otp "119$(( RANDOM % 90000000 + 10000000 ))" "Cliente Tardio")
B2=(-H "Authorization: Bearer $BUYER2")
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${B2[@]}" \
  -d "{\"cpf\":\"$(gen_cpf)\"}" >/dev/null
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${B2[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
LATE=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${B2[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${COUPON_CODE}\"}")
[ "$(echo "$LATE" | jq -r '.code')" != "null" ] || fail "cupom esgotado ainda foi aplicado: $LATE"

echo "OK: entrada de entregador (15.2) e campanhas (15.3) passaram no smoke test"
