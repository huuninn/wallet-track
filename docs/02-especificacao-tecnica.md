# 02 — Especificação Técnica

> **Fase 2 do pipeline.** Versão consolidada integrando a Revisão v2 (Laravel 13 + Gemini OCR). Aprovada pelo usuário.

---

## 1. Modelos de IA

### 1.1 Arquitetura com 2 provedores

| Provedor | Modelo | Função | Input | Output | Via |
|----------|--------|--------|-------|--------|-----|
| **DeepSeek** | `deepseek-v4-flash` | Processamento de texto (NLU, parse) | Texto | JSON | `openai-php/client` (endpoint OpenAI-compatível) |
| **Google Gemini** | `gemini-2.5-flash` | OCR multimodal de notas fiscais | Imagem | JSON estruturado | `google-gemini-php/client` (Google AI Studio) |

**Justificativa da escolha do Gemini 2.5 Flash para visão:**
- Multimodal nativo (lê imagem + gera JSON em uma única chamada)
- Suporta `responseSchema` → JSON estruturado garantido
- Flash = baixo custo e baixa latência (< 2s para OCR de nota típica)
- Suporte nativo a PT-BR
- Fallback: `gemini-2.0-flash` (GA estável) se a 2.5 apresentar instabilidade

### 1.2 Configuração

```
DeepSeek:
  Base URL:  https://api.deepseek.com
  Modelo:    deepseek-v4-flash
  JSON mode: response_format: { type: 'json_object' }

Gemini (AI Studio):
  API Key:   https://aistudio.google.com/app/apikey
  Modelo:    gemini-2.5-flash
  Auth:      API Key simples (NÃO service account)
```

---

## 2. Arquitetura de Sistema

### 2.1 Diagrama

```
┌─────────────────────────────────────────────────────────────────────┐
│                        GOOGLE CLOUD PROJECT                         │
│                                                                     │
│  ┌──────────┐     HTTPS       ┌─────────────────────────────────┐  │
│  │ Telegram │ ──────────────► │       CLOUD RUN (Laravel 13)     │  │
│  │  Servers │ ◄────────────── │  FrankenPHP + Octane · PHP 8.4   │  │
│  └──────────┘    sendMessage  │                                  │  │
│                               │  ┌───────────────────────────┐  │  │
│  ┌──────────┐                 │  │  Webhook Controller       │  │  │
│  │ DeepSeek │◄──── REST ─────►│  │  • Valida token+chat_id   │  │  │
│  │   API    │  (texto)        │  │  • Retorna 200 imediato   │  │  │
│  └──────────┘                 │  │  • Processa pós-resposta  │  │  │
│                               │  └───────────────────────────┘  │  │
│  ┌──────────┐                 │                                  │  │
│  │  Gemini  │◄──── REST ─────►│  ┌───────────────────────────┐  │  │
│  │ AI Studio│  (imagem/OCR)   │  │  Conversation StateMachine│  │  │
│  └──────────┘                 │  │  Actions / Services       │  │  │
│                               │  └───────────────────────────┘  │  │
│  ┌──────────┐                 │                                  │  │
│  │  Google  │◄──── REST ─────►│  ┌───────────────────────────┐  │  │
│  │  Sheets  │                 │  │  Firestore Client         │  │  │
│  └──────────┘                 │  └───────────────────────────┘  │  │
│                               └─────────────────────────────────┘  │
│  ┌──────────┐    ┌──────────┐                                      │
│  │ Firestore│    │  Secret  │  ← Service Account JSON              │
│  │ (NoSQL)  │    │ Manager  │                                      │
│  └──────────┘    └──────────┘                                      │
│                                                                     │
│  ┌──────────────┐                                                   │
│  │Cloud Scheduler│ ── cron 5min ──► /cron/sync-pending             │
│  └──────────────┘                                                   │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 Processamento assíncrono — Decisão

**Opção escolhida: Processamento síncrono com resposta 200 imediata + `app()->terminating()`.**

Para 1 único usuário, volume baixo:
1. Webhook recebe update → valida → retorna **200 OK imediato** (< 200ms)
2. `app()->terminating()` (fallback: `register_shutdown_function`) libera a resposta
3. Processamento ocorre na mesma execução; `sendMessage` é chamado via HTTP depois
4. Cloud Run timeout: **300s** (cobre OCR + múltiplas chamadas API)

**Mitigação de falhas:**
- Toda operação crítica envolta em try/catch com fallback
- Google Sheets falha → Firestore salva com `sync_status=pending` → cron recupera
- DeepSeek/Gemini falham → fallback para entrada manual

---

## 3. Stack Tecnológica Detalhada

| Componente | Versão | Justificativa |
|------------|--------|---------------|
| **PHP** | 8.4 | Performance, enums, readonly, match |
| **Laravel** | 13.x | Latest stable (Mar/2026); PHP 8.3–8.5 |
| **FrankenPHP** | 1.4+ | Runtime moderno, worker mode (Octane), Caddy embutido |
| **Octane** | (first-party) | Worker mode, reaproveita bootstrap |

### Pacotes Composer

| Pacote | Versão | Função |
|--------|--------|--------|
| `nutgram/nutgram` | ^4.0 | Telegram Bot SDK — moderno, conversations nativas, Laravel integration |
| `openai-php/client` | ^0.10 | DeepSeek API (compatível OpenAI; `base_url` custom) |
| `google-gemini-php/client` | ^2.7 | Gemini AI Studio (OCR multimodal) |
| `google/apiclient` | ^2.x | Google Sheets API + auth Service Account |
| `google/cloud-firestore` | ^1.x | Firestore Client oficial |
| `laravel/octane` | ^2.x | Worker mode com FrankenPHP |

### Imagem Docker base
`dunglas/frankenphp:1.4-php8.4-bookworm` (oficial). Extensões adicionais (gRPC, protobuf) via `install-php-extensions`.

---

## 4. Estrutura da Planilha Google Sheets

| Item | Valor |
|------|-------|
| **Nome do arquivo** | `Controle Financeiro` |
| **Aba principal** | `Transações` |
| **Aba auxiliar** | `Categorias` (somente leitura, sincronizada do Firestore) |

### Colunas da aba "Transações"

| # | Coluna | Tipo | Exemplo | Obrigatório? |
|---|--------|------|---------|-------------|
| A | **Data** | `DD/MM/AAAA` | `15/06/2026` | ✅ |
| B | **Descrição** | Texto (máx 500 chars) | `Almoço restaurante japonês` | ✅ |
| C | **Valor** | Número (2 casas) | `45.90` | ✅ |
| D | **Tipo** | `Despesa` / `Receita` | `Despesa` | ✅ |
| E | **Categoria** | Texto | `Alimentação` | ✅ |
| F | **Labels** | Hashtags separadas por espaço | `#almoço #japonês` | ❌ |
| G | **ID Firestore** | UUID | `abc123-def456` | ✅ |
| H | **Observações** | Texto | `Pago com cartão` | ❌ |
| I | **Itens** | Texto (multiline, numerado) | `1. Feijão (x2 — R$ 8,50 = R$ 17,00)\n2. Arroz 5kg (x1 — R$ 32,90 = R$ 32,90)` | ❌ |

> **Correção M-ITENS-7:** a documentação anterior estava divergente do código (colunas G/H/I trocadas). A tabela acima reflete a implementação real: G=ID Firestore, H=Observações, I=Itens. A coluna "Origem" (`source`) não é exposta na planilha (rastreada internamente no Firestore).

**Coluna I — Itens (detalhamento item-nível):**
- Cada transação pode ter 0 ou mais itens descritivos (produtos de um cupom fiscal, por exemplo).
- Itens são numerados e separados por quebra de linha (`\n`) dentro da célula.
- Ordenação por subtotal crescente; itens sem preço ao final, na ordem de entrada.
- Formato por linha: `N. Nome (xQtd — R$ Unit = R$ Sub)` — exibe qty e preço apenas quando informados.
- Exemplo com 3 itens:
  ```
  1. Bolsa plástica (x1 — R$ 0,50 = R$ 0,50)
  2. Detergente (x3 — R$ 4,50 = R$ 13,50)
  3. Arroz 5kg (x1 — R$ 32,90 = R$ 32,90)
  ```
- Exemplo com item só-nome: `1. Feijão` (sem parênteses quando qty/preço não informados).

**Idempotência do `ensureHeaders`:** o método só escreve cabeçalhos se a linha 1 estiver vazia. Para planilhas existentes (8 colunas), a coluna I é preenchida com dados mas o cabeçalho I1 permanece vazio até o usuário adicionar "Itens" manualmente. O código funciona com ou sem o cabeçalho em I1. Ver [Decisões Portão 3 — P9](./04-clarificacoes.md#decisões-portão-3--feature-items-granularidade-item-nível).

### Formatos
- **Data**: ISO `YYYY-MM-DD` via API; Sheets formata para `DD/MM/AAAA`
- **Valor**: número `45.90` (ponto decimal); Sheets formata para locale pt-BR
- **Labels**: string única `#almoço #japonês #recorrente`
- **Cabeçalho**: linha 1 congelada (freeze); dados a partir da linha 2

> A planilha deve ser criada manualmente pelo usuário e o ID informado no `.env`. O backend cria os cabeçalhos na primeira execução se não existirem.

---

## 5. Modelo de Dados Firestore

```
firestore
├── transactions/
│   └── {auto_id}/
│       ├── chat_id:            string
│       ├── date:               string "2026-06-15" (ISO)
│       ├── description:        string (máx 500)
│       ├── amount:             float (positivo)
│       ├── type:               "expense" | "income"
│       ├── category:           string
│       ├── labels:             array<string>
│       ├── items:               array<map{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>
│       ├── source:             "text" | "image"
│       ├── observations:       string | null
│       ├── sync_status:        "pending" | "synced" | "failed"
│       ├── sync_attempts:      integer
│       ├── sync_last_attempt_at: timestamp | null
│       ├── sync_error_message: string | null
│       ├── created_at:         timestamp
│       └── updated_at:         timestamp
│
├── categories/
│   └── {name_lowercase}/
│       ├── display_name:       string
│       ├── default_type:       "expense" | "income"
│       ├── use_count:          integer
│       ├── is_default:         bool
│       └── created_at:         timestamp
│
├── labels/
│   └── {name_lowercase}/
│       ├── name:               string
│       ├── use_count:          integer
│       └── last_used_at:       timestamp
│
└── sessions/
    └── {chat_id}/
        ├── state:              "idle" | "awaiting_data" | "awaiting_confirmation" | "awaiting_edition"
        ├── draft:              map | null
        ├── awaiting_field:     string | null
        ├── message_id_confirm: string | null
        ├── message_id_edit_picker: int | null   ← efêmero — deletado ao concluir edição
        ├── message_id_ask_edition: int | null  ← NOVO (P7-B: id do prompt "Digite o novo ...")
        ├── updated_at:         timestamp
        └── retry_count:        integer
```

> **Retrocompatibilidade (items):** documentos `transactions/{id}` criados antes da feature items (jun/2026) não têm o campo `items`. Todo código que lê items deve usar `$doc['items'] ?? []` (null-coalescing para array vazio). O campo é **sempre presente** em novos documentos (array vazio `[]` quando não há items — nunca null, nunca omitido). Ver [Decisões Portão 3 — P1](./04-clarificacoes.md#decisões-portão-3--feature-items-granularidade-item-nível).

### Índices compostos necessários
| Collection | Índice | Propósito |
|------------|--------|-----------|
| `transactions` | `chat_id` ASC, `date` DESC | `listRecent(chatId)` — últimas transações do chat (FIX-2/M5) |
| `transactions` | `chat_id` ASC, `type` ASC, `date` DESC | `listRecent(chatId, type)` — últimas por tipo dentro do chat (FIX-2/M5) |
| `transactions` | `type` ASC, `date` DESC | Últimas despesas/receitas (cross-chat, agregações) |
| `transactions` | `category` ASC, `date` DESC | Transações por categoria |
| `labels` | `use_count` DESC | Top labels mais usadas |

> **Nota:** os dois primeiros índices (`chat_id`+`date` e `chat_id`+`type`+`date`)
> são **obrigatórios** para o `FirestoreService::listRecent()` — sem eles, o
> Firestore rejeita a query composta por `chat_id` + filtro de `type` com
> erro `FAILED_PRECONDITION`. O `InMemoryFirestoreGateway` (testes) não
> exige índices.

Os índices estão declarados em [`firestore.indexes.json`](../firestore.indexes.json)
(formato aceito por `gcloud firestore indexes import`). Para aplicar em um
projeto GCP:

```bash
# Importar todos os índices do arquivo de uma vez
gcloud firestore indexes import firestore.indexes.json \
  --project=PROJECT_ID

# Ou criar individualmente (ex.: o índice de listRecent)
gcloud firestore indexes composite create \
  --collection-group=transactions \
  --field-config field-path=chat_id,order=ASCENDING \
  --field-config field-path=date,order=DESCENDING \
  --query-scope=COLLECTION \
  --project=PROJECT_ID
```

---

## 6. Estrutura de Diretórios do Projeto Laravel

Padrão: **Controllers magros → Services especializados → Actions (unidades de trabalho)**

```
app/
├── Actions/
│   ├── ExtractFromText.php        # DeepSeek parse de texto → TransactionData
│   ├── ExtractFromImage.php       # Gemini OCR multimodal → TransactionData
│   ├── RegisterTransaction.php    # Valida → Firestore insert → dispara sync
│   ├── SuggestLabels.php          # Heurística histórico + keywords
│   ├── SuggestCategory.php        # Fuzzy match categorias
│   └── SyncSheet.php              # Transação → Google Sheets append
│
├── Conversation/
│   ├── StateMachine.php           # Transições de estado
│   ├── States.php                 # Enum de estados
│   └── Router.php                 # Decide ação baseada no input + estado
│
├── Dto/
│   └── TransactionData.php        # DTO imutável (readonly class)
│
├── Enums/
│   ├── TransactionType.php        # expense, income
│   ├── TransactionSource.php      # text, image
│   └── ConversationState.php      # idle, awaiting_*
│
├── Http/Controllers/
│   └── Webhook/
│       └── TelegramController.php # Único controller (valida + dispatch)
│
├── Services/
│   ├── DeepSeek/
│   │   └── DeepSeekService.php
│   ├── Gemini/
│   │   └── GeminiService.php
│   ├── Google/
│   │   ├── SheetsService.php
│   │   └── FirestoreService.php
│   └── Telegram/
│       ├── BotService.php         # sendMessage, editMessage, keyboards
│       └── SessionService.php     # load/save session no Firestore
│
├── Middleware/
│   └── ValidateTelegramWebhook.php
│
└── Support/
    └── Stopwords.php              # Lista de stopwords PT-BR
```

---

## 7. Fluxo de Conversa (Máquina de Estados)

### Estados

| Estado | Descrição |
|--------|-----------|
| **IDLE** | Aguardando novo input |
| **AWAITING_DATA** | Faltam campos obrigatórios |
| **AWAITING_CONFIRMATION** | Dados completos, aguardando confirmação |
| **AWAITING_EDITION** | Usuário editando um campo específico |

### Diagrama de Estados

```
                         ┌────────────────────┐
           ┌────────────►│       IDLE         │◄────────────┐
           │             └──┬──────┬──────────┘             │
           │   texto c/     │      │ imagem                 │
           │   dados        │      │                        │
           │                ▼      ▼                        │
           │        ┌──────────┐  ┌──────────────────┐      │
           │        │AWAITING_  │  │ Processa Gemini  │      │
           │        │CONFIRM    │  │ (imagem→JSON)    │      │
           │        └─┬─────┬───┘  └────────┬─────────┘      │
           │ confirmar │     │ editar        │               │
           │           ▼     ▼               ▼               │
           │     ┌─────────┐  ┌──────────┐                   │
           │     │ GRAVAR  │  │AWAITING_  │◄── novo valor ───┤
           │     │FS+Sheets│  │EDITION    │                   │
           │     └────┬────┘  └───────────┘                   │
           │          ▼                                        │
           │       IDLE (sucesso)                              │
           │                                                   │
           └──── /cancelar ou timeout 15min ──────────────────┘
```

### Comandos suportados

| Comando | Descrição |
|---------|-----------|
| `/start` | Boas-vindas + instruções |
| `/help` | Lista de comandos e exemplos |
| `/nova` | Wizard passo-a-passo (Tipo→Valor→Descrição→Categoria→Labels) |
| `/cancelar` | Cancela operação atual (qualquer estado) |
| `/ultimos [n]` | Últimas N transações (default 5, máx 50) |
| `/categorias` | Lista categorias disponíveis |
| `/sync` | Dispara sincronização de pendentes sob demanda |

---

## 8. Estratégia de Prompts

### 8.1 DeepSeek — Extração de texto → JSON

Prompt do sistema instrui o modelo a retornar JSON estrito com campos: `description`, `amount`, `type` (expense/income/null), `category`, `labels[]`, `date` (YYYY-MM-DD), `observations`.

Regras principais:
- Palavras "paguei/gastei/custo" → `expense`; "recebi/ganhei/salário" → `income`; ambíguo → `null`
- `amount` sempre positivo
- `date`: "ontem" → calcula data; default hoje
- `response_format: { type: 'json_object' }`

### 8.2 Gemini — OCR multimodal de notas fiscais

O Gemini recebe a imagem como `inline_data` (base64) + prompt de sistema. Usa `responseMimeType: application/json` + `responseSchema` com os campos:
- `description` (string)
- `amount` (number) — **valor TOTAL** da nota
- `type` (string) — `expense` por padrão
- `category` (string)
- `labels` (array de strings)
- `date` (string ISO, ou null se ilegível)
- `observations` (string — CNPJ, forma de pagamento)

Regras: nunca inventar dados; campos ilegíveis → `null`; `temperature: 0.1` para precisão.

### 8.3 Sugestão de labels — Heurística PHP (sem LLM)

Ver detalhes do algoritmo em [Clarificações](./04-clarificacoes.md#4-algoritmo-exato-da-heurística-de-sugestão-de-labels-ct-020).

Resumo: histórico (top labels da categoria, prioridade) + keywords da descrição (após remoção de stopwords PT-BR), merge com dedupe, máximo 5.

---

### 8.5 DTO `TransactionData` — Propriedade `items` (M-ITENS-7)

O DTO imutável `TransactionData` (ver `app/Dto/TransactionData.php`) foi estendido com a dimensão items:

| Elemento | Tipo | Descrição |
|----------|------|-----------|
| `public array $items` | `list<array{name:string,qty:float\|null,unitPrice:float\|null,subtotal:float\|null}>` | Lista de itens descritivos (default `[]`) |
| `ITEMS_MAX_STORED` | `int = 200` | Limite de segurança para armazenamento Firestore (sanitização contra LLM descontrolado ou colagem de lista gigante) |
| `ITEMS_MAX_DISPLAY` | `int = 10` | Truncamento visual no Telegram (resumo de confirmação) |

**Helpers afetados:**
- `fromArray($data)`: normaliza items via `normalizeItems($data['items'] ?? [])`
- `withItems(array $items)`: nova instância com items normalizados
- `withField('items', $value)`: reusa `withItems`
- `getFieldValue('items')`: acesso para captura do valor antigo na edição
- `toDraftArray()`: inclui `'items'` (omitido quando `[]` — consistente com `labels`)
- `normalizeItems(mixed $items)`: sanitização privada (descarta não-arrays, coerce tipos, trunca name ≥ 500 chars, trunca para `ITEMS_MAX_STORED`)

**Invariantes garantidas pelo DTO:**
- `items` nunca é `null` — sempre `[]` ou array de maps
- `name` é string não-vazia após `trim()`, ≤ 500 chars (truncado com `"..."`)
- `qty` é `float|null`; `qty < 0` é clampado para `null`
- `unitPrice`/`subtotal` aceitam qualquer float (inclusive negativo — descontos de cupom)
- Ordem de entrada é preservada (`array_values`)

---

## 9. Validações

| Campo | Regra |
|-------|-------|
| `date` | Não pode ser futura sem confirmação; data passada OK; default hoje |
| `description` | Mín 2 chars, máx 500 (trunca com "...") |
| `amount` | `> 0`; negativo e zero rejeitados; parser robusto (45,90 / R$ 1.234,56) |
| `type` | `expense` ou `income`; se ambíguo, pergunta ao usuário |
| `category` | Deve existir ou ser criada (usuário confirma) |

---

## 10. Tratamento de Erros e Resiliência

| Falha | Comportamento |
|-------|---------------|
| **DeepSeek/Gemini indisponível** | Fallback para entrada manual (wizard `/nova`) |
| **Google Sheets falha** | Firestore salva com `sync_status=pending`; cron recupera a cada 5min; após 3 falhas → `failed` + notifica usuário |
| **Webhook timeout** | Responde 200 imediatamente; processa depois |
| **Duplo clique em Confirmar** | Idempotência via lock atômico no Firestore (campo `processing`) |

### Sincronização pendente
- **Cloud Scheduler** → `GET /cron/sync-pending` a cada **5 minutos**
- Comando artisan `transactions:sync-pending`
- Máximo **3 tentativas** por transação
- Após 3 falhas: `sync_status=failed` + notificação ao usuário via Telegram
- Comando `/sync` manual reseta contador

---

## 11. Segurança

| Prática | Implementação |
|---------|---------------|
| Webhook Telegram | Valida header `X-Telegram-Bot-Api-Secret-Token` |
| Whitelist chat_id | Apenas chat_id do dono (via `.env`); outros → 403 |
| Service Account JSON | Secret Manager do GCP (nunca no repositório/imagem) |
| API Keys | Variáveis de ambiente injetadas no Cloud Run |

---

## 12. Chaves e Secrets Necessários (CHECKLIST)

- [ ] **Telegram Bot Token** — via @BotFather
- [ ] **Telegram Webhook Secret Token** — `openssl rand -hex 32`
- [ ] **Telegram Chat ID do dono** — whitelist
- [ ] **DeepSeek API Key** — platform.deepseek.com
- [ ] **Gemini API Key** — aistudio.google.com/app/apikey (AI Studio)
- [ ] **Google Cloud Project ID**
- [ ] **Google Service Account JSON** — roles: Sheets API + Firestore User (Vision NÃO necessário)
- [ ] **Google Sheet ID** — da URL da planilha
- [ ] **Firestore Database** — Native mode

### Variáveis de ambiente (.env)

```bash
# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET_TOKEN=
TELEGRAM_ALLOWED_CHAT_IDS=           # ex: "123456789"
TELEGRAM_WEBHOOK_URL=                # ex: "https://wallet-track-xxx.a.run.app/webhook/telegram"

# DeepSeek (texto)
DEEPSEEK_API_KEY=
DEEPSEEK_BASE_URL=https://api.deepseek.com
DEEPSEEK_MODEL=deepseek-v4-flash

# Gemini (visão/OCR via AI Studio)
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash

# Google Cloud (Sheets + Firestore)
GOOGLE_CLOUD_PROJECT_ID=
GOOGLE_SERVICE_ACCOUNT_JSON=         # Base64 ou path no Secret Manager
GOOGLE_SHEETS_SPREADSHEET_ID=
GOOGLE_SHEETS_SHEET_NAME=Transações

# App
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=
LOG_CHANNEL=stderr
SYNC_MAX_RETRIES=3
```

---

## 13. Plano de Deploy no Cloud Run

### Configurações do Cloud Run

| Parâmetro | Valor | Justificativa |
|-----------|-------|---------------|
| **Memória** | 512 MiB | Suficiente; subir para 1 GiB se imagens grandes |
| **CPU** | 1 vCPU | Padrão, single-user |
| **Concurrency** | 1 | Evita race condition na sessão |
| **Min instances** | 0 | Uso pessoal; aceita cold start ~2s |
| **Max instances** | 1 | Sem necessidade de escala |
| **Timeout** | 300s | Cobre OCR + múltiplas chamadas API |
| **CPU throttling** | Desabilitado | CPU disponível durante serve |
| **Startup CPU boost** | Habilitado | Acelera bootstrap Laravel |

### Health check
`GET /health` → `{"status":"ok","timestamp":"..."}`. Em produção (M10), checa Firestore + Sheets + variáveis críticas.
