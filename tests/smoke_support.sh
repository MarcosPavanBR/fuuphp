#!/usr/bin/env bash
# Smoke test do chat de três pontas (tela 14.2) e do resgate de cupom
# (migração 008, campo que estava na tela 3.3 desde a Fase 3 esperando
# backend).
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET
export APP_ENV=development
export MERCADOPAGO_MODE=fake

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8105
BASE="http://127.0.0.1:${PORT}/api/v1"
CITY="3550308"

fail() { echo "FALHOU: $1" >&2; cat /tmp/smoke-support-server.log >&2 2>/dev/null || true; exit 1; }
psql_run() { psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -q "$@"; }
gen_uuid() { php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/'; }
gen_cnpj() { php "$ROOT/tests/support/random_cnpj.php"; }
gen_cpf() { php "$ROOT/tests/support/random_cpf.php"; }

RESTAURANT_ID="$(gen_uuid)"
RIVAL_ID="$(gen_uuid)"
STAFF_USER_ID="$(gen_uuid)"
RIVAL_STAFF_ID="$(gen_uuid)"
ADMIN_ID="$(gen_uuid)"
CNPJ="$(gen_cnpj)"
RIVAL_CNPJ="$(gen_cnpj)"
CUPOM="FUU$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
CUPOM_LOJA="LOJA$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
STAMP="$(date +%s%N)"

echo "== semear duas lojas, um admin e dois cupons =="
psql_run <<SQL
INSERT INTO users (id, role, full_name, email) VALUES
  ('${STAFF_USER_ID}',  'restaurant_staff', 'Staff Support Smoke', 'staff-support-${STAMP}@test.com'),
  ('${RIVAL_STAFF_ID}', 'restaurant_staff', 'Staff Rival Support', 'rival-support-${STAMP}@test.com'),
  ('${ADMIN_ID}',       'admin',            'Admin Support Smoke', 'admin-support-${STAMP}@test.com');

INSERT INTO platform_policies (version, enabled_methods, created_by)
  SELECT (SELECT COALESCE(MAX(version), 0) + 1 FROM platform_policies),
         ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], id
  FROM users WHERE email = 'admin-support-${STAMP}@test.com';

INSERT INTO restaurants (id, name, cnpj, city_ibge_code, is_open, approved_at) VALUES
  ('${RESTAURANT_ID}', 'Support Smoke Restaurant', '${CNPJ}',       '${CITY}', true, now()),
  ('${RIVAL_ID}',      'Support Rival Restaurant', '${RIVAL_CNPJ}', '${CITY}', true, now());

INSERT INTO restaurant_payment_settings (restaurant_id, methods, min_order) VALUES
  ('${RESTAURANT_ID}', ARRAY['cash']::payment_method[], 0),
  ('${RIVAL_ID}',      ARRAY['cash']::payment_method[], 0);

INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash) VALUES
  ('${STAFF_USER_ID}',  'restaurant', '${RESTAURANT_ID}', '${CNPJ}',
   '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a'),
  ('${RIVAL_STAFF_ID}', 'restaurant', '${RIVAL_ID}', '${RIVAL_CNPJ}',
   '\$2y\$12\$pkP2tzQAJ7l.ofvtoHzJ4ezQFbNgN0d1PQWB4b6OQgnM.nUpP3S5a');

INSERT INTO menu_items (restaurant_id, name, price, category, available)
VALUES ('${RESTAURANT_ID}', 'Prato Support Smoke', 50.00, 'Pratos', true);

-- Cupom da plataforma (R$ 10 a partir de R$ 30) e cupom da loja rival.
INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer, budget_cap, starts_at, created_by) VALUES
  ('${CUPOM}',      'fixed', 10.00, 30.00, NULL,          'all', 'platform', 1000.00, now() - interval '1 day', '${ADMIN_ID}'),
  ('${CUPOM_LOJA}', 'fixed',  5.00,  0.00, '${RIVAL_ID}', 'all', 'store',    1000.00, now() - interval '1 day', '${ADMIN_ID}');
SQL
ITEM_ID=$(psql "$DATABASE_URL" -tAc "SELECT id FROM menu_items WHERE restaurant_id='${RESTAURANT_ID}'")

DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-support-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do
  curl -s -o /dev/null "http://127.0.0.1:${PORT}/api/v1/restaurants/show.php?id=${RESTAURANT_ID}" && break
  sleep 0.2
done

echo "== cliente entra e monta carrinho =="
PHONE="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Support Smoke\"}" | jq -er '.dev_code')
ACCESS=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}" | jq -er '.access_token')
AUTH=(-H "Authorization: Bearer $ACCESS")
ADDR_ID=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d '{"street":"Rua Support Smoke","city":"São Paulo","city_ibge_code":"3550308","state":"SP","postal_code":"01001000","lat":-23.5,"lng":-46.6,"is_default":true}' | jq -er '.id')
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null

echo "== cupom exige CPF no cadastro (um uso por CPF, não por conta) =="
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM}\"}" | jq -r '.code')" = "cpf_required" ] \
  || fail "cupom passou sem CPF no cadastro"

CPF="$(gen_cpf)"
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"cpf\":\"${CPF}\"}" >/dev/null

echo "== cupom inexistente e de outra loja são barrados =="
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"NAOEXISTE\"}" | jq -r '.code')" = "coupon_not_found" ] \
  || fail "cupom inventado foi aceito"
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM_LOJA}\"}" | jq -r '.code')" = "coupon_other_store" ] \
  || fail "cupom de outra loja foi aceito"

echo "== cupom válido desconta e o total recalcula sozinho (coluna gerada) =="
APPLY=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM}\"}")
[ "$(echo "$APPLY" | jq -r '.coupon.discount')" = "10" ] || fail "desconto errado: $APPLY"
[ "$(echo "$APPLY" | jq -r '.cart.discount')" = "10.00" ] || fail "desconto não foi gravado: $APPLY"
[ "$(echo "$APPLY" | jq -r '.cart.total')" = "40.00" ] || fail "total não recalculou (50 - 10): $APPLY"

echo "== código vazio remove o cupom =="
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"\"}" | jq -r '.cart.total')" = "50.00" ] \
  || fail "não removeu o cupom"
curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM}\"}" >/dev/null

echo "== checkout grava o resgate e consome o orçamento da campanha =="
[ "$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"change_for\":100.00,\"tip\":5000}" \
   | jq -r '.code')" = "invalid_tip" ] || fail "gorjeta de R\$ 5.000 aceita no fechamento"
ORDER_ID=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"change_for\":100.00,\"coupon_code\":\"${CUPOM}\"}" \
  | jq -er '.order.id') || fail "checkout com cupom falhou"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM coupon_redemptions WHERE order_id=${ORDER_ID}")" = "1" ] \
  || fail "resgate não foi gravado"
[ "$(psql "$DATABASE_URL" -tAc "SELECT spent FROM coupons WHERE code='${CUPOM}'")" = "10.00" ] \
  || fail "orçamento da campanha não foi consumido"

echo "== o mesmo CPF não usa o cupom duas vezes =="
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM}\"}" | jq -r '.code')" = "coupon_already_used" ] \
  || fail "cupom foi usado duas vezes pelo mesmo CPF"

echo "== trocar o CPF no perfil não libera o cupom de novo (um uso por CPF E por conta) =="
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"cpf\":\"$(gen_cpf)\"}" | jq -e '.' >/dev/null || fail "troca de CPF falhou"
[ "$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM}\"}" | jq -r '.code')" = "coupon_already_used" ] \
  || fail "trocando o CPF, a mesma conta usou o cupom de novo"

echo "== público da campanha vale no resgate: 'primeiro pedido' barra quem já pediu =="
CUPOM_NOVO="NOVO$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
CUPOM_FRETE="FRETE$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
psql_run <<SQL
INSERT INTO coupons (code, kind, value, min_order, restaurant_id, audience, payer, budget_cap, starts_at, created_by) VALUES
  ('${CUPOM_NOVO}',  'fixed',         5.00, 0, NULL, 'first_order', 'platform', 1000.00, now() - interval '1 day', '${ADMIN_ID}'),
  ('${CUPOM_FRETE}', 'free_delivery', 0.00, 0, NULL, 'all',         'platform', 1000.00, now() - interval '1 day', '${ADMIN_ID}');
-- Frete de R\$ 7 só nesta loja (sem coordenada, o frete é a tarifa base).
INSERT INTO policy_overrides (scope, scope_id, patch, reason, created_by)
VALUES ('restaurant', '${RESTAURANT_ID}', '{"delivery_base_fee": 7}', 'smoke: frete pro cupom de frete grátis', '${ADMIN_ID}');
SQL
# O primeiro pedido dela só "conta" depois de pago (pendente de pagamento não é pedido feito).
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" -H "X-Idempotency-Key: $(php -r 'echo bin2hex(random_bytes(16));' | sed -E 's/(.{8})(.{4})(.{4})(.{4})(.{12})/\1-\2-\3-\4-\5/')" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID}}" >/dev/null
NOT_FIRST=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM_NOVO}\"}")
[ "$(echo "$NOT_FIRST" | jq -r '.code')" = "coupon_audience" ] || fail "cupom de primeiro pedido aceito pra quem já pediu: $NOT_FIRST"

PHONE2="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE2=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Cliente Novo\"}" | jq -er '.dev_code')
AUTH2=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"code\":\"$CODE2\"}" | jq -er '.access_token')")
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${AUTH2[@]}" -d "{\"cpf\":\"$(gen_cpf)\"}" >/dev/null
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH2[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
FIRST=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH2[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM_NOVO}\"}")
[ "$(echo "$FIRST" | jq -r '.coupon.discount')" = "5" ] || fail "cupom de primeiro pedido recusado pra quem nunca pediu: $FIRST"

echo "== primeiro pedido é um por ENDEREÇO (NEG-01): conta nova com CPF novo na mesma casa é recusada =="
HOUSE='"street":"Rua da Casa Smoke","city":"São Paulo","city_ibge_code":"3550308","state":"SP"'
ADDR2=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH2[@]}" \
  -d "{${HOUSE},\"number\":\"77\",\"postal_code\":\"01002-000\",\"lat\":-23.5003,\"lng\":-46.6003}" | jq -er '.id') || fail "endereço da casa"
FIRST_ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH2[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR2},\"payment_method\":\"cash\",\"coupon_code\":\"${CUPOM_NOVO}\"}")
echo "$FIRST_ORDER" | jq -e '.order.id' >/dev/null || fail "o primeiro pedido de verdade na casa foi recusado: $FIRST_ORDER"
PHONE3="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
CODE3=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE3\",\"full_name\":\"Conta Nova Mesma Casa\"}" | jq -er '.dev_code')
AUTH3=(-H "Authorization: Bearer $(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" \
  -d "{\"purpose\":\"signup\",\"phone\":\"$PHONE3\",\"code\":\"$CODE3\"}" | jq -er '.access_token')")
curl -s -X POST "$BASE/profile/update.php" -H "Content-Type: application/json" "${AUTH3[@]}" -d "{\"cpf\":\"$(gen_cpf)\"}" >/dev/null
curl -s -X POST "$BASE/cart/add_item.php" -H "Content-Type: application/json" "${AUTH3[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"menu_item_id\":${ITEM_ID},\"quantity\":1}" >/dev/null
curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH3[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM_NOVO}\"}" | jq -e '.coupon' >/dev/null || fail "conta 3 nem aplicou o cupom"
# Mesmo CEP (sem hífen) e mesmo número (com espaço), com o pino ~170 m longe
# (fora da tolerância de 50 m): tem que pegar pelo CEP + número.
ADDR3=$(curl -s -X POST "$BASE/addresses/create.php" -H "Content-Type: application/json" "${AUTH3[@]}" \
  -d "{${HOUSE},\"number\":\" 77 \",\"postal_code\":\"01002000\",\"lat\":-23.5018,\"lng\":-46.6003}" | jq -er '.id') || fail "endereço da conta 3"
REUSE=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH3[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR3},\"payment_method\":\"cash\",\"coupon_code\":\"${CUPOM_NOVO}\"}")
[ "$(echo "$REUSE" | jq -r '.code')" = "coupon_address_used" ] || fail "primeiro pedido repetido na mesma casa passou: $REUSE"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM fraud_signals f JOIN users u ON u.id = f.user_id WHERE f.kind = 'address_reuse' AND u.phone = '${PHONE3}'")" = "1" ] \
  || fail "a tentativa não virou sinal de fraude"
ADMIN_PHONE_FS="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
psql_run -c "UPDATE users SET phone = '${ADMIN_PHONE_FS}' WHERE id = '${ADMIN_ID}'"
ACODE=$(curl -s -X POST "$BASE/auth/otp_request.php" -H "Content-Type: application/json" -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE_FS}\"}" | jq -er '.dev_code')
ATOKEN=$(curl -s -X POST "$BASE/auth/otp_verify.php" -H "Content-Type: application/json" -d "{\"purpose\":\"login\",\"phone\":\"${ADMIN_PHONE_FS}\",\"code\":\"${ACODE}\"}" | jq -er '.access_token')
FS=$(curl -s "$BASE/admin/fraud_signals.php?days=7" -H "Authorization: Bearer $ATOKEN")
[ "$(echo "$FS" | jq -r '.by_kind.address_reuse')" -ge 1 ] || fail "o admin não vê o sinal de fraude: $FS"
echo "$FS" | jq -e '[.signals[] | select(.kind == "address_reuse") | .user_phone | test("^[0-9]{6}…[0-9]{2}$")] | all' >/dev/null \
  || fail "telefone do sinal não saiu mascarado: $FS"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/fraud_signals.php" "${AUTH3[@]}")" = "403" ] || fail "cliente viu os sinais de fraude"

echo "== frete grátis desconta o frete calculado no checkout =="
FREE=$(curl -s -X POST "$BASE/cart/apply_coupon.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"code\":\"${CUPOM_FRETE}\"}")
[ "$(echo "$FREE" | jq -r '.coupon.applies_at')" = "checkout" ] || fail "cupom de frete não avisou que vale no checkout: $FREE"
FREE_ORDER=$(curl -s -X POST "$BASE/orders/checkout.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"restaurant_id\":\"${RESTAURANT_ID}\",\"address_id\":${ADDR_ID},\"payment_method\":\"cash\",\"coupon_code\":\"${CUPOM_FRETE}\"}")
[ "$(echo "$FREE_ORDER" | jq -r '.order.delivery_fee')" = "7.00" ] || fail "frete da loja não veio da política: $FREE_ORDER"
[ "$(echo "$FREE_ORDER" | jq -r '.order.discount')" = "7.00" ] || fail "frete grátis não descontou o frete: $FREE_ORDER"
[ "$(echo "$FREE_ORDER" | jq -r '.order.total')" = "50.00" ] || fail "total com frete grátis errado: $FREE_ORDER"
[ "$(psql "$DATABASE_URL" -tAc "SELECT amount FROM coupon_redemptions r JOIN coupons c ON c.id = r.coupon_id WHERE c.code='${CUPOM_FRETE}'")" = "7.00" ] \
  || fail "o resgate do frete grátis não registrou o valor"

echo "== chat: cliente manda, loja responde, evento do sistema entra na mesma linha =="
curl -s -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(gen_uuid)" "${AUTH[@]}" -d "{\"order_id\":${ORDER_ID}}" >/dev/null
STAFF_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')
SAUTH=(-H "Authorization: Bearer $STAFF_TOKEN")
RIVAL_TOKEN=$(curl -s -X POST "$BASE/auth/partner_login.php" -H "Content-Type: application/json" \
  -d "{\"kind\":\"restaurant\",\"login_code\":\"${RIVAL_CNPJ}\",\"secret\":\"senha123\"}" | jq -er '.access_token')

SENT=$(curl -s -X POST "$BASE/orders/messages.php" -H "Content-Type: application/json" "${AUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"body\":\"pode caprichar no molho?\"}")
[ "$(echo "$SENT" | jq -r '.message.sender_role')" = "customer" ] || fail "mensagem do cliente não gravou: $SENT"

REPLY=$(curl -s -X POST "$BASE/orders/messages.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"body\":\"claro! já vai sair\"}")
[ "$(echo "$REPLY" | jq -r '.message.sender_role')" = "store" ] || fail "resposta da loja não gravou: $REPLY"

curl -s -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" "${SAUTH[@]}" \
  -d "{\"order_id\":${ORDER_ID},\"to\":\"preparing\"}" >/dev/null

CHAT=$(curl -s "$BASE/orders/messages.php?id=${ORDER_ID}" "${AUTH[@]}")
[ "$(echo "$CHAT" | jq -r '.messages | length')" = "2" ] || fail "chat não trouxe as duas mensagens: $CHAT"
[ "$(echo "$CHAT" | jq -r '.me')" = "customer" ] || fail "chat não identificou quem está falando: $CHAT"
[ "$(echo "$CHAT" | jq -r '.events | length > 0')" = "true" ] || fail "eventos do pedido não entraram na linha do tempo: $CHAT"
[ "$(echo "$CHAT" | jq -r '.quick_replies | length > 0')" = "true" ] || fail "sem respostas rápidas: $CHAT"
[ "$(echo "$CHAT" | jq -r '.closed')" = "false" ] || fail "chat de pedido em preparo veio fechado: $CHAT"

echo "== ler marca as mensagens da outra ponta como lidas =="
[ "$(psql "$DATABASE_URL" -tAc "SELECT read_at IS NOT NULL FROM order_messages WHERE order_id=${ORDER_ID} AND sender_role='store'")" = "t" ] \
  || fail "mensagem da loja não foi marcada como lida"

echo "== loja de fora não entra no chat alheio =="
[ "$(curl -s "$BASE/orders/messages.php?id=${ORDER_ID}" -H "Authorization: Bearer $RIVAL_TOKEN" | jq -r '.code')" = "order_not_found" ] \
  || fail "loja rival leu o chat alheio"
[ "$(curl -s -X POST "$BASE/orders/messages.php" -H "Content-Type: application/json" -H "Authorization: Bearer $RIVAL_TOKEN" \
   -d "{\"order_id\":${ORDER_ID},\"body\":\"oi\"}" | jq -r '.code')" = "order_not_found" ] \
  || fail "loja rival escreveu no chat alheio"

echo "== mensagem vazia é barrada =="
[ "$(curl -s -X POST "$BASE/orders/messages.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"order_id\":${ORDER_ID},\"body\":\"   \"}" | jq -r '.code')" = "body_required" ] \
  || fail "mensagem vazia foi aceita"

echo "== 2 h depois da entrega o chat fecha pra escrita, mas continua legível =="
psql_run -c "SELECT advance_order(${ORDER_ID}, 'preparing', NULL, 'system');" >/dev/null 2>&1 || true
psql_run -c "SELECT advance_order(${ORDER_ID}, 'ready', NULL, 'system');" >/dev/null
psql_run -c "SELECT advance_order(${ORDER_ID}, 'delivering', NULL, 'system');" >/dev/null
psql_run -c "SELECT advance_order(${ORDER_ID}, 'delivered', NULL, 'system');" >/dev/null
psql_run -c "UPDATE order_events SET created_at = now() - interval '3 hours' WHERE order_id=${ORDER_ID} AND to_status='delivered'"
CLOSED=$(curl -s "$BASE/orders/messages.php?id=${ORDER_ID}" "${AUTH[@]}")
[ "$(echo "$CLOSED" | jq -r '.closed')" = "true" ] || fail "chat não fechou depois de 2 h: $CLOSED"
[ "$(echo "$CLOSED" | jq -r '.messages | length')" = "2" ] || fail "histórico sumiu junto com o fechamento: $CLOSED"
[ "$(curl -s -X POST "$BASE/orders/messages.php" -H "Content-Type: application/json" "${AUTH[@]}" \
   -d "{\"order_id\":${ORDER_ID},\"body\":\"ainda dá?\"}" | jq -r '.code')" = "chat_closed" ] \
  || fail "escreveu em chat fechado"

echo "OK: chat de três pontas (14.2) e cupons passaram no smoke test"
