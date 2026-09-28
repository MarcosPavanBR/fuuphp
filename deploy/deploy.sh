#!/usr/bin/env bash
# Publica uma versão na VPS (go-live, passo 7). Rodar como o usuário de
# deploy (dono de /srv/fuuphp), a partir de um clone qualquer do repositório:
#
#   bash deploy/deploy.sh <tag-ou-commit>
#
# Cada versão vai pra /srv/fuuphp/releases/<data>-<commit>; o site aponta
# pro symlink /srv/fuuphp/current, trocado de uma vez (atômico) só depois de:
#   1. php -l em todo PHP;
#   2. build do front (npx vite build);
#   3. a trava de produção passar com o .env real (bin/check_production.php) --
#      se faltar segredo, a versão NÃO entra no ar e a anterior continua.
# Voltar pra versão anterior: `bash deploy/deploy.sh --rollback`.
#
# Migração (auditoria DevSecOps de 27/09/2026): a versão NÃO entra no ar com
# migração pendente. O número da última aplicada fica em $BASE/schema_version.
#   - sem --migrate: havendo migração nova, o deploy para, lista quais são e
#     diz o comando. Nada muda no ar.
#   - com --migrate (`bash deploy/deploy.sh --migrate <rev>`): faz o backup
#     (/usr/local/sbin/fuuphp-backup), aplica só as novas, uma por vez, como
#     postgres (o pg_cron exige superusuário), e grava o número a cada uma
#     que passa. Precisa do sudoers do GO_LIVE: é um poder a mais pro
#     usuário de deploy, e por isso fica explícito e opcional.
# Nunca rodar `down` em produção.
set -euo pipefail

BASE="${FUU_BASE:-/srv/fuuphp}"
REPO="${FUU_REPO:-$BASE/repo.git}"
ENV_FILE="${FUU_ENV_FILE:-/etc/fuuphp/fuuphp.env}"
KEEP="${FUU_KEEP_RELEASES:-5}"
DB_NAME="${FUU_DB_NAME:-fuudelivery}"
VERSION_FILE="$BASE/schema_version"
MIGRATE=0

die() { echo "deploy: $*" >&2; exit 1; }

if [ "${1:-}" = "--rollback" ]; then
  current="$(readlink -f "$BASE/current")"
  previous="$(ls -1d "$BASE"/releases/*/ | sed 's#/$##' | grep -vx "$current" | tail -1)"
  [ -n "$previous" ] || die "não há versão anterior"
  ln -sfn "$previous" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
  sudo systemctl reload php8.4-fpm
  echo "deploy: voltou pra $(basename "$previous")"
  exit 0
fi

if [ "${1:-}" = "--migrate" ]; then
  MIGRATE=1
  shift
fi
REV="${1:?uso: deploy.sh [--migrate] <tag-ou-commit> | --rollback}"

# Maior número de migração numa versão (0 se não houver).
max_migration() {
  local n=0 f num
  for f in "$1"/db/migrations/*.up.sql; do
    [ -e "$f" ] || continue
    num=$((10#$(basename "$f" | cut -d_ -f1)))
    [ "$num" -gt "$n" ] && n=$num
  done
  echo "$n"
}

git --git-dir="$REPO" fetch --quiet --tags origin
COMMIT="$(git --git-dir="$REPO" rev-parse --verify "${REV}^{commit}")" || die "revisão desconhecida: $REV"
RELEASE="$BASE/releases/$(date +%Y%m%d%H%M%S)-${COMMIT:0:8}"

echo "deploy: preparando $REV ($COMMIT) em $RELEASE"
mkdir -p "$RELEASE"
git --git-dir="$REPO" archive "$COMMIT" | tar -x -C "$RELEASE"

echo "deploy: php -l"
find "$RELEASE/lib" "$RELEASE/api" "$RELEASE/bin" -name '*.php' -print0 \
  | xargs -0 -n1 -P4 php -l > /dev/null

echo "deploy: build do front"
# A prévia do link (og:image) precisa do endereço absoluto do site.
PUBLIC_ORIGIN="$(sed -n 's/^PUBLIC_ORIGIN=//p' "$ENV_FILE" | tail -1)"
case "$PUBLIC_ORIGIN" in
  https://*) ;;
  *) echo "deploy: aviso: PUBLIC_ORIGIN vazio ou sem https:// no .env -- a prévia do link sai sem imagem" >&2 ;;
esac
case "$PUBLIC_ORIGIN" in *'<'*) PUBLIC_ORIGIN='' ;; esac
( cd "$RELEASE/web" && npm ci --silent && VITE_PUBLIC_ORIGIN="${PUBLIC_ORIGIN%/}" npx vite build > /dev/null )
rm -rf "$RELEASE/web/node_modules"

echo "deploy: trava de produção com o .env real"
FUU_ENV_FILE="$ENV_FILE" php "$RELEASE/bin/check_production.php" \
  || { rm -rf "$RELEASE"; die "trava de produção recusou -- nada mudou no ar"; }

echo "deploy: migrações"
if [ ! -f "$VERSION_FILE" ]; then
  if [ -e "$BASE/current" ]; then
    # Primeira vez com o controle: a versão no ar tem as migrações dela.
    max_migration "$(readlink -f "$BASE/current")" > "$VERSION_FILE"
  else
    rm -rf "$RELEASE"
    die "primeira instalação: aplique as migrações (docs/GO_LIVE.md) e grave o número da última em $VERSION_FILE"
  fi
fi
APPLIED="$(cat "$VERSION_FILE")"
PENDING=()
for f in "$RELEASE"/db/migrations/*.up.sql; do
  [ "$((10#$(basename "$f" | cut -d_ -f1)))" -gt "$APPLIED" ] && PENDING+=("$f")
done
if [ "${#PENDING[@]}" -gt 0 ]; then
  names="$(for f in "${PENDING[@]}"; do basename "$f"; done | tr '\n' ' ')"
  if [ "$MIGRATE" != "1" ]; then
    rm -rf "$RELEASE"
    die "migração pendente ($names). Nada mudou no ar. Rode: bash deploy/deploy.sh --migrate $REV"
  fi
  echo "deploy: backup antes da migração"
  sudo /usr/local/sbin/fuuphp-backup || { rm -rf "$RELEASE"; die "backup falhou -- migração não rodou, nada mudou no ar"; }
  for f in "${PENDING[@]}"; do
    echo "deploy: aplicando $(basename "$f")"
    # Cada arquivo tem o próprio BEGIN/COMMIT: se falhar, não fica pela
    # metade, as seguintes não rodam e a versão nova não entra no ar.
    sudo -u postgres psql -q -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$f" \
      || { rm -rf "$RELEASE"; die "$(basename "$f") falhou -- a versão no ar continua; as anteriores a ela ficaram aplicadas (veja $VERSION_FILE)"; }
    echo "$((10#$(basename "$f" | cut -d_ -f1)))" > "$VERSION_FILE"
  done
fi

# O que não vai pra produção.
rm -rf "$RELEASE/tests" "$RELEASE/.github" "$RELEASE/docker-compose.yml"

ln -sfn "$RELEASE" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
# Recarrega o PHP-FPM pra limpar o OPcache (senão ele serve o código antigo).
sudo systemctl reload php8.4-fpm
echo "deploy: no ar -> $(basename "$RELEASE")"

# Guarda as últimas $KEEP versões pro rollback.
ls -1d "$BASE"/releases/*/ | sed 's#/$##' | head -n -"$KEEP" | xargs -r rm -rf --
