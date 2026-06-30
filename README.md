# Wallet Track

> Chatbot Telegram de controle financeiro pessoal (despesas + receitas) com extração por IA, validação, confirmação inline e gravação em Google Sheets + MariaDB.

![Status](https://img.shields.io/badge/status-em%20planejamento-yellow)
![Stack](https://img.shields.io/badge/stack-PHP%208.4%20%2B%20Laravel%2013-blue)
![Deploy](https://img.shields.io/badge/deploy-Google%20Cloud%20Run-4285F4)

---

## O que é

Assistente pessoal de registro financeiro operado via Telegram. Você envia:

- **Texto livre** — *"Paguei R$ 47,50 no almoço de hoje"* — e o bot extrai tipo, valor, data, categoria, labels via **DeepSeek**.
- **Foto de nota fiscal** — e o bot lê com **Gemini 2.5 Flash** (OCR multimodal) e extrai os campos.

O bot sempre mostra um **resumo para confirmação** antes de gravar. Os dados são persistidos em **Google Sheets** (visualização) e **MariaDB** (fonte de verdade + heurística de labels).

### Funcionalidades

- ✅ Extração de texto (DeepSeek) e imagem (Gemini)
- ✅ Validação de campos (valor > 0, data passada/hoje, categoria válida)
- ✅ Sugestão automática de labels (histórico + keywords, sem LLM)
- ✅ Confirmação inline (Confirmar / Editar / Cancelar)
- ✅ Wizard manual `/nova` (fallback determinístico, 6 etapas)
- ✅ Comandos: `/start`, `/help`, `/nova`, `/cancelar`, `/ultimos [n]`, `/categorias`, `/sync`
- ✅ Sincronização pendente via Cloud Scheduler (cron a cada 5 min)
- ✅ Notificação de falha definitiva única (campo `notified_at` no banco de dados)
- ✅ Timeout de sessão (15 min)
- ✅ Idempotência de confirmação
- ✅ Whitelist de chat_id (uso pessoal, 1 usuário)

---

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Runtime | PHP 8.4 + FrankenPHP 1.12.4 |
| Framework | Laravel 13.x + Octane |
| Bot SDK | `nutgram/nutgram` ^4.0 |
| IA Texto | DeepSeek `deepseek-v4-flash` (via `openai-php/client`) |
| IA Imagem | Gemini `gemini-2.5-flash` (via `google-gemini-php/client`) |
| Sheets | Google Sheets API + Service Account |
| Persistência | MariaDB 11.8 |
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

Documentação adicional do M9 (Comandos Auxiliares):

| Documento | Descrição |
|-----------|-----------|
| [docs/M9-COMPLETO.md](./docs/M9-COMPLETO.md) | Sumário executivo do M9 entregue |
| [docs/planos/m9-plano-tecnico.md](./docs/planos/m9-plano-tecnico.md) | Plano técnico M9 (24 tarefas, 6 fases) |
| [docs/specs/m9-spec-fase-2.md](./docs/specs/m9-spec-fase-2.md) | Especificação técnica M9 (1.318 linhas, 11 decisões) |
| [docs/testes/m9-plano-testes.md](./docs/testes/m9-plano-testes.md) | Plano de testes M9 (74 CTs) |

**Ordem de leitura recomendada:** 01 → 02 → 06 → (03 durante implementação).

---

## Status do Projeto

| Fase | Status |
|------|--------|
| Análise de Negócio | ✅ Aprovada |
| Especificação Técnica | ✅ Aprovada (v2) |
| Plano de Testes Manuais | ✅ Completo (47 CTs + 27 CTs M9) |
| Plano de Implementação | ✅ Aprovado |
| M0–M6 (skeleton, IA, persistência) | ✅ Implementado |
| M7 (state machine + router) | ✅ Implementado |
| M8 (sugestão heurística) | ✅ Implementado |
| M9 (comandos auxiliares) | ✅ **Implementado** (ver [`docs/M9-COMPLETO.md`](./docs/M9-COMPLETO.md)) |
| M10 (deploy produção) | ⏸️ Pendente |

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
| MariaDB Database | Serviço de banco de dados relacional |

### Configurações GCP

- Projeto com billing habilitado
- APIs habilitadas: Cloud Run, Cloud Build, Cloud Scheduler, Secret Manager, Sheets
- Service Account com permissões de Sheets Editor (nível da planilha) + Acesso ao banco de dados MariaDB configurado
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
│     Confirmação: banco de dados (sync_status=pending) + Sheets   │
│                    ↓                                        │
│     Cron 5min: recupera pendentes (sync_status=synced)     │
└─────────────────────────────────────────────────────────────┘
```

Detalhes completos na [Especificação Técnica](./docs/02-especificacao-tecnica.md).

---

## Comandos disponíveis

| Comando | Descrição |
|---------|-----------|
| `/start` | Boas-vindas e instruções iniciais |
| `/help` | Lista completa de comandos |
| `/nova` | Cadastro passo a passo (6 etapas) |
| `/cancelar` | Cancela a operação atual |
| `/ultimos [n]` | Últimas N transações (padrão 5, máx 50) |
| `/categorias` | Lista categorias com contador de uso |
| `/sync` | Dispara sincronização com Google Sheets |

**Uso comum:** além dos comandos, basta enviar uma mensagem em linguagem natural
(*"Paguei R$ 47,50 no almoço de hoje"*) ou uma foto de nota fiscal — o bot cuida
do resto.

### Sincronização automática

A sincronização é executada periodicamente pelo scheduler interno do Laravel
(a cada 5 min) via `Schedule::command('transactions:sync-pending')` em
[`routes/console.php`](./routes/console.php). Não há variáveis de ambiente
obrigatórias para isso. Em produção (Cloud Run), o **Cloud Scheduler** acorda
a instância via HTTP a cada 5 min (configurado externamente, fora do app)
para que o scheduler interno possa rodar.

```bash
# Disparar sincronização manualmente (qualquer ambiente)
php artisan transactions:sync-pending
# → {"status":"ok","processed":N,"synced":N,"failed":N,"errors":[],"duration_ms":N,"timestamp":"..."}
```

Documentação completa: [`docs/M9-COMPLETO.md`](./docs/M9-COMPLETO.md).

---

## Privacidade e Segurança

- **Uso pessoal** — 1 usuário, 1 chat_id whitelist
- Webhook protegido por **secret token** (Telegram) + validação de origem
- **API keys e Service Account JSON** armazenados em GCP Secret Manager (nunca no repositório ou imagem Docker)
- Sem analytics, sem tracking, sem dependências externas de telemetria
- Logs em stderr (visíveis só ao dono do projeto no Cloud Logging)

---

## Dev Isolado

O projeto suporta **dois ambientes Docker simultâneos** no mesmo host: **prod** (porta 8000) e **dev** (porta 8001), com containers, volumes e bancos de dados completamente isolados.

### Subir o ambiente dev

```bash
# Primeira vez (build + up + migrate)
make setup-dev

# Ou passo a passo
make build-dev    # constrói imagens (reusa wallet-track:dev de prod)
make up-dev       # sobe containers dev (porta 8001)
make migrate-dev  # roda migrations no banco dev (wallet_track_dev)
```

A aplicação fica disponível em **http://localhost:8001**. Os containers dev são:

| Container | Porta host |
|-----------|-----------|
| `wallet-track-dev` (app) | 8001 |
| `wallet-track-dev-mariadb` | 13306 |
| `wallet-track-dev-redis` | 16379 |

### Alternar entre prod e dev

Os túneis ngrok são **mutuamente exclusivos** (1 agente ngrok por vez). Sempre encerre o túnel ativo antes de alternar:

```bash
# Se prod está com túnel ativo:
make tunnel-down      # encerra ngrok prod (porta 8000)

# Agora pode subir o túnel dev:
make tunnel-up-dev    # ngrok na porta 8001, webhook registrado no bot dev
```

### Comandos disponíveis

```bash
make help | grep dev   # lista todos os alvos -dev
```

Principais: `up-dev`, `down-dev`, `fresh-dev`, `restart-dev`, `logs-dev`, `ps-dev`,
`shell-dev`, `artisan-dev cmd="..."`, `composer-dev cmd="..."`, `migrate-dev`,
`tinker-dev`, `test-dev`, `pint-dev`, `tunnel-up-dev`, `tunnel-down-dev`.

### Troubleshooting

| Problema | Solução |
|----------|---------|
| Porta 4040 já em uso | `pkill ngrok` — ngrok antigo travado |
| Conflito de project name | `docker compose -p wallet-track-dev ps` — verifique se containers antigos existem |
| Database `wallet_track` em vez de `wallet_track_dev` | O `.env` do Docker Compose pode sobrescrever `DB_DATABASE`; verifique `.env` do host |
| Volumes de prod afetados | Volumes dev usam sufixo `_dev` — nunca tocam `mariadb_data`, `storage` etc. |
| `vendor/autoload.php` não encontrado na primeira execução | `make composer-dev cmd="install"` (já automatizado em `make setup-dev`) |

> **Nota:** O arquivo `docker-compose.override.yml` foi removido. O `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` agora é injetado diretamente no `docker-compose.yml` e `docker-compose.dev.yml`.

## Licença

Projeto pessoal. Sem licença pública no momento.

---

## Contato / manutenção

Projeto mantido por [@diego-oliveira](https://github.com/diego-oliveira). Issues e PRs são bem-vindos apenas após M0 (quando a base de código existir).
