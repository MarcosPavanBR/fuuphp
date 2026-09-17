#!/usr/bin/env bash
# Aplica ou reverte as migracoes numeradas em db/migrations, em transacao,
# usando o role migrator (ADR-002: migracoes rodam no CI, nunca no cPanel).
#
# Uso:
#   DATABASE_URL=postgres://migrator:...@host:5432/fuudelivery ./db/migrate.sh up
#   DATABASE_URL=postgres://migrator:...@host:5432/fuudelivery ./db/migrate.sh down       # desfaz a ultima
#   DATABASE_URL=postgres://migrator:...@host:5432/fuudelivery ./db/migrate.sh down 3      # desfaz as 3 ultimas
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
  down)
    n="${2:-1}"
    files=$(ls "$DIR"/*.down.sql | sort -r | head -n "$n")
    for f in $files; do
      echo "==> revertendo $(basename "$f")"
      psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"
    done
    ;;
  *)
    echo "uso: $0 [up|down] [n]" >&2
    exit 1
    ;;
esac
