#!/usr/bin/env bash
# ============================================================================
# Wallet Track — tunnel-up.sh
# ----------------------------------------------------------------------------
# Sobe um túnel ngrok → localhost:8000, atualiza TELEGRAM_WEBHOOK_URL no
# .env com a URL pública gerada, reinicia a app Docker e registra o webhook
# no Telegram. Ctrl+C encerra tudo e remove o webhook.
#
# Uso:
#   ./scripts/tunnel-up.sh
#
# Pré-requisitos:
#   - ngrok instalado e autenticado (ngrok config add-authtoken <TOKEN>)
#   - jq instalado (apt install jq / brew install jq)
#   - docker compose operacional
#   - .env presente com TELEGRAM_BOT_TOKEN e TELEGRAM_WEBHOOK_SECRET_TOKEN
# ============================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# ----------------------------------------------------------------------------
# Pré-flight checks
# ----------------------------------------------------------------------------
command -v ngrok   >/dev/null 2>&1 || { echo "❌ ngrok não encontrado no PATH"; exit 1; }
command -v jq      >/dev/null 2>&1 || { echo "❌ jq não encontrado (apt install jq / brew install jq)"; exit 1; }
command -v docker  >/dev/null 2>&1 || { echo "❌ docker não encontrado"; exit 1; }
[ -f .env ] || { echo "❌ .env ausente (copie de .env.example)"; exit 1; }
grep -qE '^TELEGRAM_BOT_TOKEN=.+' .env            || { echo "❌ TELEGRAM_BOT_TOKEN vazio no .env"; exit 1; }
grep -qE '^TELEGRAM_WEBHOOK_SECRET_TOKEN=.+' .env || { echo "❌ TELEGRAM_WEBHOOK_SECRET_TOKEN vazio no .env"; exit 1; }

# Garante que a app está rodando.
if ! docker compose ps app --status running 2>/dev/null | grep -q "wallet-track"; then
  echo "==> Subindo docker compose..."
  docker compose up -d
fi

# Garante que a porta 4040 (dashboard/API do ngrok) está livre.
if curl -sf http://localhost:4040/api/tunnels >/dev/null 2>&1; then
  echo "❌ Porta 4040 já em uso — provavelmente há um ngrok antigo. Encerre-o antes."
  exit 1
fi

# ----------------------------------------------------------------------------
# Cleanup no Ctrl+C / SIGTERM
# ----------------------------------------------------------------------------
NGROK_PID=""
cleanup() {
  echo ""
  echo "==> Encerrando..."
  if [ -n "$NGROK_PID" ] && kill -0 "$NGROK_PID" 2>/dev/null; then
    kill "$NGROK_PID" 2>/dev/null || true
  fi
  if docker compose ps app --status running 2>/dev/null | grep -q "wallet-track"; then
    docker compose exec -T app php artisan telegram:delete-webhook 2>/dev/null \
      && echo "   Webhook removido do Telegram." \
      || echo "   ⚠️  Não foi possível remover o webhook (app parada?)."
  fi
  echo "✔ Limpeza concluída."
}
trap cleanup INT TERM

# ----------------------------------------------------------------------------
# Sobe ngrok e captura URL pública
# ----------------------------------------------------------------------------
NGROK_LOG="/tmp/wallet-track-ngrok.log"
echo "==> Iniciando ngrok (log: $NGROK_LOG)..."
ngrok http 8000 --log "$NGROK_LOG" --log-format json >/dev/null 2>&1 &
NGROK_PID=$!

# Espera a API local do ngrok responder e o túnel HTTPS ficar disponível.
echo -n "==> Aguardando ngrok"
TUNNEL_URL=""
for _ in $(seq 1 30); do
  TUNNEL_URL=$(curl -sf http://localhost:4040/api/tunnels 2>/dev/null \
    | jq -r '.tunnels[] | select(.proto=="https") | .public_url' | head -1) || true
  if [ -n "$TUNNEL_URL" ] && [ "$TUNNEL_URL" != "null" ]; then
    echo " ✓"; break
  fi
  echo -n "."; sleep 1
done
if [ -z "$TUNNEL_URL" ] || [ "$TUNNEL_URL" = "null" ]; then
  echo " ✗"
  echo "❌ ngrok não respondeu em 30s ou não gerou URL HTTPS."
  echo "   Verifique autenticação e log: $NGROK_LOG"
  kill "$NGROK_PID" 2>/dev/null || true
  exit 1
fi
WEBHOOK_URL="${TUNNEL_URL}/webhook/telegram"

# ----------------------------------------------------------------------------
# Atualiza .env, reinicia app, registra webhook
# ----------------------------------------------------------------------------
echo "==> Atualizando TELEGRAM_WEBHOOK_URL no .env → $WEBHOOK_URL"
sed -i.bak -E "s|^TELEGRAM_WEBHOOK_URL=.*|TELEGRAM_WEBHOOK_URL=${WEBHOOK_URL}|" .env
rm -f .env.bak

echo "==> Recriando container para aplicar novo env_file..."
docker compose up -d --force-recreate app >/dev/null

echo "==> Limpando cache de config..."
docker compose exec -T app php artisan config:clear >/dev/null

echo -n "==> Aguardando app"
HEALTH_OK=false
for _ in $(seq 1 30); do
  docker compose exec -T app php -r 'exit(@fsockopen("127.0.0.1", 8080) ? 0 : 1);' >/dev/null 2>&1 \
    && { echo " ✓"; HEALTH_OK=true; break; }
  echo -n "."; sleep 1
done
if [ "$HEALTH_OK" = "false" ]; then
  echo " ⚠ app não respondeu em 30s (verifique \`docker compose logs app\`)"
fi

echo "==> Registrando webhook no Telegram..."
docker compose exec -T app php artisan telegram:set-webhook

cat <<EOF

✔ Tudo pronto!
  Túnel ngrok:  $TUNNEL_URL
  Webhook:      $WEBHOOK_URL
  Dashboard:    http://localhost:4040
  Logs app:     docker compose logs -f app

  Mande /start para o bot no Telegram.
  Ctrl+C aqui encerra o túnel e remove o webhook.
EOF

# Mantém o script vivo (ngrok em primeiro plano).
wait "$NGROK_PID" || true
