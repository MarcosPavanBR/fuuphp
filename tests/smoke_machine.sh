#!/usr/bin/env bash
# Smoke test das telas 9.6, 9.7, 10.4 e 10.6 -- a maquininha física e o
# fechamento semanal.
#
#  10.4 -- a loja escolhe o que aceita, com teto e cadastro de maquininha
#  10.6 -- o entregador retira, vende, informa NSU e devolve
#  9.6  -- conciliação: NSU informado x extrato importado, divergência vira
#          ocorrência e trava o fechamento do dia
#  9.7  -- netting semanal, repasse aos entregadores e bloqueios automáticos
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8115
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-machine-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
query() { psql "$DATABASE_URL" -tAc "$1"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

RESTAURANT_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
ADMIN_USER_ID="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"
COURIER_USER_ID="$(gen_uuid)"
COURIER2_ID="$(gen_uuid)"
COURIER2_USER_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
CPF="$(gen_cpf)"
CPF2="$(gen_cpf)"
ACCESS_CODE="313131"
STAMP="$(date +%s%N)"
ADMIN_PHONE="1197$(( RANDOM % 9000000 + 1000000 ))"
# NSU é UNIQUE por adquirente no banco: fixos, colidiriam com a execução
# anterior no mesmo banco. Seis dígitos derivados do carimbo desta execução.
NSU_BASE=$(( $(date +%s) % 900000 + 100000 ))
NSU1="$NSU_BASE"; NSU_B=$(( NSU_BASE + 1 )); NSU_ORPHAN=$(( NSU_BASE + 2 ))

echo "== semear loja, duas maquininhas, dois entregadores e admin =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${STAFF_USER_ID}',    'restaurant_staff', 'Staff Maquininha', NULL, 'staff-pos-${STAMP}@test.com'),
  ('${ADMIN_USER_ID}',    'admin',            'Admin Maquininha', '${ADMIN_PHONE}', 'admin-pos-${STAMP}@test.com'),
  ('${COURIER_USER_ID}',  'courier',          'Jonas da Maquininha', NULL, 'pos1-${STAMP}@test.com'),
  ('${COURIER2_USER_ID}', 'courier',          'Rita Segunda',        NULL, 'pos2-${STAMP}@test.com');

INSERT INTO platform_policies
  (version, enabled_methods, cash_ceiling, cash_settle_deadline, store_debit_dow,
   pos_return_deadline, delivery_base_fee, commission_bps, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         300.00, interval '24 hours', 2, interval '6 hours', 5.00, 800, id
  FROM users WHERE email = 'staff-pos-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at, lat, lng)
VALUES ('${RESTAURANT_ID}', 'Maquininha Smoke Restaurant', '${CNPJ}', '${CITY}', true, now(), -23.5505, -46.6333);

INSERT INTO restaurant_payment_settings (restaurant_id, methods, max_change, min_order)
VALUES ('${RESTAURANT_ID}', ARRAY['mp_card','cash','pos_machine']::payment_method[], 200.00, 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
VALUES ('${STAFF_USER_ID}', 'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
  '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO couriers (id, user_id, city_ibge_code, active) VALUES
  ('${COURIER_ID}',  '${COURIER_USER_ID}',  '${CITY}', true),
  ('${COURIER2_ID}', '${COURIER2_USER_ID}', '${CITY}', true);

INSERT INTO partner_accounts (user_id, kind, courier_id, login_code, access_code_hash) VALUES
  ('${COURIER_USER_ID}',  'courier', '${COURIER_ID}',  '${CPF}',  encode(sha256('${ACCESS_CODE}'::bytea), 'hex')),
  ('${COURIER2_USER_ID}', 'courier', '${COURIER2_ID}', '${CPF2}', encode(sha256('${ACCESS_CODE}'::bytea), 'hex'));

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Maquininha', 59.80, 'Pratos', true);
SQL
ITEM_ID=$(query "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-machine-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Maquininha\"}" | jq -er '.dev_code') || fail "otp falhou"
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login falhou"
AUTH=(-H "Authorization: Bearer $ACCESS")

ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua da Maquininha","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5630,"lng":-46.6333,"is_default":true}' \
  | jq -er '.id') || fail "endereço não criou"

STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token') || fail "login da loja falhou"
STAFF_AUTH=(-H "Authorization: Bearer $STAFF_TOKEN")

COURIER_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token') || fail "login do entregador falhou"
COURIER_AUTH=(-H "Authorization: Bearer $COURIER_TOKEN")
COURIER2_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"courier\",\"login_code\":\"${CPF2}\",\"secret\":\"${ACCESS_CODE}\"}" | jq -er '.access_token')
COURIER2_AUTH=(-H "Authorization: Bearer $COURIER2_TOKEN")
for t in "$COURIER_TOKEN" "$COURIER2_TOKEN"; do
  curl -s -X POST "$BASE/couriers/shift.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $t" -d '{"action":"start"}' >/dev/null
done

ADMIN_CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$ADMIN_CODE\"}" | jq -er '.access_token')
ADMIN_AUTH=(-H "Authorization: Bearer $ADMIN_TOKEN")

echo "== 10.4: cada forma vem com a consequência operacional escrita =="
SET=$(curl -s "$BASE/restaurants/payment_settings.php" "${STAFF_AUTH[@]}")
[ "$(echo "$SET" | jq -r '.methods.pos_machine.note')" = "A máquina é sua: o entregador leva, cobra e devolve no mesmo turno" ] \
  || fail "a consequência da maquininha não veio do servidor: $SET"
[ "$(echo "$SET" | jq -r '.methods.mp_card.recommended')" = "true" ] || fail "cartão devia estar recomendado: $SET"
[ "$(echo "$SET" | jq -r '.online_only')" = "false" ] || fail "loja em dia não é só-online: $SET"
[ "$(echo "$SET" | jq -r '.selectable | length')" = "5" ] || fail "a praça libera as cinco formas: $SET"

echo "== 10.4: teto da loja não passa do teto da plataforma =="
OVER=$(curl -s -X POST "$BASE/restaurants/payment_settings.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"methods":["mp_card","cash","pos_machine"],"max_cash":500}')
[ "$(echo "$OVER" | jq -r '.code')" = "above_cash_ceiling" ] || fail "loja passou do teto da plataforma: $OVER"

SAVED=$(curl -s -X POST "$BASE/restaurants/payment_settings.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"methods":["mp_card","cash","pos_machine"],"max_cash":150,"max_card_machine":400,"max_change":100,"min_order":25}')
[ "$(echo "$SAVED" | jq -r '.settings.max_cash')" = "150.00" ] || fail "teto de dinheiro não gravou: $SAVED"
[ "$(echo "$SAVED" | jq -r '.settings.max_card_machine')" = "400.00" ] || fail "teto de maquininha não gravou: $SAVED"

echo "== 10.4: cadastrar a maquininha (ela é da loja) =="
POS1=$(curl -s -X POST "$BASE/restaurants/pos_devices.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"action":"register","label":"POS-01","acquirer":"stone","serial":"SN-1"}')
DEVICE1=$(echo "$POS1" | jq -er '.device.id') || fail "maquininha não cadastrou: $POS1"
curl -s -X POST "$BASE/restaurants/pos_devices.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"action":"register","label":"POS-02","acquirer":"cielo"}' >/dev/null

echo "== 10.6: retirada registra posse, com prazo da política =="
TAKE=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"take\",\"device_id\":\"${DEVICE1}\"}")
CUSTODY=$(echo "$TAKE" | jq -er '.custody.id') || fail "custódia não abriu: $TAKE"
case "$(echo "$TAKE" | jq -r '.deadline_label')" in
  6:00|5:5*) ;;
  *) fail "prazo de 6 h não veio da política: $TAKE" ;;
esac

echo "== 10.6: a mesma máquina não sai com duas pessoas =="
TAKEN=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER2_AUTH[@]}" \
  -d "{\"action\":\"take\",\"device_id\":\"${DEVICE1}\"}")
[ "$(echo "$TAKEN" | jq -r '.code')" = "device_taken" ] || fail "duas pessoas com a mesma máquina: $TAKEN"

echo "== 10.6: quem já está com uma não pega outra =="
DEVICE2=$(query "SELECT id FROM pos_devices WHERE restaurant_id='${RESTAURANT_ID}' AND label='POS-02'")
SECOND=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"take\",\"device_id\":\"${DEVICE2}\"}")
[ "$(echo "$SECOND" | jq -r '.code')" = "already_holding" ] || fail "saiu com a segunda máquina: $SECOND"

echo "== 10.6: a máquina na rua não pode ser desativada no cadastro =="
DEACT=$(curl -s -X POST "$BASE/restaurants/pos_devices.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"action\":\"deactivate\",\"device_id\":\"${DEVICE1}\"}")
[ "$(echo "$DEACT" | jq -r '.code')" = "device_out" ] || fail "desativou máquina que está na rua: $DEACT"

# Um pedido de maquininha entregue por este entregador.
machine_order() {
  curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
  local id
  id=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
    -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"pos_machine\",\"machine_kind\":\"debit\"}" \
    | jq -er '.order.id') || fail "checkout de maquininha falhou"
  curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${id}}" >/dev/null
  for to in preparing ready; do
    curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
      -d "{\"order_id\":${id},\"to\":\"${to}\"}" >/dev/null
  done
  local offer
  offer=$(curl -s "$BASE/couriers/offers.php" "${COURIER_AUTH[@]}" \
    | jq -er ".offers[] | select(.order_id == ${id}) | .offer_id") || fail "oferta não apareceu"
  curl -s -X POST "$BASE/couriers/accept_offer.php" -H "Content-Type: application/json" \
    -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" -d "{\"offer_id\":${offer}}" >/dev/null
  curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
    -d "{\"order_id\":${id},\"to\":\"delivering\"}" >/dev/null
  echo "$id"
}

echo "== 9.6: venda na maquininha é conferência, não dívida =="
O1=$(machine_order)
TOTAL=$(query "SELECT total FROM orders WHERE id=${O1}")
WRONG=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O1},\"nsu\":\"${NSU1}\",\"amount\":10.00}")
[ "$(echo "$WRONG" | jq -r '.code')" = "amount_mismatch" ] || fail "aceitou valor diferente do pedido: $WRONG"

SALE=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O1},\"nsu\":\"${NSU1}\",\"amount\":${TOTAL},\"brand\":\"Visa\"}")
[ "$(echo "$SALE" | jq -r '.transaction.state')" = "pending" ] || fail "venda não entrou como pendente: $SALE"
[ "$(echo "$SALE" | jq -r '.transaction.kind')" = "debit" ] || fail "o tipo veio do pedido, não do corpo: $SALE"
CASH_AFTER=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")
[ "$CASH_AFTER" = "0" ] || fail "venda na maquininha virou dívida do entregador (saldo ${CASH_AFTER})"

# Entrega pra fechar a corrida -- o frete é devido do mesmo jeito.
CODE1=$(query "SELECT delivery_code FROM orders WHERE id=${O1}")
curl -s -X POST "$BASE/couriers/deliver.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${COURIER_AUTH[@]}" \
  -d "{\"order_id\":${O1},\"delivery_code\":\"${CODE1}\"}" >/dev/null
[ "$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")" = "0" ] \
  || fail "entregar pedido de maquininha lançou espécie"

echo "== 9.6: venda sem NSU entra e trava o fechamento =="
O2=$(machine_order)
TOTAL2=$(query "SELECT total FROM orders WHERE id=${O2}")
NONSU=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O2},\"amount\":${TOTAL2}}")
[ "$(echo "$NONSU" | jq -r '.transaction.nsu')" = "null" ] || fail "NSU vazio virou string: $NONSU"
[ "$(echo "$NONSU" | jq -r '.notice')" = "Venda registrada SEM NSU. Informe o número antes do fim do turno — sem ele o dia da loja não fecha." ] \
  || fail "o aviso do NSU faltando não é o da tela: $NONSU"

TODAY=$(date +%F)
RECON=$(curl -s "$BASE/restaurants/reconciliation.php?day=${TODAY}" "${STAFF_AUTH[@]}")
[ "$(echo "$RECON" | jq -r '.rows | length')" = "2" ] || fail "a conferência não achou as duas vendas: $RECON"
[ "$(echo "$RECON" | jq -r '.to_resolve')" = "2" ] || fail "tudo devia estar a resolver antes do extrato: $RECON"
[ "$(echo "$RECON" | jq -r '.day_closed')" = "false" ] || fail "o dia fechou com pendência: $RECON"
[ "$(echo "$RECON" | jq -r '[.rows[].situation] | unique | join(",")')" = "PENDENTE" ] \
  || fail "situação errada antes do extrato: $RECON"

echo "== 9.6: importar o extrato concilia por NSU =="
EXTRATO="/tmp/extrato-${STAMP}.csv"
cat > "$EXTRATO" <<CSV
nsu,valor,data_hora,bandeira,tipo
${NSU1},${TOTAL},$(date '+%Y-%m-%d %H:%M:%S'),Visa,debito
CSV
IMPORT=$(curl -s -X POST "$BASE/restaurants/reconciliation.php" "${STAFF_AUTH[@]}" \
  -F "acquirer=stone" -F "day=${TODAY}" -F "statement=@${EXTRATO};type=text/csv")
[ "$(echo "$IMPORT" | jq -r '.matched')" = "1" ] || fail "o extrato não casou pelo NSU: $IMPORT"
[ "$(echo "$IMPORT" | jq -r '.statement.rows_total')" = "1" ] || fail "o import não ficou registrado: $IMPORT"
[ "$(query "SELECT state FROM card_transactions WHERE nsu='${NSU1}'")" = "reconciled" ] \
  || fail "a venda não ficou conciliada"

echo "== 9.6: o mesmo arquivo não entra duas vezes =="
AGAIN=$(curl -s -X POST "$BASE/restaurants/reconciliation.php" "${STAFF_AUTH[@]}" \
  -F "acquirer=stone" -F "day=${TODAY}" -F "statement=@${EXTRATO};type=text/csv")
[ "$(echo "$AGAIN" | jq -r '.code')" = "statement_already_imported" ] || fail "reimportou o mesmo extrato: $AGAIN"

echo "== 9.6: divergência de valor vira ocorrência e não fecha o dia =="
NSU2="${NSU_B}"
# Completar o NSU pelo app atualiza a MESMA venda -- não cria uma segunda.
FILL=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O2},\"nsu\":\"${NSU2}\",\"amount\":${TOTAL2}}")
[ "$(echo "$FILL" | jq -r '.transaction.nsu')" = "${NSU2}" ] || fail "o NSU não completou a venda: $FILL"
[ "$(query "SELECT count(*) FROM card_transactions WHERE order_id=${O2}")" = "1" ] \
  || fail "completar o NSU duplicou a venda"
REUSE=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d "{\"action\":\"sale\",\"order_id\":${O2},\"nsu\":\"${NSU1}\",\"amount\":${TOTAL2}}")
[ "$(echo "$REUSE" | jq -r '.code')" = "nsu_already_used" ] || fail "aceitou NSU de outra venda: $REUSE"
DIVERG="/tmp/extrato-div-${STAMP}.csv"
DIFF_TOTAL=$(php -r "echo number_format(${TOTAL2} - 10, 2, '.', '');")
cat > "$DIVERG" <<CSV
nsu,valor,data_hora,bandeira,tipo
${NSU2},${DIFF_TOTAL},$(date '+%Y-%m-%d %H:%M:%S'),Master,credito
CSV
IMPORT2=$(curl -s -X POST "$BASE/restaurants/reconciliation.php" "${STAFF_AUTH[@]}" \
  -F "acquirer=stone" -F "day=${TODAY}" -F "statement=@${DIVERG};type=text/csv")
[ "$(echo "$IMPORT2" | jq -r '.divergent[0].difference')" = "-10" ] || fail "a diferença não foi calculada: $IMPORT2"
[ "$(query "SELECT state FROM card_transactions WHERE nsu='${NSU2}'")" = "divergent" ] || fail "não marcou divergência"
[ "$(query "SELECT count(*) FROM disputes WHERE order_id=${O2} AND kind='nsu_divergent' AND state='open'")" = "1" ] \
  || fail "a divergência não virou ocorrência -- o chip da tela diz 'ocorrências'"
[ "$(echo "$IMPORT2" | jq -r '.day_closed')" = "false" ] || fail "o dia fechou com divergência aberta: $IMPORT2"
[ "$(echo "$IMPORT2" | jq -r '.open_issues | length')" = "1" ] || fail "a ocorrência não apareceu na tela da loja: $IMPORT2"

echo "== 9.6: linha do extrato sem venda informada fica como órfã =="
ORFA="/tmp/extrato-orfa-${STAMP}.csv"
cat > "$ORFA" <<CSV
nsu,valor,data_hora,bandeira,tipo
${NSU_ORPHAN},12.34,$(date '+%Y-%m-%d %H:%M:%S'),Elo,credito
CSV
IMPORT3=$(curl -s -X POST "$BASE/restaurants/reconciliation.php" "${STAFF_AUTH[@]}" \
  -F "acquirer=stone" -F "day=${TODAY}" -F "statement=@${ORFA};type=text/csv")
[ "$(echo "$IMPORT3" | jq -r '.orphan | length')" = "1" ] || fail "a linha órfã sumiu em vez de aparecer: $IMPORT3"
[ "$(echo "$IMPORT3" | jq -r '.matched')" = "0" ] || fail "inventou par pra linha órfã: $IMPORT3"

echo "== 10.6: devolver avisa o NSU que falta; a loja confirma =="
RET=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER_AUTH[@]}" \
  -d '{"action":"return"}')
[ "$(echo "$RET" | jq -r '.custody.returned_at')" != "null" ] || fail "devolução não registrou: $RET"
[ "$(query "SELECT confirmed_by FROM pos_custody WHERE id=${CUSTODY}")" = "" ] \
  || fail "a devolução se confirmou sozinha -- a loja é a outra ponta"
# "Devolvi" é uma ponta só: até a loja conferir, a máquina não sai de novo e
# continua aparecendo no painel como fora do balcão.
EARLY=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER2_AUTH[@]}" \
  -d "{\"action\":\"take\",\"device_id\":\"${DEVICE1}\"}")
[ "$(echo "$EARLY" | jq -r '.code')" = "device_taken" ] || fail "máquina saiu antes da loja conferir a devolução: $EARLY"
PANEL=$(curl -s "$BASE/restaurants/payment_settings.php" "${STAFF_AUTH[@]}")
[ "$(echo "$PANEL" | jq -r "[.devices[] | select(.id == \"${DEVICE1}\")][0].out_with_courier")" = "true" ] \
  || fail "a custódia sumiu do painel antes da confirmação: $PANEL"
CONFIRM=$(curl -s -X POST "$BASE/restaurants/pos_devices.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"action\":\"confirm_return\",\"custody_id\":${CUSTODY}}")
[ "$(echo "$CONFIRM" | jq -r '.custody.confirmed_by')" != "null" ] || fail "a loja não confirmou: $CONFIRM"
[ "$(echo "$CONFIRM" | jq -r '.pending_transactions >= 1')" = "true" ] \
  || fail "a confirmação não avisa que o dia ainda não fechou: $CONFIRM"
DUPCONF=$(curl -s -X POST "$BASE/restaurants/pos_devices.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d "{\"action\":\"confirm_return\",\"custody_id\":${CUSTODY}}")
[ "$(echo "$DUPCONF" | jq -r '.code')" = "already_confirmed" ] || fail "confirmou duas vezes: $DUPCONF"

echo "== 10.6: máquina devolvida pode sair com outra pessoa =="
TAKE2=$(curl -s -X POST "$BASE/couriers/pos.php" -H "Content-Type: application/json" "${COURIER2_AUTH[@]}" \
  -d "{\"action\":\"take\",\"device_id\":\"${DEVICE1}\"}")
[ "$(echo "$TAKE2" | jq -r '.custody.id')" != "null" ] || fail "máquina devolvida não saiu de novo: $TAKE2"

echo "== 9.7: o acerto da semana sai do livro, não de conta refeita =="
# Envelhece tudo pra semana passada e gera o fechamento dela.
LAST_MON=$(date -d 'last monday -7 days' +%F 2>/dev/null || date -v-mon -v-7d +%F)
LAST_SUN=$(date -d "${LAST_MON} +6 days" +%F)
psql_run <<SQL
UPDATE orders SET created_at = '${LAST_MON}'::date + interval '12 hours'
 WHERE restaurant_id = '${RESTAURANT_ID}';
UPDATE ledger_entries SET created_at = '${LAST_MON}'::date + interval '13 hours'
 WHERE party_id IN ('${RESTAURANT_ID}', '${COURIER_ID}');
INSERT INTO ledger_entries (account, party_id, amount, origin, origin_id, memo)
VALUES ('store_receivable', '${RESTAURANT_ID}', 120.00, 'order', 'smoke-netting',
        'comissão e frete em aberto da semana');
UPDATE ledger_entries SET created_at = '${LAST_MON}'::date + interval '14 hours'
 WHERE origin_id = 'smoke-netting';
SQL

NET=$(curl -s "$BASE/admin/netting.php?start=${LAST_MON}&end=${LAST_SUN}" "${ADMIN_AUTH[@]}")
[ "$(echo "$NET" | jq -r '.rules | length')" = "4" ] || fail "as quatro regras da tela não vieram: $NET"
STORE_ROW=$(echo "$NET" | jq -r "[.stores[] | select(.id == \"${RESTAURANT_ID}\")][0]")
[ "$STORE_ROW" != "null" ] || fail "a loja não apareceu no acerto: $NET"
[ "$(echo "$STORE_ROW" | jq -r '.to_charge')" = "120" ] || fail "'a cobrar' não veio do livro: $STORE_ROW"
[ "$(echo "$STORE_ROW" | jq -r '.status')" != "null" ] || fail "linha sem status: $STORE_ROW"
[ "$(echo "$NET" | jq -r '.summary.courier_freight > 0')" = "true" ] || fail "frete da semana zerado: $NET"

echo "== 9.7: só admin entra no financeiro =="
[ "$(curl -s "$BASE/admin/netting.php" "${STAFF_AUTH[@]}" | jq -r '.code')" = "forbidden" ] \
  || fail "a loja abriu o financeiro da plataforma"

echo "== 9.7: gerar o fechamento é idempotente =="
GEN=$(curl -s -X POST "$BASE/admin/netting.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"action\":\"generate\",\"start\":\"${LAST_MON}\",\"end\":\"${LAST_SUN}\"}")
[ "$(echo "$GEN" | jq -r '.couriers.count >= 1')" = "true" ] || fail "não gerou repasse de entregador: $GEN"
curl -s -X POST "$BASE/admin/netting.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"action\":\"generate\",\"start\":\"${LAST_MON}\",\"end\":\"${LAST_SUN}\"}" >/dev/null
[ "$(query "SELECT count(*) FROM payouts WHERE party_id='${COURIER_ID}' AND period_start='${LAST_MON}'")" = "1" ] \
  || fail "gerar duas vezes duplicou o repasse"

echo "== 9.7: retenção nunca deixa o repasse negativo =="
[ "$(echo "$GEN" | jq -r '[.couriers.rows[].net] | map(select(. < 0)) | length')" = "0" ] \
  || fail "repasse negativo: dívida virou cobrança embutida no pagamento: $GEN"

echo "== 9.7: mandar o lote tira do rascunho =="
BATCH=$(curl -s -X POST "$BASE/admin/netting.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"action\":\"send_batch\",\"start\":\"${LAST_MON}\",\"end\":\"${LAST_SUN}\"}")
[ "$(echo "$BATCH" | jq -r '.count >= 1')" = "true" ] || fail "o lote não saiu: $BATCH"
[ "$(query "SELECT count(*) FROM payouts WHERE party_kind='courier' AND period_start='${LAST_MON}' AND state='draft' AND net > 0")" = "0" ] \
  || fail "sobrou rascunho depois do lote"

echo "== 9.7: baixa é contrapartida no livro, nunca edição =="
PAYOUT_ID=$(query "SELECT id FROM payouts WHERE party_kind='restaurant' AND party_id='${RESTAURANT_ID}' AND period_start='${LAST_MON}'")
OWED_BEFORE=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='store_receivable' AND party_id='${RESTAURANT_ID}'")
SETTLE=$(curl -s -X POST "$BASE/admin/netting.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"action\":\"settle\",\"payout_id\":${PAYOUT_ID},\"provider_ref\":\"PIX-SMOKE\"}")
[ "$(echo "$SETTLE" | jq -r '.payout.state')" = "paid" ] || fail "não baixou: $SETTLE"
OWED_AFTER=$(query "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='store_receivable' AND party_id='${RESTAURANT_ID}'")
[ "$(echo "$OWED_BEFORE > $OWED_AFTER" | bc)" = "1" ] || fail "a baixa não reduziu o saldo (${OWED_BEFORE} -> ${OWED_AFTER})"
[ "$(query "SELECT count(*) FROM ledger_entries WHERE origin='payout' AND origin_id='payout:${PAYOUT_ID}' AND amount < 0")" = "1" ] \
  || fail "a baixa não entrou como contrapartida negativa"
TWICE=$(curl -s -X POST "$BASE/admin/netting.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"action\":\"settle\",\"payout_id\":${PAYOUT_ID}}")
[ "$(echo "$TWICE" | jq -r '.code')" = "already_paid" ] || fail "baixou o mesmo acerto duas vezes: $TWICE"

echo "== 9.7: bloqueio automático de espécie fora do prazo =="
psql_run <<SQL
INSERT INTO ledger_entries (account, party_id, amount, origin, origin_id, memo, created_at)
VALUES ('courier_cash', '${COURIER2_ID}', 90.00, 'order', 'smoke-cash-old',
        'espécie antiga de teste', now() - interval '48 hours');
SQL
php "$ROOT/bin/apply_financial_blocks.php" >/tmp/smoke-machine-blocks.log 2>&1 || fail "varredura de bloqueios falhou"
[ "$(query "SELECT cash_blocked FROM couriers WHERE id='${COURIER2_ID}'")" = "t" ] \
  || fail "espécie de 48 h não bloqueou o entregador (prazo é 24 h)"
[ "$(query "SELECT cash_blocked FROM couriers WHERE id='${COURIER_ID}'")" = "f" ] \
  || fail "bloqueou quem não tem espécie na mão"

BLOCKED_OFFERS=$(curl -s "$BASE/couriers/offers.php" "${COURIER2_AUTH[@]}")
[ "$(echo "$BLOCKED_OFFERS" | jq -r '.cash_blocked')" = "true" ] || fail "o app não mostra o bloqueio: $BLOCKED_OFFERS"

echo "== 9.7: baixar a espécie destrava na varredura seguinte =="
psql_run <<SQL
INSERT INTO ledger_entries (account, party_id, amount, origin, origin_id, memo)
VALUES ('courier_cash', '${COURIER2_ID}', -90.00, 'cash_settlement', 'smoke-cash-settled',
        'baixa de teste');
SQL
php "$ROOT/bin/apply_financial_blocks.php" >>/tmp/smoke-machine-blocks.log 2>&1
[ "$(query "SELECT cash_blocked FROM couriers WHERE id='${COURIER2_ID}'")" = "f" ] \
  || fail "baixar a espécie não destravou"

echo "== 9.7: loja em atraso fica só no online, e nem o dono religa =="
psql_run <<SQL
INSERT INTO payouts (party_kind, party_id, period_start, period_end, gross, withheld, net, state)
VALUES ('restaurant', '${RESTAURANT_ID}', '${LAST_MON}'::date - 21, '${LAST_MON}'::date - 15,
        200.00, 0, 200.00, 'draft');
SQL
php "$ROOT/bin/apply_financial_blocks.php" >>/tmp/smoke-machine-blocks.log 2>&1
[ "$(query "SELECT online_only_until IS NOT NULL FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "t" ] \
  || fail "loja com débito vencido não foi limitada ao online"
LOCKED=$(curl -s "$BASE/restaurants/payment_settings.php" "${STAFF_AUTH[@]}")
[ "$(echo "$LOCKED" | jq -r '.online_only')" = "true" ] || fail "a tela da loja não mostra a trava: $LOCKED"
[ "$(echo "$LOCKED" | jq -r '.selectable | length')" = "3" ] || fail "só as três formas online deviam sobrar: $LOCKED"
RELIGA=$(curl -s -X POST "$BASE/restaurants/payment_settings.php" -H "Content-Type: application/json" "${STAFF_AUTH[@]}" \
  -d '{"methods":["mp_card","cash"]}')
[ "$(echo "$RELIGA" | jq -r '.code')" = "online_only" ] || fail "a loja em atraso religou dinheiro sozinha: $RELIGA"

echo "== 9.7: quitar o débito devolve a loja ao normal =="
LATE_ID=$(query "SELECT id FROM payouts WHERE party_id='${RESTAURANT_ID}' AND state='draft' ORDER BY id DESC LIMIT 1")
curl -s -X POST "$BASE/admin/netting.php" -H "Content-Type: application/json" "${ADMIN_AUTH[@]}" \
  -d "{\"action\":\"settle\",\"payout_id\":${LATE_ID}}" >/dev/null
[ "$(query "SELECT online_only_until FROM restaurants WHERE id='${RESTAURANT_ID}'")" = "" ] \
  || fail "quitar não destravou a loja"

rm -f "$EXTRATO" "$DIVERG" "$ORFA"
echo
echo "smoke_machine OK"
