# 06 — Plano de Implementação

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

> **Plano técnico** aprovado pelo usuário. Decompõe as especificações + plano de testes em **11 milestones (M0–M10)** com dependências, tarefas, riscos, critérios de aceitação e estimativa de esforço (~27 dev-dias).

---

## 1. Visão Geral

### 1.1 Sumário

| Atributo | Valor |
|----------|-------|
| **Total de milestones** | 11 (M0–M10) |
| **Esforço total** | ~27 dev-dias (≈ 6,5 semanas para 1 dev) |
| **Estratégia de execução** | Sequencial com **paralelização controlada** em M5–M8 (após base sólida) |
| **Critério de pronto global** | Todos os critérios de aceitação dos milestones M0–M9 atendidos + smoke test em produção (M10) |
| **Critério de pronto por milestone** | Code review + CTs aplicáveis passando + `composer test` (PHPUnit) verde |

### 1.2 Diagrama de Dependências

```
                       ┌─────────────┐
                       │  PRÉ-M0     │ (1-3 dias)
                       │  Secrets +  │
                       │  GCP setup  │
                       └──────┬──────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  M0 — Setup & Viability Gate (3 dias)   │
        │  bootstrap + Docker + FrankenPHP/Octane │
        └──────┬─────────────┬────────────────────┘
               │             │
               ▼             ▼
       ┌─────────────┐  ┌──────────────────┐
       │ M1 — Bot    │  │ M2 — Segurança   │  (1 dia cada, paralelizáveis
       │ Skeleton    │  │ Webhook + WL     │   após M0)
       └──────┬──────┘  └────────┬─────────┘
              │                  │
              └────────┬─────────┘
                       ▼
              ┌─────────────────┐
              │ M3 — DeepSeek   │  (2 dias)
              │ Texto → JSON    │
              └────────┬────────┘
                       ▼
              ┌─────────────────┐
              │ M4 — Gemini     │  (2 dias)
              │ Imagem → JSON   │
              └────────┬────────┘
                       ▼
        ┌──────────────┴──────────────┐
        │  M5–M8 — Camadas paralelas │  (4 × 2 dias = 8 dias)
        │  M5 Firestore              │
        │  M6 Google Sheets          │
        │  M7 Máquina de Estados     │
        │  M8 Heurística de Labels   │
        └──────────────┬──────────────┘
                       ▼
              ┌─────────────────┐
              │ M9 — Comandos   │  (3 dias)
              │ /start /help …  │
              └────────┬────────┘
                       ▼
              ┌─────────────────┐
              │ M10 — Deploy    │  (3 dias)
              │ Cloud Run + CI  │
              └─────────────────┘
```

**Observação sobre paralelização M5–M8:** embora listados como sequência na figura, estes milestones podem ser executados em **streams paralelos** se houver mais de um desenvolvedor. Para 1 dev, recomenda-se a ordem M5 → M6 → M7 → M8.

---

## 2. Pré-requisitos antes de começar (M0)

> ⚠️ **Bloqueante.** Sem estes itens, M0 não pode iniciar.

### 2.1 Secrets e Credenciais

O usuário precisa providenciar/gerar:

| # | Item | Onde obter | Tipo | Status |
|---|------|------------|------|--------|
| 1 | **Telegram Bot Token** | @BotFather no Telegram | string | ⬜ pendente |
| 2 | **Telegram Webhook Secret Token** | `openssl rand -hex 32` (gerar local) | string hex 64 chars | ⬜ pendente |
| 3 | **Telegram Chat ID do dono** | @userinfobot ou similar | integer | ⬜ pendente |
| 4 | **DeepSeek API Key** | https://platform.deepseek.com | string | ⬜ pendente |
| 5 | **Gemini API Key** (AI Studio) | https://aistudio.google.com/app/apikey | string | ⬜ pendente |
| 6 | **Google Cloud Project ID** | https://console.cloud.google.com | string | ⬜ pendente |
| 7 | **Google Service Account JSON** | IAM → Service Accounts → Create Key | arquivo JSON | ⬜ pendente |
| 8 | **Google Sheet ID** | URL da planilha (`/d/<ID>/edit`) | string | ⬜ pendente |
| 9 | **Firestore Database** | Firestore → Create database (Native mode) | serviço provisionado | ⬜ pendente |

### 2.2 Configurações GCP

- [ ] Projeto GCP criado e billing habilitado
- [ ] APIs habilitadas: Cloud Run API, Cloud Build API, Cloud Scheduler API, Firestore API, Secret Manager API, Sheets API
- [ ] Service Account com roles: `Sheets Editor` (no nível da planilha) + `Cloud Datastore User` (no projeto)
- [ ] Planilha Google Sheets criada (nome sugerido: `Controle Financeiro`) e **compartilhada** com o email da Service Account
- [ ] Cloud SDK (`gcloud`) instalado localmente para deploy

> #### 🔧 Estado real do provisionamento (executado em 2026-06-17)
>
> Os pré-requisitos acima foram provisionados com **desvios** em relação ao checklist genérico. O implementador do **M5 (Firestore)** e do **M10 (deploy)** DEVE observar:
>
> 1. **Firestore Database** — o `(default)` foi criado em **Datastore mode** (incompatível com `google/cloud-firestore`). Em vez de recriá-lo, criamos um banco **nomeado** `wallet-track-db` em **Native mode** (`us-central1`). O app DEVE apontar para ele via `FIRESTORE_DATABASE_ID=wallet-track-db` no `.env` e passar `databaseId` ao construir o `FirestoreClient` em `FirestoreService` (M5.1).
> 2. **Role da Service Account** — `roles/datastore.user` (o que diz o checklist acima) **NÃO funciona** em bancos Native nomeados (apenas no `(default)`/Datastore). Concedemos `roles/editor` na SA `google-sheet-202@wallet-track-499719.iam.gserviceaccount.com`. Para um bot pessoal de 1 usuário, o privilégio amplificado é aceitável; rever para role mínima se o projeto ganhar múltiplos usuários.
> 3. **APIs habilitadas** — `firestore`, `sheets`, `run`, `cloudscheduler`, `cloudbuild`, `secretmanager`, `cloudresourcemanager`, `generativelanguage` (Gemini), todas ✅.
> 4. **Planilha Sheets** — compartilhada manualmente com o email da SA como **Editor** (permissão Drive-level, não IAM).
> 5. **Telegram** — webhook secret gerado; `chat_id` do dono = `5672987197`. O registro do webhook URL só ocorre após deploy (M10) ou ngrok (dev).

### 2.3 Ambiente Local

- [ ] PHP 8.4 instalado
- [ ] Composer 2.x instalado
- [ ] Docker + Docker Compose (para teste local do build)
- [ ] Git configurado (`user.name`, `user.email`)
- [ ] FrankenPHP disponível para teste local (opcional: `php artisan octane:start` direto)

### 2.4 Variáveis de ambiente

Arquivo `.env` (gerado em M0, valores preenchidos com os secrets acima):

```bash
# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET_TOKEN=
TELEGRAM_ALLOWED_CHAT_IDS=
TELEGRAM_WEBHOOK_URL=

# DeepSeek
DEEPSEEK_API_KEY=
DEEPSEEK_BASE_URL=https://api.deepseek.com
DEEPSEEK_MODEL=deepseek-v4-flash

# Gemini
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash

# Google Cloud
GOOGLE_CLOUD_PROJECT_ID=
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/path/to/sa.json  # local; em prod via Secret Manager
GOOGLE_SHEETS_SPREADSHEET_ID=
GOOGLE_SHEETS_SHEET_NAME=Transações

# App
APP_ENV=local
APP_DEBUG=true
APP_KEY=                   # gerado em M0
APP_URL=http://localhost:8000
LOG_CHANNEL=stack
SYNC_MAX_RETRIES=3
SESSION_TIMEOUT_MINUTES=15
```

---

## 3. Milestone M0 — Setup & Viability Gate (3 dias)

### 3.1 Objetivo

Inicializar o projeto Laravel 13, validar que **toda a stack é compatível** (PHP 8.4 + Laravel 13 + FrankenPHP + Octane + todos os pacotes) e ter um "Hello World" rodando localmente em Docker com FrankenPHP/Octane.

### 3.2 Dependências

- Pré-M0 (secrets + GCP) concluído
- PHP 8.4 e Docker instalados localmente

### 3.3 Tarefas

| # | Tarefa | Detalhes | Saída |
|---|--------|----------|-------|
| M0.1 | `git init` no projeto | Repo local; configurar `.gitignore` com `vendor/`, `.env`, `storage/*.key`, `gcloud-credentials.json` | Repositório inicializado |
| M0.2 | Criar app Laravel 13 | `composer create-project laravel/laravel:^13.0 .` (diretório vazio) | Esqueleto Laravel |
| M0.3 | **GATE DE VIABILIDADE**: validar compatibilidade | Em um script `bin/check-viability.sh`: instalar todos os pacotes planejados e rodar `composer validate`. Se algum pacote falhar o requisito PHP/Laravel, abortar. | Documento `docs/viability-report.md` |
| M0.4 | Instalar pacotes core | `composer require laravel/octane nutgram/nutgram:^4.0 openai-php/client google-gemini-php/client:^2.7 google/apiclient:^2.0 google/cloud-firestore:^1.0` | `composer.json` com deps |
| M0.5 | Instalar Octane com FrankenPHP | `php artisan octane:install --server=frankenphp` | `config/octane.php` |
| M0.6 | Criar `Dockerfile` | Base: `dunglas/frankenphp:1.4-php8.4-bookworm`. Multi-stage. Caddyfile inline. `install-php-extensions` para `gmp, bcmath, intl, opcache, grpc, protobuf` | `Dockerfile` + `.dockerignore` |
| M0.7 | Smoke test local | `php artisan octane:start --port=8000` → `curl http://localhost:8000/health` → `{"status":"ok"}` | Endpoint `/health` funcional |
| M0.8 | Configurar health check mínimo | Rota `GET /health` retorna JSON `{status:"ok", timestamp, version}` | Controller ou closure |
| M0.9 | Configurar logs estruturados | `LOG_CHANNEL=stderr` em prod; logs JSON via `config/logging.php` para Cloud Run | Logs consumíveis pelo Cloud Logging |
| M0.10 | Setup de testes | `php artisan test` (PHPUnit já vem com Laravel). Criar `tests/Unit/ExampleTest.php` verde. | Test runner funcional |

### 3.4 Pacotes a instalar (versões-alvo)

```json
{
  "php": "^8.4",
  "laravel/framework": "^13.0",
  "laravel/octane": "^2.0",
  "nutgram/nutgram": "^4.0",
  "openai-php/client": "^0.10",
  "google-gemini-php/client": "^2.7",
  "google/apiclient": "^2.15",
  "google/cloud-firestore": "^1.0"
}
```

### 3.5 Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| **Laravel 13 + algum pacote incompatível** (ex: `google/apiclient`) | Baixa | **CRITICAL** (bloqueia projeto) | GATE DE VIABILIDADE no M0.3 — se falhar, voltar para spec-designer e buscar alternativas (ex: downgrade pontual de pacote). |
| **FrankenPHP build não roda em Apple Silicon** | Média | Baixa | Usar `docker buildx`; testar em CI antes de declarar pronto |
| **`install-php-extensions` falha em CI** | Baixa | Média | Fixar versão do `dunglas/frankenphp`; documentar extensões obrigatórias |

### 3.6 Critérios de Aceitação (M0 Done)

- [ ] `composer install` passa sem erros
- [ ] `php artisan octane:start` sobe FrankenPHP worker
- [ ] `curl http://localhost:8000/health` retorna `200 OK` com JSON válido
- [ ] `docker build -t wallet-track:dev .` produz imagem sem erro
- [ ] `docker run -p 8000:8000 wallet-track:dev` expõe `/health`
- [ ] `php artisan test` passa (suite inicial)
- [ ] `docs/viability-report.md` documenta que TODOS os pacotes instalaram sem conflito de versão

### 3.7 Entregáveis

- Repositório Git inicializado
- `Dockerfile` funcional
- `composer.json` com todas as deps
- `docs/viability-report.md`
- `.env.example` documentado

---

## 4. Milestone M1 — Telegram Bot Skeleton (1 dia)

### 4.1 Objetivo

Ter o bot respondendo a `/start` e `/help` via webhook HTTPS no Octane, com o SDK Nutgram configurado e os handlers registrados.

### 4.2 Dependências

- M0 concluído

### 4.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M1.1 | Configurar Nutgram em `config/telegram.php` | Provider + bot instance singleton |
| M1.2 | Criar `app/Conversations/` com classes para cada comando | `StartConversation`, `HelpConversation` |
| M1.3 | Criar `App\Console\Commands\SetTelegramWebhook` | Artisan command que chama `setWebhook` com URL + secret token |
| M1.4 | Criar rota webhook `POST /webhook/telegram` | Controller chama `$bot->run()` em fila ou síncrono |
| M1.5 | Implementar handlers de `/start` e `/help` | Mensagens de boas-vindas em PT-BR |
| M1.6 | Testar local com ngrok ou Cloud Run tunneling | Validar webhook ponta a ponta |
| M1.7 | Smoke test: enviar `/start` e `/help` reais | Bot responde corretamente |

### 4.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Nutgram não roda em Octane (worker mode) | Testar em M0 com handler dummy; Nutgram declara suporte a long-running workers |
| Webhook URL muda entre deploys | Cloud Run gera URL estável; Cloud Scheduler usa URL fixa |

### 4.5 Critérios de Aceitação

- [ ] `php artisan telegram:set-webhook` registra webhook no Telegram
- [ ] `POST /webhook/telegram` com payload válido responde `200 OK` em < 500ms
- [ ] `/start` no Telegram retorna mensagem de boas-vindas formatada
- [ ] `/help` lista todos os comandos (mesmo os ainda não implementados)
- [ ] Logs estruturados registrando cada update recebido

---

## 5. Milestone M2 — Segurança do Webhook (1 dia)

### 5.1 Objetivo

Implementar as 3 camadas de segurança do webhook: validação do secret token, whitelist de chat_id, e rate limiting básico.

### 5.2 Dependências

- M1 concluído

### 5.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M2.1 | Criar middleware `ValidateTelegramWebhook` | Compara header `X-Telegram-Bot-Api-Secret-Token` com `TELEGRAM_WEBHOOK_SECRET_TOKEN` |
| M2.2 | Implementar whitelist de chat_id | Middleware extrai `message.from.id` ou `callback_query.from.id` e valida contra `TELEGRAM_ALLOWED_CHAT_IDS` |
| M2.3 | Configurar Nutgram para usar middlewares globais | `Bot::middleware(...)` ou `Conversation::middleware(...)` |
| M2.4 | Implementar logging de tentativas bloqueadas | Log warn com `chat_id` e IP; nunca revelar detalhes ao atacante |
| M2.5 | Testes automatizados (CT-034, CT-035, CT-036) | PHPUnit cobrindo 3 cenários: sem token, token inválido, chat_id não-whitelisted |

### 5.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Falsos positivos (usuário bloqueado por engano) | Whitelist configurável via env; documentar processo de update |
| Replay attack (mesmo update_id reenviado) | Telegram já deduplica; documentar comportamento |

### 5.5 Critérios de Aceitação

- [ ] CT-034: Webhook sem `X-Telegram-Bot-Api-Secret-Token` → `401 Unauthorized`
- [ ] CT-035: Webhook com token inválido → `401 Unauthorized`
- [ ] CT-036: Webhook com chat_id fora da whitelist → `403 Forbidden`
- [ ] Tentativas bloqueadas aparecem em log com severidade `warning`
- [ ] `vendor/bin/phpunit --filter SecurityTest` 100% verde

---

## 6. Milestone M3 — DeepSeek (Texto → JSON) (2 dias)

### 6.1 Objetivo

Implementar a extração de transações a partir de texto livre usando DeepSeek v4-flash, retornando um DTO `TransactionData` validado.

### 6.2 Dependências

- M1 (bot responde a mensagens)
- M2 (webhook seguro)

### 6.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M3.1 | Criar `App\Dto\TransactionData` | Readonly class: `date, description, amount, type, category, labels[], observations, confidence?` |
| M3.2 | Criar `App\Services\DeepSeek\DeepSeekService` | Wrapper sobre `openai-php/client`; métodos `extract(string $text): TransactionData` |
| M3.3 | Definir prompt do sistema | Constante em `config/deepseek.php` ou `resources/prompts/text-extraction.php`. Ver Seção 8.1 da Especificação Técnica. |
| M3.4 | Implementar `App\Actions\ExtractFromText` | Action que recebe texto, chama DeepSeek, valida, retorna DTO |
| M3.5 | Implementar parser de valor robusto | Regex para `R$ 1.234,56`, `45,90`, `R$5,50`, `2000`; aceita `.` milhar e `,` decimal |
| M3.6 | Implementar normalização de data | "hoje" / "ontem" / "anteontem" / DD/MM/YYYY / DD-MM-YYYY / ISO |
| M3.7 | Implementar classificação de tipo | Palavras-chave: "paguei/gastei/comprei" → expense; "recebi/ganhei/salário" → income; ambíguo → null (pergunta depois) |
| M3.8 | Implementar validação de saída | `amount > 0`, `description` não vazia, `date` parseável; lançar `ExtractionException` se falhar |
| M3.9 | Tratar `ExtractionException` → fallback manual | Se DeepSeek falhar, responder com sugestão de usar `/nova` |
| M3.10 | Testes com fixtures (CT-001 a CT-006b) | PHPUnit com 7 casos; pode usar mock do DeepSeek ou chamadas reais em CI |

### 6.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| DeepSeek retorna JSON malformado | `response_format: json_object` no request; try/catch + log + fallback |
| Latência alta (cold start) | Cache de prompt do sistema; monitorar tempo |
| Custo por chamada | Em uso pessoal ~30-50 chamadas/mês, aceitável; documentar |

### 6.5 Critérios de Aceitação

- [ ] CT-001 (despesa completa) parseia corretamente
- [ ] CT-002 (receita) classifica como `income`
- [ ] CT-003 (sem valor) detecta falta e dispara pedido
- [ ] CT-004 (tipo ambíguo) retorna `type=null` e ação externa pergunta
- [ ] CT-005 ("ontem") calcula data corretamente
- [ ] CT-006 / CT-006b (formatos BR) parseiam todos os formatos
- [ ] Fallback funciona: API key inválida → bot sugere `/nova` (CT-037)
- [ ] `vendor/bin/phpunit --filter TextExtraction` 100% verde

---

## 7. Milestone M4 — Gemini (Imagem → JSON) (2 dias)

### 7.1 Objetivo

Implementar OCR multimodal de notas fiscais via Gemini 2.5 Flash, retornando `TransactionData` com 1 chamada (imagem + JSON estruturado).

### 7.2 Dependências

- M3 (DTO e padrões de validação)

### 7.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M4.1 | Criar `App\Services\Gemini\GeminiService` | Wrapper sobre `google-gemini-php/client`; método `extractFromImage(string $base64, string $mimeType): TransactionData` |
| M4.2 | Definir `responseSchema` para Gemini | Estrutura JSON com `description, amount, type, category, labels[], date, observations`. Ver Seção D da Revisão v2. |
| M4.3 | Definir prompt multimodal | Constante em `resources/prompts/image-ocr.php`. Regras: pegar TOTAL, não inventar dados, ilegível → null |
| M4.4 | Implementar `App\Actions\ExtractFromImage` | Recebe file_id do Telegram, baixa via `getFile`, codifica base64, chama Gemini |
| M4.5 | Implementar feedback de progresso em 4 etapas | Action que edita mensagem Telegram em 4 estágios (decisão #8 das clarificações) |
| M4.6 | Implementar fallback para imagem não-fiscal | Se Gemini retornar campos críticos `null` → admitir falha + oferecer texto (decisão #1) |
| M4.7 | Testes com imagens reais (CT-007 a CT-010) | Suite de imagens em `tests/fixtures/notes/`; PHPUnit chama Gemini real (gated por env) |

### 7.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Gemini 2.5 ainda em preview e instável | Fallback documentado: `gemini-2.0-flash` (GA estável) |
| Imagem muito grande excede memória (512MB) | Telegram limita upload em 20MB; Cloud Run 512MB suficiente; resize server-side se necessário |
| Latência > 10s | Cloud Run timeout 300s + feedback em 4 etapas cobre UX |

### 7.5 Critérios de Aceitação

- [ ] CT-007: Nota fiscal legível → extrai total, descrição, data
- [ ] CT-008: Foto borrada → admite falha + oferece alternativa
- [ ] CT-009: Foto de cachorro → identifica que não é transação
- [ ] CT-010: Múltiplos valores → pega **total**, não parcial
- [ ] Feedback em 4 etapas visível no Telegram (CT-038)
- [ ] `vendor/bin/phpunit --filter ImageExtraction` 100% verde (com flag `--group=integration`)

---

## 8. Milestone M5 — Firestore (Modelo de Dados + Service) (2 dias)

### 8.1 Objetivo

Implementar a camada de persistência no Firestore com 4 collections: `transactions`, `categories`, `labels`, `sessions`.

### 8.2 Dependências

- M3 (DTO `TransactionData`)

### 8.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M5.1 | Criar `App\Services\Google\FirestoreService` | Wrapper sobre `google/cloud-firestore`; métodos `saveTransaction, getSession, setSession, getCategories, incrementLabelUse` |
| M5.2 | Configurar autenticação Service Account | Path local em dev (`GOOGLE_SERVICE_ACCOUNT_JSON_PATH`); Secret Manager em prod (placeholder para M10) |
| M5.3 | Implementar seed de categorias padrão | Collection `categories` inicializada com: Alimentação, Transporte, Moradia, Saúde, Educação, Lazer, Salário, Freelance, Outros |
| M5.4 | Implementar CRUD de transações | `create`, `getById`, `listRecent(chatId, n)`, `updateSyncStatus` |
| M5.5 | Implementar gestão de sessões | `getSession(chatId)`, `setSession(chatId, state, draft)`, `clearSession(chatId)` |
| M5.6 | Implementar tracking de labels | `incrementLabelUse(name)`, `getTopLabels(category, limit=10)` |
| M5.7 | Implementar queries compostas | `transactions` ordenado por `(type, date DESC)`, `labels` por `use_count DESC` |
| M5.8 | Testes com Firestore emulator (CT-031) | PHPUnit usa `firestore-emulator` via docker-compose em CI |

### 8.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Custo de read/write | Uso pessoal ~50-100 ops/mês, insignificante; documentar |
| Service Account em prod (não local) | Abstração `Credentials` permite trocar path/env em runtime (M10 finaliza) |
| Cold start Firestore client | Singleton, criado no boot do Octane |

### 8.5 Critérios de Aceitação

- [ ] CT-031: Transação confirmada aparece no Firestore com todos os campos
- [ ] Seed de categorias roda na primeira inicialização (idempotente)
- [ ] `/ultimos` lê de Firestore com ordenação correta
- [ ] `vendor/bin/phpunit --filter FirestoreTest` 100% verde (com emulator)

---

## 9. Milestone M6 — Google Sheets (Service + Append) (2 dias)

### 9.1 Objetivo

Gravar transações na planilha Google Sheets via Service Account, com criação de cabeçalhos e aba "Categorias" auxiliar.

### 9.2 Dependências

- M5 (Firestore pronto)

### 9.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M6.1 | Criar `App\Services\Google\SheetsService` | Wrapper sobre `google/apiclient`; métodos `appendTransaction, ensureHeaders, syncCategories` |
| M6.2 | Implementar `ensureHeaders` | Verifica linha 1; se vazia, escreve cabeçalhos: Data, Descrição, Valor, Tipo, Categoria, Labels, Origem, ID Firestore, Observações |
| M6.3 | Implementar `appendTransaction` | `values.append` com `valueInputOption=USER_ENTERED` para Sheets interpretar data como data |
| M6.4 | Criar `App\Actions\SyncSheet` | Action que recebe DTO + firestoreId; chama SheetsService; atualiza `sync_status` no Firestore |
| M6.5 | Implementar tratamento de erro | Capturar `Google_Service_Exception`; retornar `false` (não throw) para que o caller marque como `pending` |
| M6.6 | Configurar formatação de colunas (opcional M6, polish M10) | Data `DD/MM/AAAA`, Valor `R$ #.##0,00`, freeze linha 1 |
| M6.7 | Testes com Sheets de staging (CT-030, CT-032) | Suite usa planilha dedicada em GCP project de teste |

### 9.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Permissões da Service Account | Script de validação: `php artisan sheets:check-access` |
| Latência alta de API Sheets | Aceitável (~500ms); sync é em background |
| Planilha compartilhada removida | Health check + `failed` status + notificação |

### 9.5 Critérios de Aceitação

- [ ] CT-030: Transação confirmada aparece na planilha com 9 colunas corretas
- [ ] CT-032: Sheets indisponível → Firestore `sync_status=failed` (com `sync_attempts` incrementado), sem erro 500
- [ ] Cabeçalhos criados automaticamente na primeira execução
- [ ] `vendor/bin/phpunit --filter SheetsTest` 100% verde

---

## 10. Milestone M7 — Máquina de Estados do Bot (2 dias)

### 10.1 Objetivo

Implementar a máquina de estados (IDLE → AWAITING_DATA → AWAITING_CONFIRMATION → AWAITING_EDITION) com router que decide a ação baseado em input + estado.

### 10.2 Dependências

- M3, M4 (extração)
- M5 (sessões no Firestore)
- M6 (gravação Sheets)

### 10.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M7.1 | Criar `App\Enums\ConversationState` | `IDLE, AWAITING_DATA, AWAITING_CONFIRMATION, AWAITING_EDITION` |
| M7.2 | Criar `App\Conversation\StateMachine` | Métodos `canTransition(from, to)`, `transition(chatId, newState)` |
| M7.3 | Criar `App\Conversation\Router` | Recebe `Update $update`; carrega sessão; decide ação |
| M7.4 | Implementar fluxo de texto natural (happy path) | IDLE + texto → Extract → CONFIRM |
| M7.5 | Implementar fluxo de AWAITING_DATA | Resposta a pergunta de campo faltante → preenche → re-avalia |
| M7.6 | Implementar fluxo de AWAITING_CONFIRMATION | Callbacks: `confirm`, `edit`, `cancel` |
| M7.7 | Implementar fluxo de AWAITING_EDITION | Callback `edit:<field>` → pergunta novo valor → atualiza draft |
| M7.8 | Implementar timeout de sessão (15 min) | Verificado em `Router::route()`; se sessão > 15min, limpa e volta a IDLE |
| M7.9 | Implementar idempotência em confirmar | Campo `processing: bool` na sessão; transação atômica no Firestore |
| M7.10 | Implementar tratamento de callback antigo (CT-047) | Validar `message_id_confirm` (X) E `message_id_edit_picker` (Y) no callback; rejeitar se nenhum bate; deleção do picker em edit:<field>, confirm e cancel |
| M7.11 | Testes (CT-015 a CT-018b) | PHPUnit cobrindo todos os fluxos |

### 10.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Race conditions em transições | Firestore `arrayUnion` + locks; testes de concorrência |
| Mensagens fora de ordem | Cada update tem `update_id`; Nutgram processa em ordem |
| Estado órfão (sessão abandonada) | Limpeza automática após 15min; cron job opcional em M10 |

### 10.5 Critérios de Aceitação

- [ ] CT-015: Confirmar grava Sheets + Firestore
- [ ] CT-016: Editar campo atualiza resumo
- [ ] CT-017: Cancelar limpa sessão, IDLE
- [ ] CT-018: Duplo clique → 1 transação
- [ ] CT-018b: Confirmar após 16 min → "Sessão expirada"
- [ ] CT-043: Timeout 15min funcional
- [ ] CT-047: Callback antigo rejeitado
- [ ] `vendor/bin/phpunit --filter StateMachineTest` 100% verde

---

## 11. Milestone M8 — Heurística de Labels + Categoria (2 dias)

### 11.1 Objetivo

Implementar a sugestão de labels (PHP puro, sem LLM) e a sugestão/criação de categoria com fuzzy match.

### 11.2 Dependências

- M5 (Firestore com histórico de labels)

### 11.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M8.1 | Criar `App\Actions\SuggestLabels` | Implementar algoritmo da Seção 4 das Clarificações: histórico prioritário + keywords, máx 5 |
| M8.2 | Criar `App\Support\Stopwords` | Array de stopwords PT-BR + helper `extractKeywords($description): array` |
| M8.3 | Implementar normalização | `Normalizer::normalize(FORM_KD)` para remoção de acentos |
| M8.4 | Criar `App\Actions\SuggestCategory` | Fuzzy match com `levenshtein()` ou similar; threshold 0.7; senão sugere "Outros" |
| M8.5 | Implementar criação de categoria | Se usuário confirma "criar nova", salva em `categories/{name_lowercase}` |
| M8.6 | Implementar tracking de uso de label | Após gravar transação, incrementar `use_count` em `labels/{name}` |
| M8.7 | Testes (CT-019 a CT-022, CT-011, CT-012) | PHPUnit com fixtures de histórico |

### 11.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| Algoritmo muito lento em produção | `labels` collection indexada por `use_count`; query O(log n) |
| Sugestão irrelevante | Usuário pode editar antes de confirmar; nunca auto-aplica |
| Categoria ambígua | Sempre pede confirmação; nunca assume |

### 11.5 Critérios de Aceitação

- [ ] CT-019: Histórico da categoria sugerido primeiro
- [ ] CT-020: Keywords da descrição complementam (sem stopwords, min 3 chars)
- [ ] CT-021: Usuário pode editar e labels não-sugeridas funcionam
- [ ] CT-022: Labels recorrentes (2+ usos) são priorizadas
- [ ] CT-011: Categoria sugerida, editável para nova
- [ ] CT-012: Categoria nova persistida
- [ ] `vendor/bin/phpunit --filter LabelsTest CategoryTest` 100% verde

---

## 12. Milestone M9 — Comandos Auxiliares (3 dias)

### 12.1 Objetivo

Implementar todos os comandos: `/start`, `/help`, `/nova` (wizard), `/cancelar`, `/ultimos [n]`, `/categorias`, `/sync`.

### 12.2 Dependências

- M7 (state machine)
- M8 (categorias)

### 12.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M9.1 | Implementar `/start` (CT-023) | Mensagem de boas-vindas com exemplos; reseta sessão para IDLE |
| M9.2 | Implementar `/help` (CT-024) | Lista todos os comandos com descrição e exemplo |
| M9.3 | Implementar `/nova` (CT-025) | Wizard passo-a-passo (ver Seção 5 das Clarificações): Tipo→Valor→Descrição→Categoria→Labels |
| M9.4 | Implementar `/cancelar` (CT-026) | Em qualquer estado, volta a IDLE; em IDLE, "Nada para cancelar" |
| M9.5 | Implementar `/ultimos [n]` (CT-027, CT-028) | Default 5; máx 50; fallback silencioso se parâmetro inválido |
| M9.6 | Implementar `/categorias` (CT-029) | Lista categorias padrão + personalizadas com contador de uso |
| M9.7 | Implementar `/sync` (decisão #7) | Dispara `transactions:sync-pending` imediatamente; reseta contador |
| M9.8 | Criar `App\Console\Commands\SyncPendingTransactions` | Artisan command; query Firestore `pending` AND `attempts<3`; tenta Sheets; atualiza status |
| M9.9 | Criar rota `GET /cron/sync-pending` | Protegida por `CRON_SECRET_TOKEN` (variável de ambiente) |
| M9.10 | Testes de todos os comandos | PHPUnit cobrindo CT-023 a CT-029 |

### 12.4 Riscos

| Risco | Mitigação |
|-------|-----------|
| `/nova` ser longo demais | Atalhos: usuário pode digitar tudo de uma vez; DeepSeek tenta extrair primeiro |
| `/ultimos` com `n=999999` retornar muita coisa | Cap em 50 |
| `/sync` manual concorrer com cron | Lock atômico no Firestore (`processing=true` enquanto roda) |

### 12.5 Critérios de Aceitação

- [ ] CT-023 a CT-029: todos os comandos respondem corretamente
- [ ] CT-033: Cron recupera pendentes em 5min
- [ ] `/sync` reseta contador e força tentativa
- [ ] Após 3 falhas: `sync_status=failed` + notificação ao usuário
- [ ] `vendor/bin/phpunit --filter CommandsTest` 100% verde

---

## 13. Milestone M10 — Deploy Cloud Run (3 dias)

### 13.1 Objetivo

Deploy em produção no Google Cloud Run, com CI/CD, observabilidade e todas as configurações de runtime alinhadas à especificação.

### 13.2 Dependências

- M0–M9 concluídos e testados localmente

### 13.3 Tarefas

| # | Tarefa | Detalhes |
|---|--------|----------|
| M10.1 | Aprimorar `Dockerfile` para produção | Multi-stage build; `--no-dev` no composer install; `--optimize-autoloader`; minimizar layers |
| M10.2 | Otimizar Caddyfile/FrankenPHP | Worker mode; `num_threads` baseado em CPU disponível; `max_reqs` para reciclar worker |
| M10.3 | Criar `cloudbuild.yaml` | Pipeline: build → push para Artifact Registry → deploy Cloud Run |
| M10.4 | Configurar Secret Manager | Mover Service Account JSON e API keys para Secret Manager; Cloud Run monta como volume ou env |
| M10.5 | Configurar Service Account do Cloud Run | Workload identity com permissões para Secret Manager + Firestore + Sheets |
| M10.6 | Deploy manual inicial | `gcloud run deploy wallet-track --source . --region=southamerica-east1 --memory=512Mi --cpu=1 --concurrency=1 --min-instances=0 --max-instances=1 --timeout=300 --no-cpu-throttling --cpu-boost` |
| M10.7 | Registrar webhook Telegram | Após deploy, executar `php artisan telegram:set-webhook` apontando para URL do Cloud Run |
| M10.8 | Configurar Cloud Scheduler | Cron job `*/5 * * * *` chamando `GET /cron/sync-pending` com header `X-Cron-Token: <secret>` |
| M10.9 | Configurar health check robusto | `GET /health` em prod verifica: Firestore ping, Sheets ping, env vars críticas presentes |
| M10.10 | Configurar alertas | Cloud Monitoring: error rate > 5%, latency p95 > 5s, memory > 80% |
| M10.11 | Configurar domínio customizado (opcional) | Mapear `wallet-track.seu-dominio.com` via Cloud Run domain mappings |
| M10.12 | Documentar runbook | `docs/runbook.md` com troubleshooting (bot não responde, sync falhou, etc.) |
| M10.13 | Smoke test em produção | Executar CT-001, CT-007, CT-015, CT-023 no bot de produção; verificar planilha e Firestore |
| M10.14 | Configurar Uptime Check (opcional) | Blackbox monitor em `/health` a cada 1min; alerta se down por > 3min |

### 13.4 Configurações Cloud Run (finais)

| Parâmetro | Valor |
|-----------|-------|
| Memória | 512 MiB |
| CPU | 1 vCPU |
| Concurrency | 1 |
| Min instances | 0 |
| Max instances | 1 |
| Timeout | 300s |
| CPU throttling | Desabilitado |
| Startup CPU boost | Habilitado |
| Region | `southamerica-east1` (São Paulo) |
| Service Account | workload-identity-bound |
| Secrets | via Secret Manager (mount) |

### 13.5 Riscos

| Risco | Mitigação |
|-------|-----------|
| Cold start longo (~5s) | Startup CPU boost reduz; aceito para uso pessoal |
| Custo de saída Sheets API | Mínimo (uso pessoal) |
| Logs do Cloud Run não aparecem | Verificar `LOG_CHANNEL=stderr` em `config/logging.php` |
| Webhook secret exposto em URL | Telegram aceita secret apenas no header, OK |
| Permissões da Service Account em prod | Workload identity + roles mínimos |

### 13.6 Critérios de Aceitação (M10 Done)

- [ ] Bot responde a `/start` em produção em < 5s (incluindo cold start)
- [ ] Planilha Google Sheets é atualizada em produção
- [ ] Firestore grava transações com `sync_status=synced`
- [ ] Cloud Scheduler executa `/cron/sync-pending` a cada 5min
- [ ] Logs estruturados aparecem em Cloud Logging
- [ ] Health check retorna 200 com checks internos
- [ ] `docs/runbook.md` publicado
- [ ] Smoke test em produção: CT-001, CT-007, CT-015, CT-023 verdes

---

## 14. Resumo de Esforço

| Milestone | Descrição | Dias | Dependência |
|-----------|-----------|------|-------------|
| **M0** | Setup & Viability Gate | 3 | — |
| **M1** | Telegram Bot Skeleton | 1 | M0 |
| **M2** | Segurança do Webhook | 1 | M1 |
| **M3** | DeepSeek (Texto) | 2 | M1, M2 |
| **M4** | Gemini (Imagem) | 2 | M3 |
| **M5** | Firestore | 2 | M3 |
| **M6** | Google Sheets | 2 | M5 |
| **M7** | State Machine | 2 | M3, M4, M5, M6 |
| **M8** | Heurística Labels/Categoria | 2 | M5 |
| **M9** | Comandos Auxiliares | 3 | M7, M8 |
| **M10** | Deploy Cloud Run | 3 | M0–M9 |
| **TOTAL** | | **23** | |

> **Buffer recomendado:** +4 dias (~15%) para imprevistos, refatorações, debugging de integração. Total real: **~27 dev-dias** (≈ 6,5 semanas).

---

## 15. Estratégia de Teste por Milestone

| M | Tipo | Cobertura |
|---|------|-----------|
| M0 | Smoke + Viability | `composer install`, `/health`, `docker build` |
| M1 | Integração | Webhook ponta a ponta com ngrok |
| M2 | Segurança | PHPUnit: CT-034, CT-035, CT-036 |
| M3 | Unit + Integração | PHPUnit: CT-001 a CT-006b, CT-037 (com flag) |
| M4 | Integração | PHPUnit: CT-007 a CT-010, CT-038 (com flag) |
| M5 | Integração | PHPUnit com Firestore emulator: CT-031 |
| M6 | Integração | PHPUnit: CT-030, CT-032 |
| M7 | Unit + Integração | PHPUnit: CT-015 a CT-018b, CT-043, CT-047 |
| M8 | Unit | PHPUnit: CT-011, CT-012, CT-019 a CT-022 |
| M9 | Integração | PHPUnit: CT-023 a CT-029, CT-033 |
| M10 | E2E (manual) | Smoke test em produção: CT-001, CT-007, CT-015, CT-023 |

**Flag de CI:** testes marcados com `@group integration` rodam apenas em pipeline (com credenciais reais), não em commit local.

---

## 16. Plano de Execução Sugerido (1 dev)

### Semana 1
- Dias 1-3: **M0** (setup + viabilidade)
- Dias 4-5: **M1** + **M2** (bot + segurança)

### Semana 2
- Dias 6-7: **M3** (DeepSeek)
- Dias 8-9: **M4** (Gemini)
- Dia 10: **M5** (início Firestore)

### Semana 3
- Dias 11-12: **M5** + **M6** (Firestore + Sheets)
- Dias 13-14: **M7** (state machine)
- Dia 15: **M8** (heurística)

### Semana 4
- Dias 16-18: **M9** (comandos)
- Dias 19-21: **M10** (deploy)
- Dias 22-23: **Buffer** + smoke test em prod + ajustes

---

## 17. Riscos Globais do Plano

| Risco | Prob. | Impacto | Mitigação |
|-------|-------|---------|-----------|
| **GATE de viabilidade M0 falhar** | Baixa | **CRITICAL** | Voltar para spec-designer; buscar pacotes alternativos |
| **Mudança de breaking change em pacote** (Laravel 13, Gemini SDK) | Média | Média | Pinar versões exatas; revisar release notes antes de upgrade |
| **Tempo real > 27 dias** | Média | Baixa | Buffer de 4 dias; cortar features não-essenciais se necessário (e.g., `/sync` manual pode ficar para depois) |
| **Gemini 2.5 instável** | Baixa | Média | Fallback `gemini-2.0-flash` documentado |
| **Custo DeepSeek/Gemini acima do esperado** | Baixa | Baixa | Uso pessoal, ~$1-3/mês aceitável; monitorar |
| **Webhook do Telegram lento** | Muito Baixa | Baixa | Resposta 200 imediata via `app()->terminating()`; não impacta Telegram |

---

## 18. Definition of Done Global

- [ ] Todos os milestones M0–M10 com critérios de aceitação ✅
- [ ] Todos os 47 casos de teste (CT-001 a CT-047) executados com status PASS
- [ ] Bot respondendo em produção (URL do Cloud Run)
- [ ] Planilha Google Sheets sendo alimentada
- [ ] Firestore com `sync_status=synced` para todas as transações
- [ ] `/sync` funcionando e Cloud Scheduler ativo
- [ ] Logs estruturados em Cloud Logging
- [ ] `docs/runbook.md` publicado
- [ ] `README.md` na raiz do projeto com instruções de uso
- [ ] Última revisão do `reviewer` com zero CRITICALs

---

## Próximos Passos Imediatos

1. ✅ Usuário coleta todos os secrets listados em §2
2. ✅ Usuário cria projeto GCP + planilha + Firestore (PRÉ-M0)
3. ⏩ **Iniciar M0** delegando ao agente `coder` com este plano como entrada
4. ⏩ Após M0, o `reviewer` valida o GATE de viabilidade
5. ⏩ Continuar com M1–M10 sequencialmente
