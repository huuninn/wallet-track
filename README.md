# Wallet Track

> Chatbot Telegram de controle financeiro pessoal (despesas + receitas) com extração por IA, validação, confirmação inline e gravação em Google Sheets + Firestore.

![Status](https://img.shields.io/badge/status-em%20planejamento-yellow)
![Stack](https://img.shields.io/badge/stack-PHP%208.4%20%2B%20Laravel%2013-blue)
![Deploy](https://img.shields.io/badge/deploy-Google%20Cloud%20Run-4285F4)

---

## O que é

Assistente pessoal de registro financeiro operado via Telegram. Você envia:

- **Texto livre** — *"Paguei R$ 47,50 no almoço de hoje"* — e o bot extrai tipo, valor, data, categoria, labels via **DeepSeek**.
- **Foto de nota fiscal** — e o bot lê com **Gemini 2.5 Flash** (OCR multimodal) e extrai os campos.

O bot sempre mostra um **resumo para confirmação** antes de gravar. Os dados são persistidos em **Google Sheets** (visualização) e **Firestore** (fonte de verdade + heurística de labels).

### Funcionalidades

- ✅ Extração de texto (DeepSeek) e imagem (Gemini)
- ✅ Validação de campos (valor > 0, data passada/hoje, categoria válida)
- ✅ Sugestão automática de labels (histórico + keywords, sem LLM)
- ✅ Confirmação inline (Confirmar / Editar / Cancelar)
- ✅ Wizard manual `/nova` (fallback determinístico)
- ✅ Comandos: `/start`, `/help`, `/ultimos`, `/categorias`, `/sync`
- ✅ Sincronização pendente via Cloud Scheduler (cron 5min)
- ✅ Timeout de sessão (15min)
- ✅ Idempotência de confirmação
- ✅ Whitelist de chat_id (uso pessoal, 1 usuário)

---

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Runtime | PHP 8.4 + FrankenPHP 1.4 |
| Framework | Laravel 13.x + Octane |
| Bot SDK | `nutgram/nutgram` ^4.0 |
| IA Texto | DeepSeek `deepseek-v4-flash` (via `openai-php/client`) |
| IA Imagem | Gemini `gemini-2.5-flash` (via `google-gemini-php/client`) |
| Sheets | Google Sheets API + Service Account |
| Persistência | Cloud Firestore (Native mode) |
| Deploy | Google Cloud Run (512MB, 1 vCPU, timeout 300s) |
| Agendamento | Cloud Scheduler (cron 5min) |
| Logs | Cloud Logging (stderr estruturado) |

---

## Documentação

Toda a documentação do projeto está em [`docs/`](./docs/):

| # | Documento | Descrição |
|---|-----------|-----------|
| 00 | [Índice](./docs/00-INDEX.md) | Visão geral + sumário executivo |
| 01 | [Análise de Negócio](./docs/01-analise-negocio.md) | Entendimento, entidades, premissas, riscos |
| 02 | [Especificação Técnica](./docs/02-especificacao-tecnica.md) | Arquitetura, modelo de dados, fluxo, segurança |
| 03 | [Plano de Testes Manuais](./docs/03-plano-testes-manuais.md) | 47 casos de teste (CT-001 a CT-047) |
| 04 | [Clarificações](./docs/04-clarificacoes.md) | 10 decisões definitivas sobre ambiguidades |
| 05 | [Revisão v2](./docs/05-revisao-v2.md) | Laravel 13 + Gemini OCR (substituindo Vision) |
| 06 | [Plano de Implementação](./docs/06-plano-implementacao.md) | 11 milestones (M0–M10) com dependências e critérios |

**Ordem de leitura recomendada:** 01 → 02 → 06 → (03 durante implementação).

---

## Status do Projeto

| Fase | Status |
|------|--------|
| Análise de Negócio | ✅ Aprovada |
| Especificação Técnica | ✅ Aprovada (v2) |
| Plano de Testes Manuais | ✅ Completo (47 CTs) |
| Plano de Implementação | ✅ Aprovado |
| **Implementação** | ⏸️ **Pendente (inicia em M0)** |
| Deploy em produção | ⏸️ Pendente (M10) |

---

## Pré-requisitos

Antes de iniciar a implementação (M0), o usuário precisa providenciar os seguintes itens:

### Secrets e credenciais

| Item | Origem |
|------|--------|
| Telegram Bot Token | @BotFather |
| Telegram Webhook Secret Token | `openssl rand -hex 32` (gerar localmente) |
| Telegram Chat ID do dono | @userinfobot ou similar |
| DeepSeek API Key | https://platform.deepseek.com |
| Gemini API Key | https://aistudio.google.com/app/apikey |
| Google Cloud Project ID | https://console.cloud.google.com |
| Google Service Account JSON | IAM → Service Accounts → Create Key |
| Google Sheet ID | URL da planilha (`/d/<ID>/edit`) |
| Firestore Database | Native mode, no mesmo projeto GCP |

### Configurações GCP

- Projeto com billing habilitado
- APIs habilitadas: Cloud Run, Cloud Build, Cloud Scheduler, Firestore, Secret Manager, Sheets
- Service Account com permissões de Sheets Editor (nível da planilha) + Cloud Datastore User (nível do projeto)
- Planilha Google Sheets criada e **compartilhada** com o email da Service Account

### Ambiente local

- PHP 8.4
- Composer 2.x
- Docker (para teste de build)
- Git

> Detalhes completos no [Plano de Implementação — Seção 2](./docs/06-plano-implementacao.md#2-pré-requisitos-antes-de-começar-m0).

---

## Quick Start (desenvolvedor)

> ⚠️ Implementação ainda não iniciada. Esta seção descreve o estado **alvo** após M0.

```bash
# 1. Clonar e instalar deps
git clone <repo>
cd wallet-track
composer install

# 2. Configurar .env (copiar .env.example e preencher todos os secrets)
cp .env.example .env
php artisan key:generate

# 3. Subir local com Octane/FrankenPHP
php artisan octane:start --port=8000

# 4. (Em outro terminal) Expor webhook via ngrok para testes Telegram
ngrok http 8000

# 5. Apontar Telegram webhook para a URL do ngrok
TELEGRAM_WEBHOOK_URL=https://<id>.ngrok.io/webhook/telegram php artisan telegram:set-webhook

# 6. Rodar testes
php artisan test
```

---

## Arquitetura (visão geral)

```
┌─────────────────────────────────────────────────────────────┐
│  Telegram → Cloud Run (Laravel 13 + FrankenPHP + Octane)   │
│                    ↓                                        │
│     Webhook → valida (secret + chat_id whitelist)          │
│                    ↓                                        │
│     200 OK imediato + app()->terminating()                  │
│                    ↓                                        │
│     Router → Machine de Estados                             │
│       ├─ Texto:  DeepSeek  → JSON → valida → confirma       │
│       └─ Imagem: Gemini    → JSON → valida → confirma       │
│                    ↓                                        │
│     Confirmação: Firestore (sync_status=pending) + Sheets   │
│                    ↓                                        │
│     Cron 5min: recupera pendentes (sync_status=synced)     │
└─────────────────────────────────────────────────────────────┘
```

Detalhes completos na [Especificação Técnica](./docs/02-especificacao-tecnica.md).

---

## Comandos do Bot

| Comando | Descrição |
|---------|-----------|
| `/start` | Boas-vindas + instruções |
| `/help` | Lista todos os comandos |
| `/nova` | Wizard passo-a-passo (Tipo→Valor→Descrição→Categoria→Labels) |
| `/cancelar` | Cancela operação em qualquer estado |
| `/ultimos [n]` | Últimas N transações (default 5, máx 50) |
| `/categorias` | Lista categorias com contador de uso |
| `/sync` | Força sincronização de pendentes |

**Uso comum:** basta enviar uma mensagem em linguagem natural ou uma foto de nota fiscal — o bot cuida do resto.

---

## Privacidade e Segurança

- **Uso pessoal** — 1 usuário, 1 chat_id whitelist
- Webhook protegido por **secret token** (Telegram) + validação de origem
- **API keys e Service Account JSON** armazenados em GCP Secret Manager (nunca no repositório ou imagem Docker)
- Sem analytics, sem tracking, sem dependências externas de telemetria
- Logs em stderr (visíveis só ao dono do projeto no Cloud Logging)

---

## Licença

Projeto pessoal. Sem licença pública no momento.

---

## Contato / manutenção

Projeto mantido por [@diego-oliveira](https://github.com/diego-oliveira). Issues e PRs são bem-vindos apenas após M0 (quando a base de código existir).
