#!/usr/bin/env bash
# Smoke test do dinheiro que chega fora de hora (decisão 48).
#
# O Mercado Pago responde depois da tela: o antifraude segura o cartão em
# análise, o Pix é pago quando o cliente quiser, o aviso chega repetido, fora
# de ordem ou ao mesmo tempo que outro, e a nossa requisição pode cair depois
# de a cobrança ter sido feita. Em nenhum desses casos o pedido pode andar
# duas vezes, e nenhum centavo pode ficar sem destino:
#
#   1. cartão em análise: o pedido espera (202), sem segundo cartão;
#   2. cancelar mata a cobrança aberta no MP antes do pedido;
#   3. pago no meio do cancelamento: o pagamento vale, o cancelamento não;
#   4. MP fora do ar: o pedido não é cancelado às cegas (503);
#   5. seis avisos iguais ao mesmo tempo: o pedido anda uma vez;
#   6. aviso velho, fora de ordem: não desfaz o que já andou;
#   7. dinheiro que chega sem pedido pra pagar: estorno integral, na fila do
#      admin, sem mexer no livro nem no status do pedido;
#   8. cobrança órfã (o MP cobrou, nós não gravamos): achada pelo
#      external_reference;
#   9. seis cobranças do mesmo pedido ao mesmo tempo, com chaves diferentes e
#      a demora da rede: uma cobrança só.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8155
LOG=/tmp/smoke-late-payment-server.log
# shellcheck source=support/seed_world.sh
source "$ROOT/tests/support/seed_world.sh"

q() { psql "$DATABASE_URL" -tAc "$1"; }
psql_run -c "UPDATE restaurant_payment_settings SET methods = array_append(methods, 'pix_auto'::payment_method) WHERE restaurant_id = '${STORE}'"
hook() {  # hook REF STATUS [EXTRA_JSON] -> corpo
  curl -s -X POST "$BASE/payments/webhook_mercadopago.php" -H "Content-Type: application/json" \
    -d "{\"type\":\"payment\",\"data\":{\"id\":\"$1\"},\"status\":\"$2\"${3:-}}"
}
pay() {  # pay JSON -> corpo, e o status HTTP na última linha
  curl -s -w '\n%{http_code}' -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $CUST" -H "X-Idempotency-Key: $(gen_uuid)" -d "$1"
}
cancel() {  # cancel PEDIDO -> corpo, e o status HTTP na última linha
  curl -s -w '\n%{http_code}' -X POST "$BASE/orders/status.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $CUST" -d "{\"order_id\":$1,\"to\":\"cancelled\",\"reason\":\"desisti\"}"
}
order_status() { q "SELECT status FROM orders WHERE id = $1"; }
charges() { q "SELECT string_agg(status || coalesce('/' || status_detail, ''), ',' ORDER BY id) FROM payments WHERE order_id = $1"; }
late_refunds() { q "SELECT count(*) FROM refunds WHERE order_id = $1 AND cause = 'late_payment'"; }
race() {  # race N COMANDO... -> códigos HTTP ordenados, um por linha
  local out; out=$(mktemp); local pids=()
  for _ in $(seq 1 "$1"); do ("${@:2}" >> "$out") & pids+=($!); done
  wait "${pids[@]}"; sort "$out"; rm -f "$out"
}

echo "== 1. cartão em análise: o pedido espera (202), sem segundo cartão =="
O=$(checkout mp_card)
R=$(pay "{\"order_id\":$O,\"card_token\":\"CONT1234\",\"installments\":1}")
[ "$(echo "$R" | tail -1)" = "202" ] || fail "cartão em análise não respondeu 202: $R"
[ "$(echo "$R" | head -1 | jq -r .in_review)" = "true" ] || fail "resposta sem in_review: $R"
[ "$(order_status "$O")" = "pending_payment" ] || fail "cartão em análise recusou o pedido na hora"
[ "$(pay "{\"order_id\":$O,\"card_token\":\"APRO5678\",\"installments\":1}" | head -1 | jq -r .code)" = "payment_in_review" ] \
  || fail "aceitou um segundo cartão com o primeiro em análise"
REF=$(q "SELECT provider_ref FROM payments WHERE order_id = $O")
hook "$REF" approved >/dev/null
[ "$(order_status "$O")" = "paid" ] || fail "a aprovação da análise não pagou o pedido"

echo "== 2. cancelar mata a cobrança aberta antes do pedido =="
O=$(checkout mp_card)
pay "{\"order_id\":$O,\"card_token\":\"CONT1234\",\"installments\":1}" >/dev/null
[ "$(cancel "$O" | tail -1)" = "200" ] || fail "não cancelou o pedido com cartão em análise"
[ "$(charges "$O")" = "rejected/cancelled_with_order" ] || fail "a cobrança ficou aberta no cancelamento: $(charges "$O")"

echo "== 7a. ...e se o MP aprovar assim mesmo, o dinheiro volta inteiro =="
REF=$(q "SELECT provider_ref FROM payments WHERE order_id = $O")
H=$(hook "$REF" approved)
[ "$(echo "$H" | jq -r .late_refund)" != "null" ] || fail "pagamento tardio sem estorno: $H"
[ "$(order_status "$O")" = "cancelled" ] || fail "o pagamento tardio mexeu no pedido cancelado"
[ "$(charges "$O")" = "refunded/late_payment" ] || fail "cobrança tardia não ficou devolvida: $(charges "$O")"
[ "$(q "SELECT amount || ' ' || fee || ' ' || payer || ' ' || state FROM refunds WHERE order_id = $O")" = "$(q "SELECT total FROM orders WHERE id = $O") 0.00 platform pending" ] \
  || fail "estorno tardio não é integral, sem taxa, na fila: $(q "SELECT row_to_json(r) FROM refunds r WHERE order_id = $O")"
hook "$REF" approved >/dev/null
[ "$(late_refunds "$O")" = "1" ] || fail "aviso repetido criou outro estorno"

echo "== 3. pago no meio do cancelamento: o pagamento vale =="
O=$(checkout pix_auto)
pay "{\"order_id\":$O}" >/dev/null
psql_run -c "UPDATE payments SET provider_ref = 'PAID' || provider_ref WHERE order_id = $O"
R=$(cancel "$O")
[ "$(echo "$R" | tail -1)" = "409" ] && [ "$(echo "$R" | head -1 | jq -r .code)" = "payment_approved" ] || fail "cancelou por cima de um pagamento aprovado: $R"
[ "$(order_status "$O")" = "paid" ] || fail "o pagamento aprovado no cancelamento não pagou o pedido"
R=$(cancel "$O")
[ "$(echo "$R" | head -1 | jq -r .refund.amount)" = "$(q "SELECT total FROM orders WHERE id = $O")" ] || fail "cancelar o pedido pago não devolveu tudo: $R"

echo "== 4. MP fora do ar: o pedido não é cancelado às cegas =="
O=$(checkout pix_auto)
pay "{\"order_id\":$O}" >/dev/null
psql_run -c "UPDATE payments SET provider_ref = 'DOWN' || provider_ref WHERE order_id = $O"
R=$(cancel "$O")
[ "$(echo "$R" | tail -1)" = "503" ] || fail "cancelou sem conseguir matar a cobrança: $R"
[ "$(order_status "$O")" = "pending_payment" ] && [ "$(charges "$O")" = "in_process" ] || fail "o 503 mexeu no pedido: $(order_status "$O") $(charges "$O")"
echo "$R" | head -1 | jq -e 'has("detail") | not' >/dev/null || fail "o detalhe do gateway vazou na resposta: $R"

echo "== 5. seis avisos iguais ao mesmo tempo: o pedido anda uma vez =="
O=$(checkout pix_auto)
REF=$(pay "{\"order_id\":$O}" | head -1 | jq -r .payment.provider_ref)
CODES=$(race 6 curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/payments/webhook_mercadopago.php" \
  -H "Content-Type: application/json" -d "{\"type\":\"payment\",\"data\":{\"id\":\"$REF\"},\"status\":\"approved\"}")
[ "$(echo "$CODES" | sort -u)" = "200" ] || fail "aviso simultâneo deu erro: $CODES"
[ "$(q "SELECT count(*) FROM order_events WHERE order_id = $O AND to_status = 'paid'")" = "1" ] || fail "o pedido andou mais de uma vez"

echo "== 6. aviso velho, fora de ordem: não desfaz o que já andou =="
[ "$(hook "$REF" pending | jq -r .changed)" = "false" ] || fail "aviso 'pending' atrasado voltou o pagamento pra análise"
cancel "$O" >/dev/null
[ "$(charges "$O")" = "refunded" ] || fail "cancelamento do pedido pago não devolveu a cobrança"
[ "$(hook "$REF" approved | jq -r .changed)" = "false" ] || fail "aviso 'approved' atrasado desfez o estorno"
[ "$(charges "$O")" = "refunded" ] && [ "$(late_refunds "$O")" = "0" ] || fail "aviso atrasado mexeu no estorno: $(charges "$O")"

echo "== 7b. o admin envia o estorno tardio: o livro e o pedido não mudam =="
LATE=$(q "SELECT id FROM refunds WHERE cause = 'late_payment' ORDER BY id DESC LIMIT 1")
LO=$(q "SELECT order_id FROM refunds WHERE id = $LATE")
BOOKED=$(q "SELECT count(*) FROM ledger_entries WHERE order_id = $LO")
[ "$(post "$ADMIN" admin/refunds.php "{\"refund_id\":$LATE,\"action\":\"wallet_offer\"}" | jq -r .code)" = "late_payment_returns_to_payer" ] \
  || fail "pagamento tardio virou oferta de crédito"
R=$(post "$ADMIN" admin/refunds.php "{\"refund_id\":$LATE,\"action\":\"refund\"}")
[ "$(echo "$R" | jq -r .refund.state)" = "sent" ] || fail "o admin não conseguiu enviar o estorno tardio: $R"
[ "$(echo "$R" | jq -r .order.status)" = "cancelled" ] || fail "o estorno tardio mudou o status do pedido: $R"
[ "$(q "SELECT count(*) FROM ledger_entries WHERE order_id = $LO")" = "$BOOKED" ] || fail "o estorno tardio entrou no livro"
[ "$(post "$ADMIN" admin/refunds.php "{\"refund_id\":$LATE,\"action\":\"execute\"}" | jq -r .refund.state)" = "done" ] \
  || fail "o executor não devolveu o pagamento tardio pelo Mercado Pago"

echo "== 8. cobrança órfã: achada pelo external_reference =="
O=$(checkout mp_card)
pay "{\"order_id\":$O,\"card_token\":\"APRO1\",\"installments\":1}" >/dev/null
TOTAL=$(q "SELECT total FROM orders WHERE id = $O")
H=$(hook "orfa_$O" approved ",\"external_reference\":\"$O\",\"transaction_amount\":$TOTAL")
[ "$(echo "$H" | jq -r .late_refund)" != "null" ] || fail "cobrança em dobro sem estorno: $H"
[ "$(order_status "$O")" = "paid" ] || fail "a cobrança em dobro mexeu no pedido pago"
[ "$(hook "orfa_sem_ref_$O" approved | jq -r .known)" = "false" ] || fail "aviso sem referência inventou um pedido"
cancel "$O" >/dev/null
[ "$(q "SELECT count(*) FROM refunds WHERE order_id = $O AND cause = 'customer_cancel'")" = "1" ] \
  || fail "o estorno tardio escondeu o estorno do cancelamento (idempotência por pedido)"
O=$(checkout pix_auto)
TOTAL=$(q "SELECT total FROM orders WHERE id = $O")
hook "orfa2_$O" approved ",\"external_reference\":\"$O\",\"transaction_amount\":$TOTAL" >/dev/null
[ "$(order_status "$O")" = "paid" ] || fail "a cobrança órfã não pagou o pedido que a esperava"

echo "== 9. seis cobranças ao mesmo tempo, com a demora da rede: uma só =="
O=$(checkout mp_card)
# Cada chamada com a SUA chave (clique duplo, duas abas): a mesma chave
# testaria a idempotência, não a trava. SLOW faz o MP simulado demorar meio
# segundo, como a rede de verdade.
pay_slow() {
  curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/payments/pay.php" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $CUST" -H "X-Idempotency-Key: $(gen_uuid)" -d "{\"order_id\":$O,\"card_token\":\"SLOW1\",\"installments\":1}"
}
CODES=$(race 6 pay_slow)
[ "$(echo "$CODES" | grep -c '^200$')" = "1" ] && [ "$(echo "$CODES" | grep -c '^409$')" = "5" ] || fail "cobrança simultânea: $(echo "$CODES" | tr '\n' ' ')"
[ "$(q "SELECT count(*) FROM payments WHERE order_id = $O")" = "1" ] || fail "o mesmo pedido foi cobrado mais de uma vez"

echo "== nenhum aviso do PHP no log =="
# Só aviso de arquivo do projeto conta: aviso de ambiente ("in Unknown on
# line 0", como a extensão de cobertura desligando o JIT) não é defeito nosso.
if grep -E "PHP (Warning|Notice|Deprecated|Fatal)" "$LOG" | grep -F "$ROOT/"; then fail "aviso do PHP no log"; fi

echo "OK: dinheiro fora de hora sempre tem destino, e o pedido anda uma vez só"
