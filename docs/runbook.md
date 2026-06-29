# Wallet Track — Runbook de Operacoes (M10)

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

Runbook para operacao do Wallet Track em producao no Google Cloud Run.
Atualizado em 2026-06-24.

---

## 1. Arquitetura em producao

```
                      Telegram API
                           |
                      (webhook POST)
                           |
                           v
                 +-------------------+
                 |   Cloud Run        |  southamerica-east1
                 |   wallet-track    |  512Mi / 1vCPU / concurrency=1
                 +--------+----------+
                          |
          +---------------+---------------+
          |               |               |
          v               v               v
    +-----------+  +-----------+  +--------------+
    | MariaDB    |  | Sheets    |  | Secret Mgr   |
    | 11.8       |  | (Google)  |  | (7 secrets)  |
    +-----------+  +-----------+  +--------------+

    +------------+  +-----------+  +------------+
    | DeepSeek   |  | Gemini    |  | Scheduler  |
    | (API Key)  |  | (API Key) |  | (cron 5min)|
    +------------+  +-----------+  +------------+
```

- **Cloud Run**: servico HTTP (FrankenPHP + Laravel) exposto publicamente (`--allow-unauthenticated`)
- **MariaDB**: banco relacional (MariaDB 11.8), fonte de verdade principal
- **Sheets**: planilha Google sincronizada periodicamente (sync pending a cada 5 min)
- **Secret Manager**: 7 secrets (SA JSON, tokens, API keys, APP_KEY) — montados como volume ou env vars
- **Cloud Scheduler**: acorda a instância do Cloud Run via HTTP a cada 5 min; a sincronização é feita pelo scheduler interno do Laravel (`Schedule::command('transactions:sync-pending')` em `routes/console.php`)
- **Telegram**: webhook registrado em `POST /webhook/telegram` com secret token

---

## 2. Checklist de primeiro deploy

### 2.1 Provisionar infraestrutura (uma vez)

```bash
# 1. Autenticar no GCP
gcloud auth login
gcloud config set project wallet-track-499719

# 2. Rodar provisionamento completo
./scripts/deploy.sh all
```

O script cria:
- 7 secrets no Secret Manager (interativo — pede valores nao encontrados no `.env`)
- Service account `wallet-track-run` com permissoes de Secret Manager e Cloud Run
- Repositorio Docker `wallet-track` no Artifact Registry (`southamerica-east1`)

### 2.2 Provisionar trigger de build automatico

```bash
./scripts/deploy.sh trigger
```

O script provisiona de forma idempotente tres recursos do Cloud Build:

1. **GitHub Connection** (`github-wallet-track`) - cria a conexao entre o
   projeto GCP e o GitHub. Na primeira execucao, exibe uma URL de OAuth
   que deve ser aberta no navegador para autorizar o Google Cloud Build
   no repositorio `github.com/huuninn/wallet-track`. O script aguarda
   (timeout de 5 minutos) ate que a autorizacao seja concluida.
2. **Repository Link** (`wallet-track`) - vincula o repositorio GitHub a
   connection criada no passo anterior.
3. **Build Trigger** (`wallet-track-deploy`) - configura o gatilho que
   executa `cloudbuild.yaml` a cada push na branch `main`.

O subcomando `trigger` **nao esta incluido** em `deploy.sh all` - deve
ser executado separadamente, assim como `scheduler`.

### 2.3 Primeiro deploy

```bash
git checkout main
git push origin main
```

O pipeline automaticamente:
1. Builda a imagem Docker
2. Pusha para Artifact Registry
3. Deploya no Cloud Run
4. Registra o webhook no Telegram
5. Atualiza `APP_URL` e `TELEGRAM_WEBHOOK_URL`

### 2.4 Pos-deploy: configurar scheduler

```bash
./scripts/deploy.sh scheduler
```

---

## 3. Deploy continuo

Todo push para `main` dispara o pipeline do Cloud Build automaticamente.
Nao ha necessidade de acoes manuais - o webhook do Telegram e
re-registrado a cada deploy (idempotente).

Para evitar um deploy, trabalhe em uma branch separada e faca merge
apenas quando pronto para produzir.

## 4. Rollback

### 4.1 Rollback para revisao anterior (traffic split)

```bash
# Lista revisoes disponiveis
gcloud run revisions list \
  --service=wallet-track \
  --region=southamerica-east1

# Redireciona 100% do trafego para a revisao anterior.
# Substitua REVISION_NAME pelo nome exibido no comando acima.
gcloud run services update-traffic wallet-track \
  --region=southamerica-east1 \
  --to-revisions=REVISION_NAME=100
```

### 4.2 Redeploy de um commit especifico

```bash
# Obter o SHA do commit anterior (via GitHub ou git log)
COMMIT_SHA="abc123..."

gcloud run deploy wallet-track \
  --image=southamerica-east1-docker.pkg.dev/wallet-track-499719/wallet-track/app:${COMMIT_SHA} \
  --region=southamerica-east1
```

---

## 5. Troubleshooting

### 5.1 Bot do Telegram nao responde

**Sintoma**: Mensagens enviadas ao bot nao tem resposta.

**Verificacao**:
```bash
# 1. Verificar se o webhook esta registrado
BOT_TOKEN=$(gcloud secrets versions access latest --secret=telegram-bot-token --project=wallet-track-499719)
curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"
```

**Causas comuns**:
- `TELEGRAM_WEBHOOK_URL` desatualizado → re-deploy (push to main) corrige
- Secret token divergente → verificar `telegram-webhook-secret-token` no Secret Manager
- Cloud Run instance parada (min-instances=0) → envie uma mensagem, o cold start (~3-5s) inicializa a instancia

### 5.2 Sync de transacoes falhou

**Sintoma**: `php artisan transactions:sync-pending` retorna `failed > 0` (via Cloud Logging).

**Verificacao**:
```bash
# Logs do Cloud Run (filtrar por severidade)
gcloud logging read 'resource.type="cloud_run_revision" AND severity>=ERROR' \
  --project=wallet-track-499719 \
  --limit=10
```

**Causas comuns**:
- Planilha Google nao compartilhada com a service account `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com`
- Planilha renomeada/movida → atualizar `GOOGLE_SHEETS_SPREADSHEET_ID` (env var no Cloud Run)
- Cota da API do Sheets excedida (improvavel para uso pessoal)

### 5.3 Cold start lento (>5s)

**Sintoma**: Primeira requisicao apos periodo de inatividade demora muito.

**Causas**:
- Min-instances=0 → container precisa iniciar do zero
- Conexao com MariaDB (DNS + TCP handshake) no primeiro request
- Composer autoload (mitigado por `--optimize` no build)
- Templates Blade (mitigado por `view:cache` no build)

**Mitigacoes ja aplicadas**:
- `--cpu-boost` no Cloud Run (acelera startup)
- `--no-cpu-throttling` (CPU disponivel durante startup)
- `view:cache` e `event:cache` no build (Dockerfile)
- `--optimize-autoloader` (autoload estatico, sem filesystem scan)

### 5.4 Webhook mal configurado

**Sintoma**: Telegram envia updates mas recebe HTTP 401.

**Causa**: `X-Telegram-Bot-Api-Secret-Token` nao confere com o `telegram-webhook-secret-token` do Secret Manager.

**Solucao**: Re-deploy (push to main) — o pipeline registra o webhook com o secret token correto.

### 5.5 Erro de conexao com MariaDB

**Sintoma**: Logs mostram "SQLSTATE[HY000] [2002] Connection refused" ou "Target Machine actively refused".

**Causas**:
- Host do banco incorreto ou inacessivel → verificar `DB_HOST` no Secret Manager
- Credenciais erradas → verificar `DB_USERNAME` e `DB_PASSWORD`
- Banco de dados inexistente → verificar `DB_DATABASE`
- Firewall bloqueando porta 3306 (se banco externo ao GCP)

**Verificacao**:
```bash
# Testar conectividade a partir do Cloud Run (via Cloud Shell ou VM)
mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT 1"
```

---

## 6. Rotacao de secrets

Secrets podem ser rotacionados sem re-deploy. O Cloud Run busca o valor mais recente automaticamente a cada nova instancia.

```bash
# 1. Atualizar o secret (cria nova versao)
echo -n "NOVO_VALOR" | gcloud secrets versions add MEU_SECRET --data-file=-

# 2. O Cloud Run usa a versao ":latest" automaticamente na proxima
#    inicializacao de instancia. Para forcar a troca imediata:
gcloud run services update wallet-track \
  --region=southamerica-east1 \
  --set-secrets="MEU_SECRET=MEU_SECRET:latest"
```

### Exemplo: rotacionar TELEGRAM_BOT_TOKEN

```bash
# 1. Atualizar o secret
echo -n "NOVO_TOKEN" | gcloud secrets versions add telegram-bot-token --data-file=-

# 2. Atualizar o Cloud Run e re-registrar webhook
gcloud run services update wallet-track \
  --region=southamerica-east1 \
  --set-secrets="TELEGRAM_BOT_TOKEN=telegram-bot-token:latest"

# 3. Registrar novo webhook (necessario apos troca de token)
BOT_TOKEN="NOVO_TOKEN"
SVC_URL=$(gcloud run services describe wallet-track --region=southamerica-east1 --format='value(status.url)')
SECRET_TOKEN=$(gcloud secrets versions access latest --secret=telegram-webhook-secret-token --project=wallet-track-499719)
curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  -d "url=${SVC_URL}/webhook/telegram" \
  -d "secret_token=${SECRET_TOKEN}"
```

---

## 7. Logs e observabilidade

### 7.1 Cloud Logging queries

```bash
# Erros nas ultimas 24h
gcloud logging read \
  'resource.type="cloud_run_revision" AND severity>=ERROR AND timestamp>="-P1D"' \
  --project=wallet-track-499719 \
  --limit=20

# Requisicoes com latencia > 1s
gcloud logging read \
  'resource.type="cloud_run_revision" AND jsonPayload.duration_ms>1000' \
  --project=wallet-track-499719 \
  --limit=20

# Tentativas de acesso nao autorizado (401)
gcloud logging read \
  'resource.type="cloud_run_revision" AND httpRequest.status=401' \
  --project=wallet-track-499719 \
  --limit=20
```

### 7.2 Metricas do Cloud Run

```bash
# Numero de instancias ativas
gcloud run services describe wallet-track --region=southamerica-east1 \
  --format='value(status.latestReadyRevisionName)'

# Uso de memoria/CPU (via Cloud Monitoring)
# Acessar: https://console.cloud.google.com/monitoring no projeto wallet-track-499719
```

### 7.3 Health check

```bash
SVC_URL=$(gcloud run services describe wallet-track --region=southamerica-east1 --format='value(status.url)')

# Modo padrao (sem rede)
curl -s "${SVC_URL}/health" | jq .

# Modo verbose (com database ping) — requer APP_DEBUG=true em runtime
curl -s "${SVC_URL}/health?verbose=1" | jq .
```

---

## 8. Procedimentos de emergencia

### 8.1 Remover webhook do Telegram (bot offline)

```bash
BOT_TOKEN=$(gcloud secrets versions access latest --secret=telegram-bot-token --project=wallet-track-499719)
curl -s "https://api.telegram.org/bot${BOT_TOKEN}/deleteWebhook"
```

### 8.2 Escalar servico para zero (parar tudo)

```bash
gcloud run services update wallet-track \
  --region=southamerica-east1 \
  --min-instances=0 \
  --max-instances=0
```

Para restaurar:
```bash
gcloud run services update wallet-track \
  --region=southamerica-east1 \
  --min-instances=0 \
  --max-instances=1
```

### 8.3 Desabilitar trigger do Cloud Build

```bash
# Listar triggers
gcloud builds triggers list --project=wallet-track-499719

# Desabilitar (substituir TRIGGER_ID)
gcloud builds triggers update TRIGGER_ID --disabled
```

Reabilitar:
```bash
gcloud builds triggers update TRIGGER_ID --no-disabled
```

---

## 9. Notas importantes

### 9.1 Por que NAO usamos `config:cache`

`php artisan config:cache` congela todas as chamadas `env()` no momento do build.
Como os secrets (APP_KEY, tokens, SA JSON) sao injetados pelo Cloud Run via
Secret Manager **em runtime** (apos o build), rodar `config:cache` faria com que
todas as chamadas `env()` retornassem `null` — quebrando completamente a aplicacao.

O cache de config e desnecessario em concurrency=1 com opcache ativo: a penalidade
de ler os arquivos de config a cada cold start e minima (~50ms).

### 9.2 Por que NAO usamos `route:cache`

`php artisan route:cache` serializa as rotas para PHP puro. Closures (funcoes
anonimas) nao serializam corretamente — sao convertidas para `null` no cache,
quebrando as rotas que as usam.

A rota `/cron/sync-pending` foi substituída por `Schedule::command('transactions:sync-pending')` em
`routes/console.php`. O comando Artisan é executado pelo scheduler interno do Laravel,
sem necessidade de rota HTTP pública. Manter `route:cache` desligado é seguro:

Manter `route:cache` desligado e seguro: o custo de interpretar as rotas e
negligenciável (~10ms) com opcache ativo.

### 9.3 Concurrency=1

Cloud Run com `concurrency=1` significa que cada instancia processa **apenas 1
requisicao por vez**. Isso e intencional:

- O bot e de uso pessoal (1 usuario)
- Evita race conditions em operações de leitura/escrita no banco de dados
- Simplifica o modelo mental: nao ha requisicoes simultaneas competindo por estado

### 9.4 Cold start

Com `min-instances=0`, quando o servico fica ocioso (>15min), o Cloud Run derruba
a instancia. A proxima requisicao sofre um cold start (~2-5s):

1. Cloud Run puxa a imagem do Artifact Registry (cache de borda reduz latencia)
2. Container inicia (FrankenPHP sobe Caddy + PHP runtime)
3. Primeiro request inicializa conexão TCP com MariaDB (pool de conexões)

O Telegram tem timeout de webhook generoso (varios segundos) e retenta em caso de
falha, entao cold starts ocasionais nao causam perda de mensagens.

### 9.5 Service account do Sheets

O Google Sheets usa uma service account **diferente** do Cloud Run:
`google-sheet-202@wallet-track-499719.iam.gserviceaccount.com`.

Esta SA tem permissao `roles/editor` diretamente na planilha (compartilhada via
Google Drive). Ela nao acessa o banco de dados nem Secret Manager — apenas escreve na
planilha durante a sincronizacao.

A SA do Cloud Run (`wallet-track-run`) acessa MariaDB, mas nao precisa de
permissao no Sheets (a sincronizacao usa a SA dedicada de Sheets).

### 9.6 Coluna de Itens na planilha (feature items)

A partir de jun/2026, a planilha possui 9 colunas (A-I), sendo a coluna I
dedicada aos **itens** da transacao (detalhamento item-nivel de cupons fiscais).

Para visualizar os itens na coluna I em uma planilha **existente** (criada antes
da feature), adicione o cabecalho `Itens` na celula I1 manualmente. O codigo e
idempotente — `ensureHeaders` nao sobrescreve cabecalhos existentes, apenas
preenche a linha 1 se estiver vazia. Os dados dos itens sao escritos na coluna I
independentemente da presenca do cabecalho.

Em uma planilha **nova** (linha 1 vazia), o `ensureHeaders` escreve todas as 9
colunas de cabecalho automaticamente, incluindo `Itens` em I1.
