#!/usr/bin/env bash
# Ponto de entrada do contêiner no Render (Dockerfile, CMD).
#
# 1. Confere que o disco persistente está montado (produção falha fechado:
#    sem ele, comprovante e documento de entregador sumiriam no próximo
#    deploy, porque o disco do contêiner é descartável).
# 2. Cria as pastas de arquivo no disco e a chave VAPID na primeira vez.
# 3. Roda a conferência de produção (bin/check_production.php): falta de
#    segredo, modo simulado, banco como superusuário, pg_cron parado = o
#    contêiner NÃO sobe, e o Render mantém a versão anterior no ar.
# 4. Sobe PHP-FPM, Nginx e as tarefas agendadas. Se qualquer um cair, o
#    contêiner sai e o Render reinicia -- nada fica meio no ar.
set -euo pipefail

APP=/srv/fuuphp
STORAGE="${FUU_STORAGE_ROOT:-/var/fuuphp/storage}"
PORT="${PORT:-10000}"
log() { echo "[start] $*" >&2; }

production_like=0
case "${APP_ENV:-production}" in development|testing) ;; *) production_like=1 ;; esac

if [ "$production_like" = 1 ] && [ "${FUU_REQUIRE_DISK:-1}" = 1 ] && ! mountpoint -q "$STORAGE"; then
    log "ERRO: $STORAGE não é um disco montado. No Render: Disks > Mount Path = $STORAGE."
    log "Sem disco, os arquivos enviados se perdem a cada deploy. Não subo."
    exit 1
fi

# Onde cada arquivo mora: no disco, por padrão. Exportado pra o PHP-FPM
# (clear_env = no) e as tarefas enxergarem os mesmos caminhos -- o padrão
# do código (storage/ dentro do projeto) é recusado pela trava de produção.
export PROOF_STORAGE_DIR="${PROOF_STORAGE_DIR:-$STORAGE/proofs}"
export MENU_PHOTO_DIR="${MENU_PHOTO_DIR:-$STORAGE/menu}"
export COURIER_DOC_DIR="${COURIER_DOC_DIR:-$STORAGE/courier_docs}"
export VAPID_PRIVATE_KEY_FILE="${VAPID_PRIVATE_KEY_FILE:-$STORAGE/vapid/private.pem}"

# Pastas no disco, do www-data (quem roda o PHP). Idempotente.
for d in "$PROOF_STORAGE_DIR" "$MENU_PHOTO_DIR" "$COURIER_DOC_DIR" "$(dirname "$VAPID_PRIVATE_KEY_FILE")"; do
    mkdir -p "$d"
    chown www-data:www-data "$d"
    chmod 700 "$d"
done

# Chave do push: gerada uma única vez, no disco. Existindo, não é tocada
# (trocar invalidaria a assinatura de todos os aparelhos).
if [ ! -f "$VAPID_PRIVATE_KEY_FILE" ]; then
    log "primeira subida: gerando a chave VAPID do push no disco"
    runuser -u www-data -- php "$APP/bin/generate_vapid_keys.php" >&2
fi

if [ "$production_like" = 1 ]; then
    log "conferindo a configuração de produção"
    runuser -u www-data -- php "$APP/bin/check_production.php" >&2
fi

mkdir -p /run/php
sed "s/__PORT__/$PORT/" /etc/nginx/fuuphp.conf.template > /etc/nginx/nginx.conf
nginx -t -q

pids=()
php-fpm --nodaemonize & pids+=($!)
nginx -g 'daemon off;' & pids+=($!)
"$APP/deploy/render/jobs.sh" & pids+=($!)
log "no ar na porta $PORT"

# Render manda SIGTERM no deploy/parada: repassa, e cada um termina o que
# está fazendo (Nginx e PHP-FPM encerram com SIGQUIT de forma graciosa).
stop() {
    log "encerrando"
    kill -QUIT "${pids[0]}" "${pids[1]}" 2>/dev/null || true
    kill -TERM "${pids[2]}" 2>/dev/null || true
    wait || true
    exit 0
}
trap stop TERM INT

# Um dos três caiu: derruba o resto e sai com erro (o Render reinicia).
set +e
wait -n "${pids[@]}"
status=$?
log "um processo terminou (código $status): derrubando o contêiner"
kill -TERM "${pids[@]}" 2>/dev/null
wait
exit 1
