#!/usr/bin/env bash
# ============================================================================
# Wallet Track — destroy-gcp-resources.sh
# ----------------------------------------------------------------------------
# Script destrutivo para remover toda a infraestrutura GCP do projeto
# wallet-track-499719, MANTENDO apenas o necessário para Google Sheets:
#   - Service Account google-sheet-202@... (Sheets writer)
#   - API sheets.googleapis.com
#   - API iam.googleapis.com
#   - API cloudresourcemanager.googleapis.com
#   - API generativelanguage.googleapis.com (Gemini)
#
# Modos:
#   --dry-run       Lista o que seria destruído. NÃO executa nada destrutivo.
#   --execute       Executa a destruição real, com pausas interativas.
#   --verify-only   Roda APENAS os CTs de verificação (read-only).
#   --help          Exibe esta ajuda.
#
# DEFAULT: --dry-run (seguro por default).
#
# Base: spec-fase-2-remocao-gcp.md v2.0, §10.
# ============================================================================
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuração fixa
# ---------------------------------------------------------------------------
PROJECT="wallet-track-499719"
REGION="southamerica-east1"
SA_BACKUP="${HOME}/backup/wallet-track/sa-json-2026-06-30.json"
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
TMP_DIR="tmp/gcp-destroy-2026-06-30"
LOG_FILE="tmp/destroy-gcp-resources-${TIMESTAMP}.log"

# Service Accounts a MANTER (Portão 1, INEGOCIÁVEL)
KEEP_SAS=(
  "google-sheet-202@wallet-track-499719.iam.gserviceaccount.com"
  "wallet-track-run@wallet-track-499719.iam.gserviceaccount.com"
)

# APIs a MANTER (4)
KEEP_APIS=(
  "sheets.googleapis.com"
  "iam.googleapis.com"
  "cloudresourcemanager.googleapis.com"
  "generativelanguage.googleapis.com"
)

# APIs a DESABILITAR (33)
APIS_TO_DISABLE=(
  "run.googleapis.com"
  "cloudbuild.googleapis.com"
  "cloudscheduler.googleapis.com"
  "secretmanager.googleapis.com"
  "artifactregistry.googleapis.com"
  "firestore.googleapis.com"
  "datastore.googleapis.com"
  "firebaserules.googleapis.com"
  "containerregistry.googleapis.com"
  "logging.googleapis.com"
  "monitoring.googleapis.com"
  "cloudtrace.googleapis.com"
  "telemetry.googleapis.com"
  "pubsub.googleapis.com"
  "sql-component.googleapis.com"
  "storage-api.googleapis.com"
  "storage-component.googleapis.com"
  "storage.googleapis.com"
  "bigquery.googleapis.com"
  "bigqueryconnection.googleapis.com"
  "bigquerydatapolicy.googleapis.com"
  "bigquerydatatransfer.googleapis.com"
  "bigquerymigration.googleapis.com"
  "bigqueryreservation.googleapis.com"
  "bigquerystorage.googleapis.com"
  "dataform.googleapis.com"
  "dataplex.googleapis.com"
  "analyticshub.googleapis.com"
  "cloudapis.googleapis.com"
  "servicemanagement.googleapis.com"
  "serviceusage.googleapis.com"
  "iamcredentials.googleapis.com"
  "containeranalysis.googleapis.com"
)

# Service agents órfãos esperados (após desabilitar APIs)
ORPHAN_AGENTS=(
  "serviceAccount:service-706720442016@containerregistry.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@firebase-rules.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@gcp-sa-artifactregistry.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@gcp-sa-cloudbuild.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@gcp-sa-cloudscheduler.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@gcp-sa-firestore.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@gcp-sa-pubsub.iam.gserviceaccount.com"
  "serviceAccount:service-706720442016@serverless-robot-prod.iam.gserviceaccount.com"
)

TOTAL_STEPS=0
STEP=0

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log_msg() {
  local prefix="[STEP ${STEP}/${TOTAL_STEPS}]"
  echo "${prefix} $*" | tee -a "${LOG_FILE}"
}

skip_msg() {
  echo "[SKIP] $*" | tee -a "${LOG_FILE}"
}

abort_msg() {
  echo "[ABORT] $*" | tee -a "${LOG_FILE}"
  exit 1
}

ok_msg() {
  echo "  OK: $*" | tee -a "${LOG_FILE}"
}

warn_msg() {
  echo "  WARN: $*" | tee -a "${LOG_FILE}"
}

fail_msg() {
  echo "  FALHA: $*" | tee -a "${LOG_FILE}"
}

confirm() {
  local prompt="$1"
  read -r -p "${prompt} [y/N] " response
  [[ "${response,,}" == "y" ]]
}

check_exists() {
  local resource_type="$1"
  eval "$2" >/dev/null 2>&1
}

delete_if_exists() {
  local check_cmd="$1"
  local delete_cmd="$2"
  local label="$3"
  local post_check_cmd="${4:-true}"

  STEP=$((STEP + 1))
  log_msg "Processando ${label}..."

  if eval "${check_cmd}" >/dev/null 2>&1; then
    eval "${delete_cmd}" && ok_msg "${label} destruído" || fail_msg "${label} — investigar"
    # Post-check verification
    if ! eval "${post_check_cmd}" >/dev/null 2>&1; then
      ok_msg "verificação pós: ${label} confirmado removido"
    else
      warn_msg "verificação pós: ${label} ainda detectado"
    fi
  else
    skip_msg "${label} já não existe"
  fi
}

# ---------------------------------------------------------------------------
# Modo --dry-run
# ---------------------------------------------------------------------------
mode_dry_run() {
  echo "============================================================"
  echo " Wallet Track — GCP Resource Destroyer (DRY-RUN)"
  echo " Projeto: ${PROJECT}"
  echo " Data:    $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo "============================================================"
  echo ""

  # SA identification
  echo "--- SA ID (§10.1.3) ---"
  echo "  Backup SA JSON: ${SA_BACKUP}"
  if [ -f "${SA_BACKUP}" ]; then
    local SA_EMAIL
    SA_EMAIL=$(cat "${SA_BACKUP}" | jq -r '.client_email' 2>/dev/null || echo "ERRO")
    echo "  client_email: ${SA_EMAIL}"
    local found=0
    for sa in "${KEEP_SAS[@]}"; do
      if [[ "${SA_EMAIL}" == "${sa}" ]]; then
        found=1
        break
      fi
    done
    if [ $found -eq 1 ]; then
      ok_msg "SA conhecida (${SA_EMAIL})"
    else
      fail_msg "SA ${SA_EMAIL} NÃO está na lista KEEP — ABORTAR"
    fi
  else
    echo "  Backup NÃO encontrado em ${SA_BACKUP}"
    echo "  (Será necessário antes do --execute)"
  fi

  # List blocks
  echo ""
  echo "--- Blocos de destruição (ordem ótima) ---"
  echo ""

  local blocks=(
    "1|Cloud Run service|wallet-track (${REGION})|gcloud run services delete wallet-track --region=${REGION} --project=${PROJECT} --quiet|IRREVERSÍVEL"
    "2|Cloud Scheduler job|wallet-track-sync-pending (${REGION})|gcloud scheduler jobs delete wallet-track-sync-pending --location=${REGION} --project=${PROJECT} --quiet|REVERSÍVEL"
    "3|Secret Manager|wallet-track-env + github-wallet-track-... (global)|gcloud secrets delete wallet-track-env --project=${PROJECT} --quiet|IRREVERSÍVEL"
    "4|Artifact Registry|wallet-track (${REGION})|gcloud artifacts repositories delete wallet-track --location=${REGION} --project=${PROJECT} --quiet|IRREVERSÍVEL"
    "5|Cloud Build connection|github-wallet-track (${REGION})|gcloud builds connections delete github-wallet-track --region=${REGION} --project=${PROJECT} --quiet|PARCIALMENTE REVERSÍVEL"
    "6|Firestore databases|wallet-track-db + wallet-track-dev-db + (default)|gcloud firestore databases delete ... --project=${PROJECT} --quiet|IRREVERSÍVEL (soft 7d)"
    "7|APIs (33)|run, cloudbuild, cloudscheduler, secretmanager, artifactregistry, firestore, datastore, ...|gcloud services disable <api> --project=${PROJECT} --force|REVERSÍVEL"
    "8|IAM cleanup|8 service agents órfãos|gcloud projects remove-iam-policy-binding ...|REVERSÍVEL"
    "9|SA wallet-track-run delete|wallet-track-run@... (decisão Portão 2)|gcloud iam service-accounts delete wallet-track-run@... --project=${PROJECT} --quiet|IRREVERSÍVEL"
  )

  for block in "${blocks[@]}"; do
    IFS='|' read -r num resource cmd reversibility <<< "${block}"
    echo "  [BLOCO ${num}] ${resource}"
    echo "    Recurso:   ${cmd%% --*}"  # just the resource name part
    echo "    Comando:   ${cmd}"
    echo "    Reversível: ${reversibility}"
    echo ""
  done

  echo "--- Resumo ---"
  echo "  Total: 9 blocos, 33 APIs, ~70 comandos gcloud."
  echo "  Tempo estimado: 5-10 min."
  echo "  Reversíveis: 3 (Scheduler, APIs, IAM)."
  echo "  Irreversíveis: 5 (Cloud Run, AR, Secrets, Firestore, SA delete)."
  echo "  Parcialmente reversíveis: 1 (Cloud Build)."
  echo ""
}

# ---------------------------------------------------------------------------
# Modo --execute
# ---------------------------------------------------------------------------
mode_execute() {
  echo "============================================================"
  echo " Wallet Track — GCP Resource Destroyer (EXECUTE)"
  echo " Projeto: ${PROJECT}"
  echo " Data:    $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo " Log:     ${LOG_FILE}"
  echo "============================================================"
  echo ""
  echo "⚠️  ATENÇÃO: Este modo DESTRÓI recursos remotamente."
  echo "   As ações são IRREVERSÍVEIS para: Cloud Run, Artifact Registry,"
  echo "   Secret Manager, Firestore databases."
  echo ""

  # Pré-condição: backup SA
  if [ ! -f "${SA_BACKUP}" ]; then
    abort_msg "Backup SA não encontrado em ${SA_BACKUP}. Execute o backup primeiro (M0)."
  fi

  local SA_EMAIL
  SA_EMAIL=$(cat "${SA_BACKUP}" | jq -r '.client_email')
  echo "  Backup SA validado: ${SA_EMAIL}"
  echo ""

  # Criar diretório de backup
  mkdir -p "${TMP_DIR}"

  # -----------------------------------------------------------------------
  # §10.3.1: Backup-first
  # -----------------------------------------------------------------------
  echo "--- Backup-first (§10.3.1) ---"
  echo "  Criando snapshots de estado pré-remoção em ${TMP_DIR}/"

  gcloud run services list --project="${PROJECT}" --format=json > "${TMP_DIR}/cloud-run-before.json" 2>/dev/null || true
  gcloud run revisions list --service=wallet-track --region="${REGION}" --project="${PROJECT}" --format=json > "${TMP_DIR}/cloud-run-revisions-before.json" 2>/dev/null || true
  gcloud artifacts repositories list --project="${PROJECT}" --format=json > "${TMP_DIR}/artifact-registry-before.json" 2>/dev/null || true
  gcloud secrets list --project="${PROJECT}" --format=json > "${TMP_DIR}/secrets-before.json" 2>/dev/null || true
  gcloud secrets versions access latest --secret=wallet-track-env --project="${PROJECT}" > "${TMP_DIR}/wallet-track-env-before.json" 2>/dev/null || true
  gcloud secrets versions access latest --secret=github-wallet-track-github-oauthtoken-a10b00 --project="${PROJECT}" > "${TMP_DIR}/github-oauth-token-before.json" 2>/dev/null || true
  gcloud scheduler jobs list --location="${REGION}" --project="${PROJECT}" --format=json > "${TMP_DIR}/scheduler-before.json" 2>/dev/null || true
  gcloud builds connections list --region="${REGION}" --project="${PROJECT}" --format=json > "${TMP_DIR}/cloudbuild-connections-before.json" 2>/dev/null || true
  gcloud firestore databases list --project="${PROJECT}" --format=json > "${TMP_DIR}/firestore-before.json" 2>/dev/null || true
  gcloud services list --enabled --project="${PROJECT}" --format=json > "${TMP_DIR}/apis-enabled-before.json" 2>/dev/null || true
  gcloud projects get-iam-policy "${PROJECT}" --format=json > "${TMP_DIR}/iam-policy-before.json" 2>/dev/null || true
  gcloud iam service-accounts list --project="${PROJECT}" --format=json > "${TMP_DIR}/service-accounts-before.json" 2>/dev/null || true

  echo "  Snapshots criados em ${TMP_DIR}/"
  ls -la "${TMP_DIR}/"
  echo ""

  # -----------------------------------------------------------------------
  # §10.3.2: SA identification
  # -----------------------------------------------------------------------
  echo "--- SA identification (§10.3.2) ---"
  local found=0
  for sa in "${KEEP_SAS[@]}"; do
    if [[ "${SA_EMAIL}" == "${sa}" ]]; then
      found=1
      break
    fi
  done
  if [ $found -ne 1 ]; then
    abort_msg "SA ${SA_EMAIL} NÃO está na lista KEEP_SAS. ABORTAR."
  fi
  ok_msg "SA ${SA_EMAIL} está na lista KEEP_SAS"
  echo ""

  # -----------------------------------------------------------------------
  # PAUSA 1: Cloud Run + Scheduler
  # -----------------------------------------------------------------------
  echo "=== PAUSA 1/5: Cloud Run + Cloud Scheduler ==="
  if ! confirm "Destruir Cloud Run service 'wallet-track' e Cloud Scheduler job?"; then
    echo "  Pulando Bloco 1 (Cloud Run + Scheduler)."
  else
    # §10.3.3: Cloud Run
    delete_if_exists \
      "gcloud run services describe wallet-track --region=${REGION} --project=${PROJECT} --format='value(metadata.name)'" \
      "gcloud run services remove-iam-policy-binding wallet-track --region=${REGION} --project=${PROJECT} --member=allUsers --role=roles/run.invoker --quiet 2>/dev/null; gcloud run services delete wallet-track --region=${REGION} --project=${PROJECT} --quiet" \
      "Cloud Run service wallet-track" \
      "gcloud run services list --project=${PROJECT} --format='value(SERVICE)' | grep -q wallet-track"

    # §10.3.4: Cloud Scheduler
    delete_if_exists \
      "gcloud scheduler jobs describe wallet-track-sync-pending --location=${REGION} --project=${PROJECT} --format='value(name)'" \
      "gcloud scheduler jobs delete wallet-track-sync-pending --location=${REGION} --project=${PROJECT} --quiet" \
      "Cloud Scheduler job wallet-track-sync-pending" \
      "gcloud scheduler jobs list --location=${REGION} --project=${PROJECT} --format='value(name)' | grep -q wallet-track"
  fi
  echo ""

  # -----------------------------------------------------------------------
  # PAUSA 2: Secret Manager
  # -----------------------------------------------------------------------
  echo "=== PAUSA 2/5: Secret Manager ==="
  if ! confirm "Destruir secrets 'wallet-track-env' e 'github-wallet-track-...'?"; then
    echo "  Pulando Bloco 2 (Secret Manager)."
  else
    # Validar backup do wallet-track-env
    if [ ! -s "${TMP_DIR}/wallet-track-env-before.json" ]; then
      abort_msg "Backup do wallet-track-env não existe em ${TMP_DIR}/"
    fi
    if ! grep -q GOOGLE_SERVICE_ACCOUNT_JSON "${TMP_DIR}/wallet-track-env-before.json"; then
      abort_msg "Backup não contém GOOGLE_SERVICE_ACCOUNT_JSON — ABORTAR"
    fi
    ok_msg "Backup wallet-track-env validado (contém GOOGLE_SERVICE_ACCOUNT_JSON)"

    # D3: wallet-track-env
    STEP=$((STEP + 1))
    log_msg "Processando Secret wallet-track-env..."
    if gcloud secrets describe wallet-track-env --project="${PROJECT}" --format="value(name)" >/dev/null 2>&1; then
      for v in $(gcloud secrets versions list wallet-track-env --project="${PROJECT}" --format='value(name)' 2>/dev/null); do
        gcloud secrets versions destroy "$v" --secret=wallet-track-env --project="${PROJECT}" --quiet 2>/dev/null || true
      done
      gcloud secrets delete wallet-track-env --project="${PROJECT}" --quiet 2>/dev/null || true
      ok_msg "wallet-track-env destruído"
    else
      skip_msg "wallet-track-env já não existe"
    fi

    # D4: github-wallet-track-github-oauthtoken-a10b00
    STEP=$((STEP + 1))
    log_msg "Processando Secret github-wallet-track-github-oauthtoken-a10b00..."
    if gcloud secrets describe github-wallet-track-github-oauthtoken-a10b00 --project="${PROJECT}" --format="value(name)" >/dev/null 2>&1; then
      for v in $(gcloud secrets versions list github-wallet-track-github-oauthtoken-a10b00 --project="${PROJECT}" --format='value(name)' 2>/dev/null); do
        gcloud secrets versions destroy "$v" --secret=github-wallet-track-github-oauthtoken-a10b00 --project="${PROJECT}" --quiet 2>/dev/null || true
      done
      gcloud secrets delete github-wallet-track-github-oauthtoken-a10b00 --project="${PROJECT}" --quiet 2>/dev/null || true
      ok_msg "github-wallet-track-github-oauthtoken-a10b00 destruído"
    else
      skip_msg "github-wallet-track-github-oauthtoken-a10b00 já não existe"
    fi

    # Pós-verificação
    local SECRETS_COUNT
    SECRETS_COUNT=$(gcloud secrets list --project="${PROJECT}" --format='value(name)' 2>/dev/null | grep -cE 'wallet-track-env|github-wallet-track' || true)
    if [ "${SECRETS_COUNT}" = "0" ]; then
      ok_msg "0 secrets GCP-infra restantes"
    else
      warn_msg "Ainda há ${SECRETS_COUNT} secrets GCP-infra"
    fi
  fi
  echo ""

  # -----------------------------------------------------------------------
  # PAUSA 3: Artifact Registry + Cloud Build
  # -----------------------------------------------------------------------
  echo "=== PAUSA 3/5: Artifact Registry + Cloud Build ==="
  if ! confirm "Destruir Artifact Registry repo 'wallet-track' e Cloud Build connection?"; then
    echo "  Pulando Bloco 3 (AR + Cloud Build)."
  else
    # §10.3.6: Artifact Registry
    STEP=$((STEP + 1))
    log_msg "Processando Artifact Registry wallet-track..."
    if gcloud artifacts repositories describe wallet-track --location="${REGION}" --project="${PROJECT}" --format="value(name)" >/dev/null 2>&1; then
      # Deletar imagens
      for img in $(gcloud artifacts docker images list "southamerica-east1-docker.pkg.dev/${PROJECT}/wallet-track/app" --project="${PROJECT}" --format='value(version)' 2>/dev/null); do
        gcloud artifacts docker images delete "southamerica-east1-docker.pkg.dev/${PROJECT}/wallet-track/app:${img}" --project="${PROJECT}" --quiet 2>/dev/null || true
      done
      gcloud artifacts repositories delete wallet-track --location="${REGION}" --project="${PROJECT}" --quiet 2>/dev/null || true
      ok_msg "Artifact Registry wallet-track destruído"
    else
      skip_msg "Artifact Registry wallet-track já não existe"
    fi

    # §10.3.7: Cloud Build connection
    delete_if_exists \
      "gcloud builds connections describe github-wallet-track --region=${REGION} --project=${PROJECT} --format='value(name)'" \
      "gcloud builds connections delete github-wallet-track --region=${REGION} --project=${PROJECT} --quiet" \
      "Cloud Build connection github-wallet-track" \
      "gcloud builds connections list --region=${REGION} --project=${PROJECT} --format='value(name)' | grep -q github-wallet-track"
  fi
  echo ""

  # -----------------------------------------------------------------------
  # PAUSA 4: Firestore
  # -----------------------------------------------------------------------
  echo "=== PAUSA 4/5: Firestore databases ==="
  if ! confirm "Destruir Firestore databases 'wallet-track-db', 'wallet-track-dev-db' e tentar deletar '(default)'?"; then
    echo "  Pulando Bloco 4 (Firestore)."
  else
    # D7: wallet-track-db
    STEP=$((STEP + 1))
    log_msg "Deletando Firestore database wallet-track-db..."
    if gcloud firestore databases describe wallet-track-db --project="${PROJECT}" --format="value(name)" >/dev/null 2>&1; then
      gcloud firestore databases delete wallet-track-db --project="${PROJECT}" --quiet 2>/dev/null && \
        ok_msg "wallet-track-db deletado" || warn_msg "wallet-track-db — falha ao deletar (pode já estar em processo)"
    else
      skip_msg "wallet-track-db já não existe"
    fi

    # D8: wallet-track-dev-db
    STEP=$((STEP + 1))
    log_msg "Deletando Firestore database wallet-track-dev-db..."
    if gcloud firestore databases describe wallet-track-dev-db --project="${PROJECT}" --format="value(name)" >/dev/null 2>&1; then
      gcloud firestore databases delete wallet-track-dev-db --project="${PROJECT}" --quiet 2>/dev/null && \
        ok_msg "wallet-track-dev-db deletado" || warn_msg "wallet-track-dev-db — falha ao deletar (pode já estar em processo)"
    else
      skip_msg "wallet-track-dev-db já não existe"
    fi

    # D9: (default) Datastore mode — tentar deletar
    STEP=$((STEP + 1))
    log_msg "Tentando deletar Firestore database (default) (Datastore mode)..."
    if gcloud firestore databases delete "(default)" --project="${PROJECT}" --quiet 2>/dev/null; then
      ok_msg "(default) deletado"
    else
      warn_msg "(default) em Datastore mode não é deletável via gcloud — será congelado ao desabilitar datastore.googleapis.com"
    fi

    # Pós-verificação
    local REMAINING
    REMAINING=$(gcloud firestore databases list --project="${PROJECT}" --format="value(type)" 2>/dev/null | grep -c FIRESTORE_NATIVE || true)
    if [ "${REMAINING}" = "0" ]; then
      ok_msg "0 databases FIRESTORE_NATIVE restantes"
    else
      warn_msg "Ainda há ${REMAINING} databases FIRESTORE_NATIVE"
    fi
  fi
  echo ""

  # -----------------------------------------------------------------------
  # PAUSA 5: APIs
  # -----------------------------------------------------------------------
  echo "=== PAUSA 5/5: Desabilitar 33 APIs ==="
  echo "  APIs a DESABILITAR:"
  for api in "${APIS_TO_DISABLE[@]}"; do
    echo "    - ${api}"
  done
  echo "  APIs a MANTER:"
  for api in "${KEEP_APIS[@]}"; do
    echo "    + ${api}"
  done

  if ! confirm "Desabilitar estas APIs? Esta ação é reversível (gcloud services enable)."; then
    echo "  Pulando Bloco 5 (APIs)."
  else
    # Sanity: nenhuma KEEP_API deve estar na lista APIS_TO_DISABLE
    for api in "${KEEP_APIS[@]}"; do
      for disable in "${APIS_TO_DISABLE[@]}"; do
        if [[ "${api}" == "${disable}" ]]; then
          abort_msg "${api} está em APIS_TO_DISABLE — REMOVA antes de prosseguir"
        fi
      done
    done

    # Cache local das APIs atualmente habilitadas (1 chamada gcloud, reusada
    # no loop inteiro — evita 33 chamadas redundantes a `gcloud services list`).
    local ENABLED_APIS
    ENABLED_APIS=$(gcloud services list --enabled --project="${PROJECT}" --format="value(config.name)" 2>/dev/null)

    for api in "${APIS_TO_DISABLE[@]}"; do
      STEP=$((STEP + 1))
      if echo "${ENABLED_APIS}" | grep -q "^${api}$"; then
        log_msg "Desabilitando ${api}..."
        gcloud services disable "${api}" --project="${PROJECT}" --force 2>/dev/null && \
          ok_msg "${api} desabilitada" || warn_msg "${api} — falha (pode já estar desabilitada)"
      else
        skip_msg "${api} já desabilitada"
      fi
    done

    # Pós-verificação
    local ENABLED
    ENABLED=$(gcloud services list --enabled --project="${PROJECT}" --format="value(config.name)" 2>/dev/null | sort)
    local EXPECTED
    EXPECTED=$(printf "%s\n" "${KEEP_APIS[@]}" | sort)
    echo "  Enabled APIs:"
    echo "${ENABLED}"
    if [ "${ENABLED}" = "${EXPECTED}" ]; then
      ok_msg "enabled APIs == expected KEEP_APIS"
    else
      warn_msg "Diferença entre enabled e expected (pode levar alguns minutos para propagar)"
    fi
  fi
  echo ""

  # -----------------------------------------------------------------------
  # §10.3.10: IAM cleanup (service agents órfãos)
  # -----------------------------------------------------------------------
  echo "=== IAM cleanup (§10.3.10) ==="
  if ! confirm "Remover bindings de service agents órfãos?"; then
    echo "  Pulando IAM cleanup."
  else
    for agent in "${ORPHAN_AGENTS[@]}"; do
      STEP=$((STEP + 1))
      log_msg "Limpando bindings de ${agent}..."
      local ROLES
      ROLES=$(gcloud projects get-iam-policy "${PROJECT}" --format="json" 2>/dev/null \
        | python3 -c "import json,sys; d=json.load(sys.stdin); [print(b['role']) for b in d.get('bindings',[]) if '${agent}' in b.get('members',[])]" 2>/dev/null || true)
      if [ -n "${ROLES}" ]; then
        for role in ${ROLES}; do
          log_msg "  Removendo binding: ${agent} ← ${role}"
          gcloud projects remove-iam-policy-binding "${PROJECT}" \
            --member="${agent}" --role="${role}" --quiet 2>/dev/null || true
        done
      else
        skip_msg "${agent} — sem bindings restantes"
      fi
    done

    # Limpar roles órfãs de wallet-track-run (Portão 2, decisão)
    STEP=$((STEP + 1))
    log_msg "Removendo roles órfãs de wallet-track-run@..."
    for role in "roles/run.invoker" "roles/datastore.user"; do
      if gcloud projects get-iam-policy "${PROJECT}" --format="json" 2>/dev/null \
        | python3 -c "import json,sys; d=json.load(sys.stdin); print('found' if any('wallet-track-run@' in m for b in d.get('bindings',[]) for m in b.get('members',[]) if b['role']=='${role}') else '')" 2>/dev/null | grep -q found; then
        gcloud projects remove-iam-policy-binding "${PROJECT}" \
          --member="serviceAccount:wallet-track-run@wallet-track-499719.iam.gserviceaccount.com" \
          --role="${role}" --quiet 2>/dev/null || true
        ok_msg "${role} removido de wallet-track-run@..."
      else
        skip_msg "${role} já removido de wallet-track-run@..."
      fi
    done

    # Limpar roles/datastore.user de google-sheet-202 (Portão 2, decisão)
    STEP=$((STEP + 1))
    log_msg "Removendo roles/datastore.user de google-sheet-202@..."
    if gcloud projects get-iam-policy "${PROJECT}" --format="json" 2>/dev/null \
      | python3 -c "import json,sys; d=json.load(sys.stdin); print('found' if any('google-sheet-202@' in m for b in d.get('bindings',[]) for m in b.get('members',[]) if b['role']=='roles/datastore.user') else '')" 2>/dev/null | grep -q found; then
      gcloud projects remove-iam-policy-binding "${PROJECT}" \
        --member="serviceAccount:google-sheet-202@wallet-track-499719.iam.gserviceaccount.com" \
        --role="roles/datastore.user" --quiet 2>/dev/null || true
      ok_msg "roles/datastore.user removido de google-sheet-202@..."
    else
      skip_msg "roles/datastore.user já removido de google-sheet-202@..."
    fi

    # Pós-verificação
    local REMAINING_ORPHANS
    REMAINING_ORPHANS=$(gcloud projects get-iam-policy "${PROJECT}" --format="json" 2>/dev/null \
      | python3 -c "import json,sys; d=json.load(sys.stdin); [b['role'] for b in d.get('bindings',[]) if any('service-' in m for m in b.get('members',[]))]" 2>/dev/null | wc -l)
    if [ "${REMAINING_ORPHANS}" = "0" ]; then
      ok_msg "0 service agent bindings órfãs"
    else
      warn_msg "Ainda há ${REMAINING_ORPHANS} bindings de service agents"
    fi
  fi
  echo ""

  # -----------------------------------------------------------------------
  # Deleção SA wallet-track-run (Portão 2, decisão inegociável)
  # -----------------------------------------------------------------------
  echo "=== Deleção SA wallet-track-run@... (Portão 2) ==="
  if confirm "DELETAR Service Account wallet-track-run@... (Cloud Run identity, orphaned)?"; then
    STEP=$((STEP + 1))
    log_msg "Deletando SA wallet-track-run@..."
    if gcloud iam service-accounts describe "wallet-track-run@wallet-track-499719.iam.gserviceaccount.com" --project="${PROJECT}" --format="value(email)" >/dev/null 2>&1; then
      gcloud iam service-accounts delete "wallet-track-run@wallet-track-499719.iam.gserviceaccount.com" --project="${PROJECT}" --quiet 2>/dev/null && \
        ok_msg "SA wallet-track-run@... deletada" || fail_msg "SA wallet-track-run@... — falha ao deletar"
    else
      skip_msg "SA wallet-track-run@... já não existe"
    fi
  else
    echo "  SA wallet-track-run@... mantida (decisão manual de manter)."
  fi
  echo ""

  # -----------------------------------------------------------------------
  # §10.3.11: Verificação final
  # -----------------------------------------------------------------------
  echo "=== Verificação final (§10.3.11) ==="
  run_verify_checks
  echo ""

  echo "============================================================"
  echo " [DONE] Bloco D completo."
  echo " Log: ${LOG_FILE}"
  echo "============================================================"
}

# ---------------------------------------------------------------------------
# Modo --verify-only
# ---------------------------------------------------------------------------
run_verify_checks() {
  echo "--- Pós-condições (CT-J-12 a CT-J-19) ---"
  local all_pass=true

  # PC1: Apenas 4 APIs habilitadas
  echo -n "  PC1 (APIs): "
  local ENABLED
  ENABLED=$(gcloud services list --enabled --project="${PROJECT}" --format="value(config.name)" 2>/dev/null | sort)
  local EXPECTED
  EXPECTED=$(printf "%s\n" "${KEEP_APIS[@]}" | sort)
  if [ "${ENABLED}" = "${EXPECTED}" ]; then
    echo "PASS"
  else
    echo "FAIL — enabled: $(echo "${ENABLED}" | tr '\n' ' ')"
    all_pass=false
  fi

  # PC2: Cloud Run vazio
  echo -n "  PC2 (Cloud Run): "
  if gcloud run services list --project="${PROJECT}" --format="value(SERVICE)" 2>/dev/null | grep -q .; then
    echo "FAIL — ainda há services"
    all_pass=false
  else
    echo "PASS"
  fi

  # PC3: Artifact Registry vazio
  echo -n "  PC3 (Artifact Registry): "
  if gcloud artifacts repositories list --project="${PROJECT}" --format="value(name)" 2>/dev/null | grep -q .; then
    echo "FAIL — ainda há repositórios"
    all_pass=false
  else
    echo "PASS"
  fi

  # PC4: Secret Manager vazio
  echo -n "  PC4 (Secret Manager): "
  if gcloud secrets list --project="${PROJECT}" --format="value(name)" 2>/dev/null | grep -q .; then
    echo "FAIL — ainda há secrets"
    all_pass=false
  else
    echo "PASS"
  fi

  # PC5: Cloud Scheduler vazio
  echo -n "  PC5 (Cloud Scheduler): "
  if gcloud scheduler jobs list --location="${REGION}" --project="${PROJECT}" --format="value(name)" 2>/dev/null | grep -q .; then
    echo "FAIL — ainda há jobs"
    all_pass=false
  else
    echo "PASS"
  fi

  # PC6: Cloud Build connections vazio
  echo -n "  PC6 (Cloud Build): "
  if gcloud builds connections list --region="${REGION}" --project="${PROJECT}" --format="value(name)" 2>/dev/null | grep -q .; then
    echo "FAIL — ainda há connections"
    all_pass=false
  else
    echo "PASS"
  fi

  # PC7: Firestore sem NATIVE
  echo -n "  PC7 (Firestore NATIVE): "
  local NATIVE_COUNT
  NATIVE_COUNT=$(gcloud firestore databases list --project="${PROJECT}" --format="value(type)" 2>/dev/null | grep -c FIRESTORE_NATIVE || true)
  if [ "${NATIVE_COUNT}" = "0" ]; then
    echo "PASS"
  else
    echo "FAIL — ${NATIVE_COUNT} databases FIRESTORE_NATIVE restantes"
    all_pass=false
  fi

  # PC8: SAs MANTER intactas
  echo -n "  PC8 (SAs google-sheet-202): "
  if gcloud iam service-accounts list --project="${PROJECT}" --format="value(email)" 2>/dev/null | grep -q "google-sheet-202@"; then
    echo "PASS"
  else
    echo "FAIL — google-sheet-202@... não encontrada"
    all_pass=false
  fi

  # PC9: Billing ativo
  echo -n "  PC9 (Billing): "
  local BILLING
  BILLING=$(gcloud beta billing projects describe "${PROJECT}" --format="value(billingEnabled)" 2>/dev/null || echo "Unknown")
  if [ "${BILLING}" = "True" ]; then
    echo "PASS"
  else
    echo "FAIL — billingEnabled=${BILLING}"
    all_pass=false
  fi

  # PC10: Chave SA no backup
  echo -n "  PC10 (SA backup): "
  if [ -s "${SA_BACKUP}" ]; then
    echo "PASS"
  else
    echo "FAIL — ${SA_BACKUP} não existe ou vazio"
    all_pass=false
  fi

  echo ""
  if [ "${all_pass}" = true ]; then
    echo "  TODOS OS CTs PASSARAM."
    return 0
  else
    echo "  ALGUNS CTs FALHARAM."
    return 1
  fi
}

mode_verify_only() {
  echo "============================================================"
  echo " Wallet Track — GCP Resource Destroyer (VERIFY-ONLY)"
  echo " Projeto: ${PROJECT}"
  echo " Data:    $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo "============================================================"
  echo ""
  echo "  Modo read-only. Nenhum recurso será alterado."
  echo ""

  run_verify_checks
}

# ---------------------------------------------------------------------------
# Help
# ---------------------------------------------------------------------------
mode_help() {
  cat <<EOF
Uso: scripts/destroy-gcp-resources.sh [MODE]

MODES:
  --dry-run       Lista o que seria destruído. NÃO executa nada destrutivo.
  --execute       Executa a destruição real, com pausas interativas.
  --verify-only   Roda APENAS os CTs de verificação (read-only).
  --help          Exibe esta ajuda.

DEFAULT: --dry-run (seguro por default).

Projeto: ${PROJECT}
Região:  ${REGION}
EOF
}

# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------
MODE="${1:---dry-run}"

# Inicializar log
mkdir -p tmp
echo "=== destroy-gcp-resources.sh — ${TIMESTAMP} ===" > "${LOG_FILE}"
echo "Mode: ${MODE}" >> "${LOG_FILE}"

case "${MODE}" in
  --dry-run)
    mode_dry_run
    ;;
  --execute)
    mode_execute
    ;;
  --verify-only)
    mode_verify_only
    ;;
  --help|-h|help)
    mode_help
    ;;
  *)
    echo "Modo desconhecido: ${MODE}"
    mode_help
    exit 1
    ;;
esac
