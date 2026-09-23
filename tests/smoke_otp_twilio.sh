#!/usr/bin/env bash
# Smoke test do envio de OTP pela Twilio (lib/messaging/otp_sender.php),
# contra uma Twilio falsa local (tests/support/fake_twilio.php) -- o código
# de curl é o mesmo que fala com a Twilio de verdade; só a base muda.
#
#  - canais: com a Twilio, só SMS (WhatsApp só com remetente aprovado);
#    pedir por e-mail dá 422 ANTES de criar conta;
#  - o pedido chega na Twilio com Basic auth SID:token, To em E.164 (+55...),
#    From e o texto com o código; o código funciona no login;
#  - Twilio recusando (400) ou demorando (timeout) = 502, o código é apagado
#    e nada do código vai pro log;
#  - com remetente de WhatsApp, "Receber por WhatsApp" vai como whatsapp:+55...;
#  - a trava de produção exige credencial e recusa base trocada.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
: "${JWT_SECRET:=ci-test-secret}"
export DATABASE_URL JWT_SECRET

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_PORT=8133; WA_PORT=8137; FAKE_PORT=8135
BASE="http://127.0.0.1:${API_PORT}/api/v1"
WA_BASE="http://127.0.0.1:${WA_PORT}/api/v1"
WORK="$(mktemp -d)"
export FAKE_TWILIO_LOG="$WORK/requests.jsonl" FAKE_TWILIO_MODE="$WORK/mode"
echo ok > "$FAKE_TWILIO_MODE"; : > "$FAKE_TWILIO_LOG"

SID="AC$(php -r 'echo bin2hex(random_bytes(16));')"
TOKEN="token-de-teste-$(date +%s)"
FROM="+5511955554444"

fail() { echo "FALHOU: $1" >&2; cat "$WORK"/api*.log >&2 2>/dev/null || true; exit 1; }

php -S "127.0.0.1:${FAKE_PORT}" "$ROOT/tests/support/fake_twilio.php" >"$WORK/fake.log" 2>&1 &
FAKE_PID=$!
twilio_env=(APP_ENV=testing OTP_SENDER=twilio TWILIO_ACCOUNT_SID="$SID" TWILIO_AUTH_TOKEN="$TOKEN"
  TWILIO_FROM="$FROM" TWILIO_API_BASE="http://127.0.0.1:${FAKE_PORT}")
env "${twilio_env[@]}" DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" \
  php -S "127.0.0.1:${API_PORT}" -t "$ROOT" >"$WORK/api.log" 2>&1 &
API_PID=$!
env "${twilio_env[@]}" TWILIO_WHATSAPP_FROM="+5511944443333" DATABASE_URL="${API_DATABASE_URL:-$DATABASE_URL}" \
  php -S "127.0.0.1:${WA_PORT}" -t "$ROOT" >"$WORK/api-wa.log" 2>&1 &
WA_PID=$!
trap 'kill "$FAKE_PID" "$API_PID" "$WA_PID" 2>/dev/null || true; rm -rf "$WORK"' EXIT
for i in $(seq 1 30); do
  curl -s -o /dev/null "$BASE/auth/channels.php" && curl -s -o /dev/null "$WA_BASE/auth/channels.php" && break
  sleep 0.2
done

post() { curl -s -X POST "$1" -H "Content-Type: application/json" -d "$2"; }
last_request() { tail -n 1 "$FAKE_TWILIO_LOG"; }

echo "== canais: Twilio sem remetente de WhatsApp = só SMS =="
CH=$(curl -s "$BASE/auth/channels.php")
[ "$(echo "$CH" | jq -c '.channels')" = '["sms"]' ] || fail "canais errados: $CH"
[ "$(echo "$CH" | jq -r '.email')" = "false" ] || fail "e-mail oferecido sem provedor de e-mail: $CH"

echo "== e-mail e WhatsApp indisponíveis dão 422 antes de criar conta =="
MAIL="twilio-$(date +%s%N)@test.com"
R=$(post "$BASE/auth/otp_request.php" "{\"purpose\":\"signup\",\"email\":\"$MAIL\",\"full_name\":\"Sem Email\"}")
[ "$(echo "$R" | jq -r '.code')" = "channel_unavailable" ] || fail "e-mail não foi recusado: $R"
[ "$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM users WHERE email='$MAIL'")" = "0" ] || fail "criou conta pra canal indisponível"
PHONE="119$(( RANDOM % 90000000 + 10000000 ))"
R=$(post "$BASE/auth/otp_request.php" "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Twilio\",\"channel\":\"whatsapp\"}")
[ "$(echo "$R" | jq -r '.code')" = "channel_unavailable" ] || fail "WhatsApp sem remetente não foi recusado: $R"
[ -s "$FAKE_TWILIO_LOG" ] && fail "chegou pedido na Twilio pra canal recusado"

echo "== SMS de cadastro chega na Twilio no formato certo =="
R=$(post "$BASE/auth/otp_request.php" "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"full_name\":\"Cliente Twilio\"}")
CODE=$(echo "$R" | jq -er '.dev_code') || fail "pedido de código falhou: $R"
REQ=$(last_request)
[ "$(echo "$REQ" | jq -r '.method')" = "POST" ] || fail "método errado: $REQ"
[ "$(echo "$REQ" | jq -r '.path')" = "/2010-04-01/Accounts/${SID}/Messages.json" ] || fail "caminho errado: $REQ"
[ "$(echo "$REQ" | jq -r '.auth')" = "Basic $(printf '%s:%s' "$SID" "$TOKEN" | base64 -w0)" ] || fail "Basic auth errada: $REQ"
[ "$(echo "$REQ" | jq -r '.form.To')" = "+55${PHONE}" ] || fail "To fora do E.164: $REQ"
[ "$(echo "$REQ" | jq -r '.form.From')" = "$FROM" ] || fail "From errado: $REQ"
echo "$REQ" | jq -r '.form.Body' | grep -q "seu código é ${CODE}\." || fail "texto sem o código: $REQ"
LOGIN=$(post "$BASE/auth/otp_verify.php" "{\"purpose\":\"signup\",\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")
echo "$LOGIN" | jq -e '.access_token' >/dev/null || fail "código enviado pela Twilio não entrou: $LOGIN"

echo "== Twilio recusando: 502, código apagado, nada do código no log =="
echo fail > "$FAKE_TWILIO_MODE"
BEFORE=$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM otp_codes o JOIN users u ON u.id = o.user_id WHERE u.phone='$PHONE'")
R=$(post "$BASE/auth/otp_request.php" "{\"purpose\":\"login\",\"phone\":\"$PHONE\"}")
[ "$(echo "$R" | jq -r '.code')" = "otp_send_failed" ] || fail "recusa da Twilio não virou 502: $R"
AFTER=$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM otp_codes o JOIN users u ON u.id = o.user_id WHERE u.phone='$PHONE'")
[ "$BEFORE" = "$AFTER" ] || fail "código não enviado ficou no banco ($BEFORE -> $AFTER)"
grep -q "otp twilio: HTTP 400, erro 21211" "$WORK/api.log" || fail "log não registrou o erro da Twilio"
FAILED_BODY=$(last_request | jq -r '.form.Body')
FAILED_CODE=$(echo "$FAILED_BODY" | grep -o '[0-9]\{6\}')
grep -q "$FAILED_CODE" "$WORK/api.log" && fail "o código apareceu no log do servidor"
grep -q "$TOKEN" "$WORK/api.log" && fail "o token da Twilio apareceu no log"

echo "== Twilio fora do ar (timeout): 502 em menos de 10 s =="
echo slow > "$FAKE_TWILIO_MODE"
START=$(date +%s)
R=$(post "$BASE/auth/otp_request.php" "{\"purpose\":\"login\",\"phone\":\"$PHONE\"}")
ELAPSED=$(( $(date +%s) - START ))
[ "$(echo "$R" | jq -r '.code')" = "otp_send_failed" ] || fail "timeout não virou 502: $R"
[ "$ELAPSED" -lt 10 ] || fail "esperou a Twilio ${ELAPSED}s (timeout não funcionou)"
echo ok > "$FAKE_TWILIO_MODE"

echo "== com remetente de WhatsApp aprovado, WhatsApp vai como whatsapp:+55... =="
[ "$(curl -s "$WA_BASE/auth/channels.php" | jq -c '.channels')" = '["sms","whatsapp"]' ] || fail "WhatsApp não apareceu nos canais"
PHONE2="119$(( RANDOM % 90000000 + 10000000 ))"
R=$(post "$WA_BASE/auth/otp_request.php" "{\"purpose\":\"signup\",\"phone\":\"$PHONE2\",\"full_name\":\"Cliente Zap\",\"channel\":\"whatsapp\"}")
echo "$R" | jq -e '.dev_code' >/dev/null || fail "WhatsApp falhou: $R"
REQ=$(last_request)
[ "$(echo "$REQ" | jq -r '.form.To')" = "whatsapp:+55${PHONE2}" ] || fail "To do WhatsApp errado: $REQ"
[ "$(echo "$REQ" | jq -r '.form.From')" = "whatsapp:+5511944443333" ] || fail "From do WhatsApp errado: $REQ"

echo "== trava de produção: credencial obrigatória, base da Twilio fixa =="
check() { env -i PATH="$PATH" HOME="$HOME" FUU_ENV_FILE=/dev/null APP_ENV=production "$@" \
  php -r 'define("FUU_SKIP_PRODUCTION_GUARD", 1); require $argv[1]; echo json_encode(otp_sender_problem());' "$ROOT/lib/bootstrap.php"; }
[ "$(check OTP_SENDER=twilio)" != "null" ] || fail "twilio sem credencial passou na trava"
[ "$(check OTP_SENDER=twilio TWILIO_ACCOUNT_SID="$SID" TWILIO_AUTH_TOKEN=x TWILIO_FROM=11999)" != "null" ] || fail "From fora do E.164 passou"
[ "$(check OTP_SENDER=twilio TWILIO_ACCOUNT_SID="$SID" TWILIO_AUTH_TOKEN=x TWILIO_FROM="$FROM" TWILIO_API_BASE=http://evil.test)" != "null" ] \
  || fail "base trocada passou na trava de produção"
[ "$(check OTP_SENDER=twilio TWILIO_ACCOUNT_SID="$SID" TWILIO_AUTH_TOKEN=x TWILIO_FROM="$FROM")" = "null" ] || fail "twilio completa recusada"
[ "$(check OTP_SENDER=twilio TWILIO_ACCOUNT_SID="$SID" TWILIO_AUTH_TOKEN=x TWILIO_MESSAGING_SERVICE_SID=MG123)" = "null" ] \
  || fail "Messaging Service sem From recusado"

echo "smoke_otp_twilio OK"
