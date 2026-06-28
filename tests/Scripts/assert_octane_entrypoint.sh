#!/usr/bin/env bash
# ============================================================================
# Validação estática anti-regressão (Componente F.3 / P10-c)
# ----------------------------------------------------------------------------
# Garante que docker/entrypoint.sh ativa o pipeline Octane via
# `octane:start --server=frankenphp` e NÃO usa o modo php_server legacy
# (`frankenphp run --config`). Falha o CI se houver regressão.
#
# Rodar: bash tests/Scripts/assert_octane_entrypoint.sh
# Saída: exit 0 se OK, exit 1 se regressão detectada.
# ============================================================================
set -euo pipefail

ENTRYPOINT="docker/entrypoint.sh"

if ! grep -q -- '--server=frankenphp' "$ENTRYPOINT"; then
    echo "FAIL: $ENTRYPOINT não invoca octane:start --server=frankenphp" >&2
    exit 1
fi

if grep -Eq 'frankenphp[[:space:]]+run[[:space:]]+(-c|--config)' "$ENTRYPOINT"; then
    echo "FAIL: $ENTRYPOINT ainda usa modo php_server legacy (frankenphp run -c/--config)" >&2
    exit 1
fi

echo "OK: entrypoint ativa Octane/FrankenPHP worker via octane:start"
