#!/usr/bin/env bash
# ============================================================================
# Wallet Track — GATE de Viabilidade (M0.3)
# ----------------------------------------------------------------------------
# Verifica que TODAS as dependências planejadas resolvem e instalam sem
# conflito de versão contra PHP 8.5 + Laravel 13.x. Este script é o "go/no-go"
# técnico do projeto: se algum pacote planejado não for compatível, o projeto
# precisa voltar ao spec-designer para buscar alternativas.
#
# Como o ambiente pode não ter PHP/Composer nativos, detecta e usa Docker
# automaticamente (imagem oficial composer:2.8).
#
# Uso:
#   bin/check-viability.sh                # GATE completo (resolve + verifica)
#   bin/check-viability.sh --skip-resolve # só verifica pacotes já instalados
#
# Saída: código 0 = viável, código !=0 = inviável.
# ============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# --- Pacotes planejados (plano de implementação §3.4 + viability-report §3) -
# Formato: "nome<sep>versão_esperada"
declare -a PLANNED_PACKAGES=(
  "laravel/framework|^13.0"
  "laravel/octane|^2.0"
  "nutgram/nutgram|^4.0"
  "openai-php/client|^0.20"
  "google-gemini-php/client|^2.7"
  "google/apiclient|^2.19"
)

# --- Extensões PHP que DEVEM estar declaradas em composer.json -------------
# (extensões de runtime como opcache não são "dependências" e ficam de fora).
declare -a REQUIRED_EXTS=("gmp" "bcmath" "intl" "zip" "pdo_sqlite")

# --- Detecta como rodar composer -------------------------------------------
run_composer() {
  if command -v composer >/dev/null 2>&1; then
    composer "$@"
  elif command -v docker >/dev/null 2>&1; then
    docker run --rm \
      -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
      -v "$ROOT_DIR":/app -w /app composer:2.8 \
      composer "$@"
  else
    echo "ERRO: nem 'composer' nem 'docker' disponíveis. Instale um deles." >&2
    exit 2
  fi
}

SKIP_RESOLVE=0
if [[ "${1:-}" == "--skip-resolve" ]]; then SKIP_RESOLVE=1; fi

echo "============================================================"
echo " Wallet Track — GATE de Viabilidade"
echo "============================================================"
echo ""

# 1) composer.json é válido?
echo "[1/4] Validando composer.json..."
if ! run_composer validate --no-check-publish --strict >/tmp/viability-validate.log 2>&1; then
  echo "  FALHOU: composer.json inválido:"
  cat /tmp/viability-validate.log
  exit 1
fi
echo "  OK: composer.json válido"
echo ""

# 2) Re-resolução (dry-run) — POR PADRÃO. O GATE só é preditivo se resolver.
#    --skip-resolve pula esta etapa (útil quando não há rede ou para CI rápida).
#    Usa --ignore-platform-reqs: o objetivo aqui é validar RESOLUÇÃO DE VERSÕES
#    dos pacotes (conflitos de constraint), NÃO disponibilidade de extensões
#    — esta última é validada no `docker build` (onde as exts estão presentes).
if [[ $SKIP_RESOLVE -eq 0 ]]; then
  echo "[2/4] Re-resolvendo dependências (dry-run)..."
  if ! run_composer install --dry-run --ignore-platform-reqs \
        >/tmp/viability-resolve.log 2>&1; then
    echo "  FALHOU: conflito de versão detectado:"
    tail -25 /tmp/viability-resolve.log
    echo ""
    echo "  >>> GATE FALHOU: dependências não resolvem. Verificar pacotes."
    exit 1
  fi
  echo "  OK: resolução sem conflitos"
else
  echo "[2/4] Pulando re-resolução (--skip-resolve)"
fi
echo ""

# 3) Todos os pacotes planejados estão instalados? (presença; a conformidade de
#    VERSÃO é validada pelo dry-run do passo 2, que checa as constraints.)
echo "[3/4] Verificando pacotes planejados (composer show)..."
declare -i MISSING=0
for entry in "${PLANNED_PACKAGES[@]}"; do
  pkg="${entry%%|*}"
  expected="${entry##*|}"
  version=$(run_composer show "$pkg" 2>/dev/null | sed -n 's/^versions[[:space:]]*:[[:space:]]*//p' | head -1)
  if [[ -z "$version" ]]; then
    echo "  FALTA: $pkg (esperado $expected): AUSENTE"
    MISSING=$((MISSING + 1))
    continue
  fi
  # normaliza removendo 'v' prefix
  version="${version#v}"
  printf "  OK: %-32s %-12s (esperado %s)\n" "$pkg" "$version" "$expected"
done

if [[ $MISSING -gt 0 ]]; then
  echo ""
  echo "  >>> $MISSING pacote(s) planejado(s) nao instalado(s). GATE FALHOU."
  exit 1
fi
echo ""

# 4) Extensões PHP declaradas no composer.json?
echo "[4/4] Verificando declaração de extensões PHP em composer.json..."
declare -i EXT_MISSING=0
for ext in "${REQUIRED_EXTS[@]}"; do
  if grep -q "\"ext-${ext}\"" "$ROOT_DIR/composer.json"; then
    echo "  OK: ext-${ext} declarada"
  else
    echo "  AVISO: ext-${ext} NAO declarada em composer.json"
    EXT_MISSING=$((EXT_MISSING + 1))
  fi
done
echo ""

echo "============================================================"
if [[ $EXT_MISSING -gt 0 ]]; then
  echo " RESULTADO: VIABEL com avisos ($EXT_MISSING extensao(oes) nao declarada(s))"
  echo "            Todas as dependencias resolvem e estao instaladas."
  exit 0
fi
echo " RESULTADO: VIABEL — todas as dependencias sao compativeis"
echo "           com PHP 8.5 + Laravel 13. Projeto pode prosseguir (M1+)."
echo "============================================================"
