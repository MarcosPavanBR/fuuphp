#!/usr/bin/env bash
# Smoke test da trava de produção (lib/core/production_guard.php):
# "produção falha fechado".
#
#  - APP_ENV=production com .env incompleto: CLI sai com 1 listando os
#    motivos; HTTP responde 503 sem detalhe nenhum pro cliente;
#  - com tudo configurado menos o OTP, só o OTP segura; com a Twilio
#    configurada, a produção sobe;
#  - staging aceita token de sandbox (TEST-), produção não;
#  - webhook sem segredo: aceito só fora de produção/homologação;
#  - dev_code do OTP só em development/testing.
set -euo pipefail

: "${DATABASE_URL:?defina DATABASE_URL apontando para um banco já migrado}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8125
STAMP="$(date +%s%N)"
SAFE="/tmp/fuu-guard-${STAMP}"          # "fora do projeto", como na VPS
mkdir -p "$SAFE"/{proofs,menu,courier_docs,vapid}

fail() { echo "FALHOU: $1" >&2; exit 1; }

# Ambiente limpo: nada vem do .env local.
base_env=(env -i PATH="$PATH" HOME="$HOME" FUU_ENV_FILE=/dev/null DATABASE_URL="$DATABASE_URL")
full_env=("${base_env[@]}"
  JWT_SECRET="$(php -r 'echo bin2hex(random_bytes(40));')"
  MERCADOPAGO_MODE=live MERCADOPAGO_ACCESS_TOKEN=APP-USR-123 MERCADOPAGO_PUBLIC_KEY=APP-USR-pub
  MERCADOPAGO_WEBHOOK_SECRET=segredo PUSH_MODE=live VAPID_PRIVATE_KEY_FILE="$SAFE/vapid/private.pem"
  ALLOWED_ORIGIN= PROOF_STORAGE_DIR="$SAFE/proofs" MENU_PHOTO_DIR="$SAFE/menu" COURIER_DOC_DIR="$SAFE/courier_docs")
boot='require $argv[1]; echo "SUBIU\n";'

echo "== production com .env vazio: CLI recusa e lista os motivos =="
set +e
OUT=$("${base_env[@]}" APP_ENV=production php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1); CODE=$?
set -e
[ "$CODE" = "1" ] || fail "subiu em produção sem configuração (saída $CODE): $OUT"
for reason in "JWT_SECRET" "Mercado Pago não está em modo live" "MERCADOPAGO_WEBHOOK_SECRET vazio" \
              "PUSH_MODE não é live" "provedor de OTP" "ALLOWED_ORIGIN não definida"; do
  echo "$OUT" | grep -q "$reason" || fail "motivo ausente: '$reason' em: $OUT"
done
echo "$OUT" | grep -q "SUBIU" && fail "o código seguiu depois da trava"

echo "== HTTP: 503 sem detalhe nenhum pro cliente =="
"${base_env[@]}" APP_ENV=production php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/smoke-guard-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$SAFE"' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:${PORT}/" && break; sleep 0.2; done
RESP=$(curl -s -w '|%{http_code}' "http://127.0.0.1:${PORT}/api/v1/push/config.php")
[ "${RESP##*|}" = "503" ] || fail "API respondeu sem a trava: $RESP"
[ "$(echo "${RESP%|*}" | jq -r '.code')" = "service_unavailable" ] || fail "corpo errado: $RESP"
echo "${RESP%|*}" | grep -q -i "jwt\|mercado\|vapid\|otp" && fail "o 503 vazou detalhe da configuração: $RESP"
grep -q "production_guard: JWT_SECRET" /tmp/smoke-guard-server.log || fail "motivo não foi pro log do servidor"

echo "== tudo configurado menos o OTP: só o provedor de OTP segura =="
"${full_env[@]}" FUU_SKIP_PRODUCTION_GUARD=1 APP_ENV=production VAPID_PRIVATE_KEY_FILE="$SAFE/vapid/private.pem" \
  php "$ROOT/bin/generate_vapid_keys.php" >/dev/null 2>&1 || fail "gerar a chave VAPID em produção falhou"
set +e
OUT=$("${full_env[@]}" APP_ENV=production php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1); CODE=$?
set -e
[ "$CODE" = "1" ] || fail "subiu sem provedor de OTP"
[ "$(echo "$OUT" | grep -c '^  - ')" = "1" ] || fail "com tudo configurado sobrou mais que o OTP: $OUT"
echo "$OUT" | grep -q "provedor de OTP não configurado" || fail "o motivo restante não é o OTP: $OUT"
set +e
OUT=$("${full_env[@]}" APP_ENV=production OTP_SENDER=log php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1)
set -e
echo "$OUT" | grep -q "OTP_SENDER=log só vale em development" || fail "OTP 'log' aceito em produção: $OUT"

echo "== tudo configurado, com a Twilio: produção SOBE =="
OUT=$("${full_env[@]}" APP_ENV=production OTP_SENDER=twilio TWILIO_ACCOUNT_SID="AC$(php -r 'echo bin2hex(random_bytes(16));')" \
      TWILIO_AUTH_TOKEN=token-de-teste TWILIO_FROM=+5511955554444 php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1) \
  || fail "produção completa não subiu: $OUT"
[ "$OUT" = "SUBIU" ] || fail "produção completa não subiu: $OUT"

echo "== sandbox: TEST- recusado em produção, aceito em staging =="
set +e
OUT=$("${full_env[@]}" APP_ENV=production MERCADOPAGO_ACCESS_TOKEN=TEST-abc php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1)
set -e
echo "$OUT" | grep -q "sandbox (TEST-) em produção" || fail "token de sandbox aceito em produção"
set +e
OUT=$("${full_env[@]}" APP_ENV=staging MERCADOPAGO_ACCESS_TOKEN=TEST-abc php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1)
set -e
echo "$OUT" | grep -q "TEST-" && fail "staging recusou token de sandbox: $OUT"

echo "== pasta de arquivo dentro do projeto é recusada =="
set +e
OUT=$("${full_env[@]}" APP_ENV=production PROOF_STORAGE_DIR=storage/proofs php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1)
set -e
echo "$OUT" | grep -q "comprovantes dentro do projeto\|pasta de comprovantes não existe" || fail "storage dentro do projeto aceito: $OUT"

echo "== webhook sem segredo: só fora de produção/homologação =="
check_sig='define("FUU_SKIP_PRODUCTION_GUARD", 1); require $argv[1]; echo mp_verify_webhook_signature("ts=1,v1=x", "r", "1") ? "aceitou" : "recusou";'
[ "$("${base_env[@]}" APP_ENV=staging php -r "$check_sig" "$ROOT/lib/bootstrap.php")" = "recusou" ] || fail "staging aceitou webhook sem segredo"
[ "$("${base_env[@]}" APP_ENV=production php -r "$check_sig" "$ROOT/lib/bootstrap.php")" = "recusou" ] || fail "produção aceitou webhook sem segredo"
[ "$("${base_env[@]}" APP_ENV=development php -r "$check_sig" "$ROOT/lib/bootstrap.php")" = "aceitou" ] || fail "desenvolvimento deixou de aceitar (modo fake)"

echo "== dev_code: development/testing sim; staging não =="
check_dev='define("FUU_SKIP_PRODUCTION_GUARD", 1); require $argv[1]; echo in_array(app_env(), OTP_DEV_ENVS, true) ? "mostra" : "esconde";'
[ "$("${base_env[@]}" APP_ENV=staging php -r "$check_dev" "$ROOT/lib/bootstrap.php")" = "esconde" ] || fail "staging mostraria o dev_code"
[ "$("${base_env[@]}" APP_ENV=testing php -r "$check_dev" "$ROOT/lib/bootstrap.php")" = "mostra" ] || fail "testing esconderia o dev_code"

echo "== o modelo de produção sem preencher (<...>) é recusado =="
set +e
OUT=$(env -i PATH="$PATH" HOME="$HOME" FUU_ENV_FILE="$ROOT/deploy/env/fuuphp.env.example" php -r "$boot" "$ROOT/lib/bootstrap.php" 2>&1); CODE=$?
set -e
[ "$CODE" = "1" ] || fail "modelo de produção sem preencher subiu: $OUT"
for key in DATABASE_URL JWT_SECRET MERCADOPAGO_ACCESS_TOKEN MERCADOPAGO_PUBLIC_KEY MERCADOPAGO_WEBHOOK_SECRET TWILIO_ACCOUNT_SID TWILIO_AUTH_TOKEN TWILIO_FROM VAPID_SUBJECT; do
  echo "$OUT" | grep -q "$key ainda com o marcador" || fail "marcador de $key não foi recusado: $OUT"
done

echo "== bin/check_production.php: papel do banco que ignora RLS é recusado =="
# O guard já foi provado acima; aqui ele é pulado (auto_prepend) pra chegar
# nas conferências de banco, que só o check_production faz.
SKIP="$SAFE/skip_guard.php"
echo '<?php define("FUU_SKIP_PRODUCTION_GUARD", 1);' > "$SKIP"
set +e
OUT=$("${base_env[@]}" APP_ENV=staging php -d auto_prepend_file="$SKIP" "$ROOT/bin/check_production.php" 2>&1); CODE=$?
set -e
if [ "$(psql "$DATABASE_URL" -tAc "SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = current_user")" = "t" ]; then
  [ "$CODE" = "1" ] && echo "$OUT" | grep -q "ignora RLS" || fail "check_production aceitou superusuário: $OUT"
fi
if [ -n "${API_DATABASE_URL:-}" ]; then
  OUT=$(env -i PATH="$PATH" HOME="$HOME" FUU_ENV_FILE=/dev/null DATABASE_URL="$API_DATABASE_URL" APP_ENV=staging \
        php -d auto_prepend_file="$SKIP" "$ROOT/bin/check_production.php" 2>&1) || fail "check_production recusou app_rw: $OUT"
  echo "$OUT" | grep -q "banco como app_rw" || fail "check_production não disse o papel: $OUT"
fi
set +e
OUT=$(env -i PATH="$PATH" HOME="$HOME" FUU_ENV_FILE=/dev/null DATABASE_URL="postgres://ninguem:x@127.0.0.1:1/nada" APP_ENV=staging \
      php -d auto_prepend_file="$SKIP" "$ROOT/bin/check_production.php" 2>&1); CODE=$?
set -e
[ "$CODE" = "1" ] && echo "$OUT" | grep -q "banco inacessível" || fail "check_production não recusou banco fora do ar: $OUT"

echo "smoke_production_guard OK"
