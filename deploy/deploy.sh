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
# Migração NÃO roda aqui: é passo manual e consciente (docs/GO_LIVE.md,
# "Migrações em produção"). Nunca rodar `down` em produção.
set -euo pipefail

BASE="${FUU_BASE:-/srv/fuuphp}"
REPO="${FUU_REPO:-$BASE/repo.git}"
ENV_FILE="${FUU_ENV_FILE:-/etc/fuuphp/fuuphp.env}"
KEEP="${FUU_KEEP_RELEASES:-5}"

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

REV="${1:?uso: deploy.sh <tag-ou-commit> | --rollback}"

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

# O que não vai pra produção.
rm -rf "$RELEASE/tests" "$RELEASE/.github" "$RELEASE/docker-compose.yml"

ln -sfn "$RELEASE" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
# Recarrega o PHP-FPM pra limpar o OPcache (senão ele serve o código antigo).
sudo systemctl reload php8.4-fpm
echo "deploy: no ar -> $(basename "$RELEASE")"

# Guarda as últimas $KEEP versões pro rollback.
ls -1d "$BASE"/releases/*/ | sed 's#/$##' | head -n -"$KEEP" | xargs -r rm -rf --
