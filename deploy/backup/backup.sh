#!/usr/bin/env bash
# Backup diário do FUUdelivery (go-live, passo 7). Chamado pelo cron como root
# (deploy/cron/fuuphp).
#
#   1. banco: pg_dump no formato custom (-Fc), rodado como o usuário postgres
#      -- inclui o livro-razão, os pontos e os jobs do pg_cron;
#   2. arquivos enviados: comprovantes, fotos do cardápio, documentos de
#      entregador e a chave VAPID (sem ela, as assinaturas de push morrem);
#   3. confere que o dump abre (pg_restore --list) antes de apagar os antigos;
#   4. mantém KEEP_DAYS dias.
#
# Os backups ficam em /var/backups/fuuphp, dono root, permissão 700: têm
# comprovante e dado pessoal. Cópia fora da VPS é obrigatória antes do
# go-live, mas o destino (e a ferramenta) é decisão do Marcos -- nada é
# instalado aqui sem autorização. Pendurar a cópia em OFFSITE_CMD, que recebe
# a pasta do dia como argumento.
set -euo pipefail

DB_NAME="${DB_NAME:-fuudelivery}"
STORAGE_DIR="${STORAGE_DIR:-/var/fuuphp/storage}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/fuuphp}"
KEEP_DAYS="${KEEP_DAYS:-14}"
OFFSITE_CMD="${OFFSITE_CMD:-}"

STAMP="$(date +%Y%m%d-%H%M)"
DEST="${BACKUP_ROOT}/${STAMP}"
umask 077
mkdir -p "$DEST"

echo "[$(date -Is)] backup ${STAMP} começou"

# pg_dump escreve no stdout (como postgres) e o root grava o arquivo: o
# usuário postgres não precisa enxergar /var/backups.
runuser -u postgres -- pg_dump -Fc "$DB_NAME" > "${DEST}/${DB_NAME}.dump"
pg_restore --list "${DEST}/${DB_NAME}.dump" > /dev/null

if [ -d "$STORAGE_DIR" ]; then
  tar -C "$(dirname "$STORAGE_DIR")" -czf "${DEST}/storage.tar.gz" "$(basename "$STORAGE_DIR")"
fi

( cd "$DEST" && sha256sum -- * > SHA256SUMS )
echo "[$(date -Is)] backup ${STAMP} ok: $(du -sh "$DEST" | cut -f1)"

if [ -n "$OFFSITE_CMD" ]; then
  $OFFSITE_CMD "$DEST"
  echo "[$(date -Is)] cópia externa ok"
else
  echo "[$(date -Is)] AVISO: sem OFFSITE_CMD -- backup só dentro da VPS"
fi

# Só apaga os antigos depois de o backup de hoje ter dado certo (set -e).
find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf -- {} +
