# Wallet Track — Índice de Documentação

> **README principal:** [`../README.md`](../README.md) — visão geral, quick start, comandos, stack.

Chatbot Telegram de controle financeiro pessoal (despesas + receitas) com extração por IA, validação, confirmação inline e gravação em Google Sheets + Firestore.

---

## Documentos principais

| # | Documento | Descrição | Status |
|---|-----------|-----------|--------|
| 00 | [Índice](./00-INDEX.md) | Este documento | ✅ |
| 01 | [Análise de Negócio](./01-analise-negocio.md) | Entendimento do problema, entidades, premissas, funcionalidades, escopo, riscos | ✅ Aprovada |
| 02 | [Especificação Técnica](./02-especificacao-tecnica.md) | Arquitetura, stack, modelo de dados, fluxo de conversa, prompts, segurança, deploy | ✅ Aprovada |
| 03 | [Plano de Testes Manuais](./03-plano-testes-manuais.md) | 47 casos de teste cobrindo fluxos felizes, edge cases, segurança e resiliência | ✅ |
| 04 | [Clarificações](./04-clarificacoes.md) | 10 pontos ambíguos resolvidos com decisões definitivas | ✅ |
| 05 | [Revisão v2](./05-revisao-v2.md) | Atualização de stack: Laravel 13 + Gemini OCR (substituindo Google Vision) | ✅ Aprovada |
| 06 | [Plano de Implementação](./06-plano-implementacao.md) | 11 milestones (M0–M10) com dependências, riscos e critérios de aceitação | ✅ Aprovado |
| - | [Viability Report](./viability-report.md) | Relatório de viabilidade técnica (PHP 8.4 + extensões + Docker) | ✅ |

## Documentos do M9 — Comandos Auxiliares

| Documento | Descrição | Status |
|-----------|-----------|--------|
| [**M9-COMPLETO**](./M9-COMPLETO.md) | Sumário executivo do M9 entregue (comandos, estatísticas, decisões) | ✅ |
| [Plano técnico M9](./planos/m9-plano-tecnico.md) | 24 tarefas em 6 fases (Fundação → Read-only → Sync → Wizard → Hardening → Docs) | ✅ |
| [Especificação M9](./specs/m9-spec-fase-2.md) | Spec detalhada (1.318 linhas, 11 decisões técnicas fechadas) | ✅ |
| [Plano de testes M9](./testes/m9-plano-testes.md) | 74 casos de teste (CT-023 a CT-061) | ✅ |

## Changelog

| Documento | Descrição |
|-----------|-----------|
| [`../CHANGELOG.md`](../CHANGELOG.md) | Histórico de versões (formato Keep a Changelog) |

---

## Resumo Executivo

### O que é
Assistente de registro de despesas/receitas operado via Telegram. O usuário envia informações de um gasto (texto livre ou foto de nota fiscal) para um bot. O sistema extrai e interpreta os dados, valida, sugere labels, confirma com o usuário e persiste em planilha Google Sheets + Firestore.

### Stack Final
- **PHP 8.4** + **Laravel 13.x** + **FrankenPHP** + **Octane**
- **Deploy**: Google Cloud Run
- **Telegram**: `nutgram/nutgram`
- **IA Texto**: DeepSeek `deepseek-v4-flash` (via `openai-php/client`)
- **IA Imagem/OCR**: Gemini `gemini-2.5-flash` (via Google AI Studio, `google-gemini-php/client`)
- **Planilha**: Google Sheets API (Service Account)
- **Persistência**: Firestore (NoSQL)

### Fluxo Principal
```
Texto/foto → IA extrai JSON → valida campos → sugere labels
         → usuário confirma (inline keyboard) → grava Firestore + Google Sheets
              ↓ (a cada 5 min, via Cloud Scheduler)
         /cron/sync-pending → processa pendentes → atualiza Sheets
```

### Comandos disponíveis (7)

| Comando | Descrição |
|---------|-----------|
| `/start` | Boas-vindas e instruções iniciais |
| `/help` | Lista completa de comandos |
| `/nova` | Cadastro passo a passo (6 etapas) |
| `/cancelar` | Cancela a operação atual |
| `/ultimos [n]` | Últimas N transações (padrão 5, máx 50) |
| `/categorias` | Lista categorias com contador de uso |
| `/sync` | Dispara sincronização com Google Sheets |

### Timeline de Implementação
~27 dev-dias (≈6,5 semanas com 1 desenvolvedor + buffer), divididos em 11 milestones.

```
M0 → M2 → M1 → M3 → M4 → [M5 ∥ M6 ∥ M7 ∥ M8] → M9 ✅ → M10
                                                  ───
                                                  entregue em 19/06/2026
```

| Milestone | Descrição | Status |
|-----------|-----------|--------|
| M0–M4 | Skeleton, webhook Telegram, extração DeepSeek/Gemini | ✅ |
| M5 | Persistência Firestore (transactions, categories, labels, sessions) | ✅ |
| M6 | Sincronização Google Sheets (`SyncSheet`) | ✅ |
| M7 | State machine + Conversation Router | ✅ |
| M8 | Sugestão heurística de labels e categoria | ✅ |
| **M9** | **Comandos auxiliares (`/nova`, `/ultimos`, `/categorias`, `/sync` + cron)** | ✅ |
| **M-ITENS** | **Feature Items — granularidade item-nível em transações (Wizard sub-fluxo, ItemsParser, coluna I Sheets, edição inline)** | ✅ 26/06/2026 |
| M10 | Deploy produção (Cloud Run + Cloud Scheduler) | ⏸️ |

**Feature Items (M-ITENS-1 a M-ITENS-7):** detalhamento de itens descritivos por transação (cupons fiscais). Documentação: [Decisões Portão 3](./04-clarificacoes.md#decisões-portão-3--feature-items-granularidade-item-nível), [Especificação Técnica §4/§5/§8.5](./02-especificacao-tecnica.md), [Checklist Staging](./testes/items-checklist-staging.md).

---

## Como usar esta documentação

- **Novo no projeto?** Leia 01 → 02 → 06 nesta ordem.
- **Vai implementar?** Siga o [Plano de Implementação](./06-plano-implementacao.md) a partir do M0.
- **Vai testar?** Use o [Plano de Testes](./03-plano-testes-manuais.md) com o checklist de validação.
- **Dúvida sobre comportamento?** Consulte as [Clarificações](./04-clarificacoes.md).
- **Mudanças recentes na arquitetura?** Veja a [Revisão v2](./05-revisao-v2.md).
- **Trabalhando no M9?** Leia [M9-COMPLETO](./M9-COMPLETO.md) (visão geral) → [plano técnico M9](./planos/m9-plano-tecnico.md) (detalhes) → [spec M9](./specs/m9-spec-fase-2.md) (decisões).

## Estrutura do repositório

```
wallet-track/
├── README.md                          # Visão geral + quick start
├── CHANGELOG.md                       # Histórico de versões
├── docs/                              # Esta pasta
│   ├── 00-INDEX.md                    # Este documento
│   ├── 01-analise-negocio.md
│   ├── 02-especificacao-tecnica.md
│   ├── 03-plano-testes-manuais.md
│   ├── 04-clarificacoes.md
│   ├── 05-revisao-v2.md
│   ├── 06-plano-implementacao.md
│   ├── viability-report.md
│   ├── M9-COMPLETO.md                 # M9 sumário executivo
│   ├── planos/                        # Planos técnicos por milestone
│   │   └── m9-plano-tecnico.md
│   ├── specs/                         # Especificações detalhadas
│   │   └── m9-spec-fase-2.md
│   └── testes/                        # Planos de teste
│       └── m9-plano-testes.md
├── app/                               # Código de produção
│   ├── Actions/                       # SyncSheet, ExtractsText/Image, SuggestLabels/Category
│   ├── Bot/
│   │   ├── BotLoader.php              # Registro central de handlers
│   │   ├── Handlers/                  # 7 handlers de comando
│   │   └── Messaging/                 # BotMessenger, Formatter
│   ├── Console/Commands/              # transactions:sync-pending, telegram:*
│   ├── Conversation/                  # Router, StateMachine, WizardHandler
│   ├── Dto/                           # TransactionData
│   ├── Enums/                         # ConversationState, WizardStep
│   ├── Http/Middleware/               # ValidateTelegramWebhook, VerifyCronToken
│   └── Services/Google/               # FirestoreService, InMemoryFirestoreGateway
├── tests/                             # 521 testes PHPUnit
│   ├── Feature/
│   │   ├── Commands/                  # 7 handler tests
│   │   ├── Console/                   # SyncPendingTransactionsCommandTest
│   │   ├── Conversation/              # ConversationRouterTest, WizardHandlerTest
│   │   ├── Http/                      # SyncPendingRouteTest
│   │   └── ...
│   └── Unit/
├── routes/                            # web.php (webhook + cron)
├── config/
└── bin/dev                            # Wrapper Docker (test, pint, composer, php)
```

## Pré-requisitos antes de implementar

Consulte a seção **"Pré-requisitos antes de começar"** no [Plano de Implementação](./06-plano-implementacao.md#6-pré-requisitos-antes-de-começar-m0). Inclui: Telegram Bot Token, Chat ID, DeepSeek API Key, Gemini API Key, Google Cloud Project + Service Account, Firestore, planilha Google Sheets.
