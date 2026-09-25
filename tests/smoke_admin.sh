#!/usr/bin/env bash
# Smoke test do painel da plataforma (Fase 12 + tela 10.5): aprovação de
# loja com trava de só-online, ocorrências com contrapartida no livro,
# relatórios do período e política versionada (nunca editada).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8106
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-admin-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }

ADMIN_ID="$(gen_uuid)"
NEW_STORE="$(gen_uuid)"
BAD_STORE="$(gen_uuid)"
COURIER_ID="$(gen_uuid)"
COURIER_USER="$(gen_uuid)"
LIVE_STORE="$(gen_uuid)"
ADMIN_PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
STAMP="$(date +%s%N)"

echo "== semear admin, duas lojas esperando análise e uma ocorrência de caixa =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, phone, email) VALUES
  ('${ADMIN_ID}',    'admin',   'Admin Plataforma', '${ADMIN_PHONE}', 'admin-panel-${STAMP}@test.com'),
  ('${COURIER_USER}','courier', 'Entregador Caixa', NULL,             'courier-panel-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, cash_ceiling, commission_bps,
                               new_store_online_only_days, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[],
         300.00, 800, 30, '${ADMIN_ID}';

-- Duas lojas sem decisão (é o que a fila da tela 12.1 mostra) e uma já viva.
INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open) VALUES
  ('${NEW_STORE}', 'Loja Esperando Análise', '$(gen_cnpj)', '${CITY}', false),
  ('${BAD_STORE}', 'Loja Documento Ruim',    '$(gen_cnpj)', '${CITY}', false);

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at)
VALUES ('${LIVE_STORE}', 'Loja Viva Admin', '$(gen_cnpj)', '${CITY}', true, now());

INSERT INTO couriers (id, user_id, city_ibge_code, active)
VALUES ('${COURIER_ID}', '${COURIER_USER}', '${CITY}', true);

-- Ocorrência de caixa: divergência de R$ 80 entre o declarado e o contado.
INSERT INTO disputes (order_id, courier_id, restaurant_id, kind, risk, amount)
VALUES (NULL, '${COURIER_ID}', '${LIVE_STORE}', 'cash_unsettled', 'high', 80.00);
SQL

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-admin-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${LIVE_STORE}" && break
  sleep 0.2
done

echo "== admin entra pelo mesmo OTP do cliente (não tem conta de parceiro) =="
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code') || fail "otp do admin falhou"
ADMIN_TOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"$CODE\"}" | jq -er '.access_token') || fail "login do admin falhou"
AAUTH=(-H "Authorization: Bearer $ADMIN_TOKEN")

echo "== cliente comum não entra no painel da plataforma =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
C=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Qualquer\"}" | jq -er '.dev_code')
CUSTOMER=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$C\"}" | jq -er '.access_token')
[ "$(curl -s "$BASE/admin/reports.php" -H "Authorization: Bearer $CUSTOMER" | jq -r '.code')" = "forbidden" ] \
  || fail "cliente entrou no painel da plataforma"

echo "== fila de aprovação mostra as lojas sem decisão =="
QUEUE=$(curl -s "$BASE/admin/restaurants.php" "${AAUTH[@]}")
[ "$(echo "$QUEUE" | jq -r "[.pending[] | select(.id == \"${NEW_STORE}\")] | length")" = "1" ] \
  || fail "loja nova não apareceu na fila: $QUEUE"
[ "$(echo "$QUEUE" | jq -r "[.pending[] | select(.id == \"${LIVE_STORE}\")] | length")" = "0" ] \
  || fail "loja já aprovada apareceu na fila de análise"

echo "== recusa exige motivo, e o motivo fica gravado =="
[ "$(curl -s -X POST "$BASE/admin/restaurants.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
   -d "{\"restaurant_id\":\"${BAD_STORE}\",\"decision\":\"reject\"}" | jq -r '.code')" = "reason_required" ] \
  || fail "recusou sem motivo"
REJ=$(curl -s -X POST "$BASE/admin/restaurants.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
  -d "{\"restaurant_id\":\"${BAD_STORE}\",\"decision\":\"reject\",\"reason\":\"contrato social ilegível\"}")
[ "$(echo "$REJ" | jq -r '.restaurant.rejection_reason')" = "contrato social ilegível" ] || fail "motivo não gravou: $REJ"

echo "== aprovar nasce só-online, e isso vira regra de pagamento =="
APP=$(curl -s -X POST "$BASE/admin/restaurants.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
  -d "{\"restaurant_id\":\"${NEW_STORE}\",\"decision\":\"approve\"}")
[ "$(echo "$APP" | jq -r '.online_only_days')" = "30" ] || fail "prazo de só-online não veio da política: $APP"
[ "$(echo "$APP" | jq -r '.restaurant.online_only_until')" != "null" ] || fail "não gravou online_only_until: $APP"
METHODS=$(psql "$DATABASE_URL" -tAc "SELECT methods::text FROM restaurant_payment_settings WHERE restaurant_id='${NEW_STORE}'")
[[ "$METHODS" != *"cash"* ]] || fail "loja nova nasceu com dinheiro liberado: $METHODS"
[[ "$METHODS" == *"mp_card"* ]] || fail "loja nova nasceu sem cartão: $METHODS"

echo "== decidir duas vezes é barrado =="
[ "$(curl -s -X POST "$BASE/admin/restaurants.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
   -d "{\"restaurant_id\":\"${NEW_STORE}\",\"decision\":\"approve\"}" | jq -r '.code')" = "already_decided" ] \
  || fail "aprovou duas vezes"

echo "== fila de ocorrências vem por risco e valor =="
DIS=$(curl -s "$BASE/admin/disputes.php" "${AAUTH[@]}")
DISPUTE_ID=$(echo "$DIS" | jq -er "[.open[] | select(.kind == \"cash_unsettled\")][0].id") || fail "ocorrência não apareceu: $DIS"
[ "$(echo "$DIS" | jq -r "[.open[] | select(.id == ${DISPUTE_ID})][0].risk")" = "high" ] || fail "risco errado: $DIS"
[ "$(echo "$DIS" | jq -r "[.open[] | select(.id == ${DISPUTE_ID})][0].courier_name")" = "Entregador Caixa" ] \
  || fail "ocorrência sem o nome do entregador: $DIS"

echo "== resolver cobrando do entregador vira contrapartida no livro =="
BEFORE=$(psql "$DATABASE_URL" -tAc "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")
RES=$(curl -s -X POST "$BASE/admin/disputes.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
  -d "{\"dispute_id\":${DISPUTE_ID},\"resolution\":\"faltou dinheiro na conferência\",\"charge\":\"courier\"}")
[ "$(echo "$RES" | jq -r '.dispute.state')" = "resolved" ] || fail "ocorrência não resolveu: $RES"
AFTER=$(psql "$DATABASE_URL" -tAc "SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE account='courier_cash' AND party_id='${COURIER_ID}'")
[ "$(php -r "echo (float)'$BEFORE' - (float)'$AFTER';")" = "80" ] || fail "contrapartida errada: antes=$BEFORE depois=$AFTER"
[ "$(psql "$DATABASE_URL" -tAc "SELECT origin FROM ledger_entries WHERE origin_id='${DISPUTE_ID}' AND origin='dispute'")" = "dispute" ] \
  || fail "lançamento não aponta pra ocorrência"

echo "== resolver de novo é barrado =="
[ "$(curl -s -X POST "$BASE/admin/disputes.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
   -d "{\"dispute_id\":${DISPUTE_ID},\"resolution\":\"de novo\"}" | jq -r '.code')" = "dispute_not_found" ] \
  || fail "resolveu a mesma ocorrência duas vezes"

echo "== relatórios trazem mix de pagamento e o que depende de conferência =="
REP=$(curl -s "$BASE/admin/reports.php?days=30" "${AAUTH[@]}")
[ "$(echo "$REP" | jq -r '.period_days')" = "30" ] || fail "período errado: $REP"
[ "$(echo "$REP" | jq -r '.totals.gmv')" != "null" ] || fail "sem GMV: $REP"
[ "$(echo "$REP" | jq -r '.fraud.loss')" != "null" ] || fail "sem perda por fraude: $REP"
echo "$REP" | jq -e '.payment_mix' >/dev/null || fail "sem mix de pagamento: $REP"
echo "$REP" | jq -e '.balances.store_receivable' >/dev/null || fail "sem saldo a cobrar: $REP"

echo "== números do dia a dia: ticket médio, horas, dias, lojas, clientes -- conferidos contra o banco =="
PAID="status IN ('paid','preparing','ready','delivering','delivered') AND created_at >= now() - interval '30 days'"
read -r DB_ORDERS DB_TICKET DB_BUYERS < <(psql "$DATABASE_URL" -tA -F' ' -c \
  "SELECT count(*), COALESCE(round(SUM(total) / NULLIF(count(*), 0), 2)::text, 'null'), count(DISTINCT user_id) FROM orders WHERE ${PAID}")
[ "$(echo "$REP" | jq -r '.totals.orders')" = "$DB_ORDERS" ] || fail "pedidos do período não batem com o banco"
[ "$(echo "$REP" | jq -r 'if .totals.average_ticket == null then "null" else (.totals.average_ticket * 100 | round | tostring) end')" \
  = "$( [ "$DB_TICKET" = "null" ] && echo null || echo "$DB_TICKET" | awk '{printf "%d", $1 * 100 + 0.5}')" ] \
  || fail "ticket médio não bate com o banco ($DB_TICKET): $(echo "$REP" | jq -c '.totals')"
[ "$(echo "$REP" | jq -r '[.by_hour | length, (map(.orders) | add)] | join("|")')" = "24|${DB_ORDERS}" ] \
  || fail "pedidos por hora não somam o total: $(echo "$REP" | jq -c '.by_hour')"
[ "$(echo "$REP" | jq -r '[.by_weekday | length, (map(.orders) | add)] | join("|")')" = "7|${DB_ORDERS}" ] \
  || fail "pedidos por dia da semana não somam o total"
[ "$(echo "$REP" | jq -r '.customers.buyers')" = "$DB_BUYERS" ] || fail "clientes do período não batem com o banco"
echo "$REP" | jq -e '(.top_stores | length) <= 5 and ([.top_stores[].gmv] == ([.top_stores[].gmv] | sort | reverse))' >/dev/null \
  || fail "top lojas fora de ordem ou com mais de 5: $(echo "$REP" | jq -c '.top_stores')"
echo "$REP" | jq -e '.customers.returning <= .customers.buyers and .customers.first_time <= .customers.buyers and (.previous | has("average_ticket"))' >/dev/null \
  || fail "clientes que voltaram/novos inconsistentes: $(echo "$REP" | jq -c '.customers, .previous')"

echo "== política é VERSIONADA, nunca editada =="
BEFORE_V=$(psql "$DATABASE_URL" -tAc "SELECT MAX(version) FROM platform_policies")
NEW=$(curl -s -X POST "$BASE/admin/policy.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
  -d '{"cash_ceiling":500.00,"cancel_fee":12.00}')
[ "$(echo "$NEW" | jq -r '.policy.cash_ceiling')" = "500.00" ] || fail "teto não mudou: $NEW"
[ "$(echo "$NEW" | jq -r '.policy.cancel_fee')" = "12.00" ] || fail "taxa não mudou: $NEW"
[ "$(echo "$NEW" | jq -r '.policy.commission_bps')" = "800" ] || fail "campo não enviado deveria continuar igual: $NEW"
AFTER_V=$(psql "$DATABASE_URL" -tAc "SELECT MAX(version) FROM platform_policies")
[ "$AFTER_V" -gt "$BEFORE_V" ] || fail "não criou versão nova"
[ "$(psql "$DATABASE_URL" -tAc "SELECT cash_ceiling FROM platform_policies WHERE version=${BEFORE_V}")" = "300.00" ] \
  || fail "a versão anterior foi alterada — deveria ser imutável"

echo "== comissão fora da faixa é barrada =="
[ "$(curl -s -X POST "$BASE/admin/policy.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
   -d '{"commission_bps":9000}' | jq -r '.code')" = "invalid_commission" ] || fail "aceitou comissão de 90%"
[ "$(curl -s -X POST "$BASE/admin/policy.php" -H "Content-Type: application/json" "${AAUTH[@]}" \
   -d '{"enabled_methods":[]}' | jq -r '.code')" = "no_methods" ] || fail "desligou todas as formas de pagamento"

echo "== saúde do sistema (INFRA-02): erro não tratado vai pro banco, sem segredo, agrupado; o admin vê e resolve =="
TAG="monitoramento-$(php -r 'echo substr(str_shuffle(str_repeat("abcdefghijklmnopqrstuvwxyz", 2)), 0, 12);')"
# Um script PHP que quebra de verdade, como um worker do cron (o handler
# global registra). Arquivo, não php -r: o -r ignora set_exception_handler.
BOOM="$(mktemp /tmp/fuu-boom-XXXXXX.php)"
cat > "$BOOM" <<'PHP'
<?php
require getenv('FUU_ROOT') . '/lib/bootstrap.php';
throw new RuntimeException('falha ' . getenv('FUU_TAG') . ' tel 11987654321 token eyJhbGciOi.eyJzdWIi.assinatura url /x?token=abc');
PHP
boom() {
  FUU_ROOT="$ROOT" FUU_TAG="$TAG" DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" APP_ENV=development \
    php "$BOOM" >/dev/null 2>&1 || true
}
boom; boom
ROW=$(psql "$DATABASE_URL" -tAc "SELECT source || '|' || count || '|' || message FROM app_errors WHERE message LIKE '%${TAG}%'")
[ "$(echo "$ROW" | cut -d'|' -f1-2)" = "worker|2" ] || fail "erro não registrado/agrupado como esperado: $ROW"
echo "$ROW" | grep -qE "11987654321|eyJ|token=abc" && fail "segredo vazou pro registro de erro: $ROW"
HEALTH=$(curl -s "$BASE/admin/system_health.php" "${AAUTH[@]}")
EID=$(echo "$HEALTH" | jq -er --arg t "$TAG" '.errors[] | select(.message | contains($t)) | .id') || fail "o admin não vê o erro: $HEALTH"
[ "$(curl -s -X POST "$BASE/admin/system_health.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"id\":${EID},\"action\":\"resolve\"}" | jq -r '.resolved')" = "true" ] || fail "não marcou como resolvido"
curl -s "$BASE/admin/system_health.php" "${AAUTH[@]}" | jq -e --arg t "$TAG" '[.errors[] | select(.message | contains($t))] | length == 0' >/dev/null \
  || fail "erro resolvido continuou em aberto"
boom
[ "$(psql "$DATABASE_URL" -tAc "SELECT resolved_at IS NULL AND count = 3 FROM app_errors WHERE message LIKE '%${TAG}%'")" = "t" ] \
  || fail "o erro que voltou não reabriu"
rm -f "$BOOM"

echo "== segundo fator do admin (SEG-04): SMS sozinho não entra; código do app, uma vez só =="
totp_at() {  # totp_at SEGREDO DESLOCAMENTO(intervalos de 30 s)
  php -r 'require $argv[1]; echo totp_code($argv[2], intdiv(time(), 30) + (int) $argv[3]);' "$ROOT/lib/core/totp.php" "$1" "$2"
}
START=$(curl -s -X POST "$BASE/admin/totp.php" "${AAUTH[@]}" -H "Content-Type: application/json" -d '{"action":"start"}')
SECRET=$(echo "$START" | jq -er '.secret') || fail "start do segundo fator: $START"
echo "$START" | jq -er '.uri' | grep -q "^otpauth://totp/FUUdelivery" || fail "otpauth errado: $START"
[ "$(psql "$DATABASE_URL" -tAc "SELECT has_table_privilege('app_ro', 'admin_totp', 'SELECT')")" = "f" ] || fail "relatório (app_ro) enxerga o segredo"
[ "$(curl -s -X POST "$BASE/admin/totp.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d '{"action":"confirm","code":"000000"}' | jq -r '.code')" = "totp_invalid" ] || fail "confirmou com código errado"
[ "$(curl -s -X POST "$BASE/admin/totp.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"action\":\"confirm\",\"code\":\"$(totp_at "$SECRET" 0)\"}" | jq -r '.enabled')" = "true" ] || fail "não ligou o segundo fator"
SMS=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
VERIFY() { curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\",\"code\":\"${SMS}\"$1}"; }
[ "$(VERIFY '' | jq -r '.code')" = "totp_required" ] || fail "admin com segundo fator entrou só com o SMS"
[ "$(VERIFY ',"totp":"000000"' | jq -r '.code')" = "totp_invalid" ] || fail "código errado do app aceito"
# O intervalo atual foi gasto no confirmar; o seguinte vale (tolerância de 30 s).
NEXT=$(totp_at "$SECRET" 1)
VERIFY ",\"totp\":\"${NEXT}\"" | jq -e '.access_token' >/dev/null || fail "SMS + código certo do app não entrou"
SMS=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE}\"}" | jq -er '.dev_code')
[ "$(VERIFY ",\"totp\":\"${NEXT}\"" | jq -r '.code')" = "totp_invalid" ] || fail "o mesmo código do app valeu duas vezes"
psql_run -c "UPDATE admin_totp SET last_step = NULL"
[ "$(curl -s -X POST "$BASE/admin/totp.php" "${AAUTH[@]}" -H "Content-Type: application/json" \
  -d "{\"action\":\"disable\",\"code\":\"$(totp_at "$SECRET" 0)\"}" | jq -r '.enabled')" = "false" ] || fail "não desligou"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM audit_log WHERE action LIKE 'admin_totp.%'")" -ge 3 ] || fail "segundo fator sem audit_log"

echo "OK: painel da plataforma (Fase 12 + 10.5) passou no smoke test"
