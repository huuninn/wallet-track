#!/usr/bin/env bash
# ============================================================================
# Wallet Track — Script de provisionamento de infraestrutura GCP (M10)
# ----------------------------------------------------------------------------
# Provisiona ou atualiza recursos do Google Cloud para o deploy do serviço.
#
# Uso:
#   scripts/deploy.sh secrets          Criar/atualizar secrets no Secret Manager
#   scripts/deploy.sh iam              Criar service account e conceder permissões
#   scripts/deploy.sh registry         Criar repositório no Artifact Registry
#   scripts/deploy.sh scheduler        Criar/atualizar job do Cloud Scheduler
#   scripts/deploy.sh all              Executa secrets → iam → registry
#   scripts/deploy.sh help             Mostra esta ajuda
#
# Pré-requisitos:
#   - gcloud CLI instalado e autenticado (gcloud auth login)
#   - Permissões: roles/owner ou equivalente no projeto
#   - Projeto GCP: wallet-track-499719
#
# Idempotente: recursos já existentes são detectados e pulados.
# Segurança: valores de secrets nunca são ecoados no terminal.
# ============================================================================
set -euo pipefail

# ---------------------------------------------------------------------------
# Constantes do projeto
# ---------------------------------------------------------------------------
readonly PROJECT_ID="wallet-track-499719"
readonly REGION="southamerica-east1"
readonly AR_REPO="wallet-track"
readonly SERVICE_NAME="wallet-track"
readonly RUN_SA_NAME="wallet-track-run"
readonly RUN_SA="${RUN_SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"

# ---------------------------------------------------------------------------
# Cores para output (desligadas se stdout não for TTY)
# ---------------------------------------------------------------------------
if [[ -t 1 ]]; then
    readonly RED='\033[31m'
    readonly GREEN='\033[32m'
    readonly CYAN='\033[36m'
    readonly YELLOW='\033[33m'
    readonly RESET='\033[0m'
else
    readonly RED='' GREEN='' CYAN='' YELLOW='' RESET=''
fi

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log()  { echo -e "${CYAN}▸${RESET} $*"; }
ok()   { echo -e "  ${GREEN}✔${RESET} $*"; }
warn() { echo -e "  ${YELLOW}⚠${RESET} $*" >&2; }
err()  { echo -e "  ${RED}✘${RESET} $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Validação de pré-requisitos
# ---------------------------------------------------------------------------
check_gcloud() {
    if ! command -v gcloud &>/dev/null; then
        err "gcloud CLI não encontrado. Instale: https://cloud.google.com/sdk/docs/install"
    fi

    local active
    active=$(gcloud config get-value project 2>/dev/null || true)
    if [[ "$active" != "$PROJECT_ID" ]]; then
        log "Configurando projeto gcloud para $PROJECT_ID..."
        gcloud config set project "$PROJECT_ID"
    fi
}

# ---------------------------------------------------------------------------
# Subcomando: secrets
# ---------------------------------------------------------------------------
cmd_secrets() {
    log "Provisionando secrets no Secret Manager..."

    # Mapa de secrets: nome → método de obtenção do valor
    # Cada entrada é: "nome_do_secret" "descricao_amigavel" "variavel_env"
    local -a secrets=(
        "google-service-account-json:SA JSON:GOOGLE_SERVICE_ACCOUNT_JSON_PATH"
        "telegram-bot-token:Telegram Bot Token:TELEGRAM_BOT_TOKEN"
        "telegram-webhook-secret-token:Webhook Secret Token:TELEGRAM_WEBHOOK_SECRET_TOKEN"
        "deepseek-api-key:DeepSeek API Key:DEEPSEEK_API_KEY"
        "gemini-api-key:Gemini API Key:GEMINI_API_KEY"
        "cron-secret-token:Cron Secret Token:CRON_SECRET_TOKEN"
        "app-key:App Key (Laravel):APP_KEY"
    )

    for entry in "${secrets[@]}"; do
        IFS=':' read -r secret_name desc env_var <<< "$entry"

        # Verifica se o secret já existe
        if gcloud secrets describe "$secret_name" --project="$PROJECT_ID" &>/dev/null; then
            ok "Secret '$secret_name' já existe — pulando."
            continue
        fi

        log "Criando secret '$secret_name' ($desc)..."

        local value=""
        local source_desc=""

        # Tenta ler do arquivo .env local primeiro (não interativo em CI/CD)
        if [[ -f ".env" ]]; then
            value=$(grep -E "^${env_var}=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//' || true)
            if [[ -n "$value" ]]; then
                source_desc=".env local"
            fi
        fi

        # Caso especial: SA JSON é lido de arquivo, não de string inline
        if [[ "$secret_name" == "google-service-account-json" ]]; then
            if [[ -z "$value" ]] || [[ ! -f "$value" ]]; then
                # Prompt interativo para o caminho do arquivo
                echo ""
                log "Arquivo JSON da Service Account necessário."
                log "Se ele estiver na raiz do projeto (ex.: wallet-track-*.json),"
                log "informe o caminho completo."
                read -r -p "  Caminho do arquivo SA JSON: " value
                if [[ ! -f "$value" ]]; then
                    err "Arquivo não encontrado: $value"
                fi
                source_desc="arquivo $value"
            fi

            # Lê o conteúdo do arquivo para criar o secret
            log "Criando secret a partir do arquivo: $value"
            gcloud secrets create "$secret_name" \
                --project="$PROJECT_ID" \
                --data-file="$value" \
                --replication-policy="automatic"

            ok "Secret '$secret_name' criado (fonte: $source_desc)."
            continue
        fi

        # Para os demais secrets: prompt interativo se não encontrado no .env
        if [[ -z "$value" ]]; then
            echo ""
            log "Valor para '$secret_name' ($desc) não encontrado no .env."
            read -r -s -p "  Digite o valor (não será exibido): " value
            echo ""
            if [[ -z "$value" ]]; then
                warn "Valor vazio — pulando criação do secret '$secret_name'."
                continue
            fi
            source_desc="entrada interativa"
        fi

        # Cria o secret com o valor
        echo -n "$value" | gcloud secrets create "$secret_name" \
            --project="$PROJECT_ID" \
            --data-file=- \
            --replication-policy="automatic"

        ok "Secret '$secret_name' criado (fonte: $source_desc)."
    done

    log "Secrets provisionados com sucesso."
}

# ---------------------------------------------------------------------------
# Subcomando: iam
# ---------------------------------------------------------------------------
cmd_iam() {
    log "Provisionando IAM (service account + permissões)..."

    # Cria a service account se não existir
    if gcloud iam service-accounts describe "$RUN_SA" --project="$PROJECT_ID" &>/dev/null; then
        ok "Service account '$RUN_SA' já existe — pulando criação."
    else
        log "Criando service account '$RUN_SA_NAME'..."
        gcloud iam service-accounts create "$RUN_SA_NAME" \
            --project="$PROJECT_ID" \
            --display-name="Wallet Track Cloud Run Service Account"
        ok "Service account '$RUN_SA' criada."
    fi

    # Lista de secrets para os quais a SA precisa de acesso
    local -a secret_names=(
        "google-service-account-json"
        "telegram-bot-token"
        "telegram-webhook-secret-token"
        "deepseek-api-key"
        "gemini-api-key"
        "cron-secret-token"
        "app-key"
    )

    log "Concedendo acesso ao Secret Manager..."
    for secret_name in "${secret_names[@]}"; do
        if ! gcloud secrets describe "$secret_name" --project="$PROJECT_ID" &>/dev/null; then
            warn "Secret '$secret_name' não existe — execute 'secrets' primeiro. Pulando permissão."
            continue
        fi

        # Verifica se a binding já existe (idempotência)
        local secret_full="projects/${PROJECT_ID}/secrets/${secret_name}"
        if gcloud secrets get-iam-policy "$secret_name" --project="$PROJECT_ID" 2>/dev/null \
            | grep -q "serviceAccount:${RUN_SA}"; then
            ok "Permissão já existe para secret '$secret_name' — pulando."
            continue
        fi

        gcloud secrets add-iam-policy-binding "$secret_name" \
            --project="$PROJECT_ID" \
            --member="serviceAccount:${RUN_SA}" \
            --role="roles/secretmanager.secretAccessor" \
            --quiet
        ok "Permissão secretAccessor concedida para '$secret_name'."
    done

    # Datastore user (Firestore em Native mode)
    log "Concedendo roles/datastore.user (Firestore)..."
    if gcloud projects get-iam-policy "$PROJECT_ID" 2>/dev/null \
        | grep -q "serviceAccount:${RUN_SA}.*roles/datastore.user"; then
        ok "Permissão datastore.user já existe — pulando."
    else
        gcloud projects add-iam-policy-binding "$PROJECT_ID" \
            --member="serviceAccount:${RUN_SA}" \
            --role="roles/datastore.user" \
            --quiet
        ok "Permissão datastore.user concedida."
    fi

    # Cloud Run invoker (necessário para Cloud Scheduler chamar o serviço)
    # Com --allow-unauthenticated, esta permissão é opcional — mas documentamos.
    log "Concedendo roles/run.invoker (para Cloud Scheduler)..."
    if gcloud projects get-iam-policy "$PROJECT_ID" 2>/dev/null \
        | grep -q "serviceAccount:${RUN_SA}.*roles/run.invoker"; then
        ok "Permissão run.invoker já existe — pulando."
    else
        gcloud projects add-iam-policy-binding "$PROJECT_ID" \
            --member="serviceAccount:${RUN_SA}" \
            --role="roles/run.invoker" \
            --quiet
        ok "Permissão run.invoker concedida (opcional com --allow-unauthenticated)."
    fi

    log "IAM provisionado com sucesso."
}

# ---------------------------------------------------------------------------
# Subcomando: registry
# ---------------------------------------------------------------------------
cmd_registry() {
    log "Provisionando Artifact Registry..."

    local repo_path="${REGION}-docker.pkg.dev/${PROJECT_ID}/${AR_REPO}"

    if gcloud artifacts repositories describe "$AR_REPO" \
        --location="$REGION" --project="$PROJECT_ID" &>/dev/null; then
        ok "Repositório '$AR_REPO' já existe em $REGION — pulando."
    else
        log "Criando repositório Docker '$AR_REPO' em $REGION..."
        gcloud artifacts repositories create "$AR_REPO" \
            --repository-format=docker \
            --location="$REGION" \
            --project="$PROJECT_ID" \
            --description="Wallet Track Docker images"
        ok "Repositório '$AR_REPO' criado."
    fi

    echo ""
    log "Repositório: $repo_path"
}

# ---------------------------------------------------------------------------
# Subcomando: scheduler
# ---------------------------------------------------------------------------
cmd_scheduler() {
    log "Provisionando Cloud Scheduler..."

    local job_name="wallet-track-sync-pending"

    # Obtém a URL do Cloud Run (precisa que o serviço já exista)
    local svc_url
    svc_url=$(gcloud run services describe "$SERVICE_NAME" \
        --region="$REGION" --project="$PROJECT_ID" \
        --format='value(status.url)' 2>/dev/null || true)

    if [[ -z "$svc_url" ]]; then
        warn "Serviço Cloud Run '$SERVICE_NAME' ainda não existe em $REGION."
        warn "Execute o primeiro deploy (push to main → Cloud Build) antes de configurar o scheduler."
        warn "Após o deploy, execute: scripts/deploy.sh scheduler"
        return 0
    fi

    local cron_url="${svc_url}/cron/sync-pending"

    # Obtém o cron token do Secret Manager
    local cron_token
    cron_token=$(gcloud secrets versions access latest \
        --secret="cron-secret-token" --project="$PROJECT_ID" 2>/dev/null || true)

    if [[ -z "$cron_token" ]]; then
        err "Secret 'cron-secret-token' não encontrado ou vazio. Execute 'secrets' primeiro."
    fi

    if gcloud scheduler jobs describe "$job_name" \
        --location="$REGION" --project="$PROJECT_ID" &>/dev/null; then

        log "Atualizando job '$job_name'..."
        gcloud scheduler jobs update http "$job_name" \
            --location="$REGION" \
            --project="$PROJECT_ID" \
            --schedule="*/5 * * * *" \
            --uri="$cron_url" \
            --http-method="GET" \
            --headers="X-Cron-Token=${cron_token}" \
            --time-zone="America/Sao_Paulo" \
            --attempt-deadline="180s" \
            --quiet
        ok "Job '$job_name' atualizado."
    else
        log "Criando job '$job_name'..."
        gcloud scheduler jobs create http "$job_name" \
            --location="$REGION" \
            --project="$PROJECT_ID" \
            --schedule="*/5 * * * *" \
            --uri="$cron_url" \
            --http-method="GET" \
            --headers="X-Cron-Token=${cron_token}" \
            --time-zone="America/Sao_Paulo" \
            --attempt-deadline="180s" \
            --quiet
        ok "Job '$job_name' criado."
    fi

    log "Cloud Scheduler configurado: $cron_url a cada 5 minutos."
}

# ---------------------------------------------------------------------------
# Subcomando: help
# ---------------------------------------------------------------------------
cmd_help() {
    sed -n 's/^#   scripts\/deploy.sh /  /p' "$0"
}

# ---------------------------------------------------------------------------
# Subcomando: all (secrets → iam → registry; scheduler precisa de deploy)
# ---------------------------------------------------------------------------
cmd_all() {
    cmd_secrets
    echo ""
    cmd_iam
    echo ""
    cmd_registry

    echo ""
    log "============================================"
    log "Infraestrutura base provisionada com sucesso!"
    log "============================================"
    echo ""
    log "Próximos passos:"
    log "  1. Configure o trigger do Cloud Build (push to main)"
    log "  2. Push para main → build → deploy automático"
    log "  3. Após o primeiro deploy, execute: scripts/deploy.sh scheduler"
    echo ""
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    check_gcloud

    local cmd="${1:-help}"

    case "$cmd" in
        secrets)   cmd_secrets ;;
        iam)       cmd_iam ;;
        registry)  cmd_registry ;;
        scheduler) cmd_scheduler ;;
        all)       cmd_all ;;
        help|--help|-h) cmd_help ;;
        *)
            echo "Subcomando desconhecido: $cmd" >&2
            echo ""
            cmd_help
            exit 1
            ;;
    esac
}

main "$@"
