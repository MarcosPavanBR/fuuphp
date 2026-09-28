#!/usr/bin/env bash
# Aplica ou reverte as migracoes numeradas em db/migrations, em transacao,
# usando o role migrator (ADR-002: migracoes rodam no CI, nunca no cPanel).
#
# Uso:
#   DATABASE_URL=postgres://migrator:...@host:5432/fuudelivery ./db/migrate.sh up
#   DATABASE_URL=postgres://migrator:...@host:5432/fuudelivery ./db/migrate.sh down       # desfaz a ultima
#   DATABASE_URL=postgres://migrator:...@host:5432/fuudelivery ./db/migrate.sh down 3      # desfaz as 3 ultimas
#   DATABASE_URL=... ./db/migrate.sh from 30   # producao: so as novas, a partir da 030
#
# Em producao so `from N` (docs/GO_LIVE.md). Nunca `up` (reaplica tudo) e
# nunca `down` (apaga dado).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/migrations" && pwd)"
: "${DATABASE_URL:?defina DATABASE_URL, ex: postgres://migrator@localhost:5432/fuudelivery}"

cmd="${1:-up}"

case "$cmd" in
  up)
    for f in "$DIR"/*.up.sql; do
      echo "==> aplicando $(basename "$f")"
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"
    done
    ;;
  from)
    # So as migracoes de numero >= N. Cada arquivo ja tem o proprio
    # BEGIN/COMMIT: se um falhar, ele nao fica pela metade e os seguintes
    # nao rodam (ON_ERROR_STOP + set -e).
    n="${2:?uso: $0 from N}"
    for f in "$DIR"/*.up.sql; do
      num=$((10#$(basename "$f" | cut -d_ -f1)))
      [ "$num" -ge "$n" ] || continue
      echo "==> aplicando $(basename "$f")"
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"
    done
    ;;
  down)
    n="${2:-1}"
    files=$(ls "$DIR"/*.down.sql | sort -r | head -n "$n")
    for f in $files; do
      echo "==> revertendo $(basename "$f")"
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"
    done
    ;;
  *)
    echo "uso: $0 [up | from N | down [n]]" >&2
    exit 1
    ;;
esac
