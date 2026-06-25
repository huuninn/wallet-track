# syntax=docker/dockerfile:1.7

# ============================================================================
# Wallet Track — Dockerfile (M0)
# ----------------------------------------------------------------------------
# Runtime: FrankenPHP 1.4 (Caddy embutido) + PHP 8.4 + Laravel Octane
# Estratégia: multi-stage build. Imagem final enxuta, sem dev deps.
# O servidor HTTP sobe via `php artisan octane:start --server=frankenphp`,
# conforme arquitetura do projeto (Especificação Técnica §3).
# ============================================================================

# ----------------------------------------------------------------------------
# Estágio base — imagem oficial FrankenPHP com PHP 8.4 (Debian Bookworm)
# ----------------------------------------------------------------------------
FROM dunglas/frankenphp:1.4-php8.4-bookworm AS base

# Extensões PHP obrigatórias ao projeto (plano §M0.6 + composer.json ext-*).
# `install-php-extensions` é um helper já presente na imagem FrankenPHP.
# Notas:
#  - grpc: instalado via bsn4/grpc-php-rs (binário pré-compilado, ~1MB) —
#    evita os 30-40 min de compilação do gRPC C++ oficial (grpc/grpc#34278).
#    FrankenPHP é ZTS → usamos a tag `-zts` do grpc-php-rs. Drop-in
#    replacement: mesma `Grpc\ namespace
#  - protobuf: exigido pelo google/cloud-firestore (otimização de serialização)
#  - intl: normalização Unicode (heurística de labels em M8)
#  - gmp/bcmath: aritmética precisa (cálculos de valores)
#  - pdo_sqlite: banco padrão do skeleton (sessões/cache em dev)
#  - opcache: performance em worker mode
#  - zip: Composer / uploads
# (pcntl seria exigido pelo Octane para sinais SIGINT; como usamos o servidor
#  FrankenPHP nativo, não é necessário — ver nota no CMD abaixo.)
#
# grpc-php-rs vem ANTES de install-php-extensions para que a .so já esteja
# no extension_dir antes de qualquer outra extensão ser configurada.
COPY --from=ghcr.io/bsn4/grpc-php-rs:latest-php8.4-zts /usr/local/ /usr/local/

# Atualizamos índices apt explicitamente para evitar listas obsoletas no cache
# da imagem base (que pode estar defasado no momento do pull).
RUN apt-get update \
    && apt-get install -y --no-install-recommends jq \
    && install-php-extensions \
        gmp \
        bcmath \
        intl \
        opcache \
        protobuf \
        pdo_sqlite \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Diretório da aplicação (padrão FrankenPHP: /app, document root /app/public)
ENV APP_DIR=/app
WORKDIR ${APP_DIR}


# ----------------------------------------------------------------------------
# Estágio deps — instala dependências Composer (cache isolado)
# ----------------------------------------------------------------------------
FROM base AS deps

# Composer oficial + git/unzip (necessários para resolução de pacotes)
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Aproveita cache: só re-instala quando composer.json/lock mudam.
COPY composer.json composer.lock ./
# --no-dev: imagem de produção não traz phpunit/pint/etc.
# --no-scripts: scripts exigem APP_KEY/.env; rodam em runtime.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist


# ----------------------------------------------------------------------------
# Estágio build — código da aplicação + autoload otimizado
# ----------------------------------------------------------------------------
FROM deps AS build

COPY . .
# --no-scripts: skip post-autoload-dump (package:discover) que falha em
# --no-dev mode (Telescope é dev-only mas o provider é registrado).
# O `package:discover` roda em runtime quando o app boota.
#
# ⚠️ NÃO rodamos `view:cache` / `event:cache` no build stage: ambos
# bootaam o Laravel e tentam carregar o TelescopeServiceProvider, que
# só existe em dev. O cold start penalty sem eles é mínimo (~100ms)
# e pode ser compensado por Cloud Run CPU boost.
RUN composer dump-autoload --no-dev --optimize --no-scripts

# Pré-compila templates Blade e descobre eventos/listeners em build-time.
# Isso acelera o cold start no Cloud Run (templates já compilados; listeners
# já mapeados em cache sem precisar escanear o filesystem a cada request).
#
# ⚠️ NÃO usamos `config:cache` — ele congela env() no momento do build, mas
#    os secrets (APP_KEY, tokens, SA JSON) são injetados pelo Cloud Run via
#    Secret Manager em RUNTIME. Rodar config:cache aqui quebraria a app em
#    produção (APP_KEY null, tokens vazios, credenciais ausentes).
#
# ⚠️ NÃO usamos `route:cache` — o projeto usa closure na rota
#    /cron/sync-pending (/health usa controller invokable desde M10)
#    e route:cache serializa closures como null, quebrando-as.
#    Mantemos as rotas interpretadas a cada request (custo
#    negligenciável em concurrency=1 com opcache ativo).
#
# ⚠️ NÃO rodamos `view:cache` / `event:cache` aqui (eram planejados para
#    reduzir cold start): eles falham no build --no-dev porque o
#    TelescopeServiceProvider só existe em dev. O cold start penalty
#    sem eles é mínimo (Laravel escaneia os diretórios em ~100ms com
#    opcache ativo) e pode ser compensado pelo Cloud Run `--cpu-boost`.


# ----------------------------------------------------------------------------
# Estágio runtime — imagem final, enxuta
# ----------------------------------------------------------------------------
FROM base AS runtime

LABEL org.opencontainers.image.title="wallet-track" \
      org.opencontainers.image.description="Chatbot Telegram de controle financeiro com IA" \
      org.opencontainers.image.source="https://github.com/diego-oliveira/wallet-track"

# Copia aplicação + vendor otimizado do estágio de build.
COPY --from=build --chown=www-data:www-data ${APP_DIR} ${APP_DIR}

# Descarta caches de bootstrap (services.php, packages.php, config.php etc.)
# gerados no build com --no-dev, que podem referenciar providers ausentes em
# produção (ex.: Telescope). Laravel os regenera em runtime sob demanda.
# NÃO fazemos config:cache no build — ele congela env() e quebra secrets
# injetados em runtime pelo Cloud Run (ver docs/decisions.md).
RUN rm -f bootstrap/cache/*.php

# Garante estrutura de diretórios gravável pelo worker (roda como www-data).
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/app/public \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap

# Caddy/FrankenPHP persiste estado em /data/caddy (locks, certs) e config em
# /config/caddy (autosave). Como o processo roda como www-data (não-root),
# garantimos ownership desses diretórios da imagem base.
RUN mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config

# ---------------------------------------------------------------------------
# Configuração PHP (opcache) otimizada para long-running workers no Cloud Run.
# ---------------------------------------------------------------------------
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_MEMORY_CONSUMPTION=128 \
    PHP_OPCACHE_MAX_ACCELERATED_FILES=20000 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

# ---------------------------------------------------------------------------
# Caddyfile para FrankenPHP servir a aplicação Laravel.
# O bloco global `frankenphp` INICIA o runtime PHP embutido (necessário para
# `php_server`). Sem ele, Caddy responde "FrankenPHP is not running" (500).
# A porta 8080 atende ao critério de aceitação do M0 e paridade com o
# PORT=8080 que o Cloud Run injeta. Em dev, docker-compose mapeia
# host:8000 → container:8080.
# Arquivo separado (não heredoc) para compatibilidade com o Docker daemon do
# Cloud Build (não suporta a sintaxe COPY << do BuildKit).
# Substitui o Caddyfile default da imagem FrankenPHP (que habilita HTTPS
# em :443 — não queremos em Cloud Run, que já faz TLS termination).
# ---------------------------------------------------------------------------
COPY docker/Caddyfile /etc/caddy/Caddyfile

# Variáveis de runtime (sobrescritas pelo Cloud Run / .env em produção).
ENV APP_ENV=production \
    APP_DEBUG=false \
    SESSION_DRIVER=file \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    SERVER_NAME=:8080 \
    OCTANE_SERVER=frankenphp

EXPOSE 8080

# ---------------------------------------------------------------------------
# Startup: entrypoint lê /secrets/env.json (Secret Manager via volume mount),
# exporta env vars e executa FrankenPHP (Caddy + runtime PHP embutido),
# servindo a aplicação Laravel a partir de /app/public na porta 8080.
#
# Fallback: se /secrets/env.json não existir (dev local), assume que as
# env vars já foram injetadas pelo docker-compose ou manualmente.
#
# Executa como usuário não-privilegiado www-data (hardening: superfície de RCE
# reduzida caso uma dependência seja vulnerável).
USER www-data
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
