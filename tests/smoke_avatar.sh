#!/usr/bin/env bash
# Foto de perfil (api/v1/profile/avatar.php, migração 048), no modo disco
# (sem SUPABASE_URL). O caminho do Supabase Storage usa a mesma rota; aqui se
# prova o contrato: só o dono troca, a imagem é validada e recodificada, e a
# chave não deixa ler arquivo fora da pasta.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=8158
LOG=/tmp/smoke-avatar-server.log
# shellcheck source=support/seed_world.sh
source "$ROOT/tests/support/seed_world.sh"

TMP=$(mktemp -d)
php -r '$i = imagecreatetruecolor(600, 400); imagepng($i, $argv[1]);' "$TMP/foto.png"
echo 'não sou imagem' > "$TMP/falsa.png"

P="119$(( (RANDOM << 15 | RANDOM) % 90000000 + 10000000 ))"
TOKEN=$(otp_login "$P" signup | jq -r .access_token)

echo "== sem login: 401 =="
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -F "photo=@$TMP/foto.png" "$BASE/profile/avatar.php")
[ "$code" = 401 ] || fail "sem login deu $code"

echo "== arquivo que não é imagem: 422 =="
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" -X POST -F "photo=@$TMP/falsa.png" "$BASE/profile/avatar.php")
[ "$code" = 422 ] || fail "arquivo falso deu $code"

echo "== envia, vira JPEG quadrado de 256 px e aparece no perfil =="
URL=$(curl -s -H "Authorization: Bearer $TOKEN" -X POST -F "photo=@$TMP/foto.png" "$BASE/profile/avatar.php" | jq -r .avatar_url)
case "$URL" in /api/v1/profile/avatar.php\?key=*) ;; *) fail "avatar_url inesperada: $URL" ;; esac
curl -s -o "$TMP/volta.jpg" "http://127.0.0.1:$PORT$URL"
php -r '$s = getimagesize($argv[1]); exit($s[0] === 256 && $s[1] === 256 && $s["mime"] === "image/jpeg" ? 0 : 1);' "$TMP/volta.jpg" \
  || fail "a foto servida não é JPEG 256x256"
[ "$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE/profile/show.php" | jq -r .user.avatar_url)" = "$URL" ] || fail "perfil sem a foto"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/profile/show.php" | jq -e '.user | has("avatar_key") | not' > /dev/null \
  || fail "perfil expõe avatar_key"

echo "== chave fora do formato não lê arquivo nenhum =="
for k in '../../.env' "00000000-0000-0000-0000-000000000000/../x.jpg" 'x/y.jpg'; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -G --data-urlencode "key=$k" "$BASE/profile/avatar.php")
  [ "$code" = 404 ] || fail "chave '$k' deu $code"
done

echo "== remover: some do perfil e do disco =="
KEY=$(psql "$DATABASE_URL" -tAc "SELECT avatar_key FROM users WHERE phone = '+55$P' OR phone = '$P'" | head -1)
[ -n "$KEY" ] || fail "avatar_key não gravada"
curl -s -H "Authorization: Bearer $TOKEN" -X DELETE "$BASE/profile/avatar.php" | jq -e '.avatar_url == null' > /dev/null || fail "DELETE não tirou"
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$URL")
[ "$code" = 404 ] || fail "arquivo continuou servido depois de remover ($code)"

rm -rf "$TMP"
echo "PASS smoke_avatar"
