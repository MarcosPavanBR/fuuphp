# Imagem do FUUdelivery pro Render (docs/SUPABASE_RENDER.md).
#
# O Render não tem PHP nativo: o serviço roda esta imagem. Dentro dela, o
# mesmo desenho da VPS (deploy/nginx, deploy/php-fpm), num contêiner só:
#   Nginx  -> serve o PWA compilado (web/dist) e só as rotas api/v1/*.php;
#   PHP-FPM -> dois pools, a API e o acompanhamento ao vivo (SSE);
#   tarefas -> os bin/*.php que na VPS são do cron (deploy/render/jobs.sh).
# Quem sobe os três e derruba o contêiner se um cair: deploy/render/start.sh.
#
# Nada de segredo aqui: tudo vem das variáveis de ambiente do Render, lidas
# em tempo de execução. O .env do projeto nem entra na imagem (.dockerignore).
#
# Teste local:
#   docker build -t fuuphp .
#   docker run --rm -p 10000:10000 --env-file <arquivo> -v fuu-storage:/var/fuuphp/storage fuuphp

# ── 1. Front: os quatro apps Svelte ─────────────────────────────────────
FROM node:22-bookworm-slim AS web
WORKDIR /build/web
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
# O Render passa as variáveis de ambiente do serviço como build args: a
# PUBLIC_ORIGIN (https://dominio) vira o endereço absoluto da imagem da
# prévia do link (web/index.html). Vazia, o build sai com caminho relativo.
ARG PUBLIC_ORIGIN=
RUN VITE_PUBLIC_ORIGIN="${PUBLIC_ORIGIN%/}" npm run build

# ── 2. Servidor: PHP-FPM 8.4 + Nginx ────────────────────────────────────
FROM php:8.4-fpm-bookworm

# Extensões que o código usa além das que a imagem oficial já traz
# (mbstring, curl, openssl, sodium, fileinfo): PDO e ext-pgsql pro
# PostgreSQL (a ext-pgsql é o LISTEN do SSE), gd com JPEG/WebP pras fotos e
# comprovantes, e o opcache ligado (algumas versões da imagem já o trazem
# ligado; aí não se liga de novo). Os pacotes -dev só existem pra compilar e saem na
# mesma camada; as bibliotecas de execução ficam marcadas como manuais.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx libpq5 libpq-dev libjpeg62-turbo libjpeg62-turbo-dev \
        libpng16-16 libpng-dev libwebp7 libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd; \
    php -m | grep -q 'Zend OPcache' || docker-php-ext-enable opcache; \
    apt-mark manual nginx libpq5 libjpeg62-turbo libpng16-16 libwebp7; \
    apt-get purge -y --auto-remove libpq-dev libjpeg62-turbo-dev libpng-dev libwebp-dev; \
    rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled /etc/nginx/conf.d/*; \
    rm -f /usr/local/etc/php-fpm.d/*.conf; \
    cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

COPY deploy/render/php.ini /usr/local/etc/php/conf.d/zz-fuuphp.ini
COPY deploy/render/php-fpm.conf /usr/local/etc/php-fpm.d/fuuphp.conf
COPY deploy/render/nginx.conf /etc/nginx/fuuphp.conf.template

# O projeto em /srv/fuuphp, dono root e só leitura pro www-data (que roda o
# PHP e o Nginx): um bug no PHP não reescreve o próprio código. Só o
# necessário pra rodar -- sem tests/, docs/, db/, .git.
WORKDIR /srv/fuuphp
COPY api/ api/
COPY lib/ lib/
COPY bin/ bin/
COPY deploy/render/start.sh deploy/render/jobs.sh deploy/render/
COPY --from=web /build/web/dist/ web/dist/
RUN chmod 755 deploy/render/start.sh deploy/render/jobs.sh \
 && php -r 'require "lib/core/env.php";' \
 && find api lib bin -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

# Nenhum .env dentro da imagem: o bootstrap lê este caminho (vazio) e fica
# só com as variáveis de ambiente do Render.
ENV FUU_ENV_FILE=/dev/null \
    PORT=10000

EXPOSE 10000
STOPSIGNAL SIGTERM
CMD ["/srv/fuuphp/deploy/render/start.sh"]
