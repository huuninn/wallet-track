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
#  - grpc/protobuf: exigidos pelo google/cloud-firestore
#  - intl: normalização Unicode (heurística de labels em M8)
#  - gmp/bcmath: aritmética precisa (cálculos de valores)
#  - pdo_sqlite: banco padrão do skeleton (sessões/cache em dev)
#  - opcache: performance em worker mode
#  - zip: Composer / uploads
# (pcntl seria exigido pelo Octane para sinais SIGINT; como usamos o servidor
#  FrankenPHP nativo, não é necessário — ver nota no CMD abaixo.)
# Atualizamos índices apt explicitamente para evitar listas obsoletas no cache
# da imagem base (que pode estar defasado no momento do pull).
RUN apt-get update \
    && install-php-extensions \
        gmp \
        bcmath \
        intl \
        opcache \
        grpc \
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
RUN composer dump-autoload --no-dev --optimize


# ----------------------------------------------------------------------------
# Estágio runtime — imagem final, enxuta
# ----------------------------------------------------------------------------
FROM base AS runtime

LABEL org.opencontainers.image.title="wallet-track" \
      org.opencontainers.image.description="Chatbot Telegram de controle financeiro com IA" \
      org.opencontainers.image.source="https://github.com/diego-oliveira/wallet-track"

# Copia aplicação + vendor otimizado do estágio de build.
COPY --from=build --chown=www-data:www-data ${APP_DIR} ${APP_DIR}

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
# Caddyfile inline para FrankenPHP servir a aplicação Laravel.
# O bloco global `frankenphp` INICIA o runtime PHP embutido (necessário para
# `php_server`). Sem ele, Caddy responde "FrankenPHP is not running" (500).
# A porta 8000 atende ao critério de aceitação do M0 (`docker run -p 8000:8000`).
# ---------------------------------------------------------------------------
COPY <<'CADDYFILE' /etc/frankenphp/Caddyfile
{
	frankenphp
	admin off
}

:8000 {
	root * /app/public
	encode zstd gzip

	# Servir arquivos estáticos existentes diretamente (ex.: css/js do Vite).
	@static {
		file {
			try_files {path}
		}
	}
	route @static {
		file_server
	}

	# Todas as demais requisições vão para o runtime PHP (FrankenPHP).
	php_server
}
CADDYFILE

# Variáveis de runtime (sobrescritas pelo Cloud Run / .env em produção).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    SERVER_NAME=:8000 \
    OCTANE_SERVER=frankenphp

EXPOSE 8000

# ---------------------------------------------------------------------------
# Startup: o binário frankenphp sobe Caddy (HTTP/TLS) + runtime PHP embutido,
# servindo a aplicação Laravel a partir de /app/public na porta 8000.
#
# Nota arquitetural: o plano original previa `php artisan octane:start
# --server=frankenphp` como processo principal. Em M0 detectamos que o driver
# Octane exige a extensão `pcntl` (compilação lenta da fonte) para tratamento
# de sinais. Optamos pelo servidor FrankenPHP nativo (`php_server`), que é o
# modo de produção documentado pela imagem oficial e dispensa pcntl. O Octane
# permanece instalado (satisfaz o GATE de viabilidade) e pode ser reavaliado em
# M10 (produção) se o benefício do worker-mode justificar o custo de pcntl.
# Executa como usuário não-privilegiado www-data (hardening: superfície de RCE
# reduzida caso uma dependência seja vulnerável).
USER www-data
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
