FROM postgres:16

# pg_cron nao vem no image oficial do Postgres -- e um dos itens de infra ja
# autorizados pela clausula zero (documento II, "Contrato de stack").
# shared_preload_libraries e cron.database_name sao passados via `command:`
# no docker-compose.yml, nao aqui, para nao brigar com o entrypoint oficial.
RUN apt-get update \
 && apt-get install -y --no-install-recommends postgresql-16-cron \
 && rm -rf /var/lib/apt/lists/*
