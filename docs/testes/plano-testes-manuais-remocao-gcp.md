# Plano de Testes Manuais — Remoção do GCP (Fase 2, mantendo Google Sheets)

> **Projeto:** Wallet Track
> **Versão:** 2.0.1 — 30/06/2026
> **Base normativa:** `docs/specs/spec-fase-2-remocao-gcp.md` v2.0 (Portão 1 aprovado + Portão 2 aprovado em 30/06/2026 + AMB #6 — correção de SA)
> **Stack:** Laravel 13 + PHP 8.5 + FrankenPHP + Octane · MariaDB (dev) / SQLite (prod) · Redis · Telegram bot · Google Sheets (Service Account)
> **Descoberta crítica v2.0:** a SA que escreve no Google Sheets é `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (NÃO `wallet-track-run@…`). Veja §1.2 e AMB #6 da spec.

---

## 0. Visão geral

Este plano valida, por inspeção humana + comandos shell, que a Fase 2 (remoção do GCP-infra, mantendo Google Sheets) **cumpriu a invariante central da spec**:

> `transactions:sync-pending`, `/sync`, e o `InMemorySheetsGateway` continuam funcionando **exatamente como antes**, sem Sheets-API quebrada, sem rotação de chave SA, sem mudança de schema.

Os casos de teste (CTs) estão organizados em **12 categorias** que correspondem, grosso modo, aos critérios de aceitação da spec §12 e aos riscos do §11. Cada CT é marcado com:

- **[REMOÇÃO]** — confirma que algo GCP-infra foi efetivamente removido
- **[REGRESSÃO]** — smoke test pós-mudança para garantir que algo **continua funcionando**
- **[AUTOMATIZÁVEL]** — pode ser extraído como script shell (assertion determinística)
- **[MANUAL]** — exige inspeção humana (UI, planilha real, console GCP)
- **[RESOLVIDO]** — ambiguidade v1.0 resolvida em v2.0 (AMB #1–#6 aplicados)

**Ordem de execução recomendada:** A → B → C → D → H → F → G → E → I → L → K → J. As categorias A–G rodam **sem acessar o GCP remoto** (podem ser executadas no host local com a base de código modificada). A categoria J **requer acesso ao console GCP** e roda **após** a task `coder` finalizar a remoção local. A categoria L (validação do backup da SA) deve ser executada **antes** de qualquer destruição remota (J). As categorias E e I podem ser executadas em paralelo.

**Duração total estimada:** ~98 min para um humano (somando as 12 categorias). Ver §15 ao final.

---

## 1. Ambiente de teste

### 1.1 Pré-condições globais

- **Working tree:** branch `chore/remove-gcp-infra` (criada conforme spec §9.1.4) com **todas as mudanças da spec §9.2 já commitadas** (10 deleções + 42 edições).
- **Dependências:** `composer install` já rodado (§9.2.38) — `vendor/` regenerado, sem referências a arquivos removidos.
- **Caches limpos:** `php artisan config:clear && php artisan cache:clear && php artisan route:clear` já executado (§9.2.37).
- **Backup do `.env.bak`:** confirmado em `~/backup/wallet-track/env-bak-2026-06-30.env` (spec §9.1.1). **Não prosseguir** se o backup não existir.
- **Backup da chave SA (AMB #6 — CRÍTICO):** confirmado em `~/backup/wallet-track/sa-json-2026-06-30.json` (spec §9.1 #0). O `client_email` deste JSON **DEVE** ser `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (a SA que escreve no Sheets). **Não prosseguir** sem este backup validado — Categoria L cobre esta validação.
- **Baseline de testes:** capturado ANTES da task — arquivo `/tmp/wallet-track-test-baseline.log` deve existir com `Tests: X, Assertions: Y` (spec §9.1.5). Os CTs da categoria D vão comparar contra esse baseline.
- **Acesso GCP:** o usuário deve estar autenticado com `gcloud auth login` e ter permissão `roles/owner` no projeto `wallet-track-499719` (necessário para a categoria J).
- **Planilha real:** o usuário deve ter acesso à planilha pessoal (a mesma compartilhada com a SA `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com`).
- **Telegram:** o bot dev (token em `.env.dev`) deve estar com webhook ativo ou testável via `telegram:set-webhook` reverso.

### 1.2 URLs e dados de teste

| Item | Valor |
|---|---|
| URL local (Octane) | http://localhost:8000 |
| URL dev (docker compose dev) | http://localhost:8001 |
| Endpoint health | `GET /health` |
| Endpoint webhook (já existente) | `POST /webhook/telegram` |
| Chat ID de teste | `5672987197` (whitelist) |
| Bot dev (token em `.env.dev`) | `8837885736:AAHNFlUeFdhN8hEi5quOByci4-qzx_ZXaCA` (vide `.env.dev`) |
| Service Account (Sheets writer — MANTER) | `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (display: "google-sheet") |
| Service Account (Cloud Run identity — DELETAR após Bloco D) | `wallet-track-run@wallet-track-499719.iam.gserviceaccount.com` (display: "Wallet Track Cloud Run Service Account") — **DECIDIDO em Portão 2 (30/06/2026): deletar após Cloud Run destruído** (spec §10.7 #1) |
| Projeto GCP | `wallet-track-499719` |
| Região GCP | `southamerica-east1` (SP) |
| Planilha real (id de prod) | `1rGNN0XOOYwDvMYDpFwU1a2ozQXPhAk8P2l8Xjnk9a14` |
| Planilha real (id de dev) | `1aRr8S511jLcZtrAKyi6wbFc4PLMV_hGNO86sP7Pj4_c` |

### 1.3 Comandos de referência

```bash
# Onde estamos
pwd  # /home/diego-oliveira/p/blussal/wallet-track

# Estado git
git status
git log --oneline -10

# Confirmar branch
git rev-parse --abbrev-ref HEAD  # → chore/remove-gcp-infra

# Backup do .env.bak
ls -la ~/backup/wallet-track/env-bak-2026-06-30.env

# Backup da chave SA (AMB #6)
ls -la ~/backup/wallet-track/sa-json-2026-06-30.json
jq -r .client_email ~/backup/wallet-track/sa-json-2026-06-30.json
# → DEVE retornar: google-sheet-202@wallet-track-499719.iam.gserviceaccount.com

# Baseline de testes (deve existir)
test -f /tmp/wallet-track-test-baseline.log && echo "OK: baseline existe" || echo "FALHA: rode a Fase 1 primeiro"
```

---

## 2. Categoria A — Remoção de arquivos [REMOÇÃO]

**Objetivo:** confirmar que os 10 arquivos GCP-infra da spec §2.1 foram deletados e não aparecem em `git status` como untracked (ou aparecem como `D` — deleted, após `git rm`).

### CT-A-01: `cloudbuild.yaml` foi deletado **[AUTOMATIZÁVEL]**

**Funcionalidade:** Pipeline CI/CD do Cloud Build (M10).
**Prioridade:** alta

**Pré-condições:** nenhuma além das globais.

**Passos:**

```bash
test -f cloudbuild.yaml && echo "FALHA: cloudbuild.yaml ainda existe" || echo "OK: cloudbuild.yaml não existe"
git status --short cloudbuild.yaml  # deve mostrar "D  cloudbuild.yaml" (após git rm) OU vazio se não commitado
```

**Resultado esperado:**

- `OK: cloudbuild.yaml não existe`
- `git status` mostra `D  cloudbuild.yaml` (deleted) ou nada (se nunca commitado — improvável dado o conteúdo da spec).

**Critério de aprovação:** o arquivo não existe no filesystem E está marcado como `D` no `git status`.

---

### CT-A-02: `scripts/deploy.sh` foi deletado **[AUTOMATIZÁVEL]**

**Prioridade:** alta

**Passos:**

```bash
test -f scripts/deploy.sh && echo "FALHA" || echo "OK"
git status --short scripts/deploy.sh
```

**Resultado esperado:** `OK` + `D  scripts/deploy.sh`.

---

### CT-A-03: `scripts/strip-google-services.php` foi deletado **[AUTOMATIZÁVEL]**

**Prioridade:** alta

**Passos:**

```bash
test -f scripts/strip-google-services.php && echo "FALHA" || echo "OK"
git status --short scripts/strip-google-services.php
```

**Resultado esperado:** `OK` + `D  scripts/strip-google-services.php`.

---

### CT-A-04: `wallet-track-499719-54b725c0bb5d.json` foi deletado **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (security — chave SA).

**Passos:**

```bash
test -f wallet-track-499719-54b725c0bb5d.json && echo "FALHA: chave ainda existe" || echo "OK: chave deletada"
git status --short wallet-track-499719-54b725c0bb5d.json
ls -la ~/backup/wallet-track/sa-json-2026-06-30.json  # backup em local externo seguro (NÃO commitado)
```

**Resultado esperado:**

- `OK: chave deletada`
- `git status` mostra `D  wallet-track-499719-54b725c0bb5d.json`
- `ls ~/backup/wallet-track/sa-json-2026-06-30.json` mostra o backup externo conforme spec §9.1 #0 (vide Categoria L para validação completa do backup).

**Critério de aprovação:** chave não está no working tree E backup externo existe (não commitado em lugar algum).

> **Atenção:** se o arquivo **ainda aparece** como untracked em `git status`, é porque nunca foi `git rm`-ado. Nesse caso, executar `git rm wallet-track-499719-54b725c0bb5d.json` manualmente e re-commitar.

> **AMB #6:** a chave deletada pertence à SA `google-sheet-202@…`, NÃO à `wallet-track-run@…`. Veja Categoria L para validação do backup.

---

### CT-A-05: `.env.bak` foi deletado **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
test -f .env.bak && echo "FALHA" || echo "OK"
git status --short .env.bak
ls -la ~/backup/wallet-track/env-bak-2026-06-30.env  # backup externo (spec §9.1.1)
```

**Resultado esperado:**

- `OK` (filesystem)
- `D  .env.bak` (git status)
- `ls -la ~/backup/wallet-track/env-bak-2026-06-30.env` mostra o backup com timestamp 2026-06-30.

---

### CT-A-06: 5 docs GCP-100% foram deletados **[AUTOMATIZÁVEL]**

**Prioridade:** média.

**Passos:**

```bash
for f in docs/runbook.md docs/viability-report.md docs/comparativo-preco-infra.md docs/comparativo-vps.md docs/infra-pessoal.md; do
  if [ -f "$f" ]; then echo "FALHA: $f ainda existe"; else echo "OK: $f deletado"; fi
done
```

**Resultado esperado:** todos os 5 outputs `OK: <arquivo> deletado`.

> **Nota sobre `docs/infra-pessoal.md`:** conforme spec §2.1 nota, esse arquivo **NÃO é commitado** (está no `.gitignore` linha 71), então a deleção é apenas local via `rm`. O `git status` não deve mostrá-lo. Se mostrar como untracked, é porque o `.gitignore` foi contornado; nesse caso, executar `git rm --cached docs/infra-pessoal.md` para limpar do índice.

---

### CT-A-07: `git status` não reporta untracked para arquivos GCP-infra **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
git status --porcelain | grep -E 'cloudbuild|deploy\.sh|strip-google-services|wallet-track-499719.*\.json|\.env\.bak|runbook|viability-report|comparativo|infra-pessoal' || echo "OK: nenhum match"
```

**Resultado esperado:** `OK: nenhum match`. Os arquivos deletados aparecem como `D  ` (deleted), não como `??  ` (untracked).

> **Exceção documentada:** se `docs/infra-pessoal.md` aparecer como untracked (foi commitado em algum momento), a spec §10 já indica que é um item separado. Reportar e seguir.

---

### CT-A-08: scripts/ tem apenas arquivos não-GCP **[AUTOMATIZÁVEL]**

**Prioridade:** baixa.

**Passos:**

```bash
ls scripts/
# Deve mostrar apenas arquivos não-GCP (ou diretório vazio).
# Após a remoção, scripts/ deve conter apenas arquivos não-GCP (ex.: tunnel-up.sh, tunnel-up-dev.sh).
# Nenhum dos GCP-infra (deploy.sh, strip-google-services.php) deve estar presente.
```

**Resultado esperado:** nenhum arquivo GCP-infra (`deploy.sh`, `strip-google-services.php`) presente.

---

## 3. Categoria B — Remoção de env vars GCP-infra [REMOÇÃO]

**Objetivo:** confirmar que `GOOGLE_CLOUD_PROJECT_ID`, `FIRESTORE_DATABASE_ID` foram removidos dos 3 `.env*`; `GOOGLE_SERVICE_ACCOUNT_JSON` e `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` foram MANTIDOS (são Sheets, não GCP-infra).

### CT-B-01: `GOOGLE_CLOUD_PROJECT_ID` removido de `.env` **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -nE '^GOOGLE_CLOUD_PROJECT_ID=' .env && echo "FALHA: GOOGLE_CLOUD_PROJECT_ID ainda em .env" || echo "OK: GOOGLE_CLOUD_PROJECT_ID removido de .env"
```

**Resultado esperado:** `OK`. (Comentários `# GOOGLE_CLOUD_PROJECT_ID` são tolerados se documentarem a remoção; o teste acima usa `^GOOGLE_CLOUD_PROJECT_ID=` com `=` para pegar apenas linhas ativas.)

---

### CT-B-02: `GOOGLE_CLOUD_PROJECT_ID` removido de `.env.dev` e `.env.example` **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -nE '^GOOGLE_CLOUD_PROJECT_ID=' .env.dev && echo "FALHA em .env.dev" || echo "OK em .env.dev"
grep -nE '^GOOGLE_CLOUD_PROJECT_ID=' .env.example && echo "FALHA em .env.example" || echo "OK em .env.example"
```

**Resultado esperado:** ambos `OK`.

---

### CT-B-03: `FIRESTORE_DATABASE_ID` removido de `.env` e `.env.dev` **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -nE '^FIRESTORE_DATABASE_ID=' .env .env.dev .env.example 2>/dev/null
# Esperado: 0 matches
```

**Resultado esperado:** 0 matches (saída vazia).

---

### CT-B-04: `GOOGLE_SERVICE_ACCOUNT_JSON` e `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` MANTIDOS **[AUTOMATIZÁVEL]**

**Prioridade:** alta (regressão de Sheets).

**Passos:**

```bash
grep -nE '^GOOGLE_SERVICE_ACCOUNT_JSON_PATH=' .env .env.dev .env.example 2>/dev/null
# Esperado: 3 matches (um por arquivo .env*)
grep -nE '^GOOGLE_SERVICE_ACCOUNT_JSON=' .env .env.dev .env.example 2>/dev/null
# Esperado: 0 matches OU matches comentados (# GOOGLE_SERVICE_ACCOUNT_JSON=)
```

**Resultado esperado:**

- 3 matches para `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` (um em cada `.env*`).
- 0 matches ativos para `GOOGLE_SERVICE_ACCOUNT_JSON` (a env var pode estar comentada com `#` — o que é OK conforme spec §3.1; linhas comentadas não são "ativas" para `env()`).

---

### CT-B-05: Sanity check final — `php artisan config:clear` + boot não quebra **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan config:clear
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->boot();
echo 'BOOT OK' . PHP_EOL;
echo 'config(google) keys: ' . implode(',', array_keys(config('google'))) . PHP_EOL;
echo 'cloud presente? ' . (array_key_exists('cloud', config('google')) ? 'SIM (FALHA)' : 'NAO (OK)') . PHP_EOL;
echo 'sheets presente? ' . (array_key_exists('sheets', config('google')) ? 'SIM (OK)' : 'NAO (FALHA)') . PHP_EOL;
"
```

**Resultado esperado:**

```
BOOT OK
config(google) keys: sheets,service_account_json_path,service_account_json
cloud presente? NAO (OK)
sheets presente? SIM (OK)
```

> **Atenção:** se `cloud` ainda aparecer, a edição de `config/google.php` (spec §2.2.2) não foi aplicada. Reabrir a categoria e verificar.

---

### CT-B-06: Header da seção Google nos `.env*` foi reescrito **[AUTOMATIZÁVEL]**

**Prioridade:** baixa.

**Passos:**

```bash
grep -nE 'Google Cloud \(Firestore \+ Sheets\)' .env .env.dev .env.example 2>/dev/null
# Esperado: 0 matches
grep -nE 'Google Sheets \(Service Account\)' .env .env.dev 2>/dev/null
# Esperado: 1 match em cada (.env, .env.dev)
```

**Resultado esperado:**

- 0 matches para o header antigo (que mencionava Firestore).
- 1 match por arquivo `.env` e `.env.dev` para o novo header `Google Sheets (Service Account)`.
- `.env.example` também deve ter o header atualizado.

---

## 4. Categoria C — Sanidade de código de Sheets [REGRESSÃO]

**Objetivo:** confirmar que o código Sheets (spec §1.2 — garantia **CRÍTICA**) está intacto, resolúvel via autoload, e continua funcional sem dependências GCP-infra.

### CT-C-01: `SheetsService` resolve via autoload **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (não pode quebrar).

**Passos:**

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('App\\Services\\Google\\SheetsService') ? 'OK' : 'FALHA'; echo PHP_EOL;"
```

**Resultado esperado:** `OK`.

---

### CT-C-02: `SheetsGateway` (interface), `GoogleSheetsGateway` (impl) e `InMemorySheetsGateway` resolvem **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA**.

**Passos:**

```bash
php -r "
require 'vendor/autoload.php';
\$classes = [
    'App\\Services\\Google\\SheetsGateway' => 'interface',
    'App\\Services\\Google\\GoogleSheetsGateway' => 'class',
    'App\\Services\\Google\\InMemorySheetsGateway' => 'class',
];
foreach (\$classes as \$class => \$type) {
    \$exists = \$type === 'interface' ? interface_exists(\$class) : class_exists(\$class);
    echo (\$exists ? 'OK' : 'FALHA') . ' : ' . \$class . PHP_EOL;
}
"
```

**Resultado esperado:**

```
OK : App\Services\Google\SheetsGateway
OK : App\Services\Google\GoogleSheetsGateway
OK : App\Services\Google\InMemorySheetsGateway
```

---

### CT-C-03: `SyncSheet` (classe) e `SyncsSheet` (interface) resolvem **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA**.

**Passos:**

```bash
php -r "
require 'vendor/autoload.php';
echo (class_exists('App\\Actions\\SyncSheet') ? 'OK' : 'FALHA') . ' : App\\Actions\\SyncSheet' . PHP_EOL;
echo (interface_exists('App\\Actions\\SyncsSheet') ? 'OK' : 'FALHA') . ' : App\\Actions\\SyncsSheet' . PHP_EOL;
"
```

**Resultado esperado:** ambos `OK`.

---

### CT-C-04: `SheetsServiceProvider` registrado em `bootstrap/providers.php` **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -n 'SheetsServiceProvider' bootstrap/providers.php
```

**Resultado esperado:** 1 linha com `App\Providers\SheetsServiceProvider::class` (sem alteração, conforme spec §1.2).

---

### CT-C-05: `php artisan list` mostra os comandos Sheets **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan list | grep -E 'transactions:sync-pending|sheets:remove-origin-column'
```

**Resultado esperado:** 2 matches — `transactions:sync-pending` e `sheets:remove-origin-column` ambos listados.

---

### CT-C-06: `php artisan list` NÃO mostra comandos Cloud Build **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan list | grep -iE 'cloudbuild|gcloud-deploy' && echo "FALHA" || echo "OK: nenhum comando Cloud Build presente"
```

**Resultado esperado:** `OK`. (Confirmando que não há comandos Artisan ligados a Cloud Build que tenham ficado órfãos.)

---

### CT-C-07: `transactions:sync-pending --dry-run` roda sem credenciais GCP reais **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (smoke test de boot do command Sheets).

**Passos:**

```bash
php artisan transactions:sync-pending --dry-run --format=json
```

**Resultado esperado:** JSON com a forma abaixo. Especificamente, `"status":"ok"` e `"mode":"dry-run"`. Pode haver `"would_process":0` (banco vazio) ou >0 (transações pendentes). **NÃO** deve haver exceção de "GOOGLE_CLOUD_PROJECT_ID" ausente.

```json
{"status":"ok","mode":"dry-run","would_process":0,"ids":[]}
```

> **Diagnóstico de falha:** se o command quebrar com mensagem contendo "GOOGLE_CLOUD_PROJECT_ID" ou "google.cloud.project_id", a edição de `HealthController.php` ou de `config/google.php` não foi aplicada. Rever spec §2.2.2 e §2.2.6.

---

### CT-C-08: `sheets:remove-origin-column --dry-run` **[AUTOMATIZÁVEL]** **[RESOLVIDO: AMB #1]**

**Prioridade:** média.

**Passos:**

```bash
php artisan sheets:remove-origin-column --dry-run
```

**Resultado esperado (em qualquer ambiente — com ou sem Sheets configurado):**

```
DRY-RUN: a coluna G (índice 6, "Origem") seria deletada da aba principal.
```

Exit code `0`.

> **[RESOLVIDO — AMB #1]:** a v1.0 deste plano (e a v1.0 da spec) marcava este CT como `[AMBIGUOUS: HOW]` por causa da spec §9.3 #11 não explicitar o comportamento esperado sem credencial Sheets. **A v2.0 da spec (§2.2.10.1) resolve AMB #1** com a edição cirúrgica do command: `app(SheetsGateway::class)` foi movido para DEPOIS do dry-run check, de modo que `--dry-run` é **credential-free por design**. Este CT agora é sempre **PASS** em qualquer ambiente — não há mais ambiguidade, não há mais caso de "ambiente sem credencial". A ressalva de v1.0 foi removida.

---

### CT-C-09: `GET /health` retorna 200 e JSON válido **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (smoke test do app).

**Passos:**

```bash
# (Certificar que o app está rodando — vide §1.1)
curl -sS -o /tmp/health-response.json -w "HTTP %{http_code}\n" http://localhost:8000/health
cat /tmp/health-response.json | head -c 500
echo
```

**Resultado esperado:**

- `HTTP 200` (ou `HTTP 503` apenas se houver outra env var crítica ausente — o que é cenário de erro, não de remoção).
- JSON com chaves `status`, `timestamp`, `octane`. Em dev (`APP_DEBUG=true`), também `version`, `app`, `checks`.

**Diagnóstico:**

- Se `status` for `degraded` E o `missing_count` mencionar `GOOGLE_CLOUD_PROJECT_ID`: a edição de `HealthController.php` não foi aplicada.
- Se `status` for `ok`: 200. OK.

---

### CT-C-10: `GET /health?verbose=1` (em dev) tem `checks.env.total === 5` **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
curl -sS "http://localhost:8000/health?verbose=1" | python3 -c "
import json, sys
d = json.load(sys.stdin)
total = d.get('checks', {}).get('env', {}).get('total')
print('total:', total)
print('esperado: 5')
print('OK' if total == 5 else 'FALHA')
"
```

**Resultado esperado:** `total: 5`, `OK`. (Antes da remoção: 6. Após: 5. Spec §2.2.6 e §6.2.)

> **Atenção:** se o app está em `APP_DEBUG=false` (modo produção), `?verbose=1` é ignorado e a resposta **NÃO** terá o objeto `checks`. Nesse caso, o teste só faz sentido com `APP_DEBUG=true` (dev).

---

### CT-C-11: `SheetsService::HEADERS` continua com 9 colunas **[AUTOMATIZÁVEL]**

**Prioridade:** média (sanidade da invariante Sheets — spec §11.3).

**Passos:**

```bash
php -r "
require 'vendor/autoload.php';
\$r = new ReflectionClass('App\\Services\\Google\\SheetsService');
\$const = \$r->getReflectionConstant('HEADERS');
print_r(\$const->getValue());
"
```

**Resultado esperado:** array com 9 colunas — `Data, Descrição, Valor, Tipo, Categoria, Labels, ID Transação, Observações, Itens`. (Esta constante **NÃO** foi alterada pela spec; é parte da garantia §1.2.)

---

### CT-C-12: Schema da tabela `transactions` intacto **[AUTOMATIZÁVEL]**

**Prioridade:** alta (spec §7 — schema intacto, sem migrations).

**Passos:**

```bash
# MariaDB (dev) ou SQLite (testes)
DB_CONNECTION=mariadb DB_DATABASE=wallet_track DB_USERNAME=wallet DB_PASSWORD=walletpass \
  mysql -h 127.0.0.1 -u wallet -pwalletpass wallet_track -e "DESCRIBE transactions;" 2>/dev/null \
  || sqlite3 database/database.sqlite "PRAGMA table_info(transactions);" 2>/dev/null
```

**Resultado esperado (lista resumida — vide spec §7.2):**

| Coluna | Tipo |
|---|---|
| `sync_status` | `varchar(10)` |
| `sync_attempts` | `smallint unsigned` |
| `sync_last_attempt_at` | `timestamp(3)` nullable |
| `sync_error_message` | `text` nullable |
| `spreadsheet_row_id` | `varchar(64)` nullable |
| `processing` | `tinyint(1)` default 0 |
| `processing_since` | `timestamp(3)` nullable |
| `notified_at` | `timestamp(3)` nullable |

E os índices `transactions_sync_status_created_at_index` e `transactions_sync_status_chat_id_created_at_index`.

> **Conexão:** adapte ao setup local (host/porta/credenciais de MariaDB). Se a tabela não existe, rodar `php artisan migrate` primeiro (mas em geral o baseline já tem).

---

## 5. Categoria D — Testes automatizados [REGRESSÃO]

**Objetivo:** confirmar que a suíte PHPUnit completa roda e que o ajuste cirúrgico em `HealthControllerTest` (`total === 5`) está aplicado.

> **Baseline:** `tail -5 /tmp/wallet-track-test-baseline.log` deve mostrar `Tests: X, Assertions: Y`. Os CTs abaixo comparam contra esses números.

### CT-D-01: `php artisan test` — suíte completa verde **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (smoke test global).

**Passos:**

```bash
php artisan test 2>&1 | tee /tmp/wallet-track-test-after.log
tail -10 /tmp/wallet-track-test-after.log
```

**Resultado esperado:**

- Exit code `0`.
- Linha final no formato `Tests: X, Assertions: Y, Skipped: Z.` (Y pode ser > baseline devido a testes novos ou menor se algum teste ficou inconsistente).
- **Nenhuma falha.**

**Critério de aprovação:** `0 falhas`. A contagem de testes pode variar ±5 do baseline (testes que dependem de estado externo, ordem de execução etc.) mas **NÃO** deve mudar drasticamente.

---

### CT-D-02: `php artisan test --filter SyncSheetTest` verde **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (Sheets).

**Passos:**

```bash
php artisan test --filter SyncSheetTest
```

**Resultado esperado:** `OK (X test(s), Y assertions)`. Zero falhas.

---

### CT-D-03: `php artisan test --filter SyncPendingTransactionsCommandTest` verde **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (Sheets command).

**Passos:**

```bash
php artisan test --filter SyncPendingTransactionsCommandTest
```

**Resultado esperado:** verde. Cobre CT-033a..033g da spec (spec §6.1).

---

### CT-D-04: `php artisan test --filter RemoveOriginColumnTest` verde **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan test --filter RemoveOriginColumnTest
```

**Resultado esperado:** verde. **Nota (AMB #1):** este teste deve passar com a edição cirúrgica aplicada em `RemoveOriginColumn.php` (movido `app(SheetsGateway::class)` para depois do dry-run check). Sem essa edição, o teste `test_dry_run_does_not_delete_column` passa por outras razões (testes usam InMemorySheetsGateway), mas a edição é **necessária** para que CT-C-08 passe em ambiente real.

---

### CT-D-05: `php artisan test --filter HealthControllerTest` verde **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (valida o ajuste `total === 5`).

**Passos:**

```bash
php artisan test --filter HealthControllerTest
```

**Resultado esperado:** verde. Especificamente, o teste `test_verbose_mode_includes_checks_object` deve passar com a assertion atualizada `checks.env.total === 5`.

**Diagnóstico de falha:**

- Se a falha for `Failed asserting that 6 is equal to 5.` na linha do `assertJsonPath('checks.env.total', 5)`: significa que a edição em `HealthControllerTest` (spec §6.2) **NÃO** foi aplicada, ou que `HealthController::CRITICAL_ENV_CHECKS` ainda contém 6 entradas (edição §2.2.6 não aplicada).
- Se a falha for em outro teste: investigar caso a caso.

---

### CT-D-06: `php artisan test --filter Health` (Health + HealthController) verde **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan test --filter Health
```

**Resultado esperado:** verde (cobre `HealthTest` + `HealthControllerTest`).

---

### CT-D-07: `php artisan test --filter Sheets` cobre 4+ arquivos **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan test --filter Sheets
```

**Resultado esperado:** verde. Deve incluir `SyncSheetTest`, `SheetsServiceTest`, `SyncPendingTransactionsCommandTest`, `RemoveOriginColumnTest` (4 arquivos) — possivelmente mais.

---

### CT-D-08: `php artisan test --filter Commands/Sync` (SyncHandler) verde **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan test --filter Commands/Sync
```

**Resultado esperado:** verde (cobre `SyncHandlerTest`).

---

### CT-D-09: `php artisan test --filter Store` (WalletStore) verde **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan test --filter Store
```

**Resultado esperado:** verde (`WalletStoreTest` — substituiu o antigo `FirestoreServiceTest` em M7, sem dependência GCP).

---

## 6. Categoria E — Comportamento do bot [REGRESSÃO]

**Objetivo:** confirmar que o bot continua respondendo aos comandos principais (sem Sheets API, mas com o handler `/sync` rodando via `--dry-run` ou contra a planilha real).

> **Modo de teste:** preferencialmente **manual** via Telegram (chat_id 5672987197). Para automação parcial, usar `php artisan telegram:delete-webhook` antes, `php artisan telegram:set-webhook` para apontar para um ngrok, e enviar mensagens via curl direto à API do Telegram (`sendMessage`). Esta seção assume que o usuário tem o bot configurado.

### CT-E-01: `/start` responde com mensagem de boas-vindas **[MANUAL]**

**Prioridade:** **CRÍTICA** (smoke test do bot).

**Passos:**

1. Abrir o Telegram no chat com o bot (ou bot dev, dependendo do `.env` em uso).
2. Enviar `/start`.
3. Observar resposta.

**Resultado esperado:** mensagem de boas-vindas, com lista de comandos disponíveis (`/start`, `/help`, `/nova`, `/cancelar`, `/ultimos`, `/categorias`, `/sync`). **NÃO** deve mencionar "Cloud Run" / "deploy em Cloud Run" / "Google Cloud".

**Diagnóstico:** se a resposta mencionar GCP, o bot ou algum docstring foi editado errado (rever spec §2.2.20 — README).

---

### CT-E-02: Registrar uma transação por texto livre grava no banco **[MANUAL]**

**Prioridade:** **CRÍTICA** (smoke test do fluxo principal).

**Passos:**

1. Enviar mensagem: "Paguei R$ 47,50 no almoço de hoje".
2. Bot deve responder com **resumo para confirmação** (campos: data, descrição, valor, tipo, categoria, labels).
3. Clicar em "Confirmar" (botão inline).

**Resultado esperado:**

- Bot responde com o resumo.
- Após confirmar, bot responde com "✅ transação registrada" (ou similar).
- A transação aparece com `sync_status='pending'` no banco:
  ```bash
  DB_CONNECTION=mariadb DB_DATABASE=wallet_track DB_USERNAME=wallet DB_PASSWORD=walletpass \
    mysql -h 127.0.0.1 -u wallet -pwalletpass wallet_track \
    -e "SELECT id, description, amount, sync_status, sync_attempts FROM transactions ORDER BY id DESC LIMIT 1;"
  ```
- `sync_status = 'pending'`, `sync_attempts = 0`.

---

### CT-E-03: `/ultimos` lista transações recentes **[MANUAL]**

**Prioridade:** alta.

**Passos:**

1. Enviar `/ultimos 5` (ou `/ultimos` para o default).
2. Observar resposta.

**Resultado esperado:** lista das últimas N transações (default 5), formatadas com data, descrição, valor, tipo, categoria. A transação criada em CT-E-02 deve aparecer.

**Diagnóstico:** se retornar lista vazia apesar de haver transações, verificar `TELEGRAM_ALLOWED_CHAT_IDS=5672987197` no `.env` e o filtro do handler.

---

### CT-E-04: `/sync` reseta attempts e processa pendentes **[MANUAL]**

**Prioridade:** **CRÍTICA** (smoke test do Sheets end-to-end).

**Pré-condições:** ter pelo menos 1 transação com `sync_status='pending'`. Pode-se:

- Reutilizar a transação de CT-E-02, OU
- Forçar via `php artisan tinker` (ou `mysql`):
  ```bash
  DB_CONNECTION=mariadb DB_DATABASE=wallet_track DB_USERNAME=wallet DB_PASSWORD=walletpass \
    mysql -h 127.0.0.1 -u wallet -pwalletpass wallet_track \
    -e "UPDATE transactions SET sync_status='pending', sync_attempts=1 WHERE id=<ID>;"
  ```

**Passos:**

1. Enviar `/sync`.
2. Observar resposta do bot.

**Resultado esperado (com Sheets real configurado em `.env`):**

- Bot responde: "⏳ Sincronizando 1 transação(ões) pendente(s)..."
- Após 1-5s: "✅ Sincronização concluída! 1 sincronizada(s) com sucesso 🎉"
- A planilha real (abrir no browser) tem 1 nova linha com a transação.
- Banco de dados: `sync_status='synced'`, `sync_attempts=0` (reset pelo `/sync`).

**Resultado esperado (sem Sheets configurado, em dev):**

- Bot responde com mensagem de erro do tipo "Falha ao inicializar Google Sheets".
- A transação volta para `sync_status='pending'` (re-enfileirada).
- O lock atômico é respeitado (ver `processing=0` após execução).

**Diagnóstico de falha crítica:** se o `/sync` resultar em erro 500 ou em stack trace mencionando `GoogleCredentials` ou `keyFile`, verificar que:

- `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` (em `.env`) aponta para um arquivo `.json` válido (vide Categoria L — deve ser o `~/backup/wallet-track/sa-json-2026-06-30.json`).
- A planilha real está **compartilhada** com a SA `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (NÃO `wallet-track-run@…` — vide AMB #6 da spec).
- O JSON da SA é válido (não expirado).

---

### CT-E-05: `/sync --dry-run` via CLI funciona (sanidade do command) **[AUTOMATIZÁVEL]**

**Prioridade:** alta (regressão do command sem tocar Sheets).

**Passos:**

```bash
php artisan transactions:sync-pending --dry-run --format=json
```

**Resultado esperado:** JSON `{"status":"ok","mode":"dry-run","would_process":N,"ids":[]}` com `N >= 0`.

> **Atenção:** esse CT é o mesmo que CT-C-07, listado também aqui para garantir cobertura em todos os ângulos.

---

## 7. Categoria F — Docker dev [REGRESSÃO]

**Objetivo:** confirmar que `docker compose` (prod e dev) continuam operacionais após a remoção de `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` da seção `environment`.

### CT-F-01: `docker compose config` valida sem erro **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
docker compose config --quiet && echo "OK: compose valido" || echo "FALHA: compose invalido"
docker compose config | grep -i 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH' && echo "FALHA: var ainda injetada" || echo "OK: var NAO injetada"
```

**Resultado esperado:**

- `OK: compose valido`
- `OK: var NAO injetada` (a env var agora é lida apenas do `.env`, não do compose)

---

### CT-F-02: `docker compose -f docker-compose.yml -f docker-compose.dev.yml config` valida **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml config --quiet && echo "OK" || echo "FALHA"
docker compose -f docker-compose.yml -f docker-compose.dev.yml config | grep -i 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH' && echo "FALHA" || echo "OK: var NAO injetada"
```

**Resultado esperado:** ambos `OK`.

---

### CT-F-03: `make up-dev` sobe a stack dev **[MANUAL]**

**Prioridade:** alta.

**Passos:**

```bash
make up-dev   # ou docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

**Resultado esperado:**

- Containers `wallet-track-dev`, `wallet-track-dev-mariadb`, `wallet-track-dev-redis` sobem.
- App responde em `http://localhost:8001/health` (HTTP 200 ou 503 se env var faltar).

**Diagnóstico:** se o container do app reiniciar em loop, verificar `docker compose logs wallet-track-dev` — provavelmente erro de permissão no volume ou env var faltando.

---

### CT-F-04: Container dev responde em `/health` **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
curl -sS -o /dev/null -w "HTTP %{http_code}\n" http://localhost:8001/health
```

**Resultado esperado:** `HTTP 200` (ou `HTTP 503` apenas se houver outra env var crítica ausente — o `wallet-track-dev` deve ter `.env.dev` completo).

---

### CT-F-05: `docker compose config` não menciona Cloud Run **[AUTOMATIZÁVEL]**

**Prioridade:** média.

**Passos:**

```bash
docker compose config | grep -iE 'cloud_run|cloud_run_revision|gcloud' && echo "FALHA" || echo "OK"
```

**Resultado esperado:** `OK`.

---

### CT-F-06: `docker build -t wallet-track:dev --target dev .` continua exit 0 **[MANUAL]** **[RESOLVIDO: AMB #2]**

**Prioridade:** média.

**Passos:**

```bash
docker build -t wallet-track:dev --target dev . 2>&1 | tail -20
```

**Resultado esperado:**

- Build completa sem erros.
- Imagem `wallet-track:dev:latest` é criada.
- **Tempo total:** **< 5 min com cache; < 10 min cold cache** (AMB #2).

> **[RESOLVIDO — AMB #2]:** a v1.0 deste plano marcava este CT como `[AMBIGUOUS: TIME]` por causa da spec §9.3 #14 não definir o tempo esperado. A v2.0 da spec (§9.3 #15 e §12.1 #16) define: alvo `dev` (linha 168 do `Dockerfile`), **< 5 min com cache; < 10 min cold cache**. Se o build estourar 10 min, é anomalia. Reportar tempo exato no PR.

---

## 8. Categoria G — grep "anti-GCP" [REMOÇÃO]

**Objetivo:** confirmar que os arquivos modificados (spec §2.2) não mencionam mais "Cloud Run", "Cloud Build", "Cloud Scheduler", "Secret Manager", etc., exceto em notas explicitamente históricas.

### CT-G-01: grep "Cloud Run|Cloud Build|Cloud Scheduler" em `app/`, `config/`, `docker/`, `routes/`, `bin/`, `scripts/` retorna 0 **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -rIn 'Cloud Run\|Cloud Build\|Cloud Scheduler' \
  --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
  app/ config/ docker/ routes/ bin/ scripts/ docker-compose*.yml 2>/dev/null
```

**Resultado esperado:** 0 matches.

> **Exceção documentada:** se algum match aparecer, deve ser uma menção a "VPS (futuro) — ver §X" sem usar o nome "Cloud Run". Verificar manualmente.

---

### CT-G-02: grep `gcloud|deploy.sh|cloudbuild.yaml|strip-google-services.php` em código/config/scripts retorna 0 **[AUTOMATIZÁVEL]** **[RESOLVIDO: AMB #5]**

**Prioridade:** alta.

**Passos (código/config/scripts — escopo restrito):**

```bash
grep -rIn 'gcloud\|deploy\.sh\|cloudbuild\.yaml\|strip-google-services\.php' \
  --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
  app/ config/ docker/ routes/ bin/ scripts/ 2>/dev/null
```

**Resultado esperado:** 0 matches.

> **[RESOLVIDO — AMB #5]:** a v1.0 deste plano (e o CT-G-02 original) sinalizava que `gcloud` poderia aparecer em `README.md` ou docs secundários em menções a decisões passadas, gerando dúvida sobre se matches devem ser PASS ou FAIL. A v2.0 da spec (§8.5) **resolve AMB #5** com **Política (b):** matches de `gcloud` em **docs/** são PASS desde que estejam em seções claramente marcadas como **históricas** ou **DEPRECATED**, conforme marcador canônico:
>
> ```markdown
> > **⚠️ NOTA DE REMOÇÃO GCP (jun/2026):** Este documento foi escrito quando
> > o deploy era no Google Cloud Run e a persistência em Firestore. Após
> > a remoção do GCP-infra (jun/2026), as referências a Cloud Run, Cloud
> > Scheduler, Cloud Build, Secret Manager, Artifact Registry, Firestore e
> > `gcloud` neste documento são **históricas**. O deploy atual (VPS) está
> > fora de escopo e será documentado em uma task futura.
> ```
>
> Heurística de validação (opcional, para automação):
>
> ```bash
> # 1. Listar docs com marcador canônico
> grep -rIln 'NOTA DE REMOÇÃO GCP (jun/2026)\|DEPRECATED' docs/ > /tmp/docs-com-marcador.txt
>
> # 2. Listar docs com matches de gcloud
> grep -rIn 'gcloud' --exclude-dir=vendor docs/ | \
>   awk -F: '{print $1}' | sort -u > /tmp/docs-com-gcloud.txt
>
> # 3. PASS se todo doc-com-gcloud está em docs-com-marcador
> diff /tmp/docs-com-gcloud.txt /tmp/docs-com-marcador.txt && echo "PASS" || echo "REVISAR"
> ```
>
> **Escopo deste CT (G-02) é restrito a `app/`, `config/`, `docker/`, `routes/`, `bin/`, `scripts/`** — esses diretórios **NÃO** devem ter matches de `gcloud` mesmo com a política (b) (são código, não docs históricas). Se aparecer match aqui, é **FAIL** sem exceção.

---

### CT-G-03: `FIRESTORE_DATABASE_ID` em código/config retorna 0 **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -rIn 'FIRESTORE_DATABASE_ID' \
  --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
  app/ config/ docker/ routes/ bin/ scripts/ docker-compose*.yml 2>/dev/null
```

**Resultado esperado:** 0 matches.

---

### CT-G-04: `GOOGLE_CLOUD_PROJECT_ID` em código/config retorna 0 **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -rIn 'GOOGLE_CLOUD_PROJECT_ID' \
  --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
  app/ config/ docker/ routes/ bin/ scripts/ docker-compose*.yml phpunit.xml 2>/dev/null
```

**Resultado esperado:** 0 matches. (Atenção: `phpunit.xml` está incluído porque a spec §2.2.16 remove essa env var de lá.)

---

### CT-G-05: `routes/console.php` não menciona Cloud Scheduler **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -n 'Cloud Scheduler\|Cloud Build' routes/console.php && echo "FALHA" || echo "OK"
```

**Resultado esperado:** `OK`. (Spec §2.2.12.)

---

### CT-G-06: `config/octane.php`, `config/gemini.php`, `config/telegram.php` não mencionam Cloud Run **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -n 'Cloud Run' config/octane.php config/gemini.php config/telegram.php && echo "FALHA" || echo "OK"
```

**Resultado esperado:** `OK`. (Spec §2.2.3, §2.2.4, §2.2.5.)

---

### CT-G-07: `app/Http/Controllers/HealthController.php` não menciona `GOOGLE_CLOUD_PROJECT_ID` ou `checkGoogleCloudProjectId` **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (smoke test do HealthController).

**Passos:**

```bash
grep -nE 'GOOGLE_CLOUD_PROJECT_ID|checkGoogleCloudProjectId|google\.cloud\.project_id' \
  app/Http/Controllers/HealthController.php && echo "FALHA" || echo "OK"
```

**Resultado esperado:** `OK`. (Spec §2.2.6.)

---

### CT-G-08: `docker-compose*.yml` não menciona `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` nem o nome do JSON **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -n 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH\|wallet-track-499719-54b725c0bb5d' \
  docker-compose.yml docker-compose.dev.yml && echo "FALHA" || echo "OK"
```

**Resultado esperado:** `OK`. (Spec §2.2.15.)

---

### CT-G-09: `php -l` em todos os arquivos modificados não retorna erros de sintaxe **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
for f in \
  app/Http/Controllers/HealthController.php \
  app/Http/Controllers/TelegramWebhookController.php \
  app/Console/Commands/SetTelegramWebhook.php \
  app/Console/Commands/SyncPendingTransactions.php \
  app/Console/Commands/RemoveOriginColumn.php \
  app/Bot/Handlers/SyncHandler.php \
  app/Services/Google/GoogleCredentials.php \
  config/google.php config/telegram.php config/gemini.php config/octane.php \
  routes/console.php \
  tests/Feature/Http/HealthControllerTest.php tests/Feature/HealthTest.php; do
  php -l "$f" 2>&1 | grep -v 'No syntax errors detected' && echo "FALHA: $f"
done
echo "OK: todos os arquivos PHP validos"
```

**Resultado esperado:** `OK`. (Spec §9.3 #14 — `php -l` em todos os modificados.)

---

## 9. Categoria H — Composição do composer [REGRESSÃO]

**Objetivo:** confirmar que `composer install`/`validate` rodam limpos, que deps Sheets/Gemini estão presentes, e que deps Firestore/grpc/protobuf estão ausentes.

### CT-H-01: `composer install` sem conflito **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
composer install --no-interaction 2>&1 | tail -10
```

**Resultado esperado:** exit 0, sem mensagem de conflito.

---

### CT-H-02: `composer validate` (strict) **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
composer validate --no-check-publish --strict 2>&1
```

**Resultado esperado:** exit 0, sem erros.

---

### CT-H-03: `composer install --dry-run` sem conflito **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
composer install --dry-run --ignore-platform-reqs 2>&1 | tail -5
```

**Resultado esperado:** exit 0. (Spec §2.2.1.)

---

### CT-H-04: `composer show | grep google` lista Sheets/Gemini mas NÃO firestore/cloud-storage **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (sanidade das deps).

**Passos:**

```bash
composer show 2>/dev/null | grep -i 'google\|firestore'
```

**Resultado esperado:** lista contém:

- `google/apiclient`
- `google/apiclient-services`
- `google/auth`
- `firebase/php-jwt` (transitiva de google/auth)
- `google-gemini-php/client`

Lista **NÃO** contém:

- `google/cloud-firestore`
- `google/cloud-storage`
- `grpc`
- `protobuf`

> **Atenção:** `firebase/php-jwt` é OK (transitiva, sem Firestore runtime). `firebase/php-jwt` é diferente de `google/cloud-firestore`.

---

### CT-H-05: `php artisan package:discover` lista os 7 providers habituais **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
php artisan package:discover 2>&1 | tail -20
```

**Resultado esperado:** lista os 7 providers do `bootstrap/providers.php` (App, Conversation, DeepSeek, Gemini, Sheets, Store, Telegram) + packages do `vendor/` descobertos automaticamente.

---

### CT-H-06: `bin/check-viability.sh` exit 0 (sem `grpc`/`protobuf` em REQUIRED_EXTS) **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -n 'grpc\|protobuf' bin/check-viability.sh && echo "FALHA: ainda referencia grpc/protobuf" || echo "OK: grpc/protobuf removidos de REQUIRED_EXTS"
./bin/check-viability.sh --skip-resolve 2>&1 | tail -10
```

**Resultado esperado:**

- `OK: grpc/protobuf removidos de REQUIRED_EXTS`
- `bin/check-viability.sh --skip-resolve` exit 0 (ou com avisos esperados de extensões não declaradas em `composer.json`).

---

## 10. Categoria I — Documentação [REMOÇÃO]

**Objetivo:** confirmar que 5 docs 100% GCP foram deletados, que os docs atualizados (README, CHANGELOG, etc.) não têm menções GCP-não-históricas, e que o CHANGELOG tem a nova entrada.

### CT-I-01: 5 docs 100% GCP foram deletados **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
for f in docs/runbook.md docs/viability-report.md docs/comparativo-preco-infra.md docs/comparativo-vps.md docs/infra-pessoal.md; do
  test -f "$f" && echo "FALHA: $f ainda existe" || echo "OK: $f deletado"
done
```

**Resultado esperado:** 5 `OK`.

---

### CT-I-02: `README.md` não tem referências GCP-não-históricas **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
grep -nE 'Cloud Run|Cloud Scheduler|Cloud Build|Secret Manager|gcloud' README.md && echo "FALHA" || echo "OK"
```

**Resultado esperado:** `OK`. (Spec §2.2.20.)

---

### CT-I-03: `CHANGELOG.md` tem nova entrada sobre a remoção **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** alta.

**Passos:**

```bash
# Verificar a presença da entrada na seção [Unreleased]
sed -n '/^## \[Unreleased\]/,/^## \[0\./p' CHANGELOG.md | grep -E 'Remoção completa|GCP-infra|Cloud Run.*removido|google-sheet-202' || echo "FALHA: entrada nao encontrada"
```

**Resultado esperado:** pelo menos 1 match. (Spec §2.2.21.)

**Verificação manual (sugestão):** abrir o CHANGELOG e confirmar que a entrada está em uma seção `### Changed` (ou similar) **abaixo** da última `### Removed` da seção `[Unreleased]`. A entrada também deve mencionar a SA correta (`google-sheet-202@…`) por causa da AMB #6.

---

### CT-I-04: Docs ativos (`00-INDEX`, `01-analise-negocio`, `02-especificacao-tecnica`, `05-revisao-v2`, `06-plano-implementacao`) foram atualizados **[AUTOMATIZÁVEL]** **[MANUAL]** **[RESOLVIDO: AMB #5]**

**Prioridade:** média.

**Passos automatizados (verificação mínima):**

```bash
for f in docs/00-INDEX.md docs/01-analise-negocio.md docs/02-especificacao-tecnica.md docs/05-revisao-v2.md docs/06-plano-implementacao.md; do
  matches=$(grep -cE 'Cloud Run|Cloud Scheduler|Cloud Build|Secret Manager|Artifact Registry|Firestore' "$f" 2>/dev/null)
  echo "$f: $matches match(es) GCP"
done
```

**Resultado esperado:** cada arquivo tem **alguns** matches (notas históricas), mas o usuário deve inspecionar manualmente para confirmar que estão em seções marcadas como "histórico" ou notas de "remoção GCP (jun/2026)" (vide AMB #5 / spec §8.5).

**Verificação manual:** abrir cada doc e localizar as menções a GCP. As remanescentes devem:

- Estar em uma seção de nota de migração no topo, OU
- Mencionar Firestore apenas em referência histórica (ex.: "Decisão M5: Firestore → MariaDB"), OU
- Indicar a remoção GCP como tarefa desta fase (nota explicativa).

**Critério de aprovação:** nenhuma menção GCP está em uma seção que descreve o estado **atual** (i.e., "stack atual", "em produção", etc.).

> **[RESOLVIDO — AMB #5]:** matches de GCP em docs são PASS desde que em seções claramente marcadas como históricas/DEPRECATED (vide Política (b) da spec §8.5). Vide também CT-G-02 para a heurística de validação.

---

### CT-I-05: Docs secundários (M9, planos) foram atualizados **[AUTOMATIZÁVEL]** **[MANUAL]** **[RESOLVIDO: AMB #5]**

**Prioridade:** baixa.

**Passos:**

```bash
for f in docs/M9-COMPLETO.md docs/planos/m9-plano-tecnico.md docs/specs/m9-spec-fase-2.md docs/testes/m9-plano-testes.md docs/testes/items-checklist-staging.md; do
  matches=$(grep -cE 'Cloud Run|Cloud Scheduler' "$f" 2>/dev/null)
  echo "$f: $matches match(es)"
done
```

**Resultado esperado:** cada arquivo tem pequena quantidade de matches (nota de migração). Verificação manual confirma que estão em contexto de "doc histórico" ou "remoção GCP".

> **[RESOLVIDO — AMB #5]:** matches em docs históricos (com marcador canônico aplicado, vide §8.5) são PASS.

---

### CT-I-06: `docs/00-INDEX.md` não referencia os 5 docs deletados **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
for f in runbook viability-report comparativo-preco-infra comparativo-vps infra-pessoal; do
  if grep -q "$f" docs/00-INDEX.md; then
    echo "FALHA: docs/00-INDEX.md ainda referencia $f"
  else
    echo "OK: $f nao referenciado em 00-INDEX"
  fi
done
```

**Resultado esperado:** 5 `OK`. (Spec §8.2.)

---

## 11. Categoria J — Recursos remotos GCP [REMOÇÃO] [MANUAL]

**Objetivo:** confirmar, via `gcloud` CLI, que os recursos GCP-infra da spec §10 foram destruídos e que a SA + Sheets API + planilha permanecem intactos.

> **ATENÇÃO:** esta categoria **EXIGE** que o usuário tenha:
>
> 1. Autenticação GCP ativa (`gcloud auth login`).
> 2. Permissão `roles/owner` no projeto `wallet-track-499719`.
> 3. Acesso à planilha real (em browser) para confirmar que continua intacta.
>
> A destruição é feita pelo script `scripts/destroy-gcp-resources.sh` (proposto pela spec §10.4), **NÃO** manualmente. O usuário apenas confirma e valida o pós-check.

> **PRÉ-CONDIÇÃO CRÍTICA (AMB #6):** antes de executar a destruição, **garantir que `~/backup/wallet-track/sa-json-2026-06-30.json` existe E que seu `client_email` é `google-sheet-202@…`** (vide Categoria L). A chave SA em si NÃO é rotacionada (Portão 1).

### CT-J-01: Backup do `wallet-track-env` está em local seguro (pré-condição) **[MANUAL]**

**Prioridade:** **CRÍTICA** (pré-condição dos demais CTs J).

**Passos:**

1. Antes de qualquer `gcloud secrets delete`:
   ```bash
   # Conferir que o backup externo da chave SA existe (vide Categoria L para detalhes completos)
   test -f ~/backup/wallet-track/sa-json-2026-06-30.json && echo "OK: backup da SA existe" || echo "FALHA: backup ausente — RODE ANTES DE DESTRUIR"
   ```
2. (Idempotente — pode rodar sem side-effects)
   ```bash
   gcloud secrets versions access latest --secret=wallet-track-env --project=wallet-track-499719 > /tmp/wallet-track-env-latest.json
   test -s /tmp/wallet-track-env-latest.json && echo "OK: snapshot do secret em /tmp"
   ```
3. Verificar que o `~/backup/wallet-track/sa-json-2026-06-30.json` **NÃO** está commitado:
   ```bash
   cd /home/diego-oliveira/p/blussal/wallet-track
   git status --porcelain | grep sa-json && echo "FALHA: backup commitado" || echo "OK: backup fora do repo"
   ```

**Resultado esperado:**

- `OK: backup da SA existe`
- `OK: backup fora do repo`
- `OK: snapshot do secret em /tmp`

**Não prosseguir** se qualquer um dos OKs falhar.

---

### CT-J-02: Cloud Run service `wallet-track` foi destruído **[MANUAL]**

**Prioridade:** alta.

**Passos (spec §10.3.3):**

```bash
# Pré-check
gcloud run services describe wallet-track --region=southamerica-east1 --project=wallet-track-499719 --format="value(metadata.name)" >/dev/null 2>&1 \
  && echo "[EXISTE] wallet-track será destruído" \
  || echo "[SKIP] wallet-track já não existe"

# Destruição (via script em modo --execute)
scripts/destroy-gcp-resources.sh --execute
# (Pausa 1: usuário confirma bloco Cloud Run + Scheduler)

# Verificação pós
gcloud run services list --project=wallet-track-499719 --region=southamerica-east1
# Esperado: (vazio) OU sem "wallet-track"
```

**Resultado esperado:** `wallet-track` não aparece na lista de services.

---

### CT-J-03: Artifact Registry `wallet-track` foi destruído **[MANUAL]**

**Prioridade:** alta.

**Passos (spec §10.3.6):**

```bash
# Verificação pós (executado dentro do script destroy-gcp-resources.sh)
gcloud artifacts repositories list --project=wallet-track-499719
# Esperado: (vazio) OU sem "wallet-track"
```

**Resultado esperado:** `wallet-track` não aparece em `repositories list`.

> **Inventário pré-remoção (para referência):** o repo `wallet-track` em `southamerica-east1` continha 431.36 MB de imagens Docker (DOCKER, STANDARD_REPOSITORY).

---

### CT-J-04: Secret Manager `wallet-track-env` foi destruído **[MANUAL]**

**Prioridade:** alta.

**Passos (spec §10.3.5):**

```bash
# Verificação pós
gcloud secrets list --project=wallet-track-499719
# Esperado: (vazio) OU sem "wallet-track-env" e "github-wallet-track-..."
```

**Resultado esperado:** `wallet-track-env` e `github-wallet-track-github-oauthtoken-a10b00` não aparecem em `secrets list`.

> **Inventário pré-remoção (para referência):** o secret `wallet-track-env` continha 12 chaves de env (APP_KEY, TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET_TOKEN, DEEPSEEK_API_KEY, GEMINI_API_KEY, CRON_SECRET_TOKEN, GOOGLE_CLOUD_PROJECT_ID, **GOOGLE_SERVICE_ACCOUNT_JSON**, GOOGLE_SHEETS_SPREADSHEET_ID, GOOGLE_SHEETS_SHEET_NAME, GOOGLE_SHEETS_CATEGORIES_SHEET_NAME, FIRESTORE_DATABASE_ID) — **continha a chave SA em base64**.

---

### CT-J-05: Cloud Build triggers + connections + repos foram destruídos **[MANUAL]**

**Prioridade:** alta.

**Passos (spec §10.3.7):**

```bash
# Verificações pós
gcloud builds triggers list --region=southamerica-east1 --project=wallet-track-499719
gcloud builds connections list --region=southamerica-east1 --project=wallet-track-499719
gcloud builds repos list --region=southamerica-east1 --project=wallet-track-499719
# Esperado: todas as 3 listas vazias
```

**Resultado esperado:** todas as 3 listas vazias.

> **Inventário pré-remoção (para referência):** 1 connection `github-wallet-track` em `southamerica-east1` (`installationState.stage=COMPLETE`); triggers e repos estavam VAZIOS (nunca usados efetivamente).

---

### CT-J-06: Cloud Scheduler está sem jobs (ou já estava) **[MANUAL]**

**Prioridade:** média.

**Passos (spec §10.3.4):**

```bash
gcloud scheduler jobs list --project=wallet-track-499719 --location=southamerica-east1
# Esperado: (vazio) — atenção: usar --location explícito
```

**Resultado esperado:** `gcloud scheduler jobs list` retorna vazio.

> **Inventário pré-remoção (para referência):** existia 1 job `wallet-track-sync-pending` com schedule `*/5 * * * *`, **STATE: ENABLED**, URI apontando para o Cloud Run que foi destruído em CT-J-02. (A v1.1 da spec reportou "vazio" incorretamente porque o `gcloud scheduler jobs list` sem `--location` esconde jobs em outras regiões. A v2.0 da spec usa `--location=southamerica-east1` explicitamente.)

---

### CT-J-07: Firestore databases foram destruídos **[MANUAL]**

**Prioridade:** alta.

**Passos (spec §10.3.8):**

```bash
# Verificação pós
gcloud firestore databases list --project=wallet-track-499719
# Esperado: vazio OU apenas (default) [Datastore mode] — herdado, não deletável
#            (databases (default) em Datastore mode não têm comando delete;
#             desabilitar a API em CT-J-08 é suficiente)
```

**Resultado esperado:** o database `wallet-track-db` (NATIVE mode) e `wallet-track-dev-db` (NATIVE mode) **NÃO** aparecem. O `(default)` em Datastore mode pode permanecer (sem impacto; vide spec §10.3.8).

> **Inventário pré-remoção (para referência):** 3 databases — `wallet-track-db` (us-central1, NATIVE), `wallet-track-dev-db` (southamerica-east1, NATIVE), `(default)` (us-central1, DATASTORE_MODE).

---

### CT-J-08: APIs GCP-infra foram desabilitadas; apenas 4 essenciais permanecem **[MANUAL]**

**Prioridade:** **CRÍTICA** (smoke test da API Sheets).

**Passos (spec §10.3.9):**

```bash
# Verificação pós
ENABLED=$(gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" | sort)
EXPECTED=$(printf "%s\n" \
  "cloudresourcemanager.googleapis.com" \
  "generativelanguage.googleapis.com" \
  "iam.googleapis.com" \
  "sheets.googleapis.com" | sort)

if [ "$ENABLED" = "$EXPECTED" ]; then
  echo "OK: enabled APIs == expected (4 APIs: sheets, iam, cloudresourcemanager, generativelanguage)"
else
  echo "FALHA: diferença entre enabled e expected:"
  diff <(echo "$ENABLED") <(echo "$EXPECTED")
fi
```

**Resultado esperado:**

- Lista de APIs habilitadas contém **EXATAMENTE** 4 entradas (ordem alfabética):
  - `cloudresourcemanager.googleapis.com`
  - `generativelanguage.googleapis.com` (Gemini OCR)
  - `iam.googleapis.com`
  - `sheets.googleapis.com` (**CRÍTICO** — manter)
- **Qualquer outra linha = FALHA.**

> **Atualizado v2.0 (era v1.0):** a v1.0 deste CT (e da spec) listava as APIs a manter de forma mais vaga ("`sheets`, `iam`, `cloudresourcemanager`, `generativelanguage`"). A v2.0 da spec (§10.3.9 e §12.4 #10) torna o critério **EXATO** — as 4 APIs acima são as **únicas** que devem estar habilitadas. **33 APIs devem ter sido desabilitadas.** (Ver CT-J-16 para o sanity check do dry-run do script.)
>
> **Inventário pré-remoção (para referência):** 37 APIs habilitadas, manter 4, desabilitar 33 (vide spec §10.3.9 para a lista canônica das 33).

---

### CT-J-09: APENAS SA `google-sheet-202@…` MANTIDA; SA `wallet-track-run@…` DELETADA **[MANUAL]**

**Prioridade:** **CRÍTICA** (Portão 1: `google-sheet-202@…` NÃO pode ser deletada — AMB #6).

**Passos (spec §10.1.2 M1, M2 e §10.7):**

```bash
# Listar SAs customizadas
gcloud iam service-accounts list --project=wallet-track-499719 --format="value(email)" \
  | grep -E 'google-sheet-202|wallet-track-run'

# DEVE mostrar APENAS:
#   google-sheet-202@wallet-track-499719.iam.gserviceaccount.com  (CRÍTICO — Sheets quebra sem ela)
#
# NÃO DEVE mostrar:
#   wallet-track-run@wallet-track-499719.iam.gserviceaccount.com   (DECIDIDO em Portão 2: deletar)
```

**Resultado esperado:**

- `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` **PRESENTE** (CRÍTICO — vide AMB #6).
- `wallet-track-run@wallet-track-499719.iam.gserviceaccount.com` **AUSENTE** (DECIDIDO em Portão 2, 30/06/2026: deletar após Cloud Run destruído — spec §10.7 #1).

> **Atualizado v2.0.1 (era v2.0):** após decisão de Portão 2 (30/06/2026), a SA `wallet-track-run@…` é DELETADA no Bloco D (não mais mantida por 30 dias). Apenas `google-sheet-202@…` permanece como SA customizada. A v2.0 deste CT (e da spec) verificava ambas como MANTIDAS — **a v2.0.1 corrige para apenas `google-sheet-202@…` MANTIDA** (CT-J-23 abaixo cobre a deleção de `wallet-track-run@…`).

> **Atenção:** se a SA `google-sheet-202@…` for acidentalmente deletada, é uma regressão **CRÍTICA**. Reportar imediatamente. Não há rollback trivial — seria necessário (i) criar nova SA com mesmo display name "google-sheet", (ii) criar nova chave, (iii) atualizar `~/backup/wallet-track/sa-json-2026-06-30.json`, (iv) re-compartilhar a planilha com a nova SA.

---

### CT-J-10: Planilha real ainda acessível pelo SA `google-sheet-202@…` (smoke test do Sheets) **[MANUAL]**

**Prioridade:** **CRÍTICA** (smoke test end-to-end do produto).

**Passos:**

1. No host local, com `.env` apontando para o JSON da SA (do backup validado na Categoria L):
   ```bash
   # Confirmar que a SA no backup é a correta
   jq -r .client_email ~/backup/wallet-track/sa-json-2026-06-30.json
   # → DEVE ser: google-sheet-202@wallet-track-499719.iam.gserviceaccount.com
   ```
2. (Opcional — smoke test real do Sheets via SheetsServiceProvider)
   ```bash
   GOOGLE_SERVICE_ACCOUNT_JSON_PATH=~/backup/wallet-track/sa-json-2026-06-30.json \
     php artisan tinker --execute='
   $sheets = app("App\Services\Google\SheetsService");
   $header = $sheets->getGateway()->getHeaderRow();
   echo "Header row: " . json_encode($header, JSON_UNESCAPED_UNICODE) . PHP_EOL;
   '
   ```
3. **OU** abrir a planilha no browser (`https://docs.google.com/spreadsheets/d/1rGNN0XOOYwDvMYDpFwU1a2ozQXPhAk8P2l8Xjnk9a14/edit`) e:
   - Verificar que **todas as linhas anteriores** estão presentes.
   - Verificar que a planilha **não está em "trash"**.

**Resultado esperado:**

- `jq -r .client_email` retorna `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (passo 1).
- Header row tem 9 colunas (passo 2) OU planilha acessível normalmente no browser (passo 3).

**Diagnóstico:** se o Sheets API estiver retornando 403, a planilha pode ter sido acidentalmente des-compartilhada da SA. Re-compartilhar via browser (botão "Compartilhar" → adicionar `google-sheet-202@…` como Editor — **NÃO** `wallet-track-run@…`).

> **Atualizado v2.0 (era v1.0):** a v1.0 deste CT verificava acesso da SA `wallet-track-run@…` — **incorreto** (vide AMB #6). A v2.0 verifica acesso da SA `google-sheet-202@…`, que é a que de fato escreve no Sheets.

---

### CT-J-11: Recursos remotos mínimos — combinação de CTs J-02 a J-09 **[MANUAL]** **[RESOLVIDO: AMB #3]**

**Prioridade:** alta (substitui o antigo "custo GCP ≈ 0" da v1.0 por critério objetivo).

**Passos:**

```bash
# Combinação de comandos (definidos em §12.4 da spec v2.0):
# 1. Cloud Run vazio
gcloud run services list --project=wallet-track-499719 --format="value(SERVICE)" | wc -l
# Esperado: 0

# 2. Artifact Registry vazio
gcloud artifacts repositories list --project=wallet-track-499719 --format="value(name)" | wc -l
# Esperado: 0

# 3. Secret Manager vazio (GCP-infra apenas)
gcloud secrets list --project=wallet-track-499719 --format="value(name)" | grep -E 'wallet-track|github-wallet-track' | wc -l
# Esperado: 0

# 4. Cloud Scheduler vazio
gcloud scheduler jobs list --project=wallet-track-499719 --location=southamerica-east1 --format="value(name)" | wc -l
# Esperado: 0

# 5. Cloud Build vazio
gcloud builds triggers list --region=southamerica-east1 --project=wallet-track-499719 --format="value(name)" | wc -l
# Esperado: 0
gcloud builds connections list --region=southamerica-east1 --project=wallet-track-499719 --format="value(name)" | wc -l
# Esperado: 0

# 6. Firestore sem NATIVE
gcloud firestore databases list --project=wallet-track-499719 --format="value(type)" | grep -c FIRESTORE_NATIVE
# Esperado: 0 (pode haver (default) em Datastore mode)
```

**Resultado esperado:** todos os contadores retornam 0. A combinação destes 6 resultados + CT-J-08 (4 APIs) + CT-J-09 (2 SAs) + CT-J-15 (billing ativo) garante o critério de aprovação da spec §12.4 #1–#8.

> **[RESOLVIDO — AMB #3]:** a v1.0 deste CT (e da spec) marcava o critério "custo GCP ≈ 0" como `[AMBIGUOUS: HOW]` por não definir métrica objetiva. A v2.0 da spec (§12.4) define **9 itens objetivos** de aceitação, implementados como CT-J-11 a CT-J-19. O custo monetário esperado (≈ $0/mês) é **consequência** de os 8 recursos estarem zerados — não precisa ser medido ativamente nesta task (CT-J-11 é a evidência objetiva; o billing é confirmatório após 30 dias, fora do escopo).

---

### CT-J-12: Apenas 4 APIs habilitadas (sheets, iam, cloudresourcemanager, generativelanguage) **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** **CRÍTICA** (smoke test objetivo da pós-remoção).

**Passos (spec §12.4 #10):**

```bash
# Comando exato (vide spec §10.5 PC1)
gcloud services list --enabled --project=wallet-track-499719 --format="value(config.name)" | sort
```

**Resultado esperado:** output é **EXATAMENTE** (4 linhas, ordem alfabética):

```
cloudresourcemanager.googleapis.com
generativelanguage.googleapis.com
iam.googleapis.com
sheets.googleapis.com
```

**Qualquer outra linha = FALHA.** Esta é a pós-condição PC1 da spec §10.5.

---

### CT-J-13: SAs MANTER preservadas (APENAS `google-sheet-202`; `wallet-track-run` DELETADA) **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** **CRÍTICA** (Sheets quebra sem `google-sheet-202` — AMB #6).

**Passos (spec §12.4 #11):**

```bash
# Comando exato (v2.0.1)
gcloud iam service-accounts list --project=wallet-track-499719 --format="value(email)" \
  | grep -E 'google-sheet-202|wallet-track-run|ais-gemini-key|compute'
```

**Resultado esperado:** output contém `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` (**CRÍTICO** — Sheets quebra sem ela). **NÃO contém** `wallet-track-run@…` (DECIDIDO em Portão 2: deletar — spec §10.7 #1). Service agents auto-managed somem com o tempo — isso é OK.

> **Diferencial v2.0.1 (era v2.0):** a v2.0 deste CT (e da spec) verificava `google-sheet-202` E `wallet-track-run` como presentes. **A v2.0.1, após decisão de Portão 2, inverte: apenas `google-sheet-202` deve estar presente**; `wallet-track-run` é deletada (CT-J-23). A `google-sheet-202` continua sendo a SA CRÍTICA para Sheets.

---

### CT-J-14: `gcloud compute regions list` (sanity check read-only do projeto) **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** baixa (sanity check).

**Passos (spec §12.4 #12):**

```bash
# Comando exato
gcloud compute regions list --project=wallet-track-499719 --format="value(name)" | head -5
```

**Resultado esperado:** exit 0; lista regiões GCP (sanity check de que o projeto está saudável). Não destrutivo. Confirma que o `gcloud` CLI está funcional e o projeto está acessível.

---

### CT-J-15: Billing ainda vinculado **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** **CRÍTICA** (Sheets API depende de billing ativo — R12 da spec).

**Passos (spec §12.4 #13):**

```bash
# Comando exato
gcloud beta billing projects describe wallet-track-499719 --format="value(billingEnabled)"
```

**Resultado esperado:** output é **EXATAMENTE** `True`. **Qualquer outro valor (False, vazio, erro) = FALHA.** Sheets API depende de billing.

> **R12 (spec §11):** se o billing for acidentalmente desvinculado, o Sheets API pode parar de funcionar. Sheets API free tier é generoso, mas o pior caso é rate-limit adicional. Em caso de dúvida, **NUNCA** rodar `gcloud billing projects unlink` durante o Bloco D.

---

### CT-J-16: `scripts/destroy-gcp-resources.sh --dry-run` é executável e mostra o que será destruído **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** alta (valida que o script de destruição existe e é utilizável).

**Passos (spec §12.4 #14):**

```bash
# O script é o entregável do coder (proposto em §10.4 da spec).
# Verificar que existe:
test -x scripts/destroy-gcp-resources.sh && echo "OK: script existe e é executável" || echo "FALHA: script ausente — coder deve implementar conforme spec §10.4"

# Rodar em modo dry-run (read-only):
scripts/destroy-gcp-resources.sh --dry-run
```

**Resultado esperado:** exit 0; output lista os 8 blocos destrutivos (§10.3.3 a §10.3.10) com formato:

```
[BLOCK 1/8] §10.3.3 Cloud Run service
  Resource: wallet-track (southamerica-east1)
  Backup: tmp/gcp-destroy-2026-06-30/cloud-run-before.json
  Command: gcloud run services delete wallet-track --region=... --project=... --quiet
  Reversibility: NO (irreversível)
  Pre-check: gcloud run services describe wallet-track --region=... >/dev/null 2>&1
  Post-check: gcloud run services list --format='value(SERVICE)' | grep -q wallet-track
[BLOCK 2/8] §10.3.4 Cloud Scheduler job
  ...
[BLOCK 8/8] §10.3.10 IAM cleanup
  ...

Total: 8 blocks, 33 APIs, ~50 commands. Estimated time: 5-10 min.
Reversible: 3 blocks (Scheduler, APIs, IAM). Irreversible: 5 blocks.
```

**NÃO** executa nada destrutivo. **NÃO** modifica o estado do projeto GCP.

> **Pré-condição:** o script deve ter sido implementado pelo `coder` conforme spec §10.4 (~250 linhas, 3 modos: `--dry-run`, `--execute`, `--verify-only`).

---

### CT-J-17: Cada bloco destrutivo tem sanity check PRÉ-execução **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** alta (anti-destroy-acidental — defesa em profundidade).

**Passos (spec §12.4 #15):**

```bash
# O output do --dry-run (CT-J-16) DEVE incluir linha `Pre-check: <comando>` para cada bloco.
scripts/destroy-gcp-resources.sh --dry-run 2>&1 | grep -E '^\s*Pre-check:' | wc -l
# Esperado: 8 (um por bloco destrutivo)
```

**Resultado esperado:** 8 linhas `Pre-check:` no output (uma por bloco destrutivo de §10.3.3 a §10.3.10). Cada `Pre-check` deve ser um comando `gcloud` read-only que confirma a existência do recurso ANTES de tentar deletar (ex.: `gcloud run services describe wallet-track --region=... >/dev/null 2>&1`).

> **Por que isso importa:** se o recurso já não existe (foi deletado em run anterior), o `Pre-check` falha e o bloco pula com `[SKIP] <recurso> já não existe` em vez de tentar deletar algo inexistente. Isso é o que torna o script **idempotente** (vide CT-J-19).

---

### CT-J-18: Cada bloco destrutivo tem sanity check PÓS-execução **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** alta (smoke test do estado pós-remoção).

**Passos (spec §12.4 #16):**

```bash
# O output do --dry-run (CT-J-16) DEVE incluir linha `Post-check: <comando>` para cada bloco.
scripts/destroy-gcp-resources.sh --dry-run 2>&1 | grep -E '^\s*Post-check:' | wc -l
# Esperado: 8
```

**Resultado esperado:** 8 linhas `Post-check:` no output (uma por bloco destrutivo). Cada `Post-check` deve ser um comando `gcloud` read-only que confirma que o recurso **NÃO** existe mais após a deleção (ex.: `gcloud run services list --format='value(SERVICE)' | grep -q wallet-track`).

> **Em modo `--verify-only`** (CT-J-12 a J-15), os `Post-check`s são executados como smoke test read-only do estado pós-remoção. Se algum `Post-check` falhar (recurso ainda existe quando deveria ter sido destruído), o bloco correspondente é marcado como **FAIL** e o Bloco D é considerado **falho**.

---

### CT-J-19: Idempotência — rodar o script 2x em modo `--execute` não causa erro **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** alta (segurança operacional).

**Passos (spec §12.4 #17):**

```bash
# ATENÇÃO: este CT é DESTRUTIVO. Rodar apenas após CT-J-01 a CT-J-11 passarem
# e a Categoria L ter validado o backup da SA.

# 1ª execução (modo --execute, com confirmações interativas):
scripts/destroy-gcp-resources.sh --execute
# (5 pausas interativas: y/N para cada bloco — vide spec §9.4.1.5)
# Esperado: exit 0; log em tmp/destroy-gcp-resources-<ts>.log

# 2ª execução (modo --execute, ≥ 1 min depois):
scripts/destroy-gcp-resources.sh --execute
# Esperado: exit 0; recursos já destruídos são pulados com [SKIP]
```

**Resultado esperado:**

- Ambos os runs retornam exit 0.
- Recursos que foram destruídos no 1º run são pulados com `[SKIP] <recurso> já não existe` no 2º run.
- Estado final do projeto GCP é o **mesmo** após o 1º e o 2º runs (idempotência).
- Log do 2º run deve mostrar: `[SKIP] wallet-track (Cloud Run) já não existe`, `[SKIP] wallet-track (AR) já não existe`, etc.

> **Risco:** este CT é **destrutivo**. Antes de rodar, garantir que:
> 1. Backup da SA validado (Categoria L, todos os CTs L verdes).
> 2. Categorias A-I e K verdes (código e docs OK).
> 3. Branch mergeada em `main` (se estratégia for merge-then-destroy) ou pronta para merge (se destroy-then-merge).
> 4. Usuário disponível para confirmar cada pausa.

---

### CT-J-20: `~/backup/wallet-track/sa-json-2026-06-30.json` existe e tem tamanho íntegro **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** **CRÍTICA** (pré-condição de toda a Categoria J; vide Categoria L para validação mais completa).

**Passos:**

```bash
# Verificar existência e tamanho
test -f ~/backup/wallet-track/sa-json-2026-06-30.json \
  && echo "OK: backup existe" \
  || { echo "FALHA: backup ausente"; exit 1; }

# Verificar tamanho mínimo esperado
SIZE=$(stat -c%s ~/backup/wallet-track/sa-json-2026-06-30.json)
if [ "$SIZE" -ge 2300 ]; then
  echo "OK: backup tem $SIZE bytes (>= 2300)"
else
  echo "FALHA: backup tem $SIZE bytes (< 2300 — chave provavelmente truncada)"
fi
```

**Resultado esperado:** `OK: backup existe` E `OK: backup tem <N> bytes (>= 2300)`.

> **Por que >= 2300 bytes:** uma chave SA JSON completa do GCP tem tipicamente ~2.3 KB (chave privada RSA em PEM + metadados do projeto). Se o backup for menor, é sinal de corrupção ou deleção parcial.

---

### CT-J-21: `client_email` do backup é `google-sheet-202@…` (NÃO `wallet-track-run@…`) **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** **CRÍTICA** (AMB #6 — Sheets quebra se a SA errada for preservada).

**Passos:**

```bash
# Extrair client_email do backup
CLIENT_EMAIL=$(jq -r .client_email ~/backup/wallet-track/sa-json-2026-06-30.json)

# Validar
EXPECTED="google-sheet-202@wallet-track-499719.iam.gserviceaccount.com"
if [ "$CLIENT_EMAIL" = "$EXPECTED" ]; then
  echo "OK: client_email = $EXPECTED"
else
  echo "FALHA: client_email = $CLIENT_EMAIL (esperado $EXPECTED)"
  echo "ATENÇÃO: AMB #6 — a SA do backup é a ERRADA. NUNCA prosseguir sem corrigir."
fi
```

**Resultado esperado:** `OK: client_email = google-sheet-202@wallet-track-499719.iam.gserviceaccount.com`.

> **AMB #6:** a v1.x da spec assumia incorretamente que a chave no JSON pertencia à SA `wallet-track-run@…`. **A investigação de 30/06/2026 revelou que pertence à `google-sheet-202@…` (display: "google-sheet").** Esta é a SA que escreve no Sheets. Se o backup tiver a SA errada, **destruir o remote SEMPRE quebra Sheets** — o backup precisa ser refeito.
>
> **Procedimento de recuperação** (se CT-J-21 falhar): NÃO prosseguir com a Categoria J. Reportar como bloqueador. A chave precisa ser regenerada (criar nova SA com display "google-sheet" + nova chave) e a planilha precisa ser re-compartilhada com a nova SA. Isto é **trabalho manual de recuperação**, não automatizável.

---

### CT-J-22: Database Firestore `(default)` (Datastore mode) DELETADO **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** média (DECIDIDO em Portão 2, 30/06/2026 — spec §10.7 #3).

**Passos (spec §10.3.8 estendido):**

```bash
# Comando exato (Bloco D, após CT-J-07)
gcloud firestore databases delete --database="(default)" --project=wallet-track-499719 --quiet
# OU (alternativa)
gcloud datastore databases delete --project=wallet-track-499719 --quiet
```

**Resultado esperado:**

- Exit code 0 E output confirma deleção do `(default)`.
- **OU** (caso Datastore mode recuse): exit code ≠ 0 + mensagem clara do `gcloud`; neste caso, o database fica "congelado" após desabilitar a API `datastore.googleapis.com` (CT-J-08); registrar como **conhecido** e prosseguir.

```bash
# Verificação
gcloud firestore databases list --project=wallet-track-499719 --format="value(name)" | grep -F "(default)" | wc -l
# Esperado: 0 (se deletion aceito) ou > 0 com warning de Datastore mode (se rejeitado)
```

> **Portão 2 (30/06/2026):** o usuário decidiu "pode remover o database". O spec-designer tinha recomendado "deixar como está" (Datastore mode não tem comando `delete` trivial), mas o usuário quer tentar. O script `destroy-gcp-resources.sh` deve implementar a tentativa + warning.

---

### CT-J-23: SA `wallet-track-run@…` DELETADA (após Cloud Run destruído) **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** média (DECIDIDO em Portão 2, 30/06/2026 — spec §10.7 #1).

**Passos (spec §10.3.12 NOVO, Bloco D final):**

```bash
# Pré-condição: Cloud Run destruído (CT-J-02 OK) E roles órfãs removidas (CT-J-24 OK)

# Comando exato
gcloud iam service-accounts delete \
  wallet-track-run@wallet-track-499719.iam.gserviceaccount.com \
  --project=wallet-track-499719 --quiet
```

**Resultado esperado:**

- Exit code 0 E output confirma deleção.

```bash
# Verificação
gcloud iam service-accounts list --project=wallet-track-499719 \
  --format="value(email)" | grep -F "wallet-track-run@"
# Esperado: 0 linhas (vazio)
```

> **Portão 2 (30/06/2026):** o usuário decidiu "deletar a SA que não será mais usada". A `wallet-track-run@…` é a identidade do Cloud Run (que será destruído em CT-J-02); após a destruição do Cloud Run, ela fica orphaned e deve ser removida (princípio do menor privilégio).
>
> **Risco:** se for necessário recriar (rollback), o display name "Wallet Track Cloud Run Service Account" pode ser reutilizado, mas o `client_email` é gerado pelo GCP e **NÃO** é determinístico. Aceitável (rollback não é trivial de qualquer forma).

---

### CT-J-24: Roles órfãs removidas (`run.invoker` e `datastore.user`) **[AUTOMATIZÁVEL]** **[MANUAL]**

**Prioridade:** média (DECIDIDO em Portão 2, 30/06/2026 — spec §10.7 #2).

**Passos (spec §10.3.10 IAM cleanup estendido, Bloco D, ANTES de CT-J-23):**

```bash
# 1. Remover roles/run.invoker de wallet-track-run@… (se ainda existir)
gcloud projects remove-iam-policy-binding wallet-track-499719 \
  --member="serviceAccount:wallet-track-run@wallet-track-499719.iam.gserviceaccount.com" \
  --role="roles/run.invoker" --quiet || true

# 2. Remover roles/datastore.user de wallet-track-run@…
gcloud projects remove-iam-policy-binding wallet-track-499719 \
  --member="serviceAccount:wallet-track-run@wallet-track-499719.iam.gserviceaccount.com" \
  --role="roles/datastore.user" --quiet || true

# 3. Remover roles/datastore.user de google-sheet-202@…
gcloud projects remove-iam-policy-binding wallet-track-499719 \
  --member="serviceAccount:google-sheet-202@wallet-track-499719.iam.gserviceaccount.com" \
  --role="roles/datastore.user" --quiet || true

# NOTA: roles/editor de google-sheet-202@… é MANTIDO (necessário para Sheets API)
```

**Resultado esperado:**

- Cada comando termina com exit code 0 (ou `true` se a binding já não existia).
- Nenhuma binding órfã de `wallet-track-run@…` ou `datastore.user` de `google-sheet-202@…` permanece.

```bash
# Verificação
gcloud projects get-iam-policy wallet-track-499719 \
  --format="value(bindings.members)" | grep -E "wallet-track-run|datastore.user" | wc -l
# Esperado: 0 (ou apenas service agents auto-managed que não podem ser removidos)
```

> **Portão 2 (30/06/2026):** aplicar a recomendação do spec-designer — `datastore.user` removido de ambas SAs; `run.invoker` removido de `wallet-track-run`; **`editor` de `google-sheet-202` MANTIDO** (necessário para Sheets API).

---

## 12. Categoria K — Git [REMOÇÃO]

**Objetivo:** confirmar que o `git status` reflete exatamente as 10 deleções + 42 edições da spec §14, e que o histórico Git **NÃO** contém a chave SA (que seria um vazamento).

### CT-K-01: `git status` mostra apenas os arquivos esperados como modificados/deletados **[AUTOMATIZÁVEL]**

**Prioridade:** alta.

**Passos:**

```bash
git status --porcelain | head -60
# Comparar com a lista da spec §14 (10 deleções + 42 edições)
```

**Resultado esperado:**

- 10 linhas começando com `D  ` (deleções).
- 42 linhas começando com `M  ` (modificações) ou `A  ` (adições — improvável, mas pode haver docs novos se necessário).
- **0 linhas** começando com `??  ` (untracked) para os arquivos GCP-infra.

> **Atenção:** `docs/infra-pessoal.md` pode aparecer como untracked se o `.gitignore` foi contornado (CT-A-06 já trata). Outros untracked (ex.: `.vscode/`, arquivos de editor) **NÃO** são problema desta task.

---

### CT-K-02: `git log --all -- wallet-track-499719-54b725c0bb5d.json` classifica como PASS / WARN / FAIL **[AUTOMATIZÁVEL]** **[RESOLVIDO: AMB #4]**

**Prioridade:** **CRÍTICA** (security).

**Passos (classificação expandida — AMB #4):**

```bash
# Verificar se a chave foi commitada em algum momento
git log --all --oneline -- wallet-track-499719-54b725c0bb5d.json
# Alternativa:
git log --all --full-history -- wallet-track-499719-54b725c0bb5d.json

# Verificar conteúdo do objeto em algum commit (se aparecer):
# git show <SHA> -- wallet-track-499719-54b725c0bb5d.json | head -10
```

**Critério de aprovação (classificação AMB #4):**

| Resultado | Classificação | Ação |
|---|---|---|
| `git log --all` retorna **vazio** | **PASS** | Chave nunca foi commitada; tarefa concluída sem incidente. |
| `git log --all` retorna **≥ 1 commit** com a chave | **WARN** (NÃO bloqueador) | Abrir **issue separada** rotulada `security/SA-key-rotation` com prioridade **alta**, mas **NÃO bloquear** o merge desta task. Issue deve listar: (i) rotação de chave via `gcloud iam service-accounts keys create` + desativar antiga; (ii) BFG ou `git filter-branch` para limpar histórico; (iii) novo deploy usando nova chave. Reportar WARN no PR description. |
| `git log` retorna vazio **E** a chave aparece no working tree após o `git rm` | **FAIL** | Bug no fluxo de deleção; re-executar passo §9.2.4. |

> **[RESOLVIDO — AMB #4]:** a v1.0 deste CT (e da spec) marcava o teste como `[AMBIGUOUS]` por não classificar entre "chave no histórico = bloqueador" vs. "chave no histórico = trabalho separado". A v2.0 da spec (§11 risco "Histórico Git com SA JSON") define explicitamente: **WARN, não FAIL** — abrir issue `security/SA-key-rotation` em separado, mas **não bloquear** o merge. Isto permite que a Fase 2 prossiga mesmo com chave no histórico (cenário esperado, não improvável).

---

### CT-K-03: `git log --stat` mostra o conjunto coerente de mudanças (10 deletes + 42 edits) **[AUTOMATIZÁVEL]**

**Prioridade:** média.

**Passos:**

```bash
# Conferir que as mudanças estão agrupadas em commits (sugestão da spec §9.2: 11 commits intermediários)
git log --oneline chore/remove-gcp-infra ^main
# Esperado: ~11 commits seguindo a sugestão da spec §9.2.
```

**Resultado esperado:** o número de commits não é rígido (pode ser 1, 5, 11, ou 30), mas o conjunto final de mudanças **deve** incluir:

- 10 deleções.
- 42 edições (com base na spec §14).
- 0 criações de novos arquivos de produção (criações só de docs de migração se necessário).

**Critério de aprovação:** `git diff main..chore/remove-gcp-infra --stat` mostra as 52 entradas (10 deletes + 42 edits).

> **Atenção:** arquivos deletados aparecem como `N files changed, M insertions(+), K deletions(-)` no `git diff --stat`. Conferir que o número de deleções corresponde a 10.

---

## 13. Categoria L — Validação do backup da Service Account (AMB #6) **[REGRESSÃO]**

**Objetivo:** confirmar que o backup local da chave SA (`~/backup/wallet-track/sa-json-2026-06-30.json`) está íntegro e contém a chave da SA **correta** — `google-sheet-202@…`, **NÃO** `wallet-track-run@…`. Esta categoria é **pré-condição absoluta** de toda a Categoria J (destruição remota).

> **Por que esta categoria é nova em v2.0:** a v1.0 deste plano não tinha validação dedicada do backup da SA. A v2.0 da spec (§0.1 + AMB #6) descobriu que a chave no JSON pertence a `google-sheet-202@…`, não a `wallet-track-run@…`. Sem esta validação, o usuário poderia destruir o remote acreditando estar preservando a SA certa, e quebrar Sheets irremediavelmente.

> **Quando executar:** **ANTES** de iniciar a Categoria J (destruição remota). Esta categoria é puramente read-only e idempotente.

### CT-L-01: `~/backup/wallet-track/sa-json-2026-06-30.json` existe **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (pré-condição absoluta).

**Passos:**

```bash
test -f ~/backup/wallet-track/sa-json-2026-06-30.json && echo "OK: backup existe" || echo "FALHA: backup ausente — RODE spec §9.1 #0 ANTES DE PROSSEGUIR"
```

**Resultado esperado:** `OK: backup existe`.

> **Procedimento de criação do backup** (se ainda não existir, conforme spec §9.1 #0):
> ```bash
> mkdir -p ~/backup/wallet-track
> cp wallet-track-499719-54b725c0bb5d.json ~/backup/wallet-track/sa-json-2026-06-30.json
> ls -la ~/backup/wallet-track/sa-json-2026-06-30.json
> ```

---

### CT-L-02: Tamanho do backup está íntegro (>= 2.3 KB) **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (detecção de corrupção / deleção parcial).

**Passos:**

```bash
SIZE=$(stat -c%s ~/backup/wallet-track/sa-json-2026-06-30.json 2>/dev/null || echo "0")
if [ "$SIZE" -ge 2300 ]; then
  echo "OK: backup tem $SIZE bytes (>= 2300)"
else
  echo "FALHA: backup tem $SIZE bytes (< 2300 — chave provavelmente truncada ou corrompida)"
fi
```

**Resultado esperado:** `OK: backup tem <N> bytes (>= 2300)`.

> **Por que >= 2300 bytes:** uma chave SA JSON completa do GCP tem tipicamente ~2.3 KB (chave privada RSA em PEM + metadados do projeto: `type`, `project_id`, `private_key_id`, `private_key`, `client_email`, `client_id`, `auth_uri`, `token_uri`, `auth_provider_x509_cert_url`, `client_x509_cert_url`). Se o backup for menor, é sinal de:
> - Truncamento na cópia
> - JSON incompleto (chave privada cortada)
> - Backup de outro arquivo (não é SA JSON)
>
> Em qualquer caso, é **bloqueador absoluto** — refazer o backup a partir de `wallet-track-499719-54b725c0bb5d.json` (que ainda existe no working tree pré-deleção) antes de prosseguir.

---

### CT-L-03: `client_email` do backup é `google-sheet-202@…` (NÃO `wallet-track-run@…`) **[AUTOMATIZÁVEL]**

**Prioridade:** **CRÍTICA** (AMB #6 — preservação da SA que escreve no Sheets).

**Passos:**

```bash
CLIENT_EMAIL=$(jq -r .client_email ~/backup/wallet-track/sa-json-2026-06-30.json)
EXPECTED="google-sheet-202@wallet-track-499719.iam.gserviceaccount.com"

if [ "$CLIENT_EMAIL" = "$EXPECTED" ]; then
  echo "OK: client_email = $EXPECTED"
  echo "OK: SA correta preservada (Sheets writer)"
else
  echo "FALHA: client_email = $CLIENT_EMAIL"
  echo "ESPERADO: $EXPECTED"
  echo "ATENÇÃO: AMB #6 — a SA do backup é a ERRADA. NUNCA prosseguir sem corrigir."
fi
```

**Resultado esperado:** `OK: client_email = google-sheet-202@wallet-track-499719.iam.gserviceaccount.com` E `OK: SA correta preservada (Sheets writer)`.

> **AMB #6 (v2.0):** a v1.x da spec assumia incorretamente que a chave no JSON pertencia à SA `wallet-track-run@…` (identidade do Cloud Run). **A investigação de 30/06/2026 revelou que pertence à `google-sheet-202@…`** (display name "google-sheet", papéis `roles/editor` + `roles/datastore.user`). Esta é a SA que **de fato** escreve no Google Sheets. A `wallet-track-run@…` é a identidade do Cloud Run service, que será destruída em CT-J-02.
>
> **Procedimento de recuperação** (se CT-L-03 falhar): **NÃO** prosseguir com a Categoria J. Reportar como bloqueador. A chave precisa ser regenerada manualmente:
> 1. Criar nova SA com display name "google-sheet" (se `google-sheet-202@…` não existir mais)
> 2. Criar nova chave: `gcloud iam service-accounts keys create ...`
> 3. Re-compartilhar a planilha `1rGNN0XOOYwDvMYDpFwU1a2ozQXPhAk8P2l8Xjnk9a14` com a nova SA (botão "Compartilhar" no Google Sheets)
> 4. Atualizar `~/backup/wallet-track/sa-json-2026-06-30.json` com a nova chave
> 5. Re-rodar CT-L-01, CT-L-02, CT-L-03
>
> Este trabalho é **manual e bloqueador** — não há atalho.

---

## 14. Checklist de validação final

> Esta seção replica o template padrão; copie o conteúdo para um issue/PR do GitHub (ou similar) ao final da execução.

```markdown
## Checklist pós-execução — Fase 2: Remoção do GCP

### Categoria A — Remoção de arquivos [REMOÇÃO]
- [ ] CT-A-01: cloudbuild.yaml deletado — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-02: scripts/deploy.sh deletado — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-03: scripts/strip-google-services.php deletado — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-04: wallet-track-499719-54b725c0bb5d.json deletado — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-05: .env.bak deletado — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-06: 5 docs GCP-100% deletados — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-07: git status sem untracked GCP-infra — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-A-08: scripts/ sem GCP-infra — Status: ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Categoria B — Remoção de env vars GCP-infra [REMOÇÃO]
- [ ] CT-B-01: GOOGLE_CLOUD_PROJECT_ID removido de .env — Status: ⬜
- [ ] CT-B-02: GOOGLE_CLOUD_PROJECT_ID removido de .env.dev e .env.example — Status: ⬜
- [ ] CT-B-03: FIRESTORE_DATABASE_ID removido — Status: ⬜
- [ ] CT-B-04: GOOGLE_SERVICE_ACCOUNT_JSON* MANTIDOS — Status: ⬜
- [ ] CT-B-05: config:clear + boot sem erro — Status: ⬜
- [ ] CT-B-06: header reescrito nos .env* — Status: ⬜

### Categoria C — Sanidade de código de Sheets [REGRESSÃO]
- [ ] CT-C-01: SheetsService resolve — Status: ⬜
- [ ] CT-C-02: SheetsGateway + impls resolvem — Status: ⬜
- [ ] CT-C-03: SyncSheet + SyncsSheet resolvem — Status: ⬜
- [ ] CT-C-04: SheetsServiceProvider registrado — Status: ⬜
- [ ] CT-C-05: artisan list mostra comandos Sheets — Status: ⬜
- [ ] CT-C-06: artisan list NAO mostra cloudbuild — Status: ⬜
- [ ] CT-C-07: transactions:sync-pending --dry-run roda — Status: ⬜
- [ ] CT-C-08: sheets:remove-origin-column --dry-run [RESOLVIDO AMB #1] — Status: ⬜
- [ ] CT-C-09: GET /health 200 + JSON válido — Status: ⬜
- [ ] CT-C-10: GET /health?verbose=1 com total=5 — Status: ⬜
- [ ] CT-C-11: SheetsService::HEADERS com 9 colunas — Status: ⬜
- [ ] CT-C-12: schema transactions intacto — Status: ⬜

### Categoria D — Testes automatizados [REGRESSÃO]
- [ ] CT-D-01: php artisan test verde — Status: ⬜
- [ ] CT-D-02: SyncSheetTest verde — Status: ⬜
- [ ] CT-D-03: SyncPendingTransactionsCommandTest verde — Status: ⬜
- [ ] CT-D-04: RemoveOriginColumnTest verde — Status: ⬜
- [ ] CT-D-05: HealthControllerTest verde (total=5) — Status: ⬜
- [ ] CT-D-06: Health (HealthTest+HealthControllerTest) verde — Status: ⬜
- [ ] CT-D-07: Sheets (4+ arquivos) verde — Status: ⬜
- [ ] CT-D-08: Commands/Sync (SyncHandler) verde — Status: ⬜
- [ ] CT-D-09: Store (WalletStore) verde — Status: ⬜

### Categoria E — Comportamento do bot [REGRESSÃO]
- [ ] CT-E-01: /start responde — Status: ⬜
- [ ] CT-E-02: registrar transação grava no DB — Status: ⬜
- [ ] CT-E-03: /ultimos lista transações — Status: ⬜
- [ ] CT-E-04: /sync processa pendentes — Status: ⬜
- [ ] CT-E-05: transactions:sync-pending --dry-run (CLI) — Status: ⬜

### Categoria F — Docker dev [REGRESSÃO]
- [ ] CT-F-01: docker compose config OK — Status: ⬜
- [ ] CT-F-02: docker compose dev config OK — Status: ⬜
- [ ] CT-F-03: make up-dev sobe — Status: ⬜
- [ ] CT-F-04: container dev /health responde — Status: ⬜
- [ ] CT-F-05: compose NAO menciona cloud_run — Status: ⬜
- [ ] CT-F-06: docker build OK [RESOLVIDO AMB #2] — Status: ⬜

### Categoria G — grep "anti-GCP" [REMOÇÃO]
- [ ] CT-G-01: grep Cloud Run/Build/Scheduler 0 matches — Status: ⬜
- [ ] CT-G-02: grep gcloud/deploy.sh/cloudbuild 0 matches [RESOLVIDO AMB #5] — Status: ⬜
- [ ] CT-G-03: grep FIRESTORE_DATABASE_ID 0 matches — Status: ⬜
- [ ] CT-G-04: grep GOOGLE_CLOUD_PROJECT_ID 0 matches — Status: ⬜
- [ ] CT-G-05: routes/console.php sem Cloud Scheduler — Status: ⬜
- [ ] CT-G-06: config/{octane,gemini,telegram} sem Cloud Run — Status: ⬜
- [ ] CT-G-07: HealthController sem GOOGLE_CLOUD_PROJECT_ID — Status: ⬜
- [ ] CT-G-08: compose sem GOOGLE_SERVICE_ACCOUNT_JSON_PATH — Status: ⬜
- [ ] CT-G-09: php -l em todos modificados OK — Status: ⬜

### Categoria H — Composer [REGRESSÃO]
- [ ] CT-H-01: composer install OK — Status: ⬜
- [ ] CT-H-02: composer validate --strict OK — Status: ⬜
- [ ] CT-H-03: composer install --dry-run OK — Status: ⬜
- [ ] CT-H-04: composer show | grep google lista Sheets/Gemini — Status: ⬜
- [ ] CT-H-05: package:discover lista 7 providers — Status: ⬜
- [ ] CT-H-06: bin/check-viability.sh --skip-resolve OK — Status: ⬜

### Categoria I — Documentação [REMOÇÃO]
- [ ] CT-I-01: 5 docs 100% GCP deletados — Status: ⬜
- [ ] CT-I-02: README sem GCP-não-histórico — Status: ⬜
- [ ] CT-I-03: CHANGELOG tem entrada — Status: ⬜
- [ ] CT-I-04: docs ativos atualizados [RESOLVIDO AMB #5] — Status: ⬜
- [ ] CT-I-05: docs secundários atualizados [RESOLVIDO AMB #5] — Status: ⬜
- [ ] CT-I-06: 00-INDEX sem refs aos 5 deletados — Status: ⬜

### Categoria J — Recursos remotos GCP [REMOÇÃO] [MANUAL]
- [ ] CT-J-01: backup do wallet-track-env (pré-condição) — Status: ⬜
- [ ] CT-J-02: Cloud Run wallet-track destruído — Status: ⬜
- [ ] CT-J-03: Artifact Registry wallet-track destruído — Status: ⬜
- [ ] CT-J-04: Secret Manager wallet-track-env destruído — Status: ⬜
- [ ] CT-J-05: Cloud Build triggers+connections destruídos — Status: ⬜
- [ ] CT-J-06: Cloud Scheduler vazio — Status: ⬜
- [ ] CT-J-07: Firestore wallet-track-db + wallet-track-dev-db destruídos — Status: ⬜
- [ ] CT-J-08: 4 APIs essenciais, 33 desabilitadas — Status: ⬜
- [ ] CT-J-09: APENAS SA google-sheet-202 MANTIDA (wallet-track-run DELETADA per Portão 2) — Status: ⬜
- [ ] CT-J-10: planilha real acessível pelo SA google-sheet-202@… — Status: ⬜
- [ ] CT-J-11: recursos remotos mínimos (substitui custo ≈ 0) [RESOLVIDO AMB #3] — Status: ⬜
- [ ] CT-J-12: exatamente 4 APIs habilitadas — Status: ⬜
- [ ] CT-J-13: SAs MANTER (APENAS google-sheet-202; wallet-track-run DELETADA per Portão 2) — Status: ⬜
- [ ] CT-J-14: gcloud compute regions list executável — Status: ⬜
- [ ] CT-J-15: billing ainda vinculado (True) — Status: ⬜
- [ ] CT-J-16: scripts/destroy-gcp-resources.sh --dry-run executável — Status: ⬜
- [ ] CT-J-17: cada bloco tem Pre-check — Status: ⬜
- [ ] CT-J-18: cada bloco tem Post-check — Status: ⬜
- [ ] CT-J-19: idempotência do script (2x execute OK) — Status: ⬜
- [ ] CT-J-20: backup SA existe e tem >= 2.3 KB — Status: ⬜
- [ ] CT-J-21: client_email do backup é google-sheet-202@… (AMB #6) — Status: ⬜
- [ ] CT-J-22: database Firestore (default) deletado (DECIDIDO Portão 2) — Status: ⬜
- [ ] CT-J-23: SA wallet-track-run@… DELETADA (DECIDIDO Portão 2) — Status: ⬜
- [ ] CT-J-24: roles órfãs removidas (run.invoker, datastore.user) (DECIDIDO Portão 2) — Status: ⬜

### Categoria K — Git [REMOÇÃO]
- [ ] CT-K-01: git status reflete mudanças esperadas — Status: ⬜
- [ ] CT-K-02: git log wallet-track-*.json (PASS/WARN/FAIL) [RESOLVIDO AMB #4] — Status: ⬜
- [ ] CT-K-03: git diff main..branch mostra 10 deletes + 42 edits — Status: ⬜

### Categoria L — Validação do backup da SA (AMB #6) [REGRESSÃO]
- [ ] CT-L-01: ~/backup/wallet-track/sa-json-2026-06-30.json existe — Status: ⬜
- [ ] CT-L-02: backup tem tamanho íntegro (>= 2.3 KB) — Status: ⬜
- [ ] CT-L-03: client_email = google-sheet-202@… (NÃO wallet-track-run) — Status: ⬜

### Regressão geral
- [ ] Suite PHPUnit completa: 0 falhas
- [ ] Smoke /health, /start, /sync funcionam
- [ ] Planilha real recebe 1 transação de teste (smoke E2E) — usando SA google-sheet-202@…
- [ ] Dados existentes preservados (transações antigas, labels, categorias)

### Aprovação final
- [ ] Todos os CTs de prioridade ALTA: PASS
- [ ] Nenhum teste bloqueado sem justificativa
- [ ] Categoria L (CT-L-01..L-03) **inteiramente verde** antes de Categoria J
- [ ] Aprovação por: _______________ Data: ___/___/___
```

---

## 15. Estimativa de tempo de execução manual

> Tempos assumem um humano com familiaridade média com o projeto. Para um humano novo no projeto, multiplicar por 1.5x.

| Categoria | CTs | Tempo estimado | Observações |
|---|---|---|---|
| A — Remoção de arquivos | 8 | ~5 min | Quase tudo automatizável; só validar o output. |
| B — Remoção de env vars | 6 | ~5 min | `php -r "..."` é rápido; config:clear + boot é trivial. |
| C — Sanidade de código Sheets | 12 | ~10 min | Predominam checks via `php -r` e `artisan list`. |
| D — Testes automatizados | 9 | ~10 min | `php artisan test` sozinho é ~3-5 min; demais são filtros. |
| E — Comportamento do bot | 5 | ~15 min | Requer Telegram real; pode exigir ajuste de webhook. |
| F — Docker dev | 6 | ~10 min | `make up-dev` + `docker build` são os mais demorados (~5 min). |
| G — grep "anti-GCP" | 9 | ~5 min | Predominam `grep` puros; rápidos. |
| H — Composer | 6 | ~5 min | `composer install` é o gargalo (~2 min). |
| I — Documentação | 6 | ~10 min | Verificação manual de cada doc; mais lento. |
| L — Backup SA (AMB #6) | 3 | ~3 min | Quick checks de `test -f`, `stat`, `jq`; puramente read-only. **Executar ANTES da J.** |
| K — Git | 3 | ~3 min | `git log` + `git status` são instantâneos. |
| J — Recursos remotos GCP | **24** | **~18 min** | Requer `gcloud` + planilha real + script de destruição; o mais arriscado. CT-J-12..J-24 (13 CTs) adicionam ~6-8 min de checks read-only e de ações destrutivas. |
| **Total** | **97** | **~98 min** | ~1h38 para humano. **+ 3 CTs e + 3 min vs. v2.0** (CT-J-22, CT-J-23, CT-J-24 — decisões de Portão 2). |

> **Observação sobre paralelismo:** Categorias A, B, C, G, H, K são **shell-puros** e podem ser executadas em <30 min por alguém com terminal. Categorias E, F, I, J, L exigem **interação humana** (Telegram, Docker, planilha, console GCP) e são as mais demoradas.

> **Observação sobre automação:** ~63 dos 97 CTs são **[AUTOMATIZÁVEL]**. Recomenda-se extrair como um script shell em `bin/validate-gcp-removal.sh` para que **o próprio `coder`** possa rodar antes de marcar a task como concluída. O script gerado poderia seguir a estrutura dos CTs e produzir um relatório `pass/fail` em `tmp/`. A Categoria L e os CTs J-12..J-15 são candidatos naturais a serem expostos como `--verify-only` do `scripts/destroy-gcp-resources.sh` (vide spec §10.4). Os CTs J-22..J-24 são `--execute` (destructive) do mesmo script.

> **Observação sobre AMB resolvidos (v1.0 → v2.0.1):** a v1.0 deste plano tinha 5 CTs marcados como `[AMBIGUOUS]` (CT-C-08, CT-F-06, CT-J-11, CT-K-02, CT-G-02). A v2.0 **resolveu todos** com base na spec §16.2 + AMB #6:
> - **CT-C-08** → `[RESOLVIDO: AMB #1]` (credential-free por design via §2.2.10.1)
> - **CT-F-06** → `[RESOLVIDO: AMB #2]` (timing definido: < 5 min com cache, < 10 min cold)
> - **CT-J-11** → `[RESOLVIDO: AMB #3]` (métrica objetiva: combinação de CTs J-02..J-09 + J-12..J-15)
> - **CT-K-02** → `[RESOLVIDO: AMB #4]` (classificação PASS/WARN/FAIL explícita)
> - **CT-G-02** → `[RESOLVIDO: AMB #5]` (Política (b) com marcador canônico)
> - **CT-J-09, CT-J-10** → atualizados com a SA correta (`google-sheet-202@…`, não `wallet-track-run@…`) por **AMB #6** (NEW v2.0)
>
> **v2.0.1 (Portão 2, 30/06/2026):** CT-J-09, CT-J-13 inalterados em redação mas **atualizados em semântica** — apenas `google-sheet-202@…` deve estar presente (CT-J-22, CT-J-23, CT-J-24 cobrem as 3 ações destrutivas decididas no Portão 2).
>
> **Total de CTs `[AMBIGUOUS]` restantes: 0.** A v2.0.1 do plano é não-ambígua.

---

## 16. Próximos passos

1. **Executar a Categoria L (validação do backup da SA) ANTES de qualquer coisa destrutiva.** Bloqueador absoluto.
2. **Executar a Categoria J (recursos remotos GCP)** apenas DEPOIS de validar todas as categorias A-I + K + L. A destruição é irreversível.
3. **Após validação total**, abrir PR (ou merge) da branch `chore/remove-gcp-infra` para `main`.
4. **Após merge**, acompanhar billing GCP por 30 dias (CT-J-11 + CT-J-15 confirmatórios; custo esperado ≈ $0/mês).
5. **Se CT-K-02 detectar chave no histórico Git (WARN):** abrir issue separada de "rotação de chave SA + BFG cleanup" — **NÃO** bloqueador desta task (vide AMB #4).
6. **Após 30 dias** (reavaliação §10.7): decidir se a SA `wallet-track-run@…` (orphaned) deve ser deletada. Por ora, MANTER.

---

**Fim do Plano de Testes Manuais — Remoção do GCP (Fase 2) — v2.0.**
