#!/usr/bin/env sh
# ============================================================================
# Wallet Track — entrypoint do container de desenvolvimento
# ----------------------------------------------------------------------------
# Setup idempotente: roda uma vez na criação do container, e re-roda com
# segurança em reinícios (checa estado antes de cada ação). Pode ser
# executado manualmente para bootstrap local sem Docker.
#
# M11: app é server-to-server puro (Firestore como fonte de verdade). Sem
# banco relacional — não há SQLite, migrations, seeders ou factories.
# Sessões em memória (`array`), cache em arquivo, queue em `sync`.
# ============================================================================
set -e

cd /app

# 1) Dependências Composer (caso o volume `vendor` tenha sido limpo).
if [ ! -f vendor/autoload.php ]; then
    echo "==> Instalando dependências Composer (com dev-deps)..."
    composer install --no-interaction --prefer-dist
fi

# 2) .env a partir de .env.example (se ausente).
if [ ! -f .env ] && [ -f .env.example ]; then
    echo "==> Criando .env a partir de .env.example"
    cp .env.example .env
fi

# 3) APP_KEY (idempotente: só gera se ainda não houver chave).
if [ -f .env ] && ! grep -qE '^APP_KEY=base64:' .env; then
    echo "==> Gerando APP_KEY"
    php artisan key:generate --ansi
fi

# 4) Estrutura de diretórios graváveis (caso volume storage tenha sido limpo).
#    (M11: removido `storage/framework/sessions` — driver=array é in-memory.)
for dir in \
    storage/framework/cache/data \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    [ -d "$dir" ] || mkdir -p "$dir"
done

echo "==> Wallet Track pronto em http://localhost:8000 (container :8080)"

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
