#!/usr/bin/env bash
# Tarefas agendadas do servidor no Render (as mesmas de deploy/cron/fuuphp).
#
# O contêiner não tem cron; start.sh roda este laço em segundo plano. Uma
# volta por minuto, uma tarefa depois da outra (cada uma é curta e
# idempotente; se uma demorar, a próxima volta só começa depois -- nunca duas
# cópias da mesma ao mesmo tempo, o que na VPS era o papel do flock).
# Roda como www-data, igual ao PHP da API. Saída vai pro log do Render com
# o nome da tarefa na frente.
#
# As tarefas do BANCO (Pix vencido, abre/fecha loja, repasse de terça,
# retenção) são do pg_cron no Supabase -- não entram aqui.
set -uo pipefail

APP=/srv/fuuphp
run() {
    local name="$1"
    runuser -u www-data -- php "$APP/bin/$name.php" 2>&1 | sed -u "s/^/[job $name] /"
}

last_hour=''
while true; do
    started=$(date +%s)
    run push_worker
    run dispatch_rounds
    run execute_refunds
    run auto_cancel_no_courier
    # Uma vez por hora (como o "0 * * * *" da VPS).
    hour=$(date +%Y%m%d%H)
    if [ "$hour" != "$last_hour" ]; then
        run apply_financial_blocks
        last_hour="$hour"
    fi
    elapsed=$(( $(date +%s) - started ))
    [ "$elapsed" -lt 60 ] && sleep $(( 60 - elapsed ))
done
