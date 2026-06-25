#!/bin/bash
set -e

# ---------------------------------------------------------------------------
# Wallet Track — Entrypoint (Cloud Run / docker compose)
# ---------------------------------------------------------------------------
# 1. Parses /secrets/env.json (Secret Manager volume mount) com PHP e exporta
#    cada key=value como variável de ambiente. Se o arquivo não existir
#    (ex.: dev local), assume que as env vars já foram injetadas.
#    PHP é usado em vez de jq porque valores podem conter newlines (SA JSON).
# 2. Cria diretórios de storage/cache/bootstrap necessários.
# 3. Executa o FrankenPHP (Caddy + runtime PHP embutido).
# ---------------------------------------------------------------------------

ENV_JSON="${GOOGLE_SECRETS_MOUNT_PATH:-/secrets}/env.json"

if [ -f "$ENV_JSON" ]; then
    eval "$(php -r '
        $json = json_decode(file_get_contents($argv[1]), true);
        foreach ($json as $key => $value) {
            echo "export " . $key . "=" . escapeshellarg($value) . "\n";
        }
    ' "$ENV_JSON")"
fi

for dir in \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    [ -d "$dir" ] || mkdir -p "$dir"
done

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
