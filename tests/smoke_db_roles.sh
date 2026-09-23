#!/usr/bin/env bash
# Smoke test dos papéis do banco em produção (go-live, passo 7).
#
# Em produção a API conecta como `app_rw`, não como dono das tabelas. Este
# teste conecta direto como app_rw (API_DATABASE_URL) e prova, no próprio
# banco, o que o PHP supõe:
#   - RLS da migração 009: sem dizer quem é, a sessão não vê pedido nenhum;
#     como "platform" vê todos; como "store" vê só a própria loja -- e não
#     consegue gravar pedido de outra;
#   - livro-razão e pontos são só de inserção (UPDATE/DELETE negados);
#   - app_rw não cria tabela (DDL é do migrator);
#   - a rota de saúde responde ok com a API como app_rw (banco + pg_cron).
# Roda depois das outras suítes: precisa de pedidos de mais de uma loja.
set -euo pipefail

: "${API_DATABASE_URL:?defina API_DATABASE_URL (conexão como app_rw)}"
: "${DATABASE_URL:?defina DATABASE_URL (dono, pra contar o total)}"

fail() { echo "FALHOU: $1" >&2; exit 1; }
as_app() { psql "$API_DATABASE_URL" -v ON_ERROR_STOP=1 -tAq -c "$1"; }
denied() { ! psql "$API_DATABASE_URL" -v ON_ERROR_STOP=1 -tAq -c "$1" >/dev/null 2>&1; }

TOTAL=$(psql "$DATABASE_URL" -tAc "SELECT count(*) FROM orders")
[ "$TOTAL" -gt 0 ] || fail "banco sem pedidos -- rode depois das outras suítes"
read -r STORE_A STORE_A_COUNT < <(psql "$DATABASE_URL" -tA -F' ' -c \
  "SELECT restaurant_id, count(*) FROM orders GROUP BY 1 ORDER BY 2 DESC LIMIT 1")
[ "$STORE_A_COUNT" -lt "$TOTAL" ] || fail "precisa de pedidos de mais de uma loja"
OTHER_ORDER=$(psql "$DATABASE_URL" -tAc "SELECT id FROM orders WHERE restaurant_id <> '${STORE_A}' LIMIT 1")

echo "== sem identificar a sessão, app_rw não vê pedido =="
[ "$(as_app "SELECT count(*) FROM orders")" = "0" ] || fail "RLS desligada: app_rw viu pedidos sem app.role"

echo "== como plataforma vê todos =="
[ "$(as_app "SELECT set_config('app.role','platform',false); SELECT count(*) FROM orders" | tail -1)" = "$TOTAL" ] \
  || fail "platform não viu todos os pedidos"

echo "== como loja vê só a própria =="
SCOPE="SELECT set_config('app.role','store',false), set_config('app.restaurant_id','${STORE_A}',false);"
[ "$(as_app "${SCOPE} SELECT count(*) FROM orders" | tail -1)" = "$STORE_A_COUNT" ] \
  || fail "loja viu pedidos de outra loja"
[ "$(as_app "${SCOPE} SELECT count(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.restaurant_id <> '${STORE_A}'" | tail -1)" = "0" ] \
  || fail "loja viu pagamento de outra loja"

echo "== loja não altera pedido de outra loja =="
[ "$(as_app "${SCOPE} WITH u AS (UPDATE orders SET updated_at = updated_at WHERE id = ${OTHER_ORDER} RETURNING 1) SELECT count(*) FROM u" | tail -1)" = "0" ] \
  || fail "loja conseguiu alterar pedido de outra loja"

echo "== livro-razão e pontos só de inserção; DDL negada =="
denied "UPDATE ledger_entries SET amount = amount WHERE false" || fail "app_rw pode dar UPDATE no livro-razão"
denied "DELETE FROM ledger_entries WHERE false" || fail "app_rw pode apagar do livro-razão"
denied "DELETE FROM loyalty_entries WHERE false" || fail "app_rw pode apagar pontos"
denied "CREATE TABLE smoke_should_not_exist (id int)" || fail "app_rw pode criar tabela"

echo "== rota de saúde, com a API como app_rw: banco e pg_cron respondendo =="
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATABASE_URL="$API_DATABASE_URL" JWT_SECRET=ci-test-secret APP_ENV=development \
  php -S 127.0.0.1:8141 -t "$ROOT" >/tmp/smoke-db-roles-server.log 2>&1 &
SERVER_PID=$!
trap '[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8141/api/v1/health.php && break; sleep 0.2; done
HEALTH=$(curl -s -w ' %{http_code}' http://127.0.0.1:8141/api/v1/health.php)
[ "${HEALTH##* }" = "200" ] && [ "$(echo "${HEALTH% *}" | jq -r '.status')" = "ok" ] || fail "health não respondeu ok: $HEALTH"

echo "OK: papéis do banco e RLS como a API supõe"
