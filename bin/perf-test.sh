#!/usr/bin/env bash
# ============================================================================
# Wallet Track — Teste de Performance HTTP
# ----------------------------------------------------------------------------
# Benchmarka endpoints HTTP da aplicação (prioritariamente /health, por ser
# stateless e leve — mede o overhead bruto do framework Laravel + FrankenPHP).
#
# Por que curl + xargs e não `ab`/`wrk`? Curl está disponível em qualquer
# ambiente (host ou container), garantindo que o script rode sem instalar
# dependências extras. A concorrência é obtida com `xargs -P`.
#
# Métricas coletadas:
#   - Requisições/s (throughput)
#   - Latência: min, média, p50, p90, p95, p99, max (ms)
#   - Distribuição de status HTTP e taxa de erro
#
# Uso:
#   bin/perf-test.sh                              # defaults: /health, 200 reqs, c=10
#   bin/perf-test.sh --endpoint /health --total 500 --concurrency 20
#   bin/perf-test.sh --url http://localhost:8000 --endpoint /health
#   bin/perf-test.sh --token "$CRON_SECRET_TOKEN" --endpoint /cron/sync-pending
#   TOTAL=1000 CONCURRENCY=50 bin/perf-test.sh    # via variáveis de ambiente
#
# Variáveis de ambiente (com defaults):
#   BASE_URL       URL base do servidor         (default: http://127.0.0.1:8000)
#   ENDPOINT       Path a benchmarkar           (default: /health)
#   TOTAL          Total de requisições         (default: 200)
#   CONCURRENCY    Requisições concorrentes     (default: 10)
#   WARMUP         Reqs de aquecimento (descartadas, mitigam cold-start)
#                                                       (default: 5)
#   P95_THRESHOLD  p95 máximo aceitável em ms   (default: 500)
#   ERROR_THRESHOLD taxa de erro máxima aceitável em % (default: 1)
#
# Saída: código 0 = dentro dos limites; código !=0 = degradado/erros.
# ============================================================================
set -euo pipefail

# --- Defaults ----------------------------------------------------------------
BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ENDPOINT="${ENDPOINT:-/health}"
TOTAL="${TOTAL:-200}"
CONCURRENCY="${CONCURRENCY:-10}"
WARMUP="${WARMUP:-5}"
P95_THRESHOLD="${P95_THRESHOLD:-500}"
ERROR_THRESHOLD="${ERROR_THRESHOLD:-1}"
TOKEN=""

# --- Parsing de argumentos ---------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --url)        BASE_URL="$2"; shift 2 ;;
        --endpoint)   ENDPOINT="$2"; shift 2 ;;
        --total)      TOTAL="$2"; shift 2 ;;
        --concurrency) CONCURRENCY="$2"; shift 2 ;;
        --warmup)     WARMUP="$2"; shift 2 ;;
        --token)      TOKEN="$2"; shift 2 ;;
        --p95)        P95_THRESHOLD="$2"; shift 2 ;;
        --max-errors) ERROR_THRESHOLD="$2"; shift 2 ;;
        -h|--help)
            sed -n 's/^#   bin\/perf-test.sh/  /p' "$0" >&2
            sed -n 's/^#   \(TOTAL\|BASE_URL\|ENDPOINT\|CONCURRENCY\|WARMUP\|P95_THRESHOLD\|ERROR_THRESHOLD\)/  \1/p' "$0" >&2
            exit 0 ;;
        *) echo "Argumento desconhecido: $1" >&2; exit 2 ;;
    esac
done

# --- Validação ---------------------------------------------------------------
[[ "$TOTAL" =~ ^[0-9]+$ && "$TOTAL" -gt 0 ]] || { echo "TOTAL inválido: $TOTAL" >&2; exit 2; }
[[ "$CONCURRENCY" =~ ^[0-9]+$ && "$CONCURRENCY" -gt 0 ]] || { echo "CONCURRENCY inválido" >&2; exit 2; }
[[ "$CONCURRENCY" -le "$TOTAL" ]] || { echo "CONCURRENCY não pode ser maior que TOTAL" >&2; exit 2; }

# Constrói URL final (evita // duplo quando ENDPOINT já começa com /)
URL="${BASE_URL%/}${ENDPOINT}"

# Header de autorização para endpoints protegidos (ex.: /cron/sync-pending).
AUTH_HEADER=()
[[ -n "$TOKEN" ]] && AUTH_HEADER=(-H "X-Cron-Token: $TOKEN")

command -v curl >/dev/null 2>&1 || { echo "ERRO: curl é obrigatório." >&2; exit 2; }

echo "============================================================"
echo " Wallet Track — Teste de Performance"
echo "============================================================"
echo "  URL          : $URL"
echo "  Total        : $TOTAL requisições"
echo "  Concorrência : $CONCURRENCY"
echo "  Warmup       : $WARMUP requisições"
echo "  Limites      : p95 <= ${P95_THRESHOLD}ms | erros <= ${ERROR_THRESHOLD}%"
echo "------------------------------------------------------------"

# --- 1) Verifica se o servidor está no ar -----------------------------------
probe() {
    curl -s -o /dev/null -w '%{http_code}' \
         --connect-timeout 3 --max-time 5 \
         "${AUTH_HEADER[@]}" "$URL" 2>/dev/null || true
}

echo "[1/4] Verificando disponibilidade do servidor..."
code="$(probe)"
if [[ ! "$code" =~ ^[2-4]..$ ]]; then
    echo "  SERVIDOR OFFLINE em $BASE_URL (resposta: '${code:-vazia}')."
    echo "  Inicie o servidor antes de rodar o benchmark:"
    echo "    bin/dev artisan serve           # dev local (PHP built-in)"
    echo "    docker run -p 8000:8000 wallet-track  # imagem de produção"
    exit 1
fi
echo "  OK: servidor respondeu HTTP $code"

# --- 2) Warmup (descartado — mitiga cold-start / JIT / cache) ----------------
if [[ "$WARMUP" -gt 0 ]]; then
    echo "[2/4] Aquecendo ($WARMUP requisições sequenciais)..."
    for _ in $(seq 1 "$WARMUP"); do
        curl -s -o /dev/null --max-time 10 "${AUTH_HEADER[@]}" "$URL" || true
    done
    echo "  OK: warmup concluído"
else
    echo "[2/4] Warmup pulado (WARMUP=0)"
fi

# --- 3) Benchmark ------------------------------------------------------------
echo "[3/4] Executando benchmark ($TOTAL reqs, concorrência $CONCURRENCY)..."

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# Uma linha por requisição: "http_code time_total" (segundos; awk converte p/ ms).
RESULTS_FILE="$TMP_DIR/results.txt"
CURL_FMT='%{http_code} %{time_total}\n'

# Gera a URL $TOTAL vezes e dispara em paralelo com xargs -P.
seq "$TOTAL" | xargs -P "$CONCURRENCY" -I{} \
    curl -s -o /dev/null -w "$CURL_FMT" \
         --connect-timeout 5 --max-time 30 \
         "${AUTH_HEADER[@]}" "$URL" > "$RESULTS_FILE" 2>/dev/null || true

# Normaliza falhas de curl (linhas sem formato esperado) para "000 0".
if [[ ! -s "$RESULTS_FILE" ]]; then
    echo "  ERRO: nenhuma resposta capturada. Verifique conectividade com $URL" >&2
    exit 1
fi

# --- 4) Análise estatística (awk) -------------------------------------------
echo "[4/4] Analisando resultados..."
echo ""

# LC_ALL=C: força ponto decimal (.), evitando bug em locales com vírgula (pt_BR).
LC_ALL=C awk -v p95_thresh="$P95_THRESHOLD" -v err_thresh="$ERROR_THRESHOLD" '
    function ceil(x) { return (x == int(x)) ? x : int(x) + 1 }
    NF >= 2 {
        code = $1
        ms   = ($2 * 1000)
        if (code == "" || code == "000" || !(code ~ /^[2-5][0-9][0-9]$/)) {
            code = "ERR"; errors++
        }
        codes[code]++
        if (code != "ERR") {
            sum += ms; count++
            lat[NR] = ms
            if (min == "" || ms < min) min = ms
            if (ms > max) max = ms
        }
        total++
    }
    END {
        if (count == 0) {
            print "ERRO: nenhuma requisição bem-sucedida para analisar."
            exit 3
        }
        avg = sum / count

        # Ordena latências para percentis (bubble sort; awk não tem sort nativo).
        n = 0
        for (i in lat) { sorted[++n] = lat[i] }
        for (i = 1; i <= n; i++) {
            for (j = i + 1; j <= n; j++) {
                if (sorted[j] < sorted[i]) { t = sorted[i]; sorted[i] = sorted[j]; sorted[j] = t }
            }
        }
        p50 = sorted[int(ceil((50/100) * n))]
        p90 = sorted[int(ceil((90/100) * n))]
        p95 = sorted[int(ceil((95/100) * n))]
        p99 = sorted[int(ceil((99/100) * n))]
        rps = (total > 0) ? total / (max / 1000) : 0

        printf "  ── Resultados ────────────────────────────────\n"
        printf "  Requisições       : %d (sucesso: %d, erro: %d)\n", total, count, errors
        printf "  Throughput (est.) : %.1f req/s\n", rps
        printf "  ── Latência (ms) ─────────────────────────────\n"
        printf "  mínima  : %8.2f\n", min
        printf "  média   : %8.2f\n", avg
        printf "  p50     : %8.2f\n", p50
        printf "  p90     : %8.2f\n", p90
        printf "  p95     : %8.2f\n", p95
        printf "  p99     : %8.2f\n", p99
        printf "  máxima  : %8.2f\n", max
        printf "  ── Status HTTP ───────────────────────────────\n"
        for (c in codes) printf "  HTTP %s : %d (%.1f%%)\n", c, codes[c], (codes[c]/total)*100
        printf "  ── Veredito ──────────────────────────────────\n"

        fail = 0
        if (p95 > p95_thresh) {
            printf "  ✗ p95 (%.2fms) ACIMA do limite (%dms)\n", p95, p95_thresh
            fail = 1
        } else {
            printf "  ✓ p95 (%.2fms) dentro do limite (%dms)\n", p95, p95_thresh
        }
        err_pct = (total > 0) ? (errors / total) * 100 : 100
        if (err_pct > err_thresh) {
            printf "  ✗ taxa de erro (%.2f%%) ACIMA do limite (%.2f%%)\n", err_pct, err_thresh
            fail = 1
        } else {
            printf "  ✓ taxa de erro (%.2f%%) dentro do limite (%.2f%%)\n", err_pct, err_thresh
        }

        if (fail) { printf "\n  RESULTADO: DEGRADADO (fora dos limites)\n"; exit 1 }
        printf "\n  RESULTADO: OK — dentro dos limites definidos\n"
    }
' "$RESULTS_FILE"
