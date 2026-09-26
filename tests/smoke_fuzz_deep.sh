#!/usr/bin/env bash
# Fuzz PROFUNDO: entrada ruim depois de achar o registro.
#
# O smoke_fuzz.sh manda lixo com ids que não existem: a rota responde 404 ou
# 422 logo na primeira linha e nunca chega no código que valida (ou não
# valida) o resto. Aqui cada rota recebe um corpo VÁLIDO -- loja, pedido,
# endereço, corrida e cupom que existem e são do usuário -- e cada campo é
# trocado, um de cada vez, por um valor ruim (lista, texto no lugar de
# número, número gigante, caractere nulo, texto de 10 mil letras...).
#
# Nenhuma troca pode dar 5xx. Foi este teste que achou o endereço aceitando
# latitude 999 e gravando "Array" (tests/support/fuzz_deep.php lista o que
# é mandado).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8151
LOG=/tmp/smoke-fuzz-deep-server.log
# shellcheck source=support/seed_world.sh
source "$ROOT/tests/support/seed_world.sh"

echo "== cada campo de cada rota, um valor ruim por vez =="
php "$ROOT/tests/support/fuzz_deep.php" || fail "alguma rota deu 5xx com entrada ruim (lista acima)"

echo "== nenhuma lista virou o texto \"Array\" no banco =="
# Não dar 500 não basta: (string) de uma lista no PHP vira "Array" e é
# gravado sem erro nenhum -- foi assim que um bairro chamado "Array" apareceu
# no onboarding. Toda coluna de texto (e lista de texto) do banco é varrida.
SCAN=$(psql "$DATABASE_URL" -tAc "
  SELECT string_agg(format('(SELECT %L AS col FROM %I WHERE %s LIMIT 1)', table_name || '.' || column_name, table_name,
         CASE WHEN data_type = 'ARRAY' THEN format('%L = ANY(%I)', 'Array', column_name)
              WHEN data_type = 'jsonb' THEN format('%I::text LIKE %L', column_name, '%"Array"%')
              ELSE format('%I::text = %L', column_name, 'Array') END), ' UNION ALL ')
    FROM information_schema.columns c
    JOIN information_schema.tables t USING (table_schema, table_name)
   WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE'
     AND (c.data_type IN ('text', 'character varying', 'character', 'jsonb') OR (c.data_type = 'ARRAY' AND c.udt_name = '_text'))")
ARRAYS=$(psql "$DATABASE_URL" -tAc "$SCAN" | sort -u | tr '\n' ' ')
[ -z "${ARRAYS// /}" ] || fail "coluna com o texto \"Array\" gravado (lista convertida em texto): $ARRAYS"

echo "== nenhum aviso do PHP no log do servidor =="
# Aviso ("Array to string conversion", "Undefined array key") é entrada mal
# tratada que não chegou a dar 500 -- e um robô enchia o log de produção com
# ele. Cada rota tem que recusar a entrada ruim antes de o PHP reclamar.
WARN=$(grep -a "PHP Warning\|PHP Notice\|PHP Deprecated" "$LOG" | sed -E 's/^.*PHP (Warning|Notice|Deprecated): +//' | sort | uniq -c | sort -rn | head -20 || true)
[ -z "$WARN" ] || fail "o PHP reclamou de entrada ruim:
$WARN"

echo "OK: fuzz profundo sem nenhum 5xx"
