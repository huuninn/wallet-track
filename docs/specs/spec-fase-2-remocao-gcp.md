# Especificação Técnica — Fase 2: Remoção do GCP (mantendo Google Sheets)

> **Projeto:** Wallet Track
> **Versão:** 2.0 — 30/06/2026 (atualização: ações destrutivas remotas no GCP agora são parte do entregável do agente executor)
> **Status:** Especificação Técnica executável (entrega do agente `spec-designer`)
> **Base:** Decisões do Portão 1 (Aprovado em 30/06/2026) — manter o projeto GCP vivo **apenas como provedor de identidade** da Service Account do Google Sheets; remover toda a infraestrutura GCP-infra (Cloud Run, Cloud Build, Cloud Scheduler, Secret Manager, Artifact Registry, Firestore) do repositório e dos recursos remotos.
> **Mudanças nesta v2.0:**
> 1. **Correção factual de SA:** a v1.x assumia que a chave no arquivo `wallet-track-499719-54b725c0bb5d.json` pertencia à SA `wallet-track-run@…`. **Investigação com `cat ... | jq` revela que o `client_email` do JSON é `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com`** (display name: "google-sheet"). Esta é **a SA que escreve no Google Sheets**. A SA `wallet-track-run@…` é a identidade de serviço do Cloud Run (orphaned após a remoção). **Implicação:** a chave que precisa ser preservada para o Sheets continuar funcionando pertence à `google-sheet-202`, não à `wallet-track-run`. Detalhes em §1.2, §1.3 e §10.1.3.
> 2. **Ações destrutivas remotas no GCP passam a ser parte do entregável do agente executor** (antes era checklist manual do usuário). Especificação completa em §11.
> 3. **Inventário real verificado via gcloud v573.0.0** (30/06/2026): 4 SAs (2 custom + 1 auto-managed Gemini + 1 Default compute + 9 service agents), 1 Cloud Run service com 8 revisions, 1 Artifact Registry repo (431 MB), 2 Secrets, 1 Cloud Scheduler job (NÃO vazio), 1 Cloud Build connection, 3 Firestore databases, 36 APIs habilitadas (manter 4, desabilitar 32). Detalhes em §10.1.

---

## Mapa de renumeração de seções (v1.1 → v2.0)

A inclusão da nova §10 ("Ações Destrutivas Remotas no GCP") entre o procedimento (§9) e os riscos (que passam de §10 para §11) provoca renumeração em cascata a partir de §10. Esta tabela serve de índice para navegação rápida entre versões e para o `manual-tester` referenciar CTs sem ambiguidade.

| v1.1 (antigo) | v2.0 (atual) | Conteúdo |
|---|---|---|
| §9 | §9 | Procedimento de execução (expandido com Bloco D — destruição remota) |
| §9.4 | §9.4 → §10 (referência) | Antigo checklist manual de destruição; substituído por §10 (mas referência em §9.4 é preservada como ponteiro) |
| §9.5 | §9.5 | Validação final integrada (pós-destruição remota) — **inalterado** |
| **— (novo)** | **§10** | **Ações Destrutivas Remotas no GCP** (substitui antigo §9.4) |
| §10 (Riscos) | §11 | Riscos técnicos e mitigações (renumerado; expandido com R11–R14) |
| §11 (Critérios) | §12 | Critérios de aceitação da Fase 2 (renumerado; §12.4 expandido com CT-J-12 a CT-J-19) |
| §12 (Out-of-scope) | §13 | Out-of-scope explícito — **inalterado** (apenas itens 2 e 8 corrigidos para refletir a SA correta) |
| §13 (Anexo) | §14 | Anexo: arquivos modificados (expandido com nova seção "Ações Destrutivas Remotas") |
| §14 (Próximos passos) | §15 | Próximos passos (para o `tech-planner`) — **inalterado** |
| §15 (Clarificações) | §16 | Clarificações F2 (expandido com nota v2.0 e AMB #6) |

---

## 0. Contexto rápido

- O projeto usa hoje o GCP para **três finalidades distintas**:
  1. **Deploy do bot** (Cloud Run + Cloud Build + Artifact Registry + Secret Manager) — **sai**.
  2. **Persistência de domínio** (Firestore) — **sai** (já migrado para MariaDB em M7; restam apenas referências históricas no código/config).
  3. **Autenticação da Service Account que escreve no Google Sheets** — **fica**. O projeto GCP `wallet-track-499719` permanece vivo apenas como IAM provider; a SA `wallet-track-run@wallet-track-499719.iam.gserviceaccount.com` continua existindo com a mesma chave.
- O deploy da nova VPS está **fora do escopo desta task** (decisão explícita do Portão 1). O container de runtime será usado em ambiente dev (`docker-compose.yml` + `docker-compose.dev.yml`) até que a task de migração para VPS aconteça.

### 0.1 Descoberta factual de v2.0 — correção sobre qual SA detém a chave

Em 30/06/2026, durante o inventário remoto via `gcloud`, o agente `spec-designer` extraiu o `client_email` do arquivo `wallet-track-499719-54b725c0bb5d.json` (a ser deletado do repo em §2.1):

```bash
cat wallet-track-499719-54b725c0bb5d.json | jq -r '.client_email'
# → "google-sheet-202@wallet-track-499719.iam.gserviceaccount.com"

gcloud iam service-accounts describe google-sheet-202@wallet-track-499719.iam.gserviceaccount.com   --project=wallet-track-499719 --format="value(displayName)"
# → "google-sheet"
```

**Implicação para o Portão 1:** a v1.x da spec declarava "a SA `wallet-track-run@…` continua existindo com a mesma chave". **Isto estava incorreto** — a chave no JSON é da SA `google-sheet-202`, e esta é a SA que escreve no Google Sheets (display name "google-sheet"). A SA `wallet-track-run@…` (display name "Wallet Track Cloud Run Service Account") é a identidade do **Cloud Run service**, que está prestes a ser destruído. A correção **não** renegociada a decisão de Portão 1 ("manter SA e chave para Sheets") — apenas corrige **QUAL** SA é essa: `google-sheet-202`, não `wallet-track-run`.

A v2.0 desta spec reflete essa correção em §1.2, §1.3, §3.3 (caminho do JSON) e §10.1.3 (passo de identificação de SA antes da destruição).

---

## 1. Visão técnica da solução

### 1.1 Padrão arquitetural

**Remoção não-afetando-produto**: o conjunto de mudanças é uma *remoção* (delete-and-clean), não uma refatoração. A invariante central é:

> Após a aplicação desta spec, **`transactions:sync-pending`, `/sync`, e o `InMemorySheetsGateway` continuam funcionando exatamente como hoje**, sem alteração de comportamento, sem necessidade de rotação da chave SA, sem mudança na estrutura do banco de dados.

O princípio de execução é **"edição mínima + deleção máxima"**:

- Tudo que toca **Cloud Run / Cloud Build / Cloud Scheduler / Secret Manager / Artifact Registry / Firestore / deploy CI** é deletado.
- Tudo que toca **Sheets API + Service Account** é preservado (código, env vars, mensagens de erro).
- Comentários textuais que mencionam "Cloud Run" / "Cloud Scheduler" / "Secret Manager" são editados para refletir a nova realidade (VPS + Secret Manager *externo* ao app, futuramente), preservando o valor documental.

### 1.2 Garantias de não-regressão (Sheets 100% funcional)

| Garantia | Como é assegurada |
|---|---|
| **SA `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (a que escreve no Sheets) mantida com mesma chave** | (a) Chave preservada em `~/backup/wallet-track/sa-json-2026-06-30.json` (ver §9.1 + §10.1.3). (b) A chave **NÃO** está sendo deletada do IAM (apenas o arquivo `.json` no repo). (c) A planilha Sheets continua compartilhada com esta SA. (d) `SheetsServiceProvider` / `GoogleCredentials` intocados. |
| `App\Services\Google\SheetsService` intocado | Não listado em §2.2 (arquivos modificados); nenhuma assinatura pública muda. |
| `App\Services\Google\SheetsGateway` (interface) intocado | Idem. |
| `App\Services\Google\GoogleSheetsGateway` (impl real) intocado | Idem. |
| `App\Services\Google\InMemorySheetsGateway` intocado | Idem. |
| `App\Actions\SyncSheet` / `SyncsSheet` (interface) intocados | Idem. |
| `App\Console\Commands\SyncPendingTransactions` intocado | Idem (apenas comentários sobre "Cloud Scheduler" atualizados). |
| `App\Console\Commands\RemoveOriginColumn` | **Editado cirurgicamente** (spec §2.2.10.1) — `app(SheetsGateway::class)` foi movido para DEPOIS do dry-run check, para que `--dry-run` funcione sem credenciais Google (AMB #1). Comportamento de runtime inalterado. |
| `app/Providers/SheetsServiceProvider.php` intocado | Idem. |
| Tabela `transactions` (colunas `sync_*` + `spreadsheet_row_id` + índices) | Decisão de Portão 1: schema intacto, sem migrations de remoção. |
| Suites PHPUnit que tocam Sheets | `tests/Feature/Actions/SyncSheetTest.php`, `tests/Feature/Console/SyncPendingTransactionsCommandTest.php`, `tests/Feature/Console/RemoveOriginColumnTest.php`, partes de `tests/Feature/Items/E2eItemsFlowTest.php`, `tests/Feature/Commands/SyncHandlerTest.php`, `tests/Unit/Services/Google/SheetsServiceTest.php`, `tests/Unit/Services/Google/GoogleCredentialsTest.php` — todas permanecem com **0 ajustes**, exceto `HealthControllerTest` (ver §6.2). |
| `php artisan list` continua mostrando `transactions:sync-pending` e `sheets:remove-origin-column` | Confirmado por inspeção do código; nenhuma assinatura ou classe é removida. |
| Docker dev continua funcionando | `docker compose up` e `make up-dev` continuam operacionais após as mudanças em §5. |

### 1.3 Estado pós-execução (resumo executivo)

| Recurso | Antes | Depois |
|---|---|---|
| Projeto GCP `wallet-track-499719` | Vivo (Cloud Run, Firestore, etc.) | Vivo, **apenas como IAM provider** (Sheets API habilitada + SA com chave existente) |
| Service Account `wallet-track-run@wallet-track-499719.iam.gserviceaccount.com` (Cloud Run identity) | Existe | **Mantida** (orphaned pós-remoção; sem role `roles/run.invoker` funcional; chave preservada) — ver §10.1.3 e §11 (R12) para decisão sobre deletar SA após Cloud Run destruído. |
| Service Account `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (Sheets writer) | Existe | **Mantida, mesma chave** — esta é a SA que de fato escreve no Google Sheets; a chave dela está no arquivo JSON sendo deletado (ver §0.1) |
| Planilha Google Sheets | Compartilhada com a SA | **Inalterada** |
| Código de Sheets (`SheetsService`, `GoogleSheetsGateway`, `InMemorySheetsGateway`, `SyncSheet`, `SyncsSheet`, `SheetsServiceProvider`) | Presente | **Inalterado** |
| Deps Composer Sheets (`google/apiclient`, `google/apiclient-services`, `google/auth`, `firebase/php-jwt`, `google-gemini-php/client`) | Instaladas | **Mantidas** |
| `.env` / `.env.dev` / `.env.example` | Contêm `GOOGLE_CLOUD_PROJECT_ID`, `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`, `GOOGLE_SERVICE_ACCOUNT_JSON`, `FIRESTORE_DATABASE_ID` | Apenas `GOOGLE_SHEETS_SPREADSHEET_ID`, `GOOGLE_SHEETS_SHEET_NAME`, `GOOGLE_SHEETS_CATEGORIES_SHEET_NAME` + `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`/`GOOGLE_SERVICE_ACCOUNT_JSON` (Sheets) |
| `.env.bak` | Existe (inclui `CRON_SECRET_TOKEN`, `TELESCOPE_*`, `FIRESTORE_DATABASE_ID`) | **Deletado** |
| `cloudbuild.yaml`, `scripts/deploy.sh`, `scripts/strip-google-services.php` | Existem | **Deletados** |
| `wallet-track-499719-54b725c0bb5d.json` | Existe | **Deletado** (chave preservada no Secret Manager externo) |
| Docs 100% GCP (`runbook.md`, `viability-report.md`, `comparativo-preco-infra.md`, `comparativo-vps.md`, `infra-pessoal.md`) | Existem | **Deletados** (informação histórica preservada em commits anteriores) |
| Demais docs | Têm seções GCP | **Seções GCP reescritas** (ver §8) |
| Recursos remotos (Cloud Run, Artifact Registry, Secret Manager, Cloud Build, Cloud Scheduler, Firestore, IAM bindings órfãs) | Existem | **Destruídos pelo usuário** (checklist manual §9.4) |

---

## 2. Mudanças por arquivo/categoria

### 2.1 Arquivos a deletar (lista exaustiva)

| # | Caminho | Justificativa (1 linha) |
|---|---|---|
| 1 | `cloudbuild.yaml` | Pipeline CI/CD do Cloud Build → Cloud Run; sem Cloud Run, fica morto. |
| 2 | `scripts/deploy.sh` | Provisionador de infraestrutura GCP (`gcloud builds connections`, `gcloud run deploy`, `gcloud artifacts`, `gcloud secrets`); sem GCP, fica morto. |
| 3 | `scripts/strip-google-services.php` | Stripper de service stubs do `google/apiclient-services` para reduzir imagem Docker; não é mais necessário (imagem Docker de produção sai de escopo, e o script só era invocado no Dockerfile de produção — ver §5.3). |
| 4 | `wallet-track-499719-54b725c0bb5d.json` | Chave JSON da Service Account commitada acidentalmente; cobertura do `.gitignore` (`wallet-track-499719-*.json`) impede recorrência. A chave em si permanece no Secret Manager externo. |
| 5 | `.env.bak` | Backup do `.env` com histórico de `CRON_SECRET_TOKEN` (já depreciado) e `TELESCOPE_*` (não utilizado). Decisão de Portão 1: deletar (não preservar). |
| 6 | `docs/runbook.md` | 100% sobre Cloud Run / Secret Manager / Cloud Logging / Cloud Scheduler. |
| 7 | `docs/viability-report.md` | 100% sobre o GATE M0 que validava `google/cloud-firestore` + extensões `grpc`/`protobuf`; sem Firestore, fica histórico morto. |
| 8 | `docs/comparativo-preco-infra.md` | Comparativo PaaS (Railway × Heroku × Render × GCP Cloud Run); sem Cloud Run como alternativa, fica histórico morto. |
| 9 | `docs/comparativo-vps.md` | Comparativo VPS (Locaweb × Hetzner × Contabo); foi útil para a decisão de VPS mas já cumpriu seu papel. |
| 10 | `docs/infra-pessoal.md` | Anotações pessoais de infraestrutura (já está no `.gitignore` desde antes, mas está no repo); sem Cloud Run, fica histórico morto. |

> **Notas**:
> - O arquivo `infra-pessoal.md` já está listado em `.gitignore` (`/docs/infra-pessoal.md`), o que significa que **não é commitado**. Verificar se ele existe localmente (sim, conforme `read` em `docs/`). Como não está no Git remoto, o `git rm` não é estritamente necessário, mas o `rm` local é parte da limpeza da working tree.
> - Os arquivos `firestore.indexes.json` e qualquer `firestore.rules` **não existem** (verificado via `glob`); nenhuma ação.

### 2.2 Arquivos a modificar

Para cada arquivo: **o que muda**, **como muda**, **critério de aceitação**.

#### 2.2.1 `composer.json` e `composer.lock`

**O que muda:** Nenhuma. As deps Sheets (`google/apiclient`, `google/apiclient-services`, `google/auth`, `firebase/php-jwt`, `google-gemini-php/client`) **permanecem** porque são Sheets-API e Gemini (não GCP). A dep `google/cloud-firestore` já não está em `composer.json` (foi removida em M7 — ver `CHANGELOG.md` linha 13). O `composer.lock` **não deve ser tocado manualmente** — será regenerado por `composer install` ou `composer update`.

**Como muda:** Sem edição manual. Apenas rodar `composer install --no-interaction` após as outras mudanças (passo §9.2.8) para garantir que o autoloader não referencia arquivos removidos.

**Critério de aceitação:** `composer install --dry-run --ignore-platform-reqs` retorna 0 e não reporta conflitos. `php artisan package:discover` lista os 7 providers habituais sem erro. As classes Sheets continuam resolúveis: `php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\SheetsService') ? 'OK' : 'FALHA';"` imprime `OK`.

#### 2.2.2 `config/google.php` — **ALTERAÇÃO CIRÚRGICA**

**O que muda:** O bloco `'cloud' => ['project_id' => env('GOOGLE_CLOUD_PROJECT_ID')]` é **removido** (era GCP-infra: identificador do projeto GCP). As chaves `service_account_json_path` e `service_account_json` **permanecem** (relevantes para resolver o `keyFile` da Sheets API).

**Como muda:**

1. **Remover** o bloco `cloud` inteiro (linhas 25-30 atuais):
   ```php
   // Identificador do projeto GCP.
   'cloud' => [
       'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
   ],
   ```
2. **Atualizar** o docblock do topo (linhas 5-22): remover a menção a `GOOGLE_CLOUD_PROJECT_ID` e ao GCP como bloco de "identidade do projeto"; reescrever para refletir que o config agora é **puramente sobre a integração Google Sheets** (Service Account + spreadsheet).
3. **Manter inalterados:** bloco `sheets` (linhas 49-53), bloco `service_account_json_path` (linha 75), bloco `service_account_json` (linha 78).

**Critério de aceitação:**

```bash
grep -n 'GOOGLE_CLOUD_PROJECT_ID\|cloud.*project_id' config/google.php
# → 0 matches

grep -n 'service_account_json\|GOOGLE_SHEETS' config/google.php
# → mantém matches (relevante para Sheets)
```

`php -r "require 'vendor/autoload.php'; var_export(config('google'));"` retorna um array **sem** a chave `cloud`, com as chaves `sheets.*` e `service_account_json*` populadas.

#### 2.2.3 `config/telegram.php`

**O que muda:** Comentário da linha 57-58 (`webhook_url`) menciona "Cloud Run, M10". Reescrever para indicar o novo estado (ngrok em dev; túnel público ou VPS em prod — esta task não decide o deploy).

**Como muda:**

- Linhas 57-58 atuais:
  ```php
  // URL pública do webhook. Definida apenas após deploy (Cloud Run, M10)
  // ou ngrok (dev). Vazio = webhook ainda não registrado no Telegram.
  ```
- Reescrever para:
  ```php
  // URL pública do webhook. Definida após deploy (VPS + túnel reverso) ou
  // ngrok (dev). Vazio = webhook ainda não registrado no Telegram.
  ```

**Critério de aceitação:** `grep -n 'Cloud Run' config/telegram.php` → 0 matches.

#### 2.2.4 `config/gemini.php`

**O que muda:** Comentário da linha 34 menciona "o Cloud Run permite até 300s". Reescrever para a nova realidade.

**Como muda:**

- Linha 34 atual: `// o Cloud Run permite até 300s. O cliente usa PSR-18 discovery.`
- Reescrever para: `// O ambiente de produção (VPS) permite até 300s no worker Octane. O cliente usa PSR-18 discovery.`

**Critério de aceitação:** `grep -n 'Cloud Run' config/gemini.php` → 0 matches.

#### 2.2.5 `config/octane.php`

**O que muda:** Comentário da linha 232 (`max_execution_time`) menciona "Cloud Run timeout (spec §14)".

**Como muda:**

- Linha 232 atual: `'max_execution_time' => 300, // Cloud Run timeout (spec §14)`
- Reescrever para: `'max_execution_time' => 300, // VPS/Octane worker — limite de request HTTP (spec §14)`

**Critério de aceitação:** `grep -n 'Cloud Run' config/octane.php` → 0 matches.

#### 2.2.6 `app/Http/Controllers/HealthController.php` — **ALTERAÇÃO CIRÚRGICA IMPORTANTE**

**O que muda:**

1. **Docblock** (linha 16): "Health check para Cloud Run (M10)" → "Health check para a app (VPS/Otane worker — não há probe externo nesta task)".
2. **Docblock** (linha 33): remover a frase sobre `--allow-unauthenticated` (específica de Cloud Run); reescrever para "O endpoint é público por design (não há autenticação HTTP — o projeto é bot-only e este probe é exposto pelo servidor HTTP local)".
3. **Docblock** (linha 82): remover a menção a "estrutura de infraestrutura" no contexto Cloud Run; manter o essencial (não vaza em produção).
4. **Array `CRITICAL_ENV_CHECKS`** (linhas 50-57): **remover** a entrada `'GOOGLE_CLOUD_PROJECT_ID' => 'checkGoogleCloudProjectId'`. A entrada `GOOGLE_SERVICE_ACCOUNT_JSON` é **mantida** (relevante para Sheets).
5. **Método `checkGoogleCloudProjectId()`** (linhas 239-244): **remover** integralmente.
6. **Método `checkGoogleServiceAccount()`** (linhas 246-256): **manter** sem alterações. Ele já é Sheets-aware (verifica `google.service_account_json_path` e `google.service_account_json`).
7. **Comentário da linha 247** ("Verifica se ao menos uma das duas fontes de credencial Google está presente"): **manter** mas atualizar para "… fontes de credencial Google **para a Sheets API**".

**Como muda:**

- Trecho a remover do array (linhas 50-57 atuais):
  ```php
  private const array CRITICAL_ENV_CHECKS = [
      'APP_KEY' => 'checkAppKey',
      'TELEGRAM_BOT_TOKEN' => 'checkTelegramBotToken',
      'GOOGLE_CLOUD_PROJECT_ID' => 'checkGoogleCloudProjectId',         // ← REMOVER
      'GOOGLE_SERVICE_ACCOUNT_JSON' => 'checkGoogleServiceAccount',
      'DEEPSEEK_API_KEY' => 'checkDeepseekApiKey',
      'GEMINI_API_KEY' => 'checkGeminiApiKey',
  ];
  ```
- Resultado (linhas 50-56 finais):
  ```php
  private const array CRITICAL_ENV_CHECKS = [
      'APP_KEY' => 'checkAppKey',
      'TELEGRAM_BOT_TOKEN' => 'checkTelegramBotToken',
      'GOOGLE_SERVICE_ACCOUNT_JSON' => 'checkGoogleServiceAccount',
      'DEEPSEEK_API_KEY' => 'checkDeepseekApiKey',
      'GEMINI_API_KEY' => 'checkGeminiApiKey',
  ];
  ```
- Método `checkGoogleCloudProjectId()` a remover (linhas 239-244):
  ```php
  private function checkGoogleCloudProjectId(): bool
  {
      $val = config('google.cloud.project_id');
      return is_string($val) && trim($val) !== '';
  }
  ```

**Critério de aceitação:**

```bash
grep -n 'GOOGLE_CLOUD_PROJECT_ID\|checkGoogleCloudProjectId\|google\.cloud\.project_id' app/Http/Controllers/HealthController.php
# → 0 matches

php artisan test --filter HealthControllerTest
# → todos os testes verdes após ajuste mínimo em §6.2
```

#### 2.2.7 `app/Http/Controllers/TelegramWebhookController.php`

**O que muda:** Comentário da linha 43 menciona "destacar nos logs do Cloud Run".

**Como muda:**

- Linha 43 atual: `// destacar nos logs do Cloud Run. Mesmo assim responde 200`
- Reescrever para: `// destacar nos logs de produção (stderr JSON, futuro). Mesmo assim responde 200`

**Critério de aceitação:** `grep -n 'Cloud Run' app/Http/Controllers/TelegramWebhookController.php` → 0 matches.

#### 2.2.8 `app/Console/Commands/SetTelegramWebhook.php`

**O que muda:**

- Docblock (linhas 17-19): "Cloud Run em M10" → "deploy em VPS (futuro) ou ngrok em dev".
- Mensagem ao usuário (linha 48): "ngrok em dev, Cloud Run em prod" → "ngrok em dev, túnel reverso/VPS em prod".

**Como muda:**

- Linhas 17-19 atuais:
  ```php
  * Executar após apontar TELEGRAM_WEBHOOK_URL para a URL pública definitiva
  * (Cloud Run em M10, ou ngrok em dev). Rode `php artisan config:clear`
  * antes se alterou variáveis de ambiente.
  ```
- Reescrever para:
  ```php
  * Executar após apontar TELEGRAM_WEBHOOK_URL para a URL pública definitiva
  * (VPS + túnel reverso, ou ngrok em dev). Rode `php artisan config:clear`
  * antes se alterou variáveis de ambiente.
  ```
- Linha 48 atual: `$this->info('Defina a URL pública (ex.: ngrok em dev, Cloud Run em prod) no .env e rode `php artisan config:clear`.');`
- Reescrever para: `$this->info('Defina a URL pública (ex.: ngrok em dev, VPS + túnel em prod) no .env e rode `php artisan config:clear`.');`

**Critério de aceitação:** `grep -n 'Cloud Run' app/Console/Commands/SetTelegramWebhook.php` → 0 matches.

#### 2.2.9 `app/Console/Commands/SyncPendingTransactions.php`

**O que muda:** 3 comentários mencionam "Cloud Scheduler" e "Cloud Run" (linhas 19, 69, 279).

**Como muda:**

- Linha 19 atual:
  ```php
   * Disparado pelo Cloud Scheduler (cron a cada 5 min) e pelo handler
  ```
  Reescrever para:
  ```php
   * Disparado pelo Laravel Scheduler (cron a cada 5 min, configurado em
   * routes/console.php) e pelo handler
  ```
- Linha 69 atual:
  ```php
   * 20 transações cabem em ~10s de latência Sheets API (500ms/call) — folga dentro do timeout
   * de 300s do Cloud Run.
  ```
  Reescrever para:
  ```php
   * 20 transações cabem em ~10s de latência Sheets API (500ms/call) — folga dentro do timeout
   * de 300s do request (config/octane.php).
  ```
- Linha 279 atual:
  ```php
   *    MATAM o loop. O orquestrador (Cloud Scheduler) vê exit≠0 e
  ```
  Reescrever para:
  ```php
   *    MATAM o loop. O orquestrador (Laravel Scheduler) vê exit≠0 e
  ```

**Critério de aceitação:** `grep -n 'Cloud Scheduler\|Cloud Run' app/Console/Commands/SyncPendingTransactions.php` → 0 matches.

#### 2.2.10 `app/Bot/Handlers/SyncHandler.php`

**O que muda:** Docblock linha 32 menciona "timeout de 300s do Cloud Run".

**Como muda:**

- Linha 31-32 atual:
  ```php
   * em-processo na thread do webhook. A duração máxima típica é ~10s para
   * 20 transações (500ms/call Sheets API) — bem dentro do timeout de 300s
   * do Cloud Run. Vantagem: feedback IMEDIATO ao usuário com contadores
  ```
- Reescrever para:
  ```php
   * em-processo na thread do webhook. A duração máxima típica é ~10s para
   * 20 transações (500ms/call Sheets API) — bem dentro do max_execution_time
   * de 300s do worker Octane (config/octane.php). Vantagem: feedback
   * IMEDIATO ao usuário com contadores
  ```

**Critério de aceitação:** `grep -n 'Cloud Run' app/Bot/Handlers/SyncHandler.php` → 0 matches.

#### 2.2.10.1 `app/Console/Commands/RemoveOriginColumn.php` — **ALTERAÇÃO CIRÚRGICA**

> **Origem:** clarificação F2 AMB #1 (resolução do `manual-tester`). A spec original declarava este command como "intocado" (§1.2). A mudança é **mínima e justificada** pelo CT-C-08: o `handle()` resolvia `app(SheetsGateway::class)` (linha 30 do código original) **antes** do dry-run check, então `--dry-run` falhava com `RuntimeException` em qualquer ambiente sem credencial Google. Esta correção separa as responsabilidades: dry-run = pure metadata; execução real = Sheets.

**O que muda:** A linha `$gateway = app(SheetsGateway::class);` é **movida** de antes do `if ($this->option('dry-run'))` para depois (entre o dry-run return e o `if (! $this->confirm(...))`). Nenhuma assinatura de método muda; nenhum teste existente quebra.

**Como muda (diff conceitual):**

- **Removido** (linha 30 original):
  ```php
  /** @var SheetsGateway $gateway */
  $gateway = app(SheetsGateway::class);
  ```
  Posicionado **antes** do bloco dry-run.

- **Adicionado** (após o `return self::SUCCESS` do dry-run):
  ```php
  /** @var SheetsGateway $gateway */
  $gateway = app(SheetsGateway::class);
  ```
  Posicionado **antes** do `if (! $this->confirm(...))`.

- **Adicionado** comentário explicativo (antes do `if ($this->option('dry-run'))`):
  ```php
  // O dry-run resolve apenas metadados estáticos (sheetId, columnIndex)
  // e imprime o que SERIA feito. Ele NÃO instancia SheetsGateway
  // (resolver o gateway exige credenciais Google válidas). Esta
  // separação é deliberada (spec §16 AMB #1) para que --dry-run
  // funcione em qualquer ambiente, mesmo sem GOOGLE_SERVICE_ACCOUNT_JSON
  // ou GOOGLE_SERVICE_ACCOUNT_JSON_PATH configurados.
  ```

**Critério de aceitação:**

```bash
# 1. Sintaxe preservada
php -l app/Console/Commands/RemoveOriginColumn.php
# → "No syntax errors detected"

# 2. --dry-run NÃO tenta resolver SheetsGateway (funciona sem credenciais)
php artisan sheets:remove-origin-column --dry-run
# → "DRY-RUN: a coluna G (índice 6, "Origem") seria deletada da aba principal."
# → exit 0
# (mesmo com GOOGLE_SERVICE_ACCOUNT_JSON_PATH vazio e GOOGLE_SERVICE_ACCOUNT_JSON vazio)

# 3. Teste existente continua verde
php artisan test --filter RemoveOriginColumnTest
# → 0 falhas (incluindo test_dry_run_does_not_delete_column)
```

> **Não-obrigatoriedade:** esta correção **NÃO** é estritamente necessária para os critérios de aceite da Fase 2 (o ambiente dev com `.env` apontando para o JSON continua funcionando como antes). Ela é **recomendada** porque resolve a ambiguidade do CT-C-08 e melhora a experiência de uso do `--dry-run` em qualquer ambiente. Se o time decidir não aplicá-la, o CT-C-08 deve ser marcado como "OK se mensagem clara (sem GOOGLE_CLOUD_PROJECT_ID); WARN caso contrário".

#### 2.2.12 `app/Services/Google/GoogleCredentials.php`

**O que muda:** 3 comentários no docblock mencionam "M10", "Secret Manager" (linhas 14, 23, 25). As mensagens de erro runtime (linhas 61, 67, 75, 83, 84) mencionam `GOOGLE_SERVICE_ACCOUNT_JSON` e `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` — **manter** (as env vars em si permanecem).

**Como muda:**

- Linhas 14-27 do docblock (atual):
  ```php
   *  1. Conteúdo inline via `service_account_json` (prioridade — caminho de
   *     produção, M10). Pode ser JSON cru ou base64 do JSON (Secret Manager
   *     às vezes entrega base64 para evitar problemas de aspas).
   *
   *  2. Caminho de arquivo via `service_account_json_path` (caminho de dev).
   *     Lê e decodifica o `.json` da service account do disco.
   *
   * Se ambos estiverem vazios, lança {@see RuntimeException} — quem instanciar
   * o cliente Google sem keyFile deve falhar cedo e com mensagem clara.
   *
   * Esta é a abstração que o M10 (Secret Manager) completa: em produção,
   * injetaremos o conteúdo JSON via variável de ambiente ou via fetch do
   * Secret Manager em runtime. Aqui em M5 apenas normalizamos o conteúdo
   * recebido em array PHP pronto para clientes Google (Sheets API).
  ```
- Reescrever para:
  ```php
   *  1. Conteúdo inline via `service_account_json` (prioridade — caminho
   *     de produção: VPS, container ou CI injeta a chave como env var,
   *     tipicamente base64 do JSON).
   *
   *  2. Caminho de arquivo via `service_account_json_path` (caminho de dev).
   *     Lê e decodifica o `.json` da service account do disco.
   *
   * Se ambos estiverem vazios, lança {@see RuntimeException} — quem instanciar
   * o cliente Sheets sem keyFile deve falhar cedo e com mensagem clara.
   *
   * Esta abstração é usada exclusivamente pela Google Sheets API. O projeto
   * GCP (que hospeda a Service Account) permanece vivo apenas como IAM
   * provider; a chave da SA pode ser injetada de qualquer fonte externa
   * (Secret Manager, vault, .env local) via as duas env vars acima.
  ```

**Critério de aceitação:** `grep -n 'M10\|Secret Manager' app/Services/Google/GoogleCredentials.php` → 0 matches. As mensagens de erro (linhas 61, 67, 75, 83, 84) **continuam mencionando** `GOOGLE_SERVICE_ACCOUNT_JSON` e `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` — isso é desejável (as env vars em si permanecem válidas).

#### 2.2.13 `routes/console.php`

**O que muda:** 2 comentários mencionam "Cloud Scheduler" (linhas 15 e 20).

**Como muda:**

- Linha 15 atual:
  ```php
   | Substitui o Cloud Scheduler + endpoint HTTP /cron/sync-pending.
  ```
  Reescrever para:
  ```php
   | Substitui o endpoint HTTP /cron/sync-pending (removido em M9). O
   | agendamento é feito pelo Laravel Scheduler — em produção, systemd
   | timer (ou equivalente na VPS) chama `php artisan schedule:run` a cada
   | minuto; o scheduler detecta comandos cuja cadência venceu e os executa.
  ```
- Linha 20 atual:
  ```php
     - everyFiveMinutes(): cadência do sync (igual ao Cloud Scheduler anterior)
  ```
  Reescrever para:
  ```php
     - everyFiveMinutes(): cadência do sync
  ```

**Critério de aceitação:** `grep -n 'Cloud Scheduler\|Cloud Build' routes/console.php` → 0 matches.

#### 2.2.14 `Dockerfile`

**O que muda:** Múltiplos comentários (linhas 18, 64, 68, 72, 98, 118, 129) mencionam "Cloud Run" e "Cloud Build". **A lógica do Dockerfile em si NÃO muda** — o container continua sendo construído multi-stage e servindo o Octane/FrankenPHP. Apenas os comentários.

**Como muda:**

- Linha 18 atual:
  ```dockerfile
  # Aproveita cache: só re-instala quando composer.json/lock mudam.
  # Build local (docker compose) e Cloud Build devem construir a
  # base primeiro e injetá-la via --build-arg BASE_IMAGE=wallet-track-base:latest.
  ```
  Reescrever para:
  ```dockerfile
  # Aproveita cache: só re-instala quando composer.json/lock mudam.
  # Build local (docker compose) deve construir a base primeiro
  # e injetá-la via --build-arg BASE_IMAGE=wallet-track-base:latest.
  ```
- Linha 64 atual: `# e pode ser compensado por Cloud Run CPU boost.` → `# e pode ser compensado por CPU boost do worker Octane em produção.`
- Linha 68 atual: `# Isso acelera o cold start no Cloud Run (templates já compilados; listeners` → `# Isso acelera o cold start do worker Octane (templates já compilados; listeners`
- Linha 72 atual: `#    os secrets (APP_KEY, tokens, SA JSON) são injetados pelo Cloud Run via` → `#    os secrets (APP_KEY, tokens, SA JSON) são injetados pelo orquestrador (VPS/CI)`
- Linha 98 atual: `# injetados em runtime pelo Cloud Run (ver docs/decisions.md).` → `# injetados em runtime pelo orquestrador (VPS/CI).`
- Linha 118 atual: `# Configuração PHP (opcache) otimizada para long-running workers no Cloud Run.` → `# Configuração PHP (opcache) otimizada para long-running workers Octane.`
- Linha 129 atual: `# Variáveis de runtime (sobrescritas pelo Cloud Run / .env em produção).` → `# Variáveis de runtime (sobrescritas pelo orquestrador / .env em produção).`

**Critério de aceitação:** `grep -n 'Cloud Run\|Cloud Build' Dockerfile` → 0 matches. O comando `docker build -t wallet-track:dev --target dev .` continua exit 0 (verificação manual em §9.3).

#### 2.2.15 `docker/entrypoint.sh`

**O que muda:** Header (linha 5) menciona "Cloud Run / docker compose".

**Como muda:**

- Linha 5 atual: `# Wallet Track — Entrypoint (Cloud Run / docker compose)`
- Reescrever para: `# Wallet Track — Entrypoint (VPS via systemd, ou docker compose dev)`

**Critério de aceitação:** `grep -n 'Cloud Run' docker/entrypoint.sh` → 0 matches.

> **Nota sobre entrypoint e `/secrets/env.json`:** o loop que carrega `/secrets/env.json` (linhas 17-26) é uma abstração genérica que **continua funcionando** com qualquer orquestrador que monte o JSON nesse path (VPS + Secret Manager CSI driver, Kubernetes, ou mesmo um docker volume). A lógica em si **não muda**; apenas o comentário do header.

#### 2.2.16 `docker-compose.yml` e `docker-compose.dev.yml`

**O que muda:**

- `docker-compose.yml`:
  - Linha 15: comentário "(Container escuta em :8080 para paridade Cloud Run PORT=8080.)" → "(Container escuta em :8080 — porta padrão do Octane/FrankenPHP worker.)"
  - Linha 50: comentário `# host 8000 → container 8080 (paridade Cloud Run PORT=8080)` → `# host 8000 → container 8080 (porta padrão Octane/FrankenPHP)`
  - Linha 67: comentário `# paridade com Cloud Run (Caddyfile usa {$PORT:8080})` → `# padrão do Octane/FrankenPHP (Caddyfile usa {$PORT:8080})`
  - Linha 79: **`GOOGLE_SERVICE_ACCOUNT_JSON_PATH: /app/wallet-track-499719-54b725c0bb5d.json` — o arquivo referenciado está sendo deletado (§2.1.4)**. **Como o `.env` em si manterá um valor para essa env var, mas o JSON sumiu, há duas opções:**
    - **Opção A (escolhida):** **REMOVER** a entrada `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` do bloco `environment` do `docker-compose.yml`. A env var passa a ser lida apenas do `.env`/`.env.dev`. Se o `.env` apontar para um path que existe, funciona; se não, o `GoogleCredentials` cai no `GOOGLE_SERVICE_ACCOUNT_JSON` (inline base64) ou falha com mensagem clara (decisão §2.2.11).
    - Opção B (rejeitada): baixar a chave externamente e montar como volume — fora do escopo desta task (cria acoplamento com provider externo).

- `docker-compose.dev.yml`:
  - Linha 45: mesma entrada `GOOGLE_SERVICE_ACCOUNT_JSON_PATH: /app/wallet-track-499719-54b725c0bb5d.json` deve ser **REMOVIDA** pelo mesmo motivo.
  - Comentário da linha 44 ("Path da Service Account dentro do container (mesmo padrão do prod)") deve ser **REMOVIDO**.

**Como muda:**

- `docker-compose.yml`:
  - **Remover** o comentário e a linha:
    ```yaml
            # Mesmo padrão dos overrides de DB/Redis: força o path do container,
            # independente do .env do host.
            GOOGLE_SERVICE_ACCOUNT_JSON_PATH: /app/wallet-track-499719-54b725c0bb5d.json
    ```
- `docker-compose.dev.yml`:
  - **Remover** o comentário e a linha:
    ```yaml
            # Path da Service Account dentro do container (mesmo padrão do prod).
            GOOGLE_SERVICE_ACCOUNT_JSON_PATH: /app/wallet-track-499719-54b725c0bb5d.json
    ```

**Critério de aceitação:**

```bash
grep -n 'wallet-track-499719-54b725c0bb5d\|GOOGLE_SERVICE_ACCOUNT_JSON_PATH' docker-compose.yml docker-compose.dev.yml
# → 0 matches
docker compose config | grep -i 'google_service'
# → vazio (env var é lida do .env, não injetada aqui)
```

#### 2.2.17 `phpunit.xml`

**O que muda:** 3 env vars GCP-infra são definidas para satisfazer `config('google.*')` (comentário linhas 44-46). Decisão:

- `GOOGLE_CLOUD_PROJECT_ID` — **REMOVER** (linha 47). Com a remoção do bloco `cloud` em `config/google.php`, essa env var deixa de ser consultada.
- `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` — **MANTER** (linha 48). O `SheetsServiceProvider` pode tentar resolver via path em testes que instanciam o `Sheets` real (mas o `InMemorySheetsGateway` evita isso). Manter por consistência.
- `GOOGLE_SERVICE_ACCOUNT_JSON` — **MANTER** (linha 49).

**Como muda:**

- **Remover** a linha 47:
  ```xml
          <env name="GOOGLE_CLOUD_PROJECT_ID" value="test-project"/>
  ```
- **Atualizar** o comentário da linha 44-46:
  ```xml
  <!-- Credenciais Google (Sheets). Os valores existem apenas
       para satisfazer config('google.*') sem Undefined index. O GoogleSheetsGateway
       real nunca é instanciado em testes — usamos InMemorySheetsGateway. -->
  ```
  Reescrever para:
  ```xml
  <!-- Credenciais Google (Sheets). Os valores existem apenas
       para satisfazer config('google.*') sem Undefined index. O GoogleSheetsGateway
       real nunca é instanciado em testes — usamos InMemorySheetsGateway. -->
  ```

**Critério de aceitação:** `grep -n 'GOOGLE_CLOUD_PROJECT_ID' phpunit.xml` → 0 matches. `php artisan test` passa sem warnings de env var ausente.

#### 2.2.18 `bin/check-viability.sh`

**O que muda:** O array `REQUIRED_EXTS` (linha 37) inclui `grpc` e `protobuf`, que eram exigidos por `google/cloud-firestore` (já removido). **Removê-los.**

**Como muda:**

- Linha 37 atual:
  ```bash
  declare -a REQUIRED_EXTS=("gmp" "bcmath" "intl" "grpc" "protobuf" "zip" "pdo_sqlite")
  ```
- Reescrever para:
  ```bash
  declare -a REQUIRED_EXTS=("gmp" "bcmath" "intl" "zip" "pdo_sqlite")
  ```

**Critério de aceitação:** `grep -n 'grpc\|protobuf' bin/check-viability.sh` → 0 matches (em REQUIRED_EXTS). O script `bin/check-viability.sh` continua exit 0.

> **Nota sobre `bin/dev`:** o `bin/dev` (Docker wrapper) tem comentários (linhas 6, 8) que mencionam `grpc`/`protobuf` como extensões necessárias. Como o `bin/dev` é usado para desenvolvimento, e a imagem `wallet-track:dev` é construída sobre `Dockerfile` (que **não instala** `grpc`/`protobuf`), esses comentários estão **tecnicamente errados** (a imagem dev não tem essas extensões, mas o wrapper não as exige). A correção é editorial: atualizar os comentários para refletir o estado atual.

#### 2.2.19 `bin/dev` (Docker wrapper)

**O que muda:** Comentários linhas 6-9 mencionam "grpc/protobuf".

**Como muda:**

- Linhas 5-9 atuais:
  ```bash
  # Motivação: o ambiente de dev (Ubuntu 26.04) não dispõe de PHP 8.5 com as
  # extensões grpc/protobuf nativamente, e o PPA ondrej/php ainda não publicou
  # build para resolute. Rodamos toda a tooling PHP dentro da imagem
  # `wallet-track:dev`, que já contém PHP 8.5.x + bcmath/gmp/grpc/intl/protobuf/
  # zip/pdo_sqlite (idêntica ao runtime de produção, definida em `Dockerfile`).
  ```
- Reescrever para:
  ```bash
  # Motivação: o ambiente de dev (Ubuntu 26.04) não dispõe de PHP 8.5 com
  # todas as extensões nativamente, e o PPA ondrej/php ainda não publicou
  # build para resolute. Rodamos toda a tooling PHP dentro da imagem
  # `wallet-track:dev`, que já contém PHP 8.5.x + bcmath/gmp/intl/zip/
  # pdo_mysql/pdo_sqlite/redis (idêntica ao runtime de produção, definida
  # em `Dockerfile`).
  ```

**Critério de aceitação:** `grep -n 'grpc\|protobuf' bin/dev` → 0 matches (em comentários; código em si não tem).

#### 2.2.20 `tests/Feature/HealthTest.php`

**O que muda:** Docblock da linha 14 menciona "checks do Cloud Run (M10)".

**Como muda:**

- Linhas 10-15 atuais:
  ```php
  /**
   * CT-M0-01 (M0 smoke test): endpoint /health retorna 200 e JSON válido.
   *
   * Garante que o health check mínimo exigido pelo plano de implementação
   * (§M0.8) está acessível e retorna a estrutura esperada para uptime
   * checks do Cloud Run (M10).
   */
  ```
- Reescrever para:
  ```php
  /**
   * CT-M0-01 (M0 smoke test): endpoint /health retorna 200 e JSON válido.
   *
   * Garante que o health check mínimo exigido pelo plano de implementação
   * (§M0.8) está acessível e retorna a estrutura JSON esperada.
   */
  ```

**Critério de aceitação:** `grep -n 'Cloud Run' tests/Feature/HealthTest.php` → 0 matches.

#### 2.2.21 `README.md`

**O que muda:** Várias seções. Mudanças cirúrgicas:

- Linha 7: badge `Deploy | Google Cloud Run` → `Deploy | Docker (VPS planejada)`.
- Linha 28: "Sincronização pendente via Cloud Scheduler (cron a cada 5 min)" → "Sincronização pendente via Laravel Scheduler (cron a cada 5 min)".
- Linha 47: "Deploy | Google Cloud Run (512MB, 1 vCPU, timeout 300s)" → "Deploy | Docker container (worker Octane/FrankenPHP, timeout 300s) — VPS planejada".
- Linha 48: "Agendamento | Cloud Scheduler (cron 5min)" → "Agendamento | Laravel Scheduler (cron 5min, executado via systemd timer na VPS)".
- Linhas 49: "Logs | Cloud Logging (stderr estruturado)" → "Logs | stderr estruturado (JSON)" (sem Cloud Logging por enquanto).
- Linha 117: "APIs habilitadas: Cloud Run, Cloud Build, Cloud Scheduler, Secret Manager, Sheets" → "APIs habilitadas: Sheets (única API GCP em uso — apenas para a Service Account do Sheets)".
- Linha 165: "Telegram → Cloud Run (Laravel 13 + FrankenPHP + Octane)" → "Telegram → App (Laravel 13 + FrankenPHP + Octane)".
- Linha 206-208: bloco sobre "Em produção (Cloud Run), o **Cloud Scheduler** acorda…" → reescrever para "Em produção (VPS), o Laravel Scheduler é executado por um systemd timer a cada minuto. Não há dependência de GCP para o scheduling."
- Linha 224: "API keys e Service Account JSON armazenados em GCP Secret Manager" → "API keys e Service Account JSON armazenados em secret manager externo (ex.: Vault, AWS Secrets Manager, ou `.env` na VPS com permissões restritas)".
- Linha 226: "Logs em stderr (visíveis só ao dono do projeto no Cloud Logging)" → "Logs em stderr (JSON estruturado, visíveis ao dono do projeto via `docker logs` ou journald)".
- Linha 286: nota sobre `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` no docker-compose — atualizar para refletir que a env var **agora é lida apenas do `.env`** (não mais injetada via docker-compose).

**Como muda:** Reescritas pontuais conforme tabela acima. Cada mudança é uma frase/parágrafo, não estrutural.

**Critério de aceitação:** `grep -n 'Cloud Run\|Cloud Scheduler\|Cloud Build\|Secret Manager\|gcloud' README.md` → 0 matches.

#### 2.2.22 `CHANGELOG.md`

**O que muda:** Adicionar entrada no topo do bloco `[Unreleased]` documentando esta task.

**Como muda:** Inserir (em uma nova seção `### Changed` ou na seção existente) um item como:

```markdown
- Remoção completa do GCP-infra (Cloud Run, Cloud Build, Cloud Scheduler,
  Secret Manager, Artifact Registry, Firestore) do repositório. O projeto
  GCP `wallet-track-499719` permanece vivo apenas como IAM provider da
  Service Account do Google Sheets. Chave SA não rotacionada. Deploy em
  VPS ainda fora de escopo.
```

Posicionar essa entrada **abaixo** da última entrada `### Removed` da seção `[Unreleased]` (acima da linha 46, antes de `## [0.8.0]`).

**Critério de aceitação:** A entrada está presente em `CHANGELOG.md` na seção `[Unreleased]`. `git diff CHANGELOG.md` mostra apenas a adição.

#### 2.2.23 `.env`, `.env.dev`, `.env.example`

Vide §3 (Variáveis de ambiente). Mudanças detalhadas在那里.

#### 2.2.24 Documentação — `docs/`

Vide §8 (Documentação). Mudanças detalhadas在那里.

#### 2.2.25 `bootstrap/cache/services.php`, `bootstrap/cache/packages.php`, `bootstrap/cache/config.php`

**O que muda:** Esses arquivos são regenerados em runtime (vide Dockerfile linha 99: `RUN rm -f bootstrap/cache/*.php`). Após o `composer dump-autoload` (passo §9.2.8) e o boot do app em qualquer ambiente dev, eles são regenerados. **Nenhuma ação manual** — o `coder` apenas roda `php artisan optimize:clear` (ou equivalente) após as mudanças.

**Critério de aceitação:** `php artisan config:clear && php artisan cache:clear && php artisan route:clear` exit 0. Reabrir o app e verificar que `/health` retorna 200.

---

## 3. Variáveis de ambiente

### 3.1 Env vars GCP-infra a REMOVER (de todos os 3 arquivos `.env*`)

| Env var | Onde aparece (grep) | Destino | Justificativa |
|---|---|---|---|
| `GOOGLE_CLOUD_PROJECT_ID` | `.env` (linha 112), `.env.dev` (linha 97), `.env.example` (linha 128) | **REMOVER** (deletar a linha inteira) | Era o identificador do projeto GCP; não há mais uso no código (bloco `cloud.project_id` removido de `config/google.php`). |
| `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` | `.env` (linha 115), `.env.dev` (linha 98), `.env.example` (linha 131) | **MANTER** (é Sheets, não GCP-infra) | Lida por `GoogleCredentials::resolveKeyFile()` no caminho de dev. O caminho pode apontar para um JSON que o usuário mantém localmente (NÃO commitado) ou ficar vazio. |
| `GOOGLE_SERVICE_ACCOUNT_JSON` | `.env` (linha 116, comentado), `.env.dev` (não tem), `.env.example` (linha 133, comentado) | **MANTER** (é Sheets, não GCP-infra) | Conteúdo inline da SA (base64 do JSON). É a fonte primária em produção. |
| `FIRESTORE_DATABASE_ID` | `.env` (linha 119), `.env.dev` (linha 100), `.env.example` (não tem; mas em outros lugares) | **REMOVER** (deletar a linha inteira) | Firestore foi migrado para MariaDB em M7. Nenhum consumidor de runtime. |

### 3.2 Env vars Sheets a MANTER (inalteradas)

| Env var | Onde aparece | O que precisa |
|---|---|---|
| `GOOGLE_SHEETS_SPREADSHEET_ID` | `.env` (linha 120), `.env.dev` (linha 102), `.env.example` (linha 135) | Manter o valor real (planilha pessoal do usuário). |
| `GOOGLE_SHEETS_SHEET_NAME` | `.env` (linha 121), `.env.dev` (linha 103), `.env.example` (linha 137) | Manter (default `Transações`). |
| `GOOGLE_SHEETS_CATEGORIES_SHEET_NAME` | `.env` (não tem), `.env.dev` (linha 104), `.env.example` (linha 139) | Manter (default `Categorias`). |
| `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` | (acima) | Manter (pode ser vazio em produção se `GOOGLE_SERVICE_ACCOUNT_JSON` for usado). |
| `GOOGLE_SERVICE_ACCOUNT_JSON` | (acima) | Manter (em produção, valor real da chave). |

### 3.3 Mudanças por arquivo `.env*`

#### `.env` (DESENVOLVIMENTO LOCAL)

**Seção `# Google Cloud (Firestore + Sheets)`** (linhas 109-121 atuais) — **REESCREVER** para:

```bash
# ============================================================================
# Google Sheets (Service Account)
# ============================================================================
# Em dev: caminho para o JSON da Service Account (NÃO commitado).
# Em prod (VPS, futuro): GOOGLE_SERVICE_ACCOUNT_JSON com o conteúdo inline
# (base64) — GOOGLE_SERVICE_ACCOUNT_JSON_PATH fica vazio.
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/path/to/sa.json
# GOOGLE_SERVICE_ACCOUNT_JSON=
GOOGLE_SHEETS_SPREADSHEET_ID=1rGNN0XOOYwDvMYDpFwU1a2ozQXPhAk8P2l8Xjnk9a14
GOOGLE_SHEETS_SHEET_NAME=Transações
```

**Remover as linhas:**
- Linha 112: `GOOGLE_CLOUD_PROJECT_ID=wallet-track-499719`
- Linha 113-114: comentário sobre path (re-escrito acima)
- Linha 117-118: comentário sobre `FIRESTORE_DATABASE_ID`
- Linha 119: `FIRESTORE_DATABASE_ID=wallet-track-db`

#### `.env.dev` (AMBIENTE DEV ISOLADO)

**Seção `# Google Cloud (Firestore + Sheets)`** (linhas 94-104 atuais) — **REESCREVER** para:

```bash
# ============================================================================
# Google Sheets (Service Account)
# ============================================================================
# Dev usa planilha DEV (WalletTrackDev).
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/path/to/sa.json
# GOOGLE_SERVICE_ACCOUNT_JSON=
GOOGLE_SHEETS_SPREADSHEET_ID=1aRr8S511jLcZtrAKyi6wbFc4PLMV_hGNO86sP7Pj4_c
GOOGLE_SHEETS_SHEET_NAME=Transações
GOOGLE_SHEETS_CATEGORIES_SHEET_NAME=Categorias
```

**Remover as linhas:**
- Linha 97: `GOOGLE_CLOUD_PROJECT_ID=wallet-track-499719`
- Linha 100: `FIRESTORE_DATABASE_ID=wallet-track-dev-db`

#### `.env.example` (TEMPLATE)

**Seção `# Google Cloud (Sheets API)`** (linhas 124-139 atuais) — **REESCREVER** para:

```bash
# ============================================================================
# Google Sheets (Service Account)
# ============================================================================
# Em desenvolvimento local: caminho para o JSON da Service Account.
# Em produção (VPS, futuro): GOOGLE_SERVICE_ACCOUNT_JSON com o conteúdo inline.
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=
# GOOGLE_SERVICE_ACCOUNT_JSON=
# ID da planilha (extraído da URL: /d/<ID>/edit).
GOOGLE_SHEETS_SPREADSHEET_ID=
# Nome da aba principal da planilha.
GOOGLE_SHEETS_SHEET_NAME=Transações
# Nome da aba auxiliar de categorias.
GOOGLE_SHEETS_CATEGORIES_SHEET_NAME=Categorias
```

**Remover as linhas:**
- Linha 127-128: bloco `Google Cloud (Sheets API)` antigo com `GOOGLE_CLOUD_PROJECT_ID`
- Linhas 129-131: comentário sobre "Google Cloud Project ID (necessário para Google Sheets API)" + `GOOGLE_SERVICE_ACCOUNT_JSON_PATH=`

> **Observação importante sobre o `.env.bak`:** o arquivo é **deletado** (vide §2.1.5). Toda a informação de env vars que estava nele e ainda é útil foi migrada para `.env` ou `.env.dev` ao longo do tempo. **Backup do `.env.bak` antes da deleção:** sim, o `coder` deve copiar `.env.bak` para um local seguro fora do repo (ex.: `~/backup/wallet-track-env-bak-2026-06-30.env`) **antes** da deleção, por questão de auditoria/histórico. O backup NÃO é commitado.

### 3.4 Sanity check final (env vars)

```bash
grep -E 'GOOGLE_CLOUD_PROJECT_ID|FIRESTORE_DATABASE_ID' .env .env.dev .env.example
# → 0 matches

grep -E 'GOOGLE_SERVICE_ACCOUNT_JSON|GOOGLE_SHEETS' .env .env.dev .env.example
# → matches apenas nas linhas mantidas (Sheets)
```

---

## 4. Composer / dependências

### 4.1 Pacotes a MANTER (relevantes para Sheets e Gemini)

| Pacote | Versão instalada (composer.lock) | Por que permanece |
|---|---|---|
| `google/apiclient` | `v2.19.3` | SDK Google Sheets API (cliente HTTP, autenticação). |
| `google/apiclient-services` | `v0.445.0` | Stubs de serviços Google (Sheets, etc.) usados pelo `apiclient`. |
| `google/auth` | `v1.51.0` | Biblioteca de autenticação Google (OAuth2, Service Account). |
| `firebase/php-jwt` | `v7.1.0` | Dependência transitiva de `google/auth` (JWT para tokens de acesso). |
| `google-gemini-php/client` | `^2.7` (instalada `2.7.4`) | SDK do Gemini AI Studio (OCR multimodal). **NÃO é GCP** — é a API pública do Gemini via API Key. |

### 4.2 Pacotes a REMOVER (já não estão em `composer.json`)

| Pacote | Status | Justificativa |
|---|---|---|
| `google/cloud-firestore` | Já removido em M7 (vide `CHANGELOG.md` linha 13 e `bin/check-viability.sh` linhas 31-32) | Firestore foi migrado para MariaDB. Nenhum consumidor. |
| `ext-grpc` | Não está em `composer.json` (e não precisa — Firestore saiu) | Era dependência de `google/cloud-firestore`. |
| `ext-protobuf` | Não está em `composer.json` | Era dependência de `google/cloud-firestore`. |

### 4.3 Procedimento

- **NÃO editar `composer.json` manualmente** — nenhuma dep nova ou removida.
- **NÃO editar `composer.lock` manualmente** — o lock será regenerado por `composer install` (passo §9.2.8).
- **NÃO rodar `composer remove`** — não há nada para remover (Firestore já saiu).
- **Rodar `composer install`** após todas as mudanças no repo, para garantir que o autoloader não referencia classes de arquivos removidos.

### 4.4 Sanity check

```bash
composer install --no-interaction
# → exit 0
composer validate --no-check-publish --strict
# → exit 0
php artisan package:discover
# → exit 0, lista os 7 providers habituais
```

---

## 5. Docker / docker-compose

### 5.1 Resumo das mudanças em Docker

| Arquivo | Mudança |
|---|---|
| `Dockerfile` | Apenas comentários (§2.2.13). Lógica inalterada. |
| `docker/entrypoint.sh` | Apenas comentário do header (§2.2.14). Lógica inalterada. |
| `docker/Dockerfile.base` | **Sem mudanças.** As extensões `gmp bcmath intl opcache pcntl pdo_mysql pdo_sqlite redis zip` permanecem. `grpc`/`protobuf` **NÃO são instaladas** (já não estavam nesta imagem). |
| `docker-compose.yml` | Remover entrada `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` do bloco `environment` do serviço `app` (§2.2.15). Comentários atualizados. |
| `docker-compose.dev.yml` | Idem. |
| `.dockerignore` | **Sem mudanças.** |

### 5.2 Por que NÃO deletar `Dockerfile`?

O `Dockerfile` (e `docker-compose.yml`) continuam sendo usados para **desenvolvimento local**. O Portão 1 explicitou que "deploy em VPS está fora do escopo desta task" — mas o ambiente dev (`make up-dev`, `make test`, etc.) **continua usando Docker**. Quando a task de deploy para VPS acontecer (futura), esse mesmo `Dockerfile` será a base da imagem de produção na VPS. Logo, **manter o Dockerfile e os composes é mandatório**.

### 5.3 Por que deletar `scripts/strip-google-services.php`?

O script era invocado no `Dockerfile` (estágio `deps`) para reduzir o `vendor/google/apiclient-services` de ~212 MB para ~13 MB, mantendo apenas os service stubs do Sheets. Como o `Dockerfile` continua existindo, mas **o container de produção sai de escopo** (deploy em VPS é futuro), a invocação do stripper **perde sua motivação prática**:
- Para o `wallet-track:dev` (target dev do Dockerfile), a economia de 199 MB não é crítica (dev local usa mais espaço).
- Para uma eventual imagem de produção na VPS, a estratégia de strip é útil mas pode ser reintroduzida quando a task de deploy acontecer (com avaliação de impacto).

**Decisão de Portão 1:** deletar o script e remover a invocação dele do `Dockerfile` (verificar se ainda há invocação — vide §5.4).

### 5.4 Verificação de invocação de `strip-google-services.php` no Dockerfile

**O que muda:** Nenhuma. O `Dockerfile` **NÃO invoca** o `scripts/strip-google-services.php` (verificado por inspeção do conteúdo lido em §1 da fase de exploração). O script era parte do **orquestrador externo** (Cloud Build) ou de um Makefile/hook local. Como o Cloud Build está sendo deletado, o script perde seu último consumidor.

**Critério de aceitação:** `grep -n 'strip-google-services' Dockerfile docker-compose*.yml Makefile bin/* 2>/dev/null` → 0 matches.

### 5.5 Sanity check Docker

```bash
docker compose config | grep -i 'google\|firestore\|sa\.json' | head -20
# → não deve mostrar GOOGLE_SERVICE_ACCOUNT_JSON_PATH injetado pelo compose
# → pode mostrar GOOGLE_SHEETS_SPREADSHEET_ID se o .env o tiver (passa via env_file)
docker compose config | grep -i 'cloud_run\|cloud_run_revision'
# → 0 matches
```

---

## 6. Testes

### 6.1 Testes que PERMANECEM INTACTOS (zero ajustes)

A maioria esmagadora dos testes não precisa de mudança. Listados os principais:

| Teste | Por que permanece |
|---|---|
| `tests/Feature/Actions/SyncSheetTest.php` | Testa `SyncSheet` e `SyncsSheet`; nenhum consumidor GCP-infra. |
| `tests/Feature/Console/SyncPendingTransactionsCommandTest.php` | Testa o command Sheets; o `InMemorySheetsGateway` é usado (sem rede). |
| `tests/Feature/Console/RemoveOriginColumnTest.php` | Testa o command `sheets:remove-origin-column`; idem. |
| `tests/Feature/Items/E2eItemsFlowTest.php` | Partes que tocam Sheets usam `InMemorySheetsGateway`. |
| `tests/Feature/Commands/SyncHandlerTest.php` | Testa o handler `/sync`; usa `InMemorySheetsGateway`. |
| `tests/Unit/Services/Google/SheetsServiceTest.php` | Testa o `SheetsService` puro. |
| `tests/Unit/Services/Google/GoogleCredentialsTest.php` | Testa o `GoogleCredentials::resolveKeyFile()`; as env vars `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` e `GOOGLE_SERVICE_ACCOUNT_JSON` **permanecem**, então o teste continua válido. **Detalhe:** o teste verifica mensagem de erro contendo `GOOGLE_SERVICE_ACCOUNT_JSON` (linha 175). Como a env var continua existindo, **o teste passa sem mudança**. |
| Demais testes em `tests/Feature/Commands/*` (Start, Help, Cancelar, Nova, Ultimos, Categorias) | Não tocam GCP-infra. |
| `tests/Unit/Support/ItemsSorterTest.php` e similares | Idem. |
| `tests/Unit/Actions/*` e `tests/Unit/Bot/*` | Idem. |
| `tests/Feature/Store/WalletStoreTest.php` | Substituiu `FirestoreServiceTest` em M7. Sem dependência GCP. |
| `tests/Feature/Http/SyncPendingRouteTest.php` | (Se existir; foi deletado conforme §0.2 do M9-COMPLETO.) |

### 6.2 Testes que PRECISAM de AJUSTE MÍNIMO

#### `tests/Feature/Http/HealthControllerTest.php`

**O que muda:**

1. **Teste `test_verbose_mode_includes_checks_object`** (linha 121): assertion `'checks.env.total' => 6` deve virar **5** (removemos `GOOGLE_CLOUD_PROJECT_ID` do `CRITICAL_ENV_CHECKS`).

   **Como muda:**

   - Linha 121 atual: `$response->assertJsonPath('checks.env.total', 6);`
   - Reescrever para: `$response->assertJsonPath('checks.env.total', 5);`

2. **Demais testes:** o `test_verbose_exposes_missing_env_var_names_for_diagnosis` (linha 198) **permanece** inalterado — ele seta `telegram.bot_token`, `google.service_account_json_path` e `google.service_account_json` como null, e verifica que `checks.env.missing_count === 2` e que os nomes ausentes são `TELEGRAM_BOT_TOKEN` e `GOOGLE_SERVICE_ACCOUNT_JSON`. Como a env var `GOOGLE_SERVICE_ACCOUNT_JSON` **permanece** no `CRITICAL_ENV_CHECKS` (mesmo após a remoção de `GOOGLE_CLOUD_PROJECT_ID`), o teste continua funcionando sem mudança.

3. **O teste `test_health_endpoint_returns_degraded_when_telegram_bot_token_missing`** (linha 76): seta `telegram.bot_token` como null. Espera `missing_count: 1`. Após a mudança, o conjunto de CRITICAL_ENV_CHECKS tem 5 entradas (em vez de 6), mas apenas 1 está ausente. Teste passa sem mudança.

4. **Docblock** da linha 14: "checks do Cloud Run (M10)" → idem §2.2.19 (mas isso é o `HealthTest.php`, não o `HealthControllerTest.php`).

**Critério de aceitação:** `php artisan test --filter HealthControllerTest` retorna 0 falhas. `tests.env.total === 5` em verbose mode.

#### `phpunit.xml`

Vide §2.2.16. Mudança: remover a linha `<env name="GOOGLE_CLOUD_PROJECT_ID" value="test-project"/>`.

### 6.3 Testes que NÃO DEVEM ser criados (escopo desta task é remoção)

Nenhum teste novo. A spec não introduz feature; apenas remove. Quaisquer regressões devem ser pegas pelos testes existentes (que cobrem Sheets) ou pelo `php -r "echo class_exists('App\\Services\\Google\\SheetsService');"` (smoke de boot).

### 6.4 Sanity check de testes

```bash
php artisan config:clear
php artisan test
# → 0 falhas (suite completa)
php artisan test --filter Sheets
# → 0 falhas (filtro Sheets, ~10 testes)
php artisan test --filter Health
# → 0 falhas (HealthTest + HealthControllerTest)
```

---

## 7. Banco de dados / migrations

### 7.1 Decisão de Portão 1 (reafirmada)

> **Nenhuma migration nova nesta task.** Schema intacto.

### 7.2 Tabela `transactions` — colunas e índices a MANTER

A tabela `transactions` (vide `database/migrations/2026_06_27_000003_create_transactions_table.php`) tem as seguintes colunas GCP-relevant (no sentido de "relevantes para sincronização Sheets"):

| Coluna | Tipo | Por que permanece |
|---|---|---|
| `sync_status` | `string(10)` default `'pending'` | Estado do sync Sheets (`pending`/`synced`/`failed`). |
| `sync_attempts` | `unsignedSmallInteger` default 0 | Contador de tentativas (3 = failed). |
| `sync_last_attempt_at` | `timestamp(3)` nullable | Última tentativa. |
| `sync_error_message` | `text` nullable | Mensagem de erro do Sheets. |
| `spreadsheet_row_id` | `string(64)` nullable | ID da linha na planilha. |
| `processing` | `boolean` default false | Lock atômico entre `/sync` × cron. |
| `processing_since` | `timestamp(3)` nullable | Quando o lock foi adquirido. |
| `notified_at` | `timestamp(3)` nullable | Carimbo de notificação única de falha. |

E os índices relacionados (linhas 39-40 da migration):

```php
$table->index(['sync_status', 'created_at'], 'transactions_sync_status_created_at_index');
$table->index(['sync_status', 'chat_id', 'created_at'], 'transactions_sync_status_chat_id_created_at_index');
```

**Todos permanecem.** São essenciais para o `transactions:sync-pending` funcionar (query `WHERE sync_status = 'pending' AND sync_attempts < 3 ORDER BY created_at ASC`).

### 7.3 Outras tabelas — sem mudanças

- `categories` — sem mudanças.
- `labels` — sem mudanças.
- `transaction_items` — sem mudanças.
- `transaction_labels` — sem mudanças.

### 7.4 Migrations futuras (fora do escopo)

Em uma task futura (pós-remoção), o usuário pode considerar remover as colunas `sync_*` se quiser — mas **não nesta task**. O Portão 1 foi explícito: schema intacto, sem migrations de limpeza.

---

## 8. Documentação

### 8.1 Política editorial

| Categoria | Política |
|---|---|
| Docs 100% GCP (sem info reutilizável pós-remoção) | **DELETAR** (4 docs) |
| Docs com seções GCP significativas, mas com info de produto | **ATUALIZAR** seções GCP, manter o doc (8 docs + 1 secundário) |
| Docs históricos (M9, Planos) | **ATUALIZAR** seções GCP com nota "Doc histórico — referências a Cloud Run / Firestore refletem estado na época da escrita" (preserva info de produto, marca como histórico). **AMB #5:** matches de `gcloud`/`Cloud Run`/`gcloud firestore` em seções claramente marcadas como **históricas** ou **DEPRECATED** são **intencionais** e **aceitáveis** — vide §8.5 para a lista exata de seções e docs onde matches são esperados. |

### 8.2 Lista exata de docs e ação

| Doc | Ação | Justificativa |
|---|---|---|
| `docs/runbook.md` | **DELETAR** | 100% sobre Cloud Run, Secret Manager, Cloud Logging. Sem VPS/host, o runbook fica vazio. |
| `docs/viability-report.md` | **DELETAR** | 100% sobre o GATE M0 que validava `google/cloud-firestore`. Histórico morto. |
| `docs/comparativo-preco-infra.md` | **DELETAR** | Comparativo de PaaS (incluindo Cloud Run); sem Cloud Run como alternativa, fica órfão. |
| `docs/comparativo-vps.md` | **DELETAR** | Comparativo de VPS. Já cumpriu seu papel na decisão Portão 1. |
| `docs/infra-pessoal.md` | **DELETAR** | Anotações pessoais de infra. Não commitado (no `.gitignore`); deletar local. |
| `README.md` | **ATUALIZAR** seções GCP (§2.2.20) | Mantém como porta de entrada do projeto. |
| `CHANGELOG.md` | **ADICIONAR** entrada (§2.2.21) | Documenta a remoção. |
| `docs/00-INDEX.md` | **ATUALIZAR** | Remover entradas de docs deletados; remover referências a Cloud Run/Firestore como stack atual. |
| `docs/01-analise-negocio.md` | **ATUALIZAR** seções GCP | Manter doc (análise de negócio ainda relevante). Reescrever premissas P8 (deploy), P10 (persistência) e similares. |
| `docs/02-especificacao-tecnica.md` | **ATUALIZAR** seções GCP | Manter doc (spec técnica). Reescrever §2 (diagrama), §3 (stack), §5 (modelo Firestore — marcar como histórico), §11 (resiliência), §12 (segurança), §13 (env vars), §14 (deploy). |
| `docs/05-revisao-v2.md` | **ATUALIZAR** seções GCP | Manter doc. Reescrever linhas sobre Firestore (migração já completa; nota histórica). |
| `docs/06-plano-implementacao.md` | **ATUALIZAR** seções GCP | Manter doc. Reescrever §2.2 (configurações GCP), §8 (M5 Firestore — marcar histórico), §14 (M10 Deploy — marcar como fora de escopo). |
| `docs/M9-COMPLETO.md` | **ATUALIZAR** nota de migração | Manter doc. Adicionar parágrafo na nota de migração indicando que a remoção GCP-infra é tarefa separada. |
| `docs/planos/m9-plano-tecnico.md` | **ATUALIZAR** cabeçalho | Manter doc. Header menciona "Cloud Run" — atualizar para refletir nova realidade. |
| `docs/specs/m9-spec-fase-2.md` | **ATUALIZAR** nota de migração + header | Manter doc. Adicionar parágrafo final indicando que GCP-infra foi removido. |
| `docs/testes/m9-plano-testes.md` | **ATUALIZAR** seções com referência a Cloud Run | Manter doc. Reescrever header (ambiente de teste) e §5 (cron) e §6 (troubleshooting) para refletir VPS/scheduler local. |
| `docs/testes/items-checklist-staging.md` | **ATUALIZAR** ambiente de staging | Manter doc. Reescrever seção "Ambiente de staging" para indicar que o staging é local (docker compose) e o Firestore de staging não existe mais. |
| `docs/04-clarificacoes.md` | **NENHUMA mudança** (referências a Firestore em §4 são históricas; não é necessário alterar) |
| `docs/03-plano-testes-manuais.md` | **NENHUMA mudança** (já é majoritariamente agnóstico; referências a "Coleções Firestore" são pré-condições, e o teste manual não roda mais contra Firestore de qualquer modo) |
| `docs/planos/migracao-mysql-redis/*` | **NENHUMA mudança** (já documenta a migração Firestore → MySQL/Redis) |

### 8.3 Procedimento editorial prático

Para cada doc a **atualizar**:

1. **Ler** o doc na íntegra.
2. **Localizar** todas as menções a GCP (grep: `Cloud Run|Cloud Build|Cloud Scheduler|Cloud Logging|Cloud Storage|Firestore|gcloud|deploy.sh|cloudbuild.yaml|Secret Manager|Artifact Registry|google-cloud-php|google/cloud-firestore|google/cloud-storage|wallet-track-499719`).
3. **Reescrever** as menções seguindo o padrão:
   - "Cloud Run" → "VPS (futuro) — fora de escopo desta task" ou apenas remover se for título/seção.
   - "Cloud Scheduler" → "Laravel Scheduler" (já é a verdade no `routes/console.php`).
   - "Secret Manager" → "secret manager externo (VPS, Vault, .env seguro)" ou apenas remover.
   - "Firestore" → "MariaDB" (ou "banco de dados") se referência for de runtime; manter "Firestore" se for puramente histórica (ex.: CHANGELOG, decisões de M5).
   - "deploy em Cloud Run" → "deploy em VPS (futuro)".
4. **Adicionar nota no topo** do doc (quando o doc tem nota de migração pré-existente):

   ```markdown
   > **⚠️ NOTA DE REMOÇÃO GCP (jun/2026):** Este documento foi escrito quando
   > o deploy era no Google Cloud Run e a persistência em Firestore. Após
   > a remoção do GCP-infra (jun/2026), as referências a Cloud Run, Cloud
   > Scheduler, Cloud Build, Secret Manager, Artifact Registry e Firestore
   > neste documento são **históricas**. O deploy atual (VPS) está fora de
   > escopo e será documentado em uma task futura.
   ```

5. **Commit** com mensagem descritiva: `docs(<doc>): atualiza seções GCP pós-remoção (jun/2026)`.

### 8.4 Sanity check de docs

```bash
# Para cada doc mantido, verificar que não restou referência GCP não-histórica
for f in docs/*.md docs/testes/*.md docs/specs/*.md docs/planos/*.md; do
  if [ -f "$f" ]; then
    echo "=== $f ==="
    grep -nE 'Cloud Run|Cloud Scheduler|Cloud Build|Cloud Logging|Cloud Storage|Secret Manager|Artifact Registry|Firestore|gcloud|deploy\.sh|cloudbuild\.yaml' "$f" | head -20
  fi
done
# → cada match deve estar em uma seção marcada como histórica, ou
#   em uma referência a decisão passada (ex.: "Decisão M5: Firestore → MariaDB")
```

Para docs deletados, verificar que o arquivo não está no filesystem:

```bash
test -f docs/runbook.md && echo "FALHA: runbook.md ainda existe" || echo "OK: runbook.md deletado"
test -f docs/viability-report.md && echo "FALHA" || echo "OK"
# → todos os 5 docs deletados devem retornar "OK"
```

### 8.5 AMB #5 — Política editorial para matches históricos (CT-G-02)

A spec §8.3 já orienta que matches de `gcloud`/`Cloud Run` em docs históricos devem ser preservados (não deletados), desde que marcados. Esta sub-seção **consolida a lista exata** de docs e seções onde matches intencionais são aceitos, para eliminar a ambiguidade do CT-G-02.

**Política adotada: (b)** — manter menções em seções marcadas como "histórico" ou "DEPRECATED", desde que explicitamente identificadas. **Não** adotar (a) (deletar tudo) nem (c) (mover para `docs/historico-m9-gcp.md`) — ambas quebram rastreabilidade histórica.

**Marcador canônico** (a ser inserido no topo de cada doc afetado):

```markdown
> **⚠️ NOTA DE REMOÇÃO GCP (jun/2026):** Este documento foi escrito quando
> o deploy era no Google Cloud Run e a persistência em Firestore. Após
> a remoção do GCP-infra (jun/2026), as referências a Cloud Run, Cloud
> Scheduler, Cloud Build, Secret Manager, Artifact Registry, Firestore e
> `gcloud` neste documento são **históricas**. O deploy atual (VPS) está
> fora de escopo e será documentado em uma task futura.
```

**Tabela de docs com matches históricos aceitáveis** (CT-G-02 tolera matches aqui):

| Doc | Seção | Matches esperados | Marcador aplicado? |
|---|---|---|---|
| `docs/02-especificacao-tecnica.md` | §2 (diagrama), §3 (stack), §5 (modelo Firestore), §11 (resiliência), §12 (segurança), §13 (env vars), §14 (deploy) | `gcloud firestore indexes import`, `gcloud firestore indexes composite create` (linhas 240, 245, 249) | SIM — nota no topo. |
| `docs/specs/m9-spec-fase-2.md` | Bloco DEPRECATED do Cloud Scheduler (linha 905) | `gcloud scheduler`, `gcloud` CLI referenciado como alternativa para header customizado | SIM — nota no topo + label `(DEPRECATED)` na seção. |
| `docs/M9-COMPLETO.md` | Texto DEPRECATED e referências a M10/Cloud Run | Múltiplos matches `gcloud`, `Cloud Run` | SIM — nota de remoção GCP no topo (vide §8.2). |
| `docs/06-plano-implementacao.md` | §2.2 (configurações GCP), §8 (M5 Firestore), §14 (M10 Deploy), checklist M0/M10 (linhas 103, 176, 627) | `gcloud` em passos de M0.1, M10.6 (estes são PLANO, não execução) | SIM — nota no topo. |
| `docs/planos/m9-plano-tecnico.md` | Header | `Cloud Run` no título/cabeçalho | SIM — nota no topo. |
| `docs/testes/m9-plano-testes.md` | §5 (cron), §6 (troubleshooting) | `gcloud` em troubleshooting do Cloud Scheduler | SIM — nota no topo. |
| `docs/testes/items-checklist-staging.md` | Ambiente de staging | `Firestore` no contexto de staging pré-M7 | SIM — nota no topo. |
| `CHANGELOG.md` | Seção `[Unreleased]` + entradas `[0.X.Y]` históricas | Múltiplos matches `gcloud`, `Cloud Run`, `M10` (entradas DATADAS) | **NÃO** aplicável — CHANGELOG é, por definição, histórico. Não adicionar nota de topo (manter formato padrão). |
| `docs/01-analise-negocio.md` | Premissas P8 (deploy), P10 (persistência) | Referências a GCP | SIM — nota no topo. |
| `docs/05-revisao-v2.md` | Linhas sobre Firestore (migração já completa) | `Firestore` em contexto histórico | SIM — nota no topo. |
| `docs/runbook.md` | (DELETADO em §2.1.6) | N/A | N/A — não conta para CT-G-02. |
| `docs/viability-report.md` | (DELETADO em §2.1.7) | N/A | N/A — não conta para CT-G-02. |
| `docs/comparativo-preco-infra.md` | (DELETADO em §2.1.8) | N/A | N/A — não conta para CT-G-02. |
| `docs/comparativo-vps.md` | (DELETADO em §2.1.9) | N/A | N/A — não conta para CT-G-02. |
| `docs/infra-pessoal.md` | (DELETADO em §2.1.10) | N/A | N/A — não conta para CT-G-02. |

**Comportamento do CT-G-02 após AMB #5:**

- `grep -rIn 'gcloud' --exclude-dir=vendor docs/` vai retornar matches nos docs listados acima.
- Esses matches são **PASS** (não FALHA), desde que o grep manual confirme que estão em seções marcadas com o marcador canônico.
- Para automatizar a validação, o coder pode estender o CT-G-02 com a heurística simplificada:
  ```bash
  # Aceitar matches em docs com marcador canônico OU label DEPRECATED
  grep -rIln 'NOTA DE REMOÇÃO GCP (jun/2026)\|DEPRECATED' docs/ > /tmp/docs-com-marcador.txt
  grep -rIn 'gcloud' --exclude-dir=vendor docs/ | \
    awk -F: '{print $1}' | sort -u > /tmp/docs-com-gcloud.txt
  # PASS se todo doc-com-gcloud está em docs-com-marcador
  diff /tmp/docs-com-gcloud.txt /tmp/docs-com-marcador.txt && echo "PASS" || echo "REVISAR"
  ```
- **Heurística simplificada** (recomendada para o manual-tester): aceitar matches desde que o `grep` aponte para um doc que contenha o marcador canônico OU a palavra `DEPRECATED` na mesma linha/seção.

> **Não-obrigatoriedade da nota de topo:** o `coder` pode aplicar a nota canônica apenas aos docs onde aparecem matches reais de `gcloud`/`Cloud Run` no `grep`. Doc que **não** tem matches (já reescrito de fato) não precisa da nota. A tabela acima é maximalista (cobre os docs que POTENCIALMENTE teriam matches); a aplicação é seletiva.

---

## 9. Procedimento de execução

### 9.1 Pré-condições

> **A ordem das pré-condições importa.** O passo **0 (backup da chave SA)** é **pré-requisito absoluto** do §9.4 / §11: se a chave for destruída antes do backup, é irrecuperável.

0. **Backup da chave da SA `google-sheet-202@…`** (CRÍTICO — investigar e copiar ANTES de qualquer destruição remota):
   ```bash
   # 0a. Identificar qual SA detém a chave do JSON
   cat wallet-track-499719-54b725c0bb5d.json | jq -r '.client_email'
   # → ESPERADO: google-sheet-202@wallet-track-499719.iam.gserviceaccount.com
   #             (ver §0.1; se o resultado for outro, ABORTAR e investigar)

   # 0b. Backup do JSON local (em local seguro, NÃO commitado)
   mkdir -p ~/backup/wallet-track
   cp wallet-track-499719-54b725c0bb5d.json ~/backup/wallet-track/sa-json-2026-06-30.json
   ls -la ~/backup/wallet-track/sa-json-2026-06-30.json

   # 0c. Backup do conteúdo da chave codificado em base64 (forma como aparece em GOOGLE_SERVICE_ACCOUNT_JSON)
   base64 -w0 wallet-track-499719-54b725c0bb5d.json > ~/backup/wallet-track/sa-json-2026-06-30.b64
   # Validar:
   base64 -d ~/backup/wallet-track/sa-json-2026-06-30.b64 | jq -r '.client_email'
   # → DEVE ser igual ao resultado do passo 0a

   # 0d. Confirmar que o backup tem 2 cópias independentes (em mídias distintas é melhor,
   #     mas para esta task, duas cópias no mesmo host satisfazem o mínimo)
   ```

1. **Backup do `.env.bak`** (passo manual antes de qualquer mudança):
   ```bash
   mkdir -p ~/backup/wallet-track
   cp .env.bak ~/backup/wallet-track/env-bak-2026-06-30.env
   ls -la ~/backup/wallet-track/
   ```
2. **Confirmação de que o usuário tem acesso ao GCP** (mas a destruição de recursos remotos é manual — vide §9.4).
3. **Working tree limpa** (sem mudanças pendentes):
   ```bash
   git status
   # → "nothing to commit, working tree clean"
   ```
4. **Branch dedicado**:
   ```bash
   git checkout -b chore/remove-gcp-infra
   ```
5. **Suíte de testes baseline verde** (capturar baseline para comparar depois):
   ```bash
   php artisan test > /tmp/wallet-track-test-baseline.log 2>&1
   tail -20 /tmp/wallet-track-test-baseline.log
   # → "Tests: X, Assertions: Y" — guardar esses números
   ```

### 9.2 Sequência de mudanças no repo (ordem ótima)

A ordem importa por causa de dependências (autoloader, cache, validação cruzada).

| # | Ação | Comando | Justificativa da ordem |
|---|---|---|---|
| 1 | **Deletar `cloudbuild.yaml`** | `git rm cloudbuild.yaml` | Primeiro deleta o que não tem mais consumer. |
| 2 | **Deletar `scripts/deploy.sh`** | `git rm scripts/deploy.sh` | Idem. |
| 3 | **Deletar `scripts/strip-google-services.php`** | `git rm scripts/strip-google-services.php` | Idem. |
| 4 | **Deletar `wallet-track-499719-54b725c0bb5d.json`** | `git rm wallet-track-499719-54b725c0bb5d.json` | Remove a chave commitada. (Backup no Secret Manager externo é responsabilidade do usuário, fora do repo.) |
| 5 | **Deletar `docs/runbook.md`** | `git rm docs/runbook.md` | Doc 100% GCP. |
| 6 | **Deletar `docs/viability-report.md`** | `git rm docs/viability-report.md` | Idem. |
| 7 | **Deletar `docs/comparativo-preco-infra.md`** | `git rm docs/comparativo-preco-infra.md` | Idem. |
| 8 | **Deletar `docs/comparativo-vps.md`** | `git rm docs/comparativo-vps.md` | Idem. |
| 9 | **Deletar `docs/infra-pessoal.md`** | `rm docs/infra-pessoal.md` (não commitado, no `.gitignore`; `rm` local) | Idem. |
| 10 | **Backup e deletar `.env.bak`** | `cp .env.bak ~/backup/wallet-track/env-bak-2026-06-30.env` (pré-condição §9.1) → `git rm .env.bak` | Backup prévio garante auditoria. |
| 11 | **Editar `config/google.php`** (remover bloco `cloud`) | Conforme §2.2.2 | Mudança cirúrgica em config; sem efeito colateral em runtime até o próximo boot. |
| 12 | **Editar `config/telegram.php`** (comentário webhook_url) | Conforme §2.2.3 | Só comentário. |
| 13 | **Editar `config/gemini.php`** (comentário timeout) | Conforme §2.2.4 | Só comentário. |
| 14 | **Editar `config/octane.php`** (comentário max_execution_time) | Conforme §2.2.5 | Só comentário. |
| 15 | **Editar `app/Http/Controllers/HealthController.php`** (remover GOOGLE_CLOUD_PROJECT_ID do array e do método) | Conforme §2.2.6 | Mudança cirúrgica que afeta o output de `/health`. |
| 16 | **Editar `app/Http/Controllers/TelegramWebhookController.php`** (comentário) | Conforme §2.2.7 | Só comentário. |
| 17 | **Editar `app/Console/Commands/SetTelegramWebhook.php`** (2 menções) | Conforme §2.2.8 | Só comentário/mensagem. |
| 18 | **Editar `app/Console/Commands/SyncPendingTransactions.php`** (3 menções) | Conforme §2.2.9 | Só comentário. |
| 19 | **Editar `app/Bot/Handlers/SyncHandler.php`** (1 menção) | Conforme §2.2.10 | Só comentário. |
| 19b | **Editar `app/Console/Commands/RemoveOriginColumn.php`** (cirúrgico AMB #1) | Conforme §2.2.10.1 | Mover `app(SheetsGateway::class)` para depois do dry-run check. Adicionar comentário explicativo. |
| 20 | **Editar `app/Services/Google/GoogleCredentials.php`** (3 menções no docblock) | Conforme §2.2.11 | Só comentário. |
| 21 | **Editar `routes/console.php`** (2 comentários) | Conforme §2.2.12 | Só comentário. |
| 22 | **Editar `Dockerfile`** (7 comentários) | Conforme §2.2.13 | Só comentário. |
| 23 | **Editar `docker/entrypoint.sh`** (1 comentário) | Conforme §2.2.14 | Só comentário. |
| 24 | **Editar `docker-compose.yml`** (comentários + remover `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` do environment) | Conforme §2.2.15 | Mudança cirúrgica. |
| 25 | **Editar `docker-compose.dev.yml`** (comentário + remover `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` do environment) | Conforme §2.2.15 | Idem. |
| 26 | **Editar `phpunit.xml`** (remover `GOOGLE_CLOUD_PROJECT_ID`) | Conforme §2.2.16 | Mudança mínima. |
| 27 | **Editar `bin/check-viability.sh`** (remover `grpc` e `protobuf` de REQUIRED_EXTS) | Conforme §2.2.17 | GATE ainda funciona. |
| 28 | **Editar `bin/dev`** (atualizar comentários sobre extensões) | Conforme §2.2.18 | Só comentário. |
| 29 | **Editar `tests/Feature/HealthTest.php`** (1 menção) | Conforme §2.2.19 | Só comentário. |
| 30 | **Editar `tests/Feature/Http/HealthControllerTest.php`** (1 assertion: `total === 6` → `total === 5`) | Conforme §6.2 | Mudança cirúrgica. |
| 31 | **Editar `.env`** (remover 3 env vars GCP-infra + ajustar header da seção) | Conforme §3.3 | Arquivo do host; valores reais. |
| 32 | **Editar `.env.dev`** (remover 2 env vars GCP-infra + ajustar header da seção) | Conforme §3.3 | Idem. |
| 33 | **Editar `.env.example`** (remover `GOOGLE_CLOUD_PROJECT_ID` + ajustar header da seção) | Conforme §3.3 | Template. |
| 34 | **Editar `README.md`** (10+ mudanças conforme §2.2.20) | Conforme §2.2.20 | Visão geral do projeto. |
| 35 | **Editar `CHANGELOG.md`** (adicionar entrada) | Conforme §2.2.21 | Histórico. |
| 36 | **Editar/atualizar docs/** | Conforme §8 | Para cada doc na lista. |
| 37 | **Limpar caches Laravel** | `php artisan config:clear && php artisan cache:clear && php artisan route:clear` | Limpa `bootstrap/cache/*.php` (§2.2.24). |
| 38 | **Regenerar autoloader Composer** | `composer install --no-interaction` | Garante que o autoloader não referencia arquivos removidos. |
| 39 | **Validar tudo** (§9.3) | Verificação completa. |

> **Sugestão de commits intermediários** (facilita code review e rollback):
> - Commit 1: "chore: remove GCP infra files (cloudbuild, deploy.sh, strip-google-services)" — passos 1-3, 5-10.
> - Commit 2: "chore(config): remove cloud.project_id from config/google.php" — passo 11.
> - Commit 3: "docs: update comments and references from Cloud Run to VPS/scheduler" — passos 12-14, 16, 21-23, 28-29.
> - Commit 4: "fix(Health): drop GOOGLE_CLOUD_PROJECT_ID from critical env checks" — passo 15.
> - Commit 5: "chore(commands): update comments in SetTelegramWebhook, SyncPendingTransactions" — passos 17-19.
> - Commit 6: "chore(services): update GoogleCredentials docblock" — passo 20.
> - Commit 7: "chore(env): remove GCP infra env vars from .env/.env.dev/.env.example; delete .env.bak" — passos 31-33.
> - Commit 8: "chore(docker): remove GOOGLE_SERVICE_ACCOUNT_JSON_PATH from docker-compose; update comments" — passos 24-25.
> - Commit 9: "chore(phpunit): remove GOOGLE_CLOUD_PROJECT_ID; update HealthControllerTest total" — passos 26, 30.
> - Commit 10: "chore(ci): remove grpc/protobuf from check-viability" — passo 27.
> - Commit 11: "docs: update README, CHANGELOG, docs/* for GCP removal" — passos 34-36.

### 9.3 Pós-condições (validação automática)

Executar **na ordem**:

| # | Verificação | Comando | Resultado esperado |
|---|---|---|---|
| 1 | Grep GCP-infra no código (deve ser 0 ou só docs históricas) | `grep -rIn 'cloudbuild\|Cloud Build\|Cloud Run\|gcloud\|deploy\.sh' --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git app/ config/ docker/ docker-compose*.yml routes/ bin/ scripts/ 2>/dev/null` | **0 matches** (código/config/scripts sem GCP) |
| 2 | Grep GCP-infra em `.env*` (deve ser 0) | `grep -rIn 'GOOGLE_CLOUD_PROJECT_ID\|FIRESTORE_DATABASE_ID' .env .env.dev .env.example` | **0 matches** |
| 3 | Grep `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` em `docker-compose*.yml` (deve ser 0) | `grep -n 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH' docker-compose*.yml` | **0 matches** |
| 4 | Suite PHPUnit completa | `php artisan test` | **0 falhas**, mesma contagem (ou próxima) de testes que o baseline |
| 5 | `php artisan list` mostra comandos Sheets e não Cloud Build | `php artisan list | grep -E 'transactions:sync-pending\|sheets:remove-origin-column\|cloudbuild'` | Mostra `transactions:sync-pending`, `sheets:remove-origin-column`; **0 matches** para `cloudbuild` |
| 6 | `composer install` funciona | `composer install --no-interaction` | exit 0 |
| 7 | `docker compose config` sem GOOGLE_SERVICE_ACCOUNT_JSON_PATH injetado | `docker compose config | grep -i 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH'` | **0 matches** na seção `environment` (pode aparecer em `env_file` se referenciado) |
| 8 | Classes Sheets resolvem via autoload | `php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\SheetsService') ? 'OK' : 'FALHA';"` | Imprime `OK` |
| 9 | SheetsServiceProvider boota | `php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make('App\\Services\\Google\\SheetsService'); echo 'OK';"` | Imprime `OK` (com a devida config; em teste usa InMemorySheetsGateway via `tests/Unit/Services/Google/SheetsServiceTest.php` que já valida isso) |
| 10 | `transactions:sync-pending --help` (sem credenciais) | `php artisan transactions:sync-pending --help` | Lista as opções; exit 0. (Não tenta resolver `Sheets` neste caminho — o `Sheets` é resolvido lazy dentro de `handle()` quando há transações; com DB vazio + `--dry-run` funciona sem Sheets.) |
| 11 | `sheets:remove-origin-column --dry-run` | `php artisan sheets:remove-origin-column --dry-run` | Imprime "DRY-RUN: a coluna G (índice 6, "Origem") seria deletada da aba principal." — exit 0. (Em ambiente dev, o `InMemorySheetsGateway` é registrado pelos testes; sem teste, pode falhar por falta de credenciais. **Verificação prática só faz sentido em ambiente com Sheets config.**) |
| 12 | `php artisan test --filter Sheets` | `php artisan test --filter Sheets` | 0 falhas |
| 13 | `php artisan test --filter Health` | `php artisan test --filter Health` | 0 falhas (HealthTest + HealthControllerTest) |
| 14 | `php -l <cada arquivo modificado>` | Para cada arquivo PHP em §2.2, rodar `php -l <path>` | Sem erros de sintaxe |
| 15 | `docker build -t wallet-track:dev --target dev . 2>&1 | tail -30` (**AMB #2** — requer `BASE_IMAGE=wallet-track-base:latest` pré-construída; estágio-alvo `dev` linha 168 do `Dockerfile`; tempo esperado < 5 min com cache, < 10 min cold) | Exit 0; imagem `wallet-track:dev:latest` criada. |

### 9.4.1 Bloco D — Destruição remota dos recursos GCP (NOVA FASE, v2.0)

> **Onde estou no fluxo:** após Bloco A (mudanças no repo + commit + push + merge), após Bloco B (validação local em §9.3), e **antes** de qualquer deploy novo. É a **última** fase. Modo: **INTERATIVO** (o agente executor pausa para confirmação do usuário antes de cada bloco destrutivo). Logging: stdout do `gcloud` + `tee` em log file (vide §10.3).

#### 9.4.1.1 Pré-condições do Bloco D

1. **Backup da chave SA** já executado e validado (vide §9.1 #0).
2. **Todas as categorias A-I do plano de testes** já passaram (vide `docs/testes/plano-testes-manuais-remocao-gcp.md`).
3. **Branch mergeada em `main`** (se a estratégia for merge-then-destroy) **OU** branch `chore/remove-gcp-infra` está pronta (se a estratégia for destroy-then-merge).
4. **Usuário disponível** para confirmar a cada bloco (input interativo no script).
5. **Conexão ao console GCP** (https://console.cloud.google.com) aberta em outra aba como fallback se algum comando `gcloud` falhar.

#### 9.4.1.2 Modos de execução

O script `scripts/destroy-gcp-resources.sh` (proposto em §10.4) tem **3 modos**:

| Modo | Flag | Comportamento |
|---|---|---|
| **Dry-run** | `--dry-run` | Lista exatamente o que **seria** destruído. **Não** executa nada destrutivo. |
| **Execute** | `--execute` | Executa a destruição **passo a passo**, com confirmação do usuário antes de cada bloco. Logging em `tmp/destroy-gcp-resources-<timestamp>.log`. |
| **Verify** | `--verify-only` | Roda apenas os CTs de verificação (CT-J-12 a CT-J-19) sem tocar em nada destrutivo. Útil para auditar pós-execução. |

#### 9.4.1.3 Política de backup-first

**SEMPRE** fazer backup do recurso antes de deletar. Política concreta por bloco:

| Bloco (§10.3) | Backup antes de deletar |
|---|---|
| §10.3.1 Cloud Run | Não há dados (imagens Docker ficam no Artifact Registry). Backup = `gcloud run services describe wallet-track --region=... --format=json` → `tmp/cloud-run-before.json`. |
| §10.3.2 Cloud Scheduler | `gcloud scheduler jobs describe wallet-track-sync-pending --location=... --format=json` → `tmp/cloud-scheduler-before.json`. |
| §10.3.3 Secret Manager | **CRÍTICO** — `gcloud secrets versions access latest --secret=wallet-track-env` → `tmp/wallet-track-env-before.json` (contém 12 chaves de env, incluindo `GOOGLE_SERVICE_ACCOUNT_JSON`). Repetir para `github-wallet-track-...`. Validar que o backup tem `GOOGLE_SERVICE_ACCOUNT_JSON` (length > 1000). |
| §10.3.4 Artifact Registry | Não há dados críticos (imagens podem ser reconstruídas). Backup = log do `gcloud artifacts docker images list`. |
| §10.3.5 Cloud Build connection | Não há dados. Backup = `gcloud builds connections describe ... --format=json`. |
| §10.3.6 Firestore | `gcloud firestore databases list` + (opcional) export via `gcloud firestore export` para GCS — **mas como vamos desabilitar Storage API também, este backup é arriscado**. Decisão: **não fazer export automático**; Firestore de runtime **não tem dados** (migração para MariaDB completa em M7). Apenas logar o `describe` por bloco. |
| §10.3.7 APIs | N/A (não há dados; é só `services disable`). |
| §10.3.8 IAM cleanup | Backup = `gcloud projects get-iam-policy wallet-track-499719 --format=json` → `tmp/iam-policy-before.json` (**antes** de qualquer `remove-iam-policy-binding`). |

#### 9.4.1.4 Rollback — o que **NÃO** é reversível

| Recurso | Reversível? | Rollback |
|---|---|---|
| Cloud Run service | **Não** | Recriar service com mesmo nome + imagem (precisa do Artifact Registry, que também será destruído). **Irreversível** na prática. |
| Artifact Registry | **Não** | Re-`docker push` da imagem (precisa da imagem local; podemos ter perdido). **Irreversível** na prática. |
| Secret Manager (wallet-track-env) | **Não** | Sem versão anterior. O backup em `tmp/` é a única esperança. **Crítico ter backup**. |
| Cloud Scheduler | **Sim** | `gcloud scheduler jobs create` (a config está em `tmp/cloud-scheduler-before.json`). |
| Cloud Build connection | **Parcial** | Reconectar GitHub OAuth (workaround manual; GitHub App precisa ser re-autorizada). |
| Firestore database | **Não** | Re-criar cria database vazio. Dados perdidos. (Irrelevante para esta task — não há dados de runtime). |
| APIs (services disable) | **Sim** | `gcloud services enable` (pode levar minutos). |
| IAM policy binding | **Sim** | `gcloud projects add-iam-policy-binding` (a config está em `tmp/iam-policy-before.json`). |

#### 9.4.1.5 Pontos de pausa para confirmação do usuário

O script, em modo `--execute`, pausa nos seguintes pontos (input `y/N`):

1. **PAUSA 1:** antes do bloco §10.3.1 (Cloud Run) — exibir estado atual e perguntar.
2. **PAUSA 2:** antes do bloco §10.3.3 (Secret Manager) — exibir backup criado e perguntar.
3. **PAUSA 3:** antes do bloco §10.3.6 (Firestore) — exibir databases e perguntar.
4. **PAUSA 4:** antes do bloco §10.3.7 (APIs) — exibir lista de APIs que serão desabilitadas e perguntar.
5. **PAUSA 5:** antes do bloco §10.3.8 (IAM cleanup) — exibir bindings órfãs e perguntar.

Os blocos §10.3.2 (Scheduler), §10.3.4 (AR), §10.3.5 (Cloud Build) não pausam individualmente — são executados em lote com **uma** confirmação no início do lote "scheduler + AR + cloudbuild".

#### 9.4.1.6 Pós-condição do Bloco D (handoff para §9.5)

Após o Bloco D, **todas as listas de recursos GCP-infra** devem estar vazias (verificável pelos CT-J-12 a CT-J-15). O handoff para §9.5 (validação final integrada) é automático: o usuário roda os CTs da categoria E (Sheets smoke test) e, se passarem, a Fase 2 está completa.



### 9.4 Ações destrutivas REMOTAS no GCP — **delegadas ao agente executor**

**Esta seção foi MIGRADA para §10 ("Ações Destrutivas Remotas no GCP")** na v2.0 da spec. Anteriormente, a destruição dos recursos remotos GCP era uma checklist manual que o usuário executava no console / `gcloud` CLI. A partir desta v2.0, **a destruição é parte do entregável do agente executor** (`coder` + script `scripts/destroy-gcp-resources.sh` descrito em §10.4), e o usuário apenas:

1. **Confirma o backup** da chave SA (passo §9.1 #0).
2. **Autoriza o início** da execução (input interativo no script — vide §10.4 modos `--execute`).
3. **Visualiza o log** de destruição (`tee` em log file — vide §10.3).
4. **Executa o pós-check** objetivo (CT-J-12 a CT-J-19 — vide §12.4).

A ordem de destruição, comandos `gcloud` exatos, política de idempotência, soft-vs-hard delete e rollback estão **inteiramente** especificados em §10.3. Não há nada nesta seção (§9.4) que precise ser executado manualmente pelo usuário além dos 4 passos acima.

> **Justificativa do cambio de escopo:** executar a destruição via script versionado (1) garante idempotência (pode rodar 2x sem erro), (2) produz log auditável, (3) evita erros de digitação em comandos `gcloud` longos, (4) permite dry-run antes da execução real. O script mora no repo (auditável) e é executado pelo agente executor sob supervisão do usuário.

### 9.5 Validação final integrada (pós-destruição remota)

```bash
# No host local (com .env apontando para a planilha real):
php artisan transactions:sync-pending --dry-run --format=json
# → {"status":"ok","mode":"dry-run","would_process":N,"ids":[]}
# (N pode ser 0; depende do estado do banco)

# Se houver 1 transação pendente:
php artisan transactions:sync-pending --dry-run --format=json
# → {"status":"ok","mode":"dry-run","would_process":1,"ids":[42]}

# Em modo real (testar com 1 transação pendente controlada):
php artisan transactions:sync-pending --format=json
# → {"status":"ok","processed":1,"synced":1,"failed":0,"errors":[],"time_budget_exhausted":false}

# Verificar na planilha real (browser): a linha correspondente deve aparecer.

# Suíte completa de testes
php artisan test
# → 0 falhas
```

---

## 10. Ações Destrutivas Remotas no GCP (delegadas ao agente executor)

> **Escopo:** destruição completa da infraestrutura GCP-infra no projeto `wallet-track-499719`, **exceto** o estritamente necessário para o Google Sheets continuar funcionando (ver Portão 1). O **agente executor** (`coder` + script `scripts/destroy-gcp-resources.sh`) é responsável por executar esta fase, **sob confirmação do usuário em pontos de pausa explícitos** (ver §9.4.1.5). O usuário **NÃO** roda comandos `gcloud` manualmente — apenas confirma e valida o pós-check.
>
> **Origem do escopo (v2.0):** a v1.x desta spec tratava a destruição remota como checklist manual. A v2.0 internaliza-a no entregável do agente executor para garantir (a) idempotência, (b) log auditável, (c) dry-run antes da execução real, (d) pós-check objetivo automatizado (CT-J-12 a CT-J-19).

### 10.1 Inventário remoto verificado (via `gcloud`, 30/06/2026)

#### 10.1.1 Recursos a DESTRUIR (corpo da task)

| # | Tipo | Recurso | ID/Name | Região/Location | Detalhes |
|---|---|---|---|---|---|
| D1 | Cloud Run service | `wallet-track` | URL: `https://wallet-track-gr5tptkqza-rj.a.run.app` | `southamerica-east1` | 8 revisions ativas (`wallet-track-00001-gv8` a `wallet-track-00008-prr`); IAM `allUsers` com `roles/run.invoker`; runtime SA = `wallet-track-run@…` |
| D2 | Artifact Registry repo | `wallet-track` | `projects/wallet-track-499719/locations/southamerica-east1/repositories/wallet-track` | `southamerica-east1` | `DOCKER`, `STANDARD_REPOSITORY`; **431.36 MB** de imagens (imagens Docker) |
| D3 | Secret Manager | `wallet-track-env` | 1 versão ativa (criada 2026-06-25) | — (global) | Conteúdo: 12 chaves de env (APP_KEY, TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET_TOKEN, DEEPSEEK_API_KEY, GEMINI_API_KEY, CRON_SECRET_TOKEN, GOOGLE_CLOUD_PROJECT_ID, **GOOGLE_SERVICE_ACCOUNT_JSON**, GOOGLE_SHEETS_SPREADSHEET_ID, GOOGLE_SHEETS_SHEET_NAME, GOOGLE_SHEETS_CATEGORIES_SHEET_NAME, FIRESTORE_DATABASE_ID) — **contém a chave SA em base64** |
| D4 | Secret Manager | `github-wallet-track-github-oauthtoken-a10b00` | 1 versão ativa (criada 2026-06-27) | — (global) | OAuth token do GitHub App para Cloud Build (Connection) |
| D5 | Cloud Build connection | `github-wallet-track` | `projects/.../locations/southamerica-east1/connections/github-wallet-track` | `southamerica-east1` | `installationState.stage=COMPLETE`; sem triggers nem repos nem worker-pools (todos vazios) |
| D6 | Cloud Scheduler job | `wallet-track-sync-pending` | `projects/.../locations/southamerica-east1/jobs/wallet-track-sync-pending` | `southamerica-east1` | Schedule: `*/5 * * * *`; **STATE: ENABLED**; URI: `https://wallet-track-.../cron/sync-pending` (aponta para Cloud Run que será destruído em D1) |
| D7 | Firestore database | `wallet-track-db` | `projects/.../databases/wallet-track-db` | `us-central1` | `FIRESTORE_NATIVE` — referenciado no `.env` antigo (linha 119) |
| D8 | Firestore database | `wallet-track-dev-db` | `projects/.../databases/wallet-track-dev-db` | `southamerica-east1` | `FIRESTORE_NATIVE` — **NÃO estava no `.env`** (achego de inventário) |
| D9 | Firestore database | `(default)` | `projects/.../databases/(default)` | `us-central1` | `DATASTORE_MODE` — provavelmente auto-criado, sem uso de runtime |
| D10-D42 | APIs | **33 APIs** a desabilitar | (ver lista §10.3.7) | — (global) | Manter apenas 4: `sheets.googleapis.com`, `iam.googleapis.com`, `cloudresourcemanager.googleapis.com`, `generativelanguage.googleapis.com` (Gemini) |

#### 10.1.2 Recursos a MANTER (decisão Portão 1, INEGOCIÁVEL)

| # | Tipo | Recurso | Por que permanece |
|---|---|---|---|
| M1 | Service Account (custom) | `wallet-track-run@wallet-track-499719.iam.gserviceaccount.com` (display: "Wallet Track Cloud Run Service Account") | **Orphaned pós-remoção** (era identidade do Cloud Run). Manter por segurança (reversão teórica). Roles atuais: `roles/run.invoker` (vai ficar inútil), `roles/datastore.user` (vai ficar inútil). Decisão sobre deletar esta SA após o Bloco D é **item pendente de decisão** do usuário (ver §12 R12 e §10.7). |
| M2 | Service Account (custom) | `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (display: "google-sheet") | **A SA que escreve no Google Sheets.** Tem `roles/editor` e `roles/datastore.user`. A chave desta SA está no JSON que está sendo deletado do repo. **A chave precisa ser preservada em backup** (ver §9.1 #0 e §10.1.3). |
| M3 | Service Account (auto-managed) | `ais-gemini-key-d477594f3cbb4b8@706720442016.iam.gserviceaccount.com` | Auto-managed pelo Gemini API; some/desabilita sozinho. |
| M4 | Service Account (Default compute) | `706720442016-compute@developer.gserviceaccount.com` | Default compute SA do projeto; required por Google. Manter. |
| M5 | Service Agents (auto-managed, 9+) | `service-706720442016@*.iam.gserviceaccount.com` (containerregistry, firebase-rules, artifactregistry, cloudbuild, cloudscheduler, firestore, pubsub, serverless-robot-prod) | Auto-managed; somem quando os serviços correspondentes são desabilitados (Bloco D7). |
| M6 | Sheets API | `sheets.googleapis.com` | Necessária para o Sheets. |
| M7 | IAM API | `iam.googleapis.com` | Necessária para as SAs existirem. |
| M8 | Cloud Resource Manager API | `cloudresourcemanager.googleapis.com` | Necessária para o projeto existir. |
| M9 | Gemini API | `generativelanguage.googleapis.com` | Gemini OCR é feature de produto (não GCP-infra). Manter. |
| M10 | Billing | `012501-E0763B-441359` (vinculado, `billingEnabled=True`) | Necessário para Sheets API funcionar em modo autenticado. **NÃO TOCAR**. |
| M11 | Planilha Google Sheets | `1rGNN0XOOYwDvMYDpFwU1a2ozQXPhAk8P2l8Xjnk9a14` (prod) / `1aRr8S511jLcZtrAKyi6wbFc4PLMV_hGNO86sP7Pj4_c` (dev) | Dados do produto. **NÃO TOCAR**. |

#### 10.1.3 Identificação da SA cuja chave está no JSON sendo deletado (passo crítico pré-§10.3)

**Contexto:** o arquivo `wallet-track-499719-54b725c0bb5d.json` (a ser deletado do repo em §2.1) contém a chave privada de **UMA** das SAs customizadas. Antes de qualquer destruição, **é obrigatório** identificar qual:

```bash
# PASSO 1: extrair client_email do JSON
cat wallet-track-499719-54b725c0bb5d.json | jq -r '.client_email'
# → "google-sheet-202@wallet-track-499719.iam.gserviceaccount.com" (ESPERADO, vide §0.1)

# PASSO 2: comparar com a lista de SAs customizadas conhecidas
EXPECTED=(
  "wallet-track-run@wallet-track-499719.iam.gserviceaccount.com"
  "google-sheet-202@wallet-track-499719.iam.gserviceaccount.com"
)
ACTUAL=$(cat wallet-track-499719-54b725c0bb5d.json | jq -r '.client_email')

if [[ " ${EXPECTED[@]} " =~ " ${ACTUAL} " ]]; then
  echo "OK: SA conhecida (${ACTUAL})"
else
  echo "FALHA: SA ${ACTUAL} não está na lista conhecida — ABORTAR e investigar"
  exit 1
fi

# PASSO 3: confirmar que a SA existe no IAM
gcloud iam service-accounts describe "${ACTUAL}" --project=wallet-track-499719 --format="value(email,disabled)"
# → "google-sheet-202@wallet-track-499719.iam.gserviceaccount.com  False" (ESPERADO)

# PASSO 4: contar chaves (a chave do JSON é uma delas)
gcloud iam service-accounts keys list   --iam-account="${ACTUAL}"   --project=wallet-track-499719   --format="value(name.basename())" | wc -l
# → 2 (chave antiga + chave atual; a atual é a do JSON sendo deletado)
```

**Achado verificado (30/06/2026):** o `client_email` é `google-sheet-202@…` (M2 em §10.1.2). A SA que escreve no Sheets é `google-sheet-202`, **NÃO** `wallet-track-run`. A v1.x da spec estava errada nesse ponto (ver §0.1). A correção é registrada em §1.2, §1.3 e §16 (AMB #6).

### 10.2 Política de destruição

#### 10.2.1 Ordem ótima (minimizar janelas de inconsistência)

A ordem de destruição importa. Destruir recursos na ordem **errada** pode deixar o estado do projeto em situações como "Artifact Registry API desabilitada antes do repo ser deletado" ou "SA deletada antes da chave" (impossível recuperar).

| # | Bloco | Por que nesta ordem |
|---|---|---|
| 1 | **§10.3.1 Backup-first** (criar diretório `tmp/gcp-destroy-2026-06-30/`, capturar snapshots de todas as listas e do IAM policy) | Tudo que vem depois assume que os backups existem. |
| 2 | **§10.3.2 SA identification** (rodar §10.1.3; ABORTAR se falhar) | Se a SA não é conhecida, o backup está errado; não prosseguir. |
| 3 | **§10.3.3 Cloud Run service** + 8 revisions (cascateia) | Cloud Run depende de Cloud Scheduler (D6) apontar para ele. Destruir Cloud Run **antes** do Scheduler evita requests falhando durante a janela. |
| 4 | **§10.3.4 Cloud Scheduler job** (D6) | Após Cloud Run destruído, este job vira dead config; deletar agora. |
| 5 | **§10.3.5 Secret Manager** (D3 + D4) | Após o backup da chave SA (passo 1), o conteúdo de `wallet-track-env` pode ser destruído. **CUIDADO**: a chave SA está em base64 dentro do secret. |
| 6 | **§10.3.6 Artifact Registry** (D2) | Imagens Docker — não há mais container de produção que precise delas. |
| 7 | **§10.3.7 Cloud Build connection** (D5) | Após AR e Cloud Run destruídos, o Cloud Build perde o último consumer. |
| 8 | **§10.3.8 Firestore databases** (D7 + D8 + D9) | Após o projeto sem Cloud Run, o Firestore de runtime não tem consumer. D9 (`(default)` em Datastore mode) pode precisar de tratamento especial (databases `(default)` em Datastore mode não são deletáveis; apenas desabilitar a API). |
| 9 | **§10.3.9 APIs** (33 disables) | **Após** todos os recursos que usam essas APIs estarem destruídos. **CRÍTICO**: `iam.googleapis.com` e `cloudresourcemanager.googleapis.com` **NÃO** devem ser desabilitados (M7, M8). |
| 10 | **§10.3.10 IAM cleanup** (remover bindings órfãs de service agents desabilitados) | Última coisa — só após todas as APIs desabilitadas, podemos listar os service agents que ficaram órfãos. |
| 11 | **§10.3.11 Verificação final** (rodar CTs J-12 a J-19) | Sanity check do estado pós-remoção. |

#### 10.2.2 Idempotência

**Cada comando `gcloud` é envolto em uma função helper `delete_if_exists`** que checa a existência antes de deletar. Em modo `--execute`, se o recurso já não existe (foi deletado em run anterior), o helper imprime `[SKIP] <recurso> já não existe` e segue. Exemplo:

```bash
delete_if_exists() {
  local check_cmd="$1"
  local delete_cmd="$2"
  local label="$3"
  if eval "$check_cmd" >/dev/null 2>&1; then
    echo "[STEP ${STEP}/${TOTAL_STEPS}] Deletando ${label}..."
    eval "$delete_cmd" && echo "  OK: ${label} destruído" || echo "  FALHA: ${label} — investigar"
  else
    echo "[SKIP] ${label} já não existe"
  fi
}
```

Garantia: rodar o script 2x em modo `--execute` produz o mesmo estado final, sem erros.

#### 10.2.3 Soft delete vs hard delete

| Recurso | Soft delete? | Observação |
|---|---|---|
| Cloud Run | **Não** | Deletar é imediato e irreversível. |
| Artifact Registry | **Não** | Idem. |
| Secret Manager | **Versão: sim (10–30 dias). Secret inteiro: não.** | `gcloud secrets versions destroy` é soft (recuperável até a data de destruição); `gcloud secrets delete` é hard. Usar **versions destroy** + depois `delete` para ter janela de auditoria. |
| Cloud Build connection | **Não** | Hard delete. |
| Cloud Scheduler | **Não** | Hard delete. |
| Firestore database | **Parcial** | `gcloud firestore databases delete` é soft durante ~7 dias (recovery window). Após isso, hard. |
| APIs | **Não** | `gcloud services disable` é imediato. |
| IAM bindings | **Não** | Hard. (Mas restaurável a partir do backup `tmp/iam-policy-before.json`.) |

#### 10.2.4 Paralelização segura

Grupos que podem rodar **em paralelo** (sem ordem entre si):

- **Grupo P1:** §10.3.3 (Cloud Run) e §10.3.4 (Cloud Scheduler) — independentes, mas ambos devem vir antes de §10.3.9 (APIs). **Recomendação: serializar** (Pausa 1 cobre ambos).
- **Grupo P2:** §10.3.6 (AR) e §10.3.7 (Cloud Build connection) — AR imagens podem ir junto com Cloud Build. **Recomendação: serializar** (lote único).
- **Grupo P3:** §10.3.8 (Firestore D7, D8) — D9 é caso especial; rodar D7 + D8 em paralelo, D9 sequencial.

**Grupos que NÃO podem paralelizar:**

- §10.3.1 (backup) → §10.3.2 (SA id) → §10.3.3+ (início da destruição) — **sequencial obrigatório**.
- §10.3.9 (APIs) → §10.3.10 (IAM cleanup) — **sequencial** (cleanup depende de quais service agents ficaram órfãos).
- §10.3.10 (IAM) → §10.3.11 (verify) — **sequencial**.

### 10.3 Comandos `gcloud` exatos (por bloco)

> **Convenção**: todos os comandos abaixo incluem `--project=wallet-track-499719` (explícito, não inferido de `gcloud config set project`). Flags `--quiet` para não-interactive em produção, `--force` em APIs disable (que pediria confirmação). Cada bloco termina com **verificação pós-comando** (read-only) que confirma o estado esperado.

#### 10.3.1 Backup-first (criar `tmp/gcp-destroy-2026-06-30/`)

```bash
mkdir -p tmp/gcp-destroy-2026-06-30
TS=$(date -u +%Y%m%dT%H%M%SZ)

# Estado pré-remoção de TUDO
gcloud config set project wallet-track-499719

gcloud run services list --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/cloud-run-before.json
gcloud run revisions list --service=wallet-track --region=southamerica-east1 --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/cloud-run-revisions-before.json
gcloud artifacts repositories list --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/artifact-registry-before.json
gcloud secrets list --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/secrets-before.json
gcloud secrets versions access latest --secret=wallet-track-env --project=wallet-track-499719 > tmp/gcp-destroy-2026-06-30/wallet-track-env-before.json
gcloud secrets versions access latest --secret=github-wallet-track-github-oauthtoken-a10b00 --project=wallet-track-499719 > tmp/gcp-destroy-2026-06-30/github-oauth-token-before.json
gcloud scheduler jobs list --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/scheduler-before.json
gcloud builds connections list --region=southamerica-east1 --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/cloudbuild-connections-before.json
gcloud firestore databases list --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/firestore-before.json
gcloud services list --enabled --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/apis-enabled-before.json
gcloud projects get-iam-policy wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/iam-policy-before.json
gcloud iam service-accounts list --project=wallet-track-499719 --format=json > tmp/gcp-destroy-2026-06-30/service-accounts-before.json

# Verificação: 10 arquivos criados
ls -la tmp/gcp-destroy-2026-06-30/
# → 10 arquivos, com timestamp ${TS} ≤ 60s atrás
```

#### 10.3.2 SA identification (passo §10.1.3)

Comandos do §10.1.3 devem ser rodados **antes** de qualquer destruição. **ABORTAR se `client_email` não for `google-sheet-202@…` ou `wallet-track-run@…`.**

#### 10.3.3 Cloud Run service (D1)

```bash
# Pré-check: confirmar que existe
gcloud run services describe wallet-track --region=southamerica-east1 --project=wallet-track-499719 --format="value(metadata.name)" >/dev/null 2>&1   || { echo "[ABORT] Cloud Run service 'wallet-track' não existe"; exit 1; }

# Remover IAM binding allUsers (pré-deleção, para evitar warning)
gcloud run services remove-iam-policy-binding wallet-track   --region=southamerica-east1   --project=wallet-track-499719   --member=allUsers   --role=roles/run.invoker   --quiet 2>/dev/null

# Deletar service (cascateia nas 8 revisions)
gcloud run services delete wallet-track   --region=southamerica-east1   --project=wallet-track-499719   --quiet

# Verificação pós
gcloud run services list --project=wallet-track-499719 --format="value(SERVICE)" | grep -q wallet-track   && { echo "  FALHA: wallet-track ainda existe"; exit 1; }   || echo "  OK: Cloud Run service destruído"
```

#### 10.3.4 Cloud Scheduler job (D6)

```bash
# Pré-check
gcloud scheduler jobs describe wallet-track-sync-pending   --location=southamerica-east1 --project=wallet-track-499719 --format="value(name)" >/dev/null 2>&1   || { echo "[SKIP] job 'wallet-track-sync-pending' não existe"; }

# Deletar
gcloud scheduler jobs delete wallet-track-sync-pending   --location=southamerica-east1   --project=wallet-track-499719   --quiet

# Verificação pós
gcloud scheduler jobs list --project=wallet-track-499719 --format="value(name)" | grep -q wallet-track   && { echo "  FALHA"; exit 1; }   || echo "  OK: Cloud Scheduler job destruído"
```

#### 10.3.5 Secret Manager (D3 + D4)

```bash
# D3: wallet-track-env
# Verificar backup foi criado (passo §10.3.1)
test -s tmp/gcp-destroy-2026-06-30/wallet-track-env-before.json   || { echo "[ABORT] backup do wallet-track-env não existe"; exit 1; }
# Validar que o backup tem GOOGLE_SERVICE_ACCOUNT_JSON
grep -q GOOGLE_SERVICE_ACCOUNT_JSON tmp/gcp-destroy-2026-06-30/wallet-track-env-before.json   || { echo "[ABORT] backup não contém GOOGLE_SERVICE_ACCOUNT_JSON"; exit 1; }

# Deletar todas as versões (soft) — para auditoria
for v in $(gcloud secrets versions list wallet-track-env --project=wallet-track-499719 --format='value(name)'); do
  gcloud secrets versions destroy "$v" --secret=wallet-track-env --project=wallet-track-499719 --quiet
done

# Deletar o secret (hard)
gcloud secrets delete wallet-track-env --project=wallet-track-499719 --quiet

# D4: github-wallet-track-github-oauthtoken-a10b00
for v in $(gcloud secrets versions list github-wallet-track-github-oauthtoken-a10b00 --project=wallet-track-499719 --format='value(name)'); do
  gcloud secrets versions destroy "$v" --secret=github-wallet-track-github-oauthtoken-a10b00 --project=wallet-track-499719 --quiet
done
gcloud secrets delete github-wallet-track-github-oauthtoken-a10b00 --project=wallet-track-499719 --quiet

# Verificação pós
SECRETS=$(gcloud secrets list --project=wallet-track-499719 --format='value(name)' | grep -E 'wallet-track|github-wallet-track' | wc -l)
test "$SECRETS" = "0"   && echo "  OK: 0 secrets GCP-infra restantes"   || { echo "  FALHA: ainda há $SECRETS secrets GCP-infra"; exit 1; }
```

#### 10.3.6 Artifact Registry (D2)

```bash
# Pré-check
gcloud artifacts repositories describe wallet-track --location=southamerica-east1 --project=wallet-track-499719 --format="value(name)" >/dev/null 2>&1   || { echo "[SKIP] repo 'wallet-track' não existe"; }

# Deletar todas as imagens (uma a uma)
for img in $(gcloud artifacts docker images list southamerica-east1-docker.pkg.dev/wallet-track-499719/wallet-track/app --project=wallet-track-499719 --format='value(version)' 2>/dev/null); do
  gcloud artifacts docker images delete     "southamerica-east1-docker.pkg.dev/wallet-track-499719/wallet-track/app:${img}"     --project=wallet-track-499719 --quiet 2>/dev/null
done

# Deletar o repositório
gcloud artifacts repositories delete wallet-track   --location=southamerica-east1   --project=wallet-track-499719   --quiet

# Verificação pós
gcloud artifacts repositories list --project=wallet-track-499719 --format="value(name)" | grep -q wallet-track   && { echo "  FALHA"; exit 1; }   || echo "  OK: Artifact Registry repo destruído"
```

#### 10.3.7 Cloud Build connection (D5)

```bash
# Pré-check (triggers, repos, worker-pools estão vazios)
gcloud builds connections describe github-wallet-track --region=southamerica-east1 --project=wallet-track-499719 --format="value(name)" >/dev/null 2>&1   || { echo "[SKIP] connection 'github-wallet-track' não existe"; }

# Deletar
gcloud builds connections delete github-wallet-track   --region=southamerica-east1   --project=wallet-track-499719   --quiet

# Verificação pós
gcloud builds connections list --region=southamerica-east1 --project=wallet-track-499719 --format="value(name)" | grep -q github-wallet-track   && { echo "  FALHA"; exit 1; }   || echo "  OK: Cloud Build connection destruída"
```

#### 10.3.8 Firestore databases (D7 + D8 + D9)

```bash
# D7: wallet-track-db (NATIVE, us-central1) — deletável
gcloud firestore databases delete wallet-track-db   --project=wallet-track-499719   --quiet 2>/dev/null

# D8: wallet-track-dev-db (NATIVE, southamerica-east1) — deletável
gcloud firestore databases delete wallet-track-dev-db   --project=wallet-track-499719   --quiet 2>/dev/null

# D9: (default) em Datastore mode — NÃO deletável via gcloud
#     (databases (default) em Datastore mode foram criadas quando a API foi habilitada
#      pela primeira vez, e não têm comando delete). A solução é desabilitar
#      firestore/datastore APIs no §10.3.9. Apenas logar.
echo "[INFO] database (default) em Datastore mode não é deletável — desabilitar API em §10.3.9"

# Verificação pós
REMAINING=$(gcloud firestore databases list --project=wallet-track-499719 --format="value(type)" | grep -c FIRESTORE_NATIVE || true)
test "$REMAINING" = "0"   && echo "  OK: 0 databases FIRESTORE_NATIVE restantes (Datastore mode (default) pode persistir)"   || { echo "  FALHA: ainda há $REMAINING databases FIRESTORE_NATIVE"; exit 1; }
```

#### 10.3.9 APIs (D10–D42: desabilitar 33 APIs)

```bash
# Lista canônica de 33 APIs a desabilitar (verificada em 30/06/2026)
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
  # Adicione mais se aparecerem no `gcloud services list --enabled`
)

# Manter (não desabilitar):
KEEP_APIS=(
  "sheets.googleapis.com"
  "iam.googleapis.com"
  "cloudresourcemanager.googleapis.com"
  "generativelanguage.googleapis.com"
)

# Sanity pré: nenhuma das KEEP_APIS está na APIS_TO_DISABLE
for api in "${KEEP_APIS[@]}"; do
  echo "${APIS_TO_DISABLE[@]}" | grep -q "$api"     && { echo "[ABORT] $api está em APIS_TO_DISABLE — REMOVER antes de prosseguir"; exit 1; }
done

# Desabilitar uma a uma
for api in "${APIS_TO_DISABLE[@]}"; do
  if gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" | grep -q "^${api}$"; then
    echo "[STEP] Desabilitando ${api}..."
    gcloud services disable "$api" --project=wallet-track-499719 --force
  else
    echo "[SKIP] ${api} já desabilitada"
  fi
done

# Verificação pós: enabled list contém EXATAMENTE as 4 KEEP_APIS (ordem não importa)
ENABLED=$(gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" | sort)
EXPECTED=$(printf "%s\n" "${KEEP_APIS[@]}" | sort)
if [ "$ENABLED" = "$EXPECTED" ]; then
  echo "  OK: enabled APIs == expected KEEP_APIS"
else
  echo "  FALHA: diferença entre enabled e expected:"
  diff <(echo "$ENABLED") <(echo "$EXPECTED")
  exit 1
fi
```

#### 10.3.10 IAM cleanup (remover bindings órfãs)

```bash
# Listar service agents órfãos (que ficaram sem API após §10.3.9)
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

# Para cada um, listar as roles que tem e remover
for agent in "${ORPHAN_AGENTS[@]}"; do
  ROLES=$(gcloud projects get-iam-policy wallet-track-499719 --format="json" 2>/dev/null     | python3 -c "import json,sys; d=json.load(sys.stdin); [print(b['role']) for b in d.get('bindings',[]) if '${agent}' in b.get('members',[])]" 2>/dev/null)
  for role in $ROLES; do
    echo "[STEP] Removendo binding: ${agent} ← ${role}"
    gcloud projects remove-iam-policy-binding wallet-track-499719       --member="${agent}"       --role="${role}"       --quiet
  done
done

# Verificação pós: nenhum service agent órfão restante
REMAINING_ORPHANS=$(gcloud projects get-iam-policy wallet-track-499719 --format="json" 2>/dev/null   | python3 -c "import json,sys; d=json.load(sys.stdin); [b['role'] for b in d.get('bindings',[]) if any('service-' in m for m in b.get('members',[]))]" 2>/dev/null | wc -l)
test "$REMAINING_ORPHANS" = "0"   && echo "  OK: 0 service agent bindings órfãs"   || echo "  WARN: ainda há $REMAINING_ORPHANS bindings de service agents (podem ser legítimos se o serviço não foi desabilitado em §10.3.9)"
```

#### 10.3.11 Verificação final

```bash
# Roda CTs J-12 a J-19 (definidos em §10.4)
# Esta seção é o "entry point" do modo --verify-only do script.
echo "=== Verificação final (CT-J-12 a CT-J-19) ==="
echo "  (delegado ao modo --verify-only do script, ver §10.4)"
```

### 10.4 Script master: `scripts/destroy-gcp-resources.sh` (proposto, **não executar nesta task**)

> **Status:** o script é **proposto** nesta spec. A **execução** dele é responsabilidade do agente executor (`coder` + supervisor). Esta seção documenta o comportamento esperado, mas o conteúdo do script não é gerado por este agente `spec-designer`. O `coder` deve implementar o script seguindo a especificação abaixo.

#### 10.4.1 Interface

```bash
scripts/destroy-gcp-resources.sh [MODE]

MODES:
  --dry-run       Lista o que seria destruído. NÃO executa nada destrutivo.
                  Inclui: inventário de recursos, ordem de destruição, comandos
                  que seriam rodados, estimativas de irreversibilidade.

  --execute       Executa a destruição real, com pausas para confirmação
                  do usuário (5 pausas explícitas, vide §9.4.1.5).
                  Log em tmp/destroy-gcp-resources-<timestamp>.log (via tee).

  --verify-only   Roda APENAS os CTs J-12 a J-19 (verificação pós-remoção).
                  Read-only. Útil para auditar o estado pós-execução.

  --help          Exibe ajuda.

DEFAULT: --dry-run (seguro por default).
```

#### 10.4.2 Comportamento esperado

**Modo `--dry-run`:**

1. Ler `client_email` do JSON (passo §10.1.3).
2. Validar que está em SAs MANTER (M1 ou M2 em §10.1.2). ABORT se não.
3. Para cada bloco §10.3.3 a §10.3.10, imprimir:
   - `[BLOCK 1/8] §10.3.3 Cloud Run service`
   - `  Resource: wallet-track (southamerica-east1)`
   - `  Backup: tmp/gcp-destroy-2026-06-30/cloud-run-before.json (would create)`
   - `  Command: gcloud run services delete wallet-track --region=... --project=... --quiet`
   - `  Reversibility: NO (irreversível)`
   - `  Pre-check: gcloud run services describe ... >/dev/null 2>&1`
   - `  Post-check: gcloud run services list --format='value(SERVICE)' | grep -q wallet-track`
4. No final, imprimir resumo: `Total: 8 blocks, 33 APIs, ~50 commands. Estimated time: 5-10 min. Reversible: 3 blocks (Scheduler, APIs, IAM). Irreversible: 5 blocks.`

**Modo `--execute`:**

1. Validar backup em `~/backup/wallet-track/sa-json-2026-06-30.json` (de §9.1 #0). ABORT se não existir.
2. **PAUSA 1** (Bloco A: Cloud Run + Scheduler): exibir estado atual + perguntar `[y/N]`. Se sim, executar §10.3.1 (backup) + §10.3.2 (SA id) + §10.3.3 (Cloud Run) + §10.3.4 (Scheduler).
3. **PAUSA 2** (Bloco B: Secret Manager): exibir backup criado + perguntar. Se sim, executar §10.3.5.
4. **PAUSA 3** (Bloco C: AR + Cloud Build): exibir tamanhos + perguntar. Se sim, executar §10.3.6 + §10.3.7.
5. **PAUSA 4** (Bloco D: Firestore): exibir databases + perguntar. Se sim, executar §10.3.8.
6. **PAUSA 5** (Bloco E: APIs): exibir lista de 33 APIs a desabilitar + perguntar. Se sim, executar §10.3.9.
7. **PAUSA 6** (Bloco F: IAM cleanup): exibir service agents órfãos + perguntar. Se sim, executar §10.3.10.
8. Executar §10.3.11 (verificação final).
9. Imprimir `[DONE] Bloco D completo. Estado pós-remoção: tmp/destroy-gcp-resources-<ts>.log.`

**Modo `--verify-only`:**

1. Executar cada CT J-12 a J-19 (definidos em §10.4) e imprimir `PASS` / `FAIL` por CT.
2. Exit code: 0 se todos PASS, 1 se algum FAIL.

#### 10.4.3 Logging

Todas as saídas do `gcloud` (stdout + stderr) são capturadas via `tee` em `tmp/destroy-gcp-resources-<timestamp>.log`. Formato de linha: `[STEP 5/33] gcloud run services delete ...`. O `gcloud` é invocado com `--format=json` para parsing programático (e `--format=text` apenas em interação humana).

#### 10.4.4 Idempotência

O script pode ser rodado 2x em modo `--execute` sem erro. Cada bloco checa existência pré-condição (§10.2.2) e pula com `[SKIP] <recurso> já não existe` se já foi processado.

### 10.5 Pós-condições (verificação automática)

Após o Bloco D, **TODAS** as seguintes verificações devem passar:

| # | Verificação | Comando | Critério |
|---|---|---|---|
| PC1 | Apenas 4 APIs habilitadas | `gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" \| sort` | Igual a: `cloudresourcemanager.googleapis.com\ngenerativelanguage.googleapis.com\niam.googleapis.com\nsheets.googleapis.com` |
| PC2 | Cloud Run vazio | `gcloud run services list --project=wallet-track-499719` | (vazio) |
| PC3 | Artifact Registry vazio | `gcloud artifacts repositories list --project=wallet-track-499719` | (vazio) |
| PC4 | Secret Manager vazio | `gcloud secrets list --project=wallet-track-499719` | (vazio) |
| PC5 | Cloud Scheduler vazio | `gcloud scheduler jobs list --project=wallet-track-499719` | (vazio) |
| PC6 | Cloud Build connections vazio | `gcloud builds connections list --region=southamerica-east1 --project=wallet-track-499719` | (vazio) |
| PC7 | Firestore sem NATIVE | `gcloud firestore databases list --project=wallet-track-499719 --format="value(type)" \| grep -c FIRESTORE_NATIVE` | `0` (pode haver `(default)` em Datastore mode) |
| PC8 | SAs MANTER intactas | `gcloud iam service-accounts list --project=wallet-track-499719 --format="value(email)"` | Contém `google-sheet-202@…` e `wallet-track-run@…`; pode conter outras auto-managed |
| PC9 | Billing ainda ativo | `gcloud beta billing projects describe wallet-track-499719 --format="value(billingEnabled)"` | `True` |
| PC10 | Chave SA no backup | `test -s ~/backup/wallet-track/sa-json-2026-06-30.json` | exit 0 |

Estas pós-condições são automatizadas como CTs J-12 a J-19 (ver §10.4). Falha em qualquer uma delas é **bloqueador** da Fase 2.

### 10.6 Anexo — Saída esperada do inventário (referência)

A saída bruta do `gcloud` em 30/06/2026 (usada para gerar §10.1.1):

```text
$ gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" | sort
analyticshub.googleapis.com
artifactregistry.googleapis.com
bigqueryconnection.googleapis.com
bigquerydatapolicy.googleapis.com
bigquerydatapolicy.googleapis.com  # (33 linhas total, omitidas)

$ gcloud run services list --project=wallet-track-499719 --format="value(SERVICE)"
wallet-track

$ gcloud artifacts repositories list --project=wallet-track-499719 --format="value(name)"
wallet-track

$ gcloud secrets list --project=wallet-track-499719 --format="value(name)"
github-wallet-track-github-oauthtoken-a10b00
wallet-track-env

$ gcloud scheduler jobs list --project=wallet-track-499719 --format="value(name)"
[empty — mas a v2.0 descobriu que o filtro sem --location escondeu o job;
 com --location=southamerica-east1, retorna: wallet-track-sync-pending]

$ gcloud firestore databases list --project=wallet-track-499719 --format="value(name)"
projects/wallet-track-499719/databases/(default)
projects/wallet-track-499719/databases/wallet-track-db
projects/wallet-track-499719/databases/wallet-track-dev-db

$ gcloud iam service-accounts list --project=wallet-track-499719 --format="value(email)"
706720442016-compute@developer.gserviceaccount.com
ais-gemini-key-d477594f3cbb4b8@706720442016.iam.gserviceaccount.com
google-sheet-202@wallet-track-499719.iam.gserviceaccount.com
wallet-track-run@wallet-track-499719.iam.gserviceaccount.com
[+ 9 service-...iam.gserviceaccount.com — auto-managed]

$ cat wallet-track-499719-54b725c0bb5d.json | jq -r '.client_email'
google-sheet-202@wallet-track-499719.iam.gserviceaccount.com
```

### 10.7 Decisões em aberto (itens que o usuário precisa decidir)

Itens que **NÃO** são decididos por esta spec e que o usuário precisa confirmar antes/depois do Bloco D:

1. **A SA `wallet-track-run@…` deve ser deletada após o Bloco D?**
   - **Decisão final (Portão 2, 30/06/2026):** **DELETAR** após Cloud Run ser destruído. Justificativa: SA não será mais usada (Cloud Run foi o único consumidor); princípio do menor privilégio; limpeza de custom SAs.
   - **Comando:** `gcloud iam service-accounts delete wallet-track-run@wallet-track-499719.iam.gserviceaccount.com --project=wallet-track-499719 --quiet` (§10.3.12).
   - **Risco:** se a SA precisar ser recriada (rollback), o display name "Wallet Track Cloud Run Service Account" deve ser reutilizado; o client_email é gerado pelo GCP e **NÃO** é determinístico. Aceitável.
   - Ver R12 em §12.

2. **As roles órfãs de `wallet-track-run@…` e `google-sheet-202@…` devem ser removidas?**
   - `wallet-track-run@…` tem `roles/run.invoker` e `roles/datastore.user` — ambos inúteis pós-Cloud Run destruído.
   - `google-sheet-202@…` tem `roles/editor` (broad!) e `roles/datastore.user` — `roles/editor` é mantido para permitir o Sheets API funcionar (escopo do projeto).
   - **Decisão final (Portão 2, 30/06/2026):** aplicar a recomendação — remover `roles/datastore.user` de ambas SAs; remover `roles/run.invoker` de `wallet-track-run` (antes de deletar a SA); **manter** `roles/editor` de `google-sheet-202` (necessário para Sheets API).
   - **Comandos:** §10.3.10 (IAM cleanup) — adicionar entradas para remover `roles/datastore.user` de `google-sheet-202@…` antes do Bloco D.

3. **O database `(default)` em Datastore mode deve ser migrado/limpo?**
   - É auto-criado e não tem comando `delete` via `gcloud firestore databases delete` (apenas para Firestore Native, não Datastore mode).
   - **Decisão final (Portão 2, 30/06/2026):** **TENTAR DELETAR** via `gcloud firestore databases delete --database="(default)" --project=wallet-track-499719` e capturar erro se Datastore mode recusar. Se recusar, **reportar como conhecido** e prosseguir com a desabilitação da API `datastore.googleapis.com` (em §10.3.9) que efetivamente "congela" o database.
   - **Comando §10.3.8 (atualizado):** incluir tentativa de deletar `(default)` com try/catch e warning.

---

## 11. Riscos técnicos e mitigações

| Risco | Probabilidade | Impacto | Mitigação |
|---|---|---|---|
| **Regressão em Sheets** (Sheets client não instancia, sync não funciona) | Baixa | **CRÍTICO** (quebra o produto) | (a) `SheetsServiceProvider` **intocado**. (b) `InMemorySheetsGateway` intocado. (c) Suíte de testes Sheets cobre os fluxos. (d) Verificação manual `transactions:sync-pending --dry-run` em §9.5. (e) Smoke real: confirmar 1 sync contra a planilha real. |
| **`composer install` quebra por autoload referenciando arquivos removidos** | Baixa | Média | `composer install --no-interaction` regenera o autoload. **Não deletar `vendor/` manualmente**; deixar o Composer fazer. |
| **Cache do `bootstrap/cache/services.php` referenciando providers removidos** | Média | Baixa | (a) `Dockerfile` linha 99 já faz `rm -f bootstrap/cache/*.php` no build. (b) `php artisan optimize:clear` em §9.2.37 limpa o cache. (c) Não há providers removidos — apenas arquivos `.yaml`/`.sh`/`.json`/`.bak`/`.md`. `bootstrap/providers.php` **intocado**. |
| **Histórico Git com SA JSON commitada em algum momento** | Média | **CRÍTICO** (security) | (a) `wallet-track-499719-54b725c0bb5d.json` é **deletado** do working tree (passo 4). (b) `.gitignore` (linhas 28-35) já tem `wallet-track-499719-*.json`, então o arquivo **não deveria estar** no histórico. (c) Verificar com `git log --all --full-history -- wallet-track-499719-54b725c0bb5d.json` — se aparecer, o usuário deve: (i) rotacionar a chave SA no IAM do GCP (criar nova key, deletar a antiga), (ii) considerar `git filter-branch` ou BFG para reescrever histórico. **Esta spec NÃO rotaciona a chave** (decisão de Portão 1); se aparecer no histórico, é uma task separada. **AMB #4 (CT-K-02):** o teste deve classificar o resultado como **PASS / WARN / FAIL**:<br>- **PASS** (caso ideal): `git log` retorna vazio → chave nunca foi commitada → tarefa concluída sem incidente.<br>- **WARN** (caso esperado, NÃO bloqueador): `git log` retorna ≥ 1 commit com a chave → abrir **issue separada** rotulada `security/SA-key-rotation` com prioridade **alta**, mas **NÃO bloquear** o merge desta task. A issue deve listar: (i) rotação de chave via `gcloud iam service-accounts keys create` + desativar antiga; (ii) BFG ou `git filter-branch` para limpar histórico; (iii) novo deploy usando nova chave. Reportar WARN no PR description.<br>- **FAIL** (não esperado neste cenário): se `git log` retornar vazio **E** a chave aparecer no working tree após o `git rm` → bug no fluxo de deleção; re-executar passo §9.2.4. |
| **`transactions:sync-pending` em modo `--dry-run` tenta bootar Sheets client** | Baixa | Baixa | (a) Verificado no código (linhas 124-127 do `SyncPendingTransactions.php`): `dryRun()` resolve apenas `WalletStore` e sai — não toca `SyncsSheet` nem `Sheets`. (b) `--dry-run` é seguro em qualquer ambiente. |
| **`sheets:remove-origin-column` tenta resolver SheetsService no boot** | Baixa | Baixa | O command é lazy: `app(SheetsGateway::class)` é resolvido dentro de `handle()`. Sem `InMemorySheetsGateway` registrado, o boot do SheetsServiceProvider tentará criar o `Sheets` real (que exige credenciais). Em ambiente dev com `.env` apontando para o JSON, funciona. Sem credenciais, falha com mensagem clara. |
| **Mudança em `config/google.php` quebra consumidores não mapeados** | Baixa | Média | (a) Buscar `config('google.cloud')` em todo o código (grep §12.1) — único consumidor é `HealthController::checkGoogleCloudProjectId`, que é deletado em conjunto. (b) Buscar `GOOGLE_CLOUD_PROJECT_ID` no código (grep §12.2) — único consumidor é o array `CRITICAL_ENV_CHECKS` do HealthController, que é editado. (c) Demais consumidores de `config('google.*')` usam `config('google.sheets.*')` ou `config('google.service_account_json*')` — **intactos**. |
| **Documentação desatualizada após deleção de docs** | Baixa | Baixa | `docs/00-INDEX.md` é atualizado (§8.2) para remover entradas de docs deletados. |
| **Race condition entre git rm e git push** | N/A | N/A | Cada `git rm` é seguido de commit atômico. Push é último passo. |
| **Recursos remotos não destruídos corretamente (orçamento)** | Baixa | Baixa | §10 (Ações Destrutivas Remotas) detalha comandos idempotentes com `--dry-run` antes de `--execute`. CT-J-12 a CT-J-19 validam o estado pós-remoção. Usuário pode rodar `gcloud billing accounts list` para confirmar queda no billing. |
| **R11**: Apagar SA errada (quebra Sheets) — a v1.x da spec assumiu que a chave no JSON pertencia à `wallet-track-run@…`. **Investigação factual (30/06/2026)** mostra que pertence à `google-sheet-202@…`. Se o backup for feito para a SA errada ou a chave for destruída sem backup, Sheets para de funcionar. | Média | **CRÍTICO** (Sheets quebra) | (a) Passo §10.1.3 (SA identification) **obrigatório** antes de qualquer destruição. (b) Backup em `~/backup/wallet-track/sa-json-2026-06-30.json` ANTES de §10.3.5. (c) Validação `cat .../sa-json-2026-06-30.json | jq -r '.client_email'` deve retornar `google-sheet-202@…`. (d) CT-J-12 a CT-J-15 (em §12.4) verificam que SAs MANTER permanecem. (e) Se a SA for deletada acidentalmente, **NÃO é trivial recuperar** — a chave precisa ser restaurada do backup (que deve estar em `~/backup/wallet-track/sa-json-2026-06-30.json`); se o backup não existir, **a única opção é gerar uma nova chave e re-compartilhar a planilha com a SA** (esta operação requer acesso ao console GCP + e-mail da SA ainda válido). |
| **R12**: Billing desvinculado por engano durante a limpeza — se algum comando tocar o billing, o Sheets API pode parar de funcionar. | Baixa | **CRÍTICO** (Sheets para de funcionar) | (a) **Billing NUNCA é tocado** em §10.3. (b) CT-J-15 (em §12.4) valida `gcloud beta billing projects describe wallet-track-499719 --format="value(billingEnabled)"` deve retornar `True` pós-remoção. (c) Mesmo se a billing fosse desvinculada, o Sheets API free tier é generoso; o pior caso é rate-limit adicional. |
| **R13**: Ordem errada de destruição deixa recursos em estado inconsistente (ex.: deletar Artifact Registry API antes do repo ser deletado) — pode causar erros de `gcloud` ou estado "pending" indefinido. | Baixa | Média | (a) §10.2.1 define **ordem ótima** (10.3.1 backup → 10.3.2 SA id → 10.3.3 Cloud Run → 10.3.4 Scheduler → 10.3.5 Secrets → 10.3.6 AR → 10.3.7 Cloud Build → 10.3.8 Firestore → 10.3.9 APIs → 10.3.10 IAM → 10.3.11 verify). (b) §10.2.2 define **idempotência** — cada bloco checa existência antes de deletar. (c) §10.2.3 documenta **soft vs hard delete** (Secret Manager tem janela de recovery; Cloud Run e AR não). |
| **R14**: Service agents órfãos acumulam bindings inúteis no IAM — após desabilitar APIs, os service agents auto-managed ficam com bindings inúteis (ex.: `roles/run.serviceAgent` para `serverless-robot-prod` mesmo sem `run.googleapis.com`). | Média | Baixa | (a) §10.3.10 (IAM cleanup) lista os 8 service agents órfãos conhecidos e remove as bindings. (b) Verificação pós-bloco imprime quantas bindings órfãs restaram. (c) Os service agents em si (SAs) **NÃO são deletáveis** (auto-managed); apenas as bindings podem ser removidas. (d) Esta limpeza é **cosmética** — o billing não é afetado, e o IAM policy é um JSON que cresce monotonamente; o impacto prático é zero. |

### 11.1 Sanidade específica para Sheets (crítico)

Antes de marcar a task como concluída, **DEVE** passar:

```bash
# 1. Classe Sheets resolve via autoload
php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\\Services\\Google\\SheetsService'));"
# bool(true)

# 2. ServiceProvider boota
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->boot(); echo 'OK';"
# OK

# 3. Testes Sheets verdes
php artisan test --filter Sheets
# 0 falhas

# 4. dry-run do sync não toca Sheets
php artisan transactions:sync-pending --dry-run
# "DRY-RUN: 0 transação(ões) seria(m) processada(s)." (ou N>0, dependendo do estado)
# Exit 0

# 5. Smoke real (se houver transação pendente controlada)
php artisan transactions:sync-pending --format=text
# "Sincronizando N transação(ões) pendente(s)..."
# "Transação N sincronizada (row=...)"
# "Concluído: N processadas, N sincronizadas, 0 falhou."
# Exit 0
# Planilha real: linha correspondente aparece
```

---

## 12. Critérios de aceitação da Fase 2

### 12.1 Smoke tests (validação manual)

| # | Teste | Resultado esperado |
|---|---|---|
| 1 | `git status` (após todas as mudanças) | Lista exatamente os arquivos em §9.2 modificados/deletados, com o `git rm` refletido. |
| 2 | `php -l <cada arquivo PHP modificado>` | Sem erros de sintaxe. |
| 3 | `php artisan config:clear && php artisan cache:clear && php artisan route:clear` | Exit 0. |
| 4 | `php artisan list` | Mostra `transactions:sync-pending`, `sheets:remove-origin-column`, `telegram:set-webhook`, `telegram:delete-webhook`. **NÃO** mostra `cloudbuild:*`. |
| 5 | `grep -rIn 'cloudbuild\|Cloud Build\|Cloud Run\|gcloud\|deploy\.sh' --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git app/ config/ docker/ docker-compose*.yml routes/ bin/ scripts/ 2>/dev/null` | **0 matches**. |
| 6 | `grep -rIn 'GOOGLE_CLOUD_PROJECT_ID\|FIRESTORE_DATABASE_ID' .env .env.dev .env.example` | **0 matches**. |
| 7 | `grep -rIn 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH' docker-compose*.yml` | **0 matches**. |
| 8 | `docker compose config | grep -i 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH'` | **0 matches** (a env var é lida apenas do `.env`, não injetada pelo compose). |
| 9 | `php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\SheetsService') ? 'OK' : 'FALHA';"` | `OK`. |
| 10 | `php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\SheetsGateway') ? 'OK' : 'FALHA';"` | `OK`. |
| 11 | `php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\GoogleSheetsGateway') ? 'OK' : 'FALHA';"` | `OK`. |
| 12 | `php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\InMemorySheetsGateway') ? 'OK' : 'FALHA';"` | `OK`. |
| 13 | `php -r "require 'vendor/autoload.php'; echo interface_exists('App\\Actions\\SyncsSheet') ? 'OK' : 'FALHA';"` | `OK`. |
| 14 | `php artisan transactions:sync-pending --dry-run --format=json` | JSON com `"status":"ok","mode":"dry-run"`. |
| 15 | `php artisan sheets:remove-origin-column --dry-run` | "DRY-RUN: a coluna G (índice 6, "Origem") seria deletada da aba principal." (exit 0). **AMB #1:** após o fix cirúrgico em §2.2.10.1, este command funciona em **qualquer ambiente** (sem credenciais Sheets) — o teste é robusto contra falta de credencial. |
| 16 | `docker build -t wallet-track:dev --target dev .` (**AMB #2** — estágio `dev`, linha 168 do `Dockerfile`; tempo esperado: < 5 min com cache, < 10 min cold cache) | Exit 0; imagem `wallet-track:dev:latest` criada. |

### 12.2 Testes automatizados a rodar

| # | Suite | Esperado |
|---|---|---|
| 1 | `php artisan test` (suite completa) | 0 falhas. Contagem de testes deve ser igual ao baseline (ou próxima, devido a testes que podem ter sido impactados pelo ajuste em HealthControllerTest). |
| 2 | `php artisan test --filter Sheets` | 0 falhas (cobre `SyncSheetTest`, `SheetsServiceTest`, `SyncPendingTransactionsCommandTest`, `RemoveOriginColumnTest`). |
| 3 | `php artisan test --filter Health` | 0 falhas (cobre `HealthTest` e `HealthControllerTest` ajustado). |
| 4 | `php artisan test --filter Commands/Sync` | 0 falhas (cobre `SyncHandlerTest`). |
| 5 | `php artisan test --filter Items` | 0 falhas (cobre `E2eItemsFlowTest`). |
| 6 | `php artisan test --filter Store` | 0 falhas (cobre `WalletStoreTest`). |

### 12.3 Verificação de invariantes (Sheets continua funcionando do ponto de vista do código)

| Invariante | Como verificar |
### 12.4 AMB #3 — Métrica objetiva de "custo GCP ≈ 0"

| # | Verificação | Comando | Critério de aprovação |
|---|---|---|---|
| 1 | Apenas APIs essenciais habilitadas | `gcloud services list --enabled --project=wallet-track-499719` | A lista contém APENAS: `sheets.googleapis.com`, `iam.googleapis.com`, `cloudresourcemanager.googleapis.com`, opcionalmente `generativelanguage.googleapis.com`. Qualquer outra API = FALHA. |
| 2 | `gcloud run services list` vazio | `gcloud run services list --project=wallet-track-499719` | **0 services**. |
| 3 | `gcloud artifacts repositories list` vazio | `gcloud artifacts repositories list --project=wallet-track-499719` | **0 repositories**. |
| 4 | `gcloud secrets list` vazio | `gcloud secrets list --project=wallet-track-499719` | **0 secrets** (ou apenas secrets não relacionados a wallet-track). |
| 5 | `gcloud scheduler jobs list` vazio | `gcloud scheduler jobs list --project=wallet-track-499719` | **0 jobs**. |
| 6 | `gcloud builds triggers list` vazio | `gcloud builds triggers list --region=southamerica-east1 --project=wallet-track-499719` | **0 triggers**. |
| 7 | `gcloud firestore databases list` vazio | `gcloud firestore databases list --project=wallet-track-499719` | **0 databases** (Native) OU apenas `(default)` em Datastore mode (herdado, sem impacto). |
| 8 | SAs MANTER intactas | `gcloud iam service-accounts list --project=wallet-track-499719` | Lista contém `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (a que escreve no Sheets, vide §10.1.3) E `wallet-track-run@wallet-track-499719.iam.gserviceaccount.com` (orphaned, decision pending — vide §10.7). |
| 9 | Custo monetário esperado | `gcloud billing accounts list` (apenas leitura) | **$0/mês esperado** (Sheets API free tier + IAM/SA gratuito + nenhum recurso cobrado). Snapshot após 30 dias é opcional e apenas confirmatório. |

> **Não-obrigatoriedade do item 9:** o snapshot de billing após 30 dias é **fora do escopo desta task** (Portão 1: tarefa destrutiva, sem janela de 30 dias). O critério de aprovação objetivo é a **combinação dos itens 1-8**: se todos passam, o custo será $0/mês. Item 9 é apenas confirmatório.

> **Itens 10-17 (CT-J-12 a CT-J-19):** métricas **objetivas e automatizáveis** introduzidas na v2.0. Cada item tem comando shell exato e critério de aprovação determinístico (sem ambiguidade). Estas métricas cobrem o estado pós-remoção remota e devem ser implementadas como o modo `--verify-only` do script `scripts/destroy-gcp-resources.sh` (vide §10.4).

| # | Verificação | Comando | Critério de aprovação |
|---|---|---|---|
| 10 (**CT-J-12**) | Apenas 4 APIs habilitadas (sheets, iam, cloudresourcemanager, generativelanguage) | `gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" \| sort` | Output é **EXATAMENTE**: `cloudresourcemanager.googleapis.com\ngenerativelanguage.googleapis.com\niam.googleapis.com\nsheets.googleapis.com` (4 linhas, ordem alfabética). **Qualquer outra linha = FALHA.** |
| 11 (**CT-J-13**) | SAs MANTER preservadas | `gcloud iam service-accounts list --project=wallet-track-499719 --format="value(email)" \| grep -E "google-sheet-202\|wallet-track-run\|ais-gemini-key\|compute"` | Output contém `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (CRÍTICO — Sheets quebra sem ela). Pode ou não conter `wallet-track-run@…` (decisão §10.7). Service agents auto-managed somem com o tempo; isso é OK. |
| 12 (**CT-J-14**) | `gcloud compute regions list` (sanity check read-only do projeto) | `gcloud compute regions list --project=wallet-track-499719 --format="value(name)" \| head -5` | Exit 0; lista regiões GCP (sanity check de que o projeto está saudável). Não destrutivo. |
| 13 (**CT-J-15**) | Billing ainda vinculado | `gcloud beta billing projects describe wallet-track-499719 --format="value(billingEnabled)"` | Output é **EXATAMENTE**: `True`. **Qualquer outro valor (False, vazio, erro) = FALHA.** Sheets API depende de billing. |
| 14 (**CT-J-16**) | Modo `--dry-run` do script é executável e mostra o que seria destruído | `scripts/destroy-gcp-resources.sh --dry-run` | Exit 0; output lista os 8 blocos (§10.3.3 a §10.3.10) com `[BLOCK 1/8] §10.3.3 Cloud Run service` etc. **NÃO** executa nada destrutivo. |
| 15 (**CT-J-17**) | Sanity pré-execução (recurso existe? billing OK?) é checado por cada bloco destrutivo | `scripts/destroy-gcp-resources.sh --dry-run` | Output inclui linha `Pre-check: <comando>` para cada bloco (ex.: `Pre-check: gcloud run services describe wallet-track --region=...`). Confirma que cada bloco tem sanity pré-execução. |
| 16 (**CT-J-18**) | Sanity pós-execução (recurso realmente destruído?) é checado por cada bloco | `scripts/destroy-gcp-resources.sh --dry-run` | Output inclui linha `Post-check: <comando>` para cada bloco. Confirma que cada bloco tem sanity pós-execução. |
| 17 (**CT-J-19**) | Idempotência: rodar o script 2x em modo `--execute` não causa erro | `scripts/destroy-gcp-resources.sh --execute` (rodar 2x, com ≥ 1 min entre as execuções) | Ambos runs retornam exit 0; recursos que foram destruídos no 1º run são pulados com `[SKIP]` no 2º run. Estado final é o mesmo. |

> **Roteiro de uso dos CTs 10-17:** o `coder` implementa o script `scripts/destroy-gcp-resources.sh` (§10.4) que, em modo `--verify-only`, executa CT-J-12 a CT-J-19 sequencialmente e imprime `PASS` / `FAIL` por CT. O `manual-tester` usa este output como evidência objetiva para a categoria J do plano de testes. Se algum CT retornar `FAIL`, o Bloco D é considerado **falho** e o estado pós-remoção precisa ser investigado/recuperado (ver §10.2.4 para reversibilidade por bloco).


---|---|
| `SheetsService::HEADERS` inalterado | `grep -n 'HEADERS' app/Services/Google/SheetsService.php` deve mostrar o array de 9 colunas (Data, Descrição, Valor, Tipo, Categoria, Labels, ID Transação, Observações, Itens). |
| `GoogleCredentials::resolveKeyFile()` resolve via path ou inline | `tests/Unit/Services/Google/GoogleCredentialsTest.php` cobre ambos os caminhos. |
| `InMemorySheetsGateway` implementa `SheetsGateway` | `grep -n 'implements SheetsGateway' app/Services/Google/InMemorySheetsGateway.php`. |
| `SheetsServiceProvider` registrado | `grep -n 'SheetsServiceProvider' bootstrap/providers.php` deve mostrar a linha. |
| `transactions:sync-pending` é Artisan command | `php artisan list | grep sync-pending`. |
| Tabela `transactions` tem colunas `sync_*` e `spreadsheet_row_id` | `php artisan db:show` (ou `DESCRIBE transactions` via SQL). |
| Suites PHPUnit que usam `InMemorySheetsGateway` continuam verdes | `php artisan test --filter Sheets` (cobre 4+ arquivos de teste). |

---

## 13. Out-of-scope explícito

**O que NÃO está nesta task** (reforço do Portão 1):

1. ❌ **Migrar deploy** — não provisionar VPS, não configurar nginx, não configurar systemd timer, não configurar DNS. Esta task deixa a app pronta para deploy em VPS (imagem Docker constrói, scripts auxiliares existem) mas não deploya.
2. ❌ **Rotacionar chave SA** — a chave existente da `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (a que de fato escreve no Sheets, vide §10.1.3) é preservada. O usuário pode (em task futura) rotacionar se aparecer a chave em histórico Git. A chave está no backup `~/backup/wallet-track/sa-json-2026-06-30.json` (vide §9.1 #0).
3. ❌ **Deletar projeto GCP** — `wallet-track-499719` permanece vivo (apenas como IAM provider). Billing continua ativo (custo mínimo — apenas Sheets API e IAM).
4. ❌ **Criar VPS / host novo** — decisão de VPS existe (e há docs comparativos em `docs/comparativo-vps.md` antes da deleção), mas o provisionamento concreto é outra task.
5. ❌ **Migrar Firestore para outro lugar** — Firestore já foi migrado para MariaDB em M7 (vide `WalletStore` em `app/Services/Store/`). Não há Firestore de runtime para migrar. O Firestore de staging (se houver) está fora do escopo.
6. ❌ **Criar migrations de limpeza de colunas `sync_*`** — schema da tabela `transactions` permanece com colunas `sync_*` e `spreadsheet_row_id`. Decisão de Portão 1.
7. ❌ **Implementar Secret Manager / Vault** — a chave SA pode ser injetada via `GOOGLE_SERVICE_ACCOUNT_JSON` (env var com base64) sem dependência de Secret Manager externo. Esta task não introduz nenhuma abstração de secret manager.
8. ❌ **Deletar chave SA do IAM do GCP** — Portão 1: manter a SA `google-sheet-202@…` (a que escreve no Sheets) + chave. A destruição da chave só faz sentido se (a) o histórico Git confirmar vazamento, ou (b) a VPS deploy usar chave nova. **Esta v2.0 mantém a SA + chave** (R11 em §11 cobre o risco de SA errada).
9. ❌ **Refatorar `config/google.php`** além do mínimo — apenas removemos o bloco `cloud`. Reorganizações mais profundas (ex.: `config/google-sheets.php`) são futuras.
10. ❌ **Criar testes novos** — escopo é remoção. Testes existentes cobrem Sheets; nenhum novo teste é necessário.
11. ❌ **Atualizar `docs/M9-COMPLETO.md` e `docs/testes/m9-plano-testes.md` em profundidade** — apenas a nota de migração e o header são atualizados. O conteúdo técnico do M9 permanece (registro histórico do milestone).
12. ❌ **Criar/atualizar docs de deploy para VPS** — fora do escopo. Quando a task de deploy acontecer, novos docs serão criados.
13. ❌ **Reativar `strip-google-services.php`** no `Dockerfile` — fora do escopo. Se o `Dockerfile` ganhar invocação no futuro (task de deploy), reintroduzir com base em avaliação de impacto.
14. ❌ **Mover `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` para outro mecanismo** — o caminho de dev continua sendo o path local. A env var continua existindo (apenas não é mais injetada pelo docker-compose; agora vem apenas do `.env`).
15. ❌ **Deletar a `gcloud-credentials.json` (que NÃO EXISTE)** — ela é apenas uma entrada no `.gitignore` (linha 28), preventiva.
16. ❌ **Implementar feature de "auto-rotacionar chave SA periodicamente"** — fora do escopo.
17. ❌ **Migrar `SheetsService::HEADERS` para uma constante separada** — fora do escopo.
18. ❌ **Escrever um ADR (Architecture Decision Record) para a remoção** — fora do escopo desta task. A spec em si documenta as decisões; um ADR formal pode ser criado se a equipe decidir manter histórico de decisões arquiteturais.

---

## 14. Anexo: arquivos modificados (visão consolidada)

| Categoria | Arquivo | Operação |
|---|---|---|
| **Delete** | `cloudbuild.yaml` | `git rm` |
| **Delete** | `scripts/deploy.sh` | `git rm` |
| **Delete** | `scripts/strip-google-services.php` | `git rm` |
| **Delete** | `wallet-track-499719-54b725c0bb5d.json` | `git rm` |
| **Delete** | `.env.bak` | `git rm` (após backup em `~/backup/`) |
| **Delete** | `docs/runbook.md` | `git rm` |
| **Delete** | `docs/viability-report.md` | `git rm` |
| **Delete** | `docs/comparativo-preco-infra.md` | `git rm` |
| **Delete** | `docs/comparativo-vps.md` | `git rm` |
| **Delete** | `docs/infra-pessoal.md` | `rm` local (não commitado) |
| **Edit** | `config/google.php` | Remover bloco `cloud` |
| **Edit** | `config/telegram.php` | Atualizar comentário webhook_url |
| **Edit** | `config/gemini.php` | Atualizar comentário timeout |
| **Edit** | `config/octane.php` | Atualizar comentário max_execution_time |
| **Edit** | `app/Http/Controllers/HealthController.php` | Remover GOOGLE_CLOUD_PROJECT_ID do array e do método |
| **Edit** | `app/Http/Controllers/TelegramWebhookController.php` | Atualizar comentário |
| **Edit** | `app/Console/Commands/SetTelegramWebhook.php` | Atualizar docblock e mensagem |
| **Edit** | `app/Console/Commands/SyncPendingTransactions.php` | Atualizar 3 comentários |
| **Edit** | `app/Console/Commands/RemoveOriginColumn.php` | **AMB #1 — Cirúrgico:** mover `app(SheetsGateway::class)` para depois do dry-run check, para que `--dry-run` funcione sem credenciais. |
| **Edit** | `app/Bot/Handlers/SyncHandler.php` | Atualizar docblock |
| **Edit** | `app/Services/Google/GoogleCredentials.php` | Atualizar docblock |
| **Edit** | `routes/console.php` | Atualizar 2 comentários |
| **Edit** | `Dockerfile` | Atualizar 7 comentários |
| **Edit** | `docker/entrypoint.sh` | Atualizar header |
| **Edit** | `docker-compose.yml` | Remover GOOGLE_SERVICE_ACCOUNT_JSON_PATH; atualizar 3 comentários |
| **Edit** | `docker-compose.dev.yml` | Remover GOOGLE_SERVICE_ACCOUNT_JSON_PATH; atualizar comentário |
| **Edit** | `phpunit.xml` | Remover env var GOOGLE_CLOUD_PROJECT_ID |
| **Edit** | `bin/check-viability.sh` | Remover `grpc` e `protobuf` de REQUIRED_EXTS |
| **Edit** | `bin/dev` | Atualizar comentários sobre extensões |
| **Edit** | `tests/Feature/HealthTest.php` | Atualizar docblock |
| **Edit** | `tests/Feature/Http/HealthControllerTest.php` | Mudar `total === 6` → `total === 5` |
| **Edit** | `.env` | Remover 3 env vars GCP-infra; ajustar header |
| **Edit** | `.env.dev` | Remover 2 env vars GCP-infra; ajustar header |
| **Edit** | `.env.example` | Remover GOOGLE_CLOUD_PROJECT_ID; ajustar header |
| **Edit** | `README.md` | 10+ mudanças (badges, stack, env vars, arquitetura) |
| **Edit** | `CHANGELOG.md` | Adicionar entrada em `[Unreleased]` |
| **Edit** | `docs/00-INDEX.md` | Remover docs deletados; ajustar referências GCP |
| **Edit** | `docs/01-analise-negocio.md` | Reescrever premissas GCP |
| **Edit** | `docs/02-especificacao-tecnica.md` | Reescrever §2/§3/§11/§12/§13/§14 |
| **Edit** | `docs/05-revisao-v2.md` | Atualizar referências Firestore |
| **Edit** | `docs/06-plano-implementacao.md` | Reescrever §2.2/§8/§14 |
| **Edit** | `docs/M9-COMPLETO.md` | Nota de remoção GCP |
| **Edit** | `docs/planos/m9-plano-tecnico.md` | Header |
| **Edit** | `docs/specs/m9-spec-fase-2.md` | Nota de remoção GCP |
| **Edit** | `docs/testes/m9-plano-testes.md` | Ambiente de teste |
| **Edit** | `docs/testes/items-checklist-staging.md` | Ambiente de staging |

**Total:** 10 deleções + 42 edições (1 cirúrgica adicional em `RemoveOriginColumn.php` por AMB #1 — opcional, vide §2.2.10.1).

#### Ações Destrutivas Remotas (scriptable) — NEW v2.0

A partir da v2.0, a destruição dos recursos remotos no GCP é feita por **1 script + 9 grupos de comandos `gcloud`**, especificados em §10. Esta tabela sumariza o entregável para o agente executor (`coder`).

| # | ID | Descrição | Comandos | Ordem | Reversibilidade |
|---|---|---|---|---|---|
| 1 | `scripts/destroy-gcp-resources.sh` | Script master com 3 modos: `--dry-run`, `--execute`, `--verify-only`. Idempotente. Log via `tee`. | (shell script com ~250 linhas) | 1º (pré-destruição) | N/A (script em si) |
| 2 | §10.3.1 | Backup-first (criar `tmp/gcp-destroy-2026-06-30/` com 10 snapshots) | 11 comandos `gcloud` (list/describe) | 1º | Reversível (apaga-se `tmp/`) |
| 3 | §10.3.2 | SA identification (ler `client_email` do JSON) | 4 comandos shell | 2º | N/A (read-only) |
| 4 | §10.3.3 | Cloud Run service + IAM `allUsers` | 3 comandos (`remove-iam-policy-binding` + `delete` + `list`) | 3º | **Não** (irreversível) |
| 5 | §10.3.4 | Cloud Scheduler job | 3 comandos | 4º | **Sim** (recreate) |
| 6 | §10.3.5 | Secret Manager (2 secrets × 2 ações: destroy versions + delete) | 6 comandos | 5º | **Não** (versão: 10-30d soft; secret: hard) |
| 7 | §10.3.6 | Artifact Registry (delete images + repo) | ~3 comandos (loop sobre versões) | 6º | **Não** (irreversível) |
| 8 | §10.3.7 | Cloud Build connection | 3 comandos | 7º | **Parcial** (re-autorizar GitHub App) |
| 9 | §10.3.8 | Firestore databases (2 NATIVE + 1 Datastore) | 2 comandos (Datastore é só log) | 8º | **Parcial** (~7d soft) |
| 10 | §10.3.9 | APIs (33 `gcloud services disable`) | ~33 comandos (loop) | 9º | **Sim** (re-enable) |
| 11 | §10.3.10 | IAM cleanup (8 service agents órfãos) | ~16 comandos (loop sobre roles) | 10º | **Sim** (re-add via backup) |
| 12 | §10.3.11 | Verificação final (CT-J-12 a CT-J-19) | 8 comandos read-only | 11º | N/A (read-only) |

**Total:** 1 script + 11 grupos de comandos = ~90 invocações `gcloud`. Tempo estimado: 5-10 min em modo `--execute` (depende da latência do `gcloud`).

**Critério de aceitação do entregável (categoria J do plano de testes):** o script, em modo `--verify-only`, deve passar **TODOS** os CTs J-12 a J-19.

---

## 15. Próximos passos (para o `tech-planner`)

Esta spec deve ser passada para o agente `tech-planner` que irá:

1. **Decompôr em milestones** baseados na ordem ótima de execução (§9.2).
2. **Sequenciar** com base nas dependências (ex.: `config/google.php` antes de `HealthController` antes de `HealthControllerTest`).
3. **Estimar** esforço (a maioria é trivial: deleções, edições de comentário).
4. **Identificar** gates intermediários (ex.: rodar `php artisan test` após cada milestone para garantir que o baseline não regride).
5. **Marcar** o checklist manual §9.4 como task separada do `coder` (é tarefa do usuário, não automatizável).

Após o `tech-planner`, o agente `coder` implementa milestone a milestone, validando com §9.3 e §12.

Por fim, o agente `manual-tester` usa esta spec como insumo para gerar o plano de testes manuais (validar que `/sync` ainda funciona em ambiente real, que o `transactions:sync-pending` consegue ler/escrever na planilha real, que o `Sheets` client boota sem erros em condições normais).

---

**Fim da Especificação Técnica — Fase 2: Remoção do GCP (mantendo Google Sheets).**

---

## 16. Clarificações F2 (resolução de 6 AMB)

> **Status atual (30/06/2026):** todas as 5 AMBs da v1.0 estão **resolvidas e aplicadas** (ver tabela abaixo). A v2.0 desta spec introduz a **AMB #6** (correção de SA: a chave no JSON é de `google-sheet-202@…`, não `wallet-track-run@…`) que foi **detectada durante o inventário remoto** e está **resolvida** com a investigação de §10.1.3. As decisões de Portão 1 **não foram renegociadas** — apenas a identidade da SA foi corrigida (a "SA + chave" do Portão 1 é `google-sheet-202`, não `wallet-track-run`).

Esta seção documenta as 6 ambiguidades sinalizadas após a entrega da spec F2 v1.0, e como foram resolvidas. Cada resolução tem impacto **cirúrgico e rastreável** na spec e/ou no código.

| AMB | Origem (CT) | Decisão | Aplicado em |
|---|---|---|---|
| **#1** | CT-C-08 | **Fazer `sheets:remove-origin-column --dry-run` ser credential-free** via reordenação de `app(SheetsGateway::class)` no `handle()`. Opção (a). | §2.2.10.1 (nova); §9.2 passo 19b; §9.3 #15; §12.1 #15; §14 (linha do anexo); código `app/Console/Commands/RemoveOriginColumn.php`. **Status: APLICADO em v1.1** (cirurgia opcional não-bloqueadora). |
| **#2** | CT-F-06 | **Confirmar estágio `dev` (linha 168 do Dockerfile)** e definir **tempo esperado** (< 5 min com cache; < 10 min cold). Comando exato: `docker build -t wallet-track:dev --target dev .`. | §9.3 #15 (nova linha na tabela); §12.1 #16. |
| **#3** | CT-J-11 | **Métrica objetiva:** `gcloud services list --enabled` deve mostrar apenas `sheets`, `iam`, `cloudresourcemanager`, opcionalmente `generativelanguage`. Todas as listas de recursos (Run, AR, SM, CS, CB, Firestore) devem estar **vazias**. Custo esperado: $0/mês. | §9.4 cabeçalho (nova nota AMB #3); §12.4 (nova sub-seção com 9 itens de aceitação). |
| **#4** | CT-K-02 | **PASS** se `git log` vazio. **WARN** (NÃO bloqueador) se ≥ 1 commit com a chave — abre issue `security/SA-key-rotation` separada. **FAIL** só em caso de bug no fluxo de deleção. | §11 (risco "Histórico Git com SA JSON" — classificação expandida). |
| **#5** | CT-G-02 | **Política (b):** manter menções em seções marcadas como históricas/DEPRECATED. Marcador canônico de nota de topo aplicado seletivamente. Tabela de docs com matches aceitáveis em §8.5. | §8.1 (política); §8.5 (nova sub-seção). |
| **#6 (NEW v2.0)** | Investigação factual em 30/06/2026 | **Correção de SA:** a v1.x declarava "a SA `wallet-track-run@…` continua existindo com a mesma chave". **Achado:** o `client_email` no JSON `wallet-track-499719-54b725c0bb5d.json` é `google-sheet-202@…` (display name "google-sheet"). **A SA que escreve no Sheets é `google-sheet-202`, não `wallet-track-run`.** A SA `wallet-track-run@…` é a identidade do Cloud Run (orphaned pós-remoção). **Implicação:** o backup da chave (§9.1 #0) deve preservar a chave de `google-sheet-202`. O §10.1.3 documenta o passo de identificação. | §0.1 (descoberta); §1.2 (garantia); §1.3 (estado pós-execução); §10.1.3 (identificação); §10.7 (decisão sobre deletar `wallet-track-run@…`); §11 (R11); §12.4 (CT-J-13). |

### 16.1 Mudanças por arquivo (consolidado AMB)

| Arquivo | Tipo de mudança | Origem |
|---|---|---|
| `app/Console/Commands/RemoveOriginColumn.php` | **Edit** — reordenação de `app(SheetsGateway::class)` + comentário explicativo | AMB #1 |
| `docs/specs/spec-fase-2-remocao-gcp.md` | **Edit (v2.0)** — esta seção §16 + ajustes em §0.1, §1.2, §1.3, §2.2.10.1, §8.1, §8.5, §9.1, §9.2, §9.3, §9.4.1 (Bloco D), §9.4 (ponteiro), §10 (NOVO), §11 (com R11–R14), §12.4 (com CT-J-12 a CT-J-19), §13 (itens 2 e 8), §14 (com nova seção Anexo), §16 (com AMB #6) | AMB #1, #2, #3, #4, #5, **#6 (NEW)** |
| `docs/testes/plano-testes-manuais-remocao-gcp.md` | **Recomendação para o manual-tester** (nota na §16 do plano, não aplicada automaticamente) — ajustar CT-C-08, CT-F-06, CT-J-11, CT-K-02, CT-G-02 | AMB #1, #2, #3, #4, #5 |

### 16.2 Recomendações para o manual-tester (ajustes nos CTs)

> Estas recomendações não são aplicadas automaticamente no plano de testes — o `manual-tester` deve revisar e aplicar no momento da implementação, junto com o `coder`.

- **CT-C-08 (`sheets:remove-origin-column --dry-run`):** se a edição AMB #1 for aplicada, o teste vira trivial: deve sempre funcionar (exit 0) em qualquer ambiente. Atualizar o CT para remover a ressalva "em ambiente sem Sheets configurado". Adicionar nota: "**Após AMB #1:** este teste é sempre PASS — o `--dry-run` é credential-free por design".
- **CT-F-06 (`docker build`):** atualizar o comando para `docker build -t wallet-track:dev --target dev .` (já é o que está — não muda) e adicionar critério de aprovação com tempo: "< 5 min com cache, < 10 min cold cache". Manter como [MANUAL].
- **CT-J-11 (custo GCP ≈ 0):** substituir a nota ambígua por uma referência à tabela §12.4 (9 itens de aceitação objetivos). Sugestão: renomear para "CT-J-11: recursos remotos mínimos (Sheets + IAM only)" e listar os 9 comandos de validação.
- **CT-K-02 (git log da chave):** adicionar a classificação PASS/WARN/FAIL no critério de aprovação. Se WARN, **não falhar** o teste; apenas anotar no relatório.
- **CT-G-02 (grep gcloud):** adicionar nota "matches intencionais em seções históricas (vide spec §8.5) são PASS". O coder pode estender o comando de validação com a heurística sugerida em §8.5.

### 16.3 Não-obrigatoriedade

**Nenhuma das 5 primeiras clarificações é estritamente necessária** para os critérios de aceite da Fase 2 v1.0. Elas são **melhorias** que:

- AMB #1: melhora UX do `--dry-run` (mas o ambiente dev com `.env` configurado continua funcionando sem a mudança).
- AMB #2: define timing (mas o build sempre existiu — o teste não falhava, era ambíguo).
- AMB #3: define métrica (mas o teste "custo ≈ 0" já era validado implicitamente pelos CTs J-02..J-08).
- AMB #4: explicita WARN (mas o risco já era conhecido e o tratamento já estava no §11).
- AMB #5: explicita política (mas a §8.3 já indicava manter menções históricas).

**AMB #6 (NEW v2.0) é diferente:** é uma **correção factual obrigatória** — sem ela, o backup da chave SA (§9.1 #0) seria feito para a SA errada, e a destruição do JSON removeria a chave da SA que efetivamente escreve no Sheets, quebrando o produto. **AMB #6 deve ser aplicada obrigatoriamente.**

O `coder` deve aplicar **pelo menos** AMB #1, #3, #4, #5 e **#6** (são correções de spec + 1 cirurgia de código). AMB #2 é puramente documental.

### 16.4 Pronto para Portão 2

Após a aplicação das 6 clarificações, a spec F2 v2.0 está:

- **Não-ambígua** para os 6 pontos sinalizados (5 do `manual-tester` + 1 factual nova em v2.0).
- **Pronta para apresentação ao usuário no Portão 2** (revisão final antes da fase de execução do `coder`).
- **Mantém a compatibilidade** com a spec v1.0/v1.1: as decisões de Portão 1 não foram renegociadas (a "SA + chave" do Portão 1 é `google-sheet-202`, não `wallet-track-run` — a AMB #6 corrige a identidade, não a decisão).
- **Estende o escopo do entregável** do `coder` para incluir a destruição remota automatizada (script `scripts/destroy-gcp-resources.sh` + 11 grupos de comandos `gcloud`), especificada integralmente em §10.
- **Total de mudanças:** 10 deleções + 42 edições no repo (inalterado) + 1 script novo (`scripts/destroy-gcp-resources.sh`, ~250 linhas) + 1 conjunto de 11 grupos de comandos GCP (Bloco D).

> **Checklist do Portão 2:**
> 1. Apresentar AMB #6 ao usuário (correção de SA: `google-sheet-202` em vez de `wallet-track-run`).
> 2. Apresentar as 3 decisões em aberto de §10.7 (deletar `wallet-track-run@…`? limpar roles órfãs? limpar `(default)` Datastore?).
> 3. Confirmar que o script `scripts/destroy-gcp-resources.sh` será implementado pelo `coder` (não é decisão do usuário).
> 4. Confirmar que o `manual-tester` atualizará o plano de testes com CT-J-12 a CT-J-19 (lista em §12.4).
