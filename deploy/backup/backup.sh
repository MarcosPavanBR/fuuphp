#!/usr/bin/env bash
# Backup diário do FUUdelivery (go-live, passo 7). Chamado pelo cron como root
# (deploy/cron/fuuphp).
#
#   1. banco: pg_dump no formato custom (-Fc), rodado como o usuário postgres
#      -- inclui o livro-razão, os pontos e os jobs do pg_cron;
#   2. arquivos enviados: comprovantes, fotos do cardápio, documentos de
#      entregador e a chave VAPID (sem ela, as assinaturas de push morrem);
#   3. confere que o dump abre (pg_restore --list) antes de apagar os antigos;
#   4. uma vez por semana (domingo; RESTORE_TEST=always|weekly|never), RESTAURA
#      o dump num banco descartável e confere tabelas e linhas -- backup que
#      nunca foi restaurado é esperança, não backup (auditoria INFRA-01);
#   5. mantém KEEP_DAYS dias.
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
RESTORE_TEST="${RESTORE_TEST:-weekly}"

# O resultado vai pro banco (migração 041): o admin vê em "Saúde do sistema"
# se o backup rodou, se a restauração conferiu, e a falha vira um erro na
# lista -- sem ninguém precisar abrir o log da VPS.
record_status() {  # record_status true|false "detalhe"
  runuser -u postgres -- psql -q -d "$DB_NAME" -v ok="$1" -v detail="$2" >/dev/null 2>&1 <<'SQL' || true
INSERT INTO system_status (key, ok, detail) VALUES ('backup', :'ok', :'detail')
  ON CONFLICT (key) DO UPDATE SET ok = EXCLUDED.ok, detail = EXCLUDED.detail, updated_at = now();
SQL
  if [ "$1" = "false" ]; then
    runuser -u postgres -- psql -q -d "$DB_NAME" -v detail="$2" >/dev/null 2>&1 <<'SQL' || true
INSERT INTO app_errors (source, fingerprint, message, route)
  VALUES ('backup', encode(sha256('backup|falha'::bytea), 'hex'), left(:'detail', 600), 'deploy/backup/backup.sh')
  ON CONFLICT (fingerprint) DO UPDATE
     SET count = app_errors.count + 1, last_seen = now(), message = EXCLUDED.message, resolved_at = NULL;
SQL
  fi
}
trap 'record_status false "backup parou na linha $LINENO (veja /var/log/fuuphp/backup.log)"' ERR

STAMP="$(date +%Y%m%d-%H%M)"
DEST="${BACKUP_ROOT}/${STAMP}"
umask 077
mkdir -p "$DEST"

echo "[$(date -Is)] backup ${STAMP} começou"

# pg_dump escreve no stdout (como postgres) e o root grava o arquivo: o
# usuário postgres não precisa enxergar /var/backups.
BEFORE=$(runuser -u postgres -- psql -tAq -d "$DB_NAME" -c "SELECT (SELECT count(*) FROM pg_tables WHERE schemaname='public') || ' ' ||
  (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM orders) || ' ' || (SELECT count(*) FROM ledger_entries)")
runuser -u postgres -- pg_dump -Fc "$DB_NAME" > "${DEST}/${DB_NAME}.dump"
pg_restore --list "${DEST}/${DB_NAME}.dump" > /dev/null

# ── teste de restauração ──────────────────────────────────────────────
# Restaura num banco descartável e compara com o banco de verdade: o mesmo
# número de tabelas, e as tabelas que importam com pelo menos as linhas que
# tinham antes do dump (o banco vivo só cresce enquanto o dump roda). O
# pg_cron fica de fora: a extensão só existe num banco do servidor, e os
# jobs já estão na migração. Falhou = o script sai com erro e o log mostra.
restore_test() {
  local check="fuu_restore_check_$$"
  # A lista de objetos (só nomes, sem dado) vai pro /tmp legível pelo
  # postgres: a pasta do backup é 700 do root, de propósito.
  local list; list=$(mktemp /tmp/fuu-restore-XXXXXX.list); chmod 644 "$list"
  runuser -u postgres -- dropdb --if-exists "$check"
  runuser -u postgres -- createdb "$check"
  pg_restore --list "${DEST}/${DB_NAME}.dump" | grep -viE 'pg_cron| cron ' > "$list"
  if ! runuser -u postgres -- pg_restore --exit-on-error --no-owner -L "$list" -d "$check" < "${DEST}/${DB_NAME}.dump"; then
    runuser -u postgres -- dropdb --if-exists "$check"; rm -f "$list"
    echo "[$(date -Is)] ERRO: o dump de hoje NÃO restaurou"
    record_status false "o dump de ${STAMP} NÃO restaurou num banco de teste"; return 1
  fi
  rm -f "$list"
  local q="SELECT (SELECT count(*) FROM pg_tables WHERE schemaname='public') || ' ' ||
                  (SELECT count(*) FROM users) || ' ' || (SELECT count(*) FROM orders) || ' ' ||
                  (SELECT count(*) FROM ledger_entries)"
  local restored; restored=$(runuser -u postgres -- psql -tAq -d "$check" -c "$q")
  runuser -u postgres -- dropdb "$check"
  read -r r_tables r_users r_orders r_ledger <<< "$restored"
  read -r b_tables b_users b_orders b_ledger <<< "$BEFORE"
  if [ "$r_tables" != "$b_tables" ] || [ "$r_users" -lt "$b_users" ] || [ "$r_orders" -lt "$b_orders" ] || [ "$r_ledger" -lt "$b_ledger" ]; then
    echo "[$(date -Is)] ERRO: restauração incompleta (restaurado: $restored; antes do dump: $BEFORE)"
    record_status false "restauração de ${STAMP} incompleta (restaurado: $restored; antes: $BEFORE)"; return 1
  fi
  echo "[$(date -Is)] restauração conferida: ${r_tables} tabelas, ${r_users} usuários, ${r_orders} pedidos, ${r_ledger} lançamentos"
  date -Is > "${BACKUP_ROOT}/last_restore_ok"
  RESTORE_NOTE=" · restauração conferida (${r_tables} tabelas, ${r_orders} pedidos)"
}

if [ -d "$STORAGE_DIR" ]; then
  tar -C "$(dirname "$STORAGE_DIR")" -czf "${DEST}/storage.tar.gz" "$(basename "$STORAGE_DIR")"
fi

if [ "$RESTORE_TEST" = "always" ] || { [ "$RESTORE_TEST" = "weekly" ] && [ "$(date +%u)" = "7" ]; }; then
  restore_test
fi

( cd "$DEST" && sha256sum -- * > SHA256SUMS )
echo "[$(date -Is)] backup ${STAMP} ok: $(du -sh "$DEST" | cut -f1)"

if [ -n "$OFFSITE_CMD" ]; then
  $OFFSITE_CMD "$DEST"
  echo "[$(date -Is)] cópia externa ok"
  OFFSITE_NOTE=" · cópia externa ok"
else
  echo "[$(date -Is)] AVISO: sem OFFSITE_CMD -- backup só dentro da VPS"
  OFFSITE_NOTE=" · SEM cópia fora da VPS"
fi
record_status true "backup ${STAMP} ($(du -sh "$DEST" | cut -f1))${RESTORE_NOTE:-}${OFFSITE_NOTE}"

# Só apaga os antigos depois de o backup de hoje ter dado certo (set -e).
find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf -- {} +
