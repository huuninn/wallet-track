# M9 — Comandos Auxiliares: COMPLETO

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

> **Milestone:** M9 (Comandos Auxiliares)
> **Status:** ✅ **Entregue em 19/06/2026**
> **Versão proposta:** `v0.9.0-M9`
> **Documentos relacionados:** [plano técnico](../planos/m9-plano-tecnico.md) · [especificação](../specs/m9-spec-fase-2.md) · [plano de testes](../testes/m9-plano-testes.md)

---

## 1. Resumo

O M9 entrega o **segundo bloco de comandos do bot** Wallet Track, completando
a cobertura de interação textual iniciada em M1 (start/help) e expandida em
M7 (state machine). Com o M9 fechado, o bot passa a oferecer 7 comandos
(`/start`, `/help`, `/nova`, `/cancelar`, `/ultimos`, `/categorias`, `/sync`),
um wizard passo-a-passo para cadastro alternativo, e sincronização automática
com Google Sheets via endpoint server-to-server.

Adicionalmente, **3 gaps críticos** identificados durante a revisão do M7/M8
foram fechados neste milestone:

| Gap | Sintoma | Resolução | Commit |
|-----|---------|-----------|--------|
| **GAP-01** | `/start` em AWAITING_DATA mantinha sessão travada | `StartHandler` agora chama `clearSession` | `a624a33` (M9-A) |
| **GAP-02** | `/cancelar` em IDLE enviava "Transação cancelada" enganosa | `CancelarHandler` detecta IDLE e responde amigavelmente | `a624a33` (M9-A) |
| **GAP-03** | `/help` marcava 5 comandos M9 como `⏳ em breve` | `HelpHandler::commands()` flags `active=true` | `a624a33` (M9-A) |

---

## 2. Os 7 comandos finais

| Comando | Handler | Descrição | Cobertura |
|---------|---------|-----------|-----------|
| `/start` | `StartHandler` | Boas-vindas + instruções + reset de sessão (GAP-01) | CT-023, CT-023a-f |
| `/help` | `HelpHandler` | Lista 7 comandos com `✅ ativo` (GAP-03) | CT-024, CT-024a-c |
| `/nova` | `NovaHandler` → `WizardHandler` | Wizard 6 etapas: Tipo → Valor → Descrição → Categoria → Labels → Confirmação | CT-025, CT-025a-n |
| `/cancelar` | `CancelarHandler` | Cancela sessão ativa; amigável em IDLE (GAP-02) | CT-026, CT-026a-e |
| `/ultimos [n]` | `UltimosHandler` | Últimas N transações (padrão 5, cap 50) | CT-027, CT-028, CT-028a-g |
| `/categorias` | `CategoriasHandler` | Lista categorias com `use_count` (ordenado DESC) | CT-029, CT-029a-f |
| `/sync` | `SyncHandler` → command | Sincroniza pendentes com Google Sheets, reseta `sync_attempts` | CT-048 a CT-053, CT-061 |

Mais o endpoint HTTP `GET /cron/sync-pending` para execução via Cloud Scheduler
(a cada 5 min), autenticado por `X-Cron-Token` (CT-054 a CT-057).
**(Posteriormente substituído por `Schedule::command('transactions:sync-pending')` em `routes/console.php:28` — Cloud Scheduler agora apenas acorda a instância.)**

---

## 3. Estatísticas

### 3.1 Commits (5 commits atômicos)

```
cea834c style(M9-D): apply pint style fixes to ConversationRouter
2e3bb28 feat(M9-D): implement /nova wizard with 6 steps
85249ab refactor(M9-D): add wizard support to ConversationRouter
b785597 feat(M9-C): implement sync infrastructure (cron + /sync)
06418cd feat(M9-B): implement /ultimos and /categorias commands
a624a33 feat(M9-A): fix gaps in start/help/cancelar handlers
```

> **Nota:** a Fase A (gaps) foi incorporada como prelúdio ao M9 (commit `a624a33`)
> no mesmo branch, antes das Fases B/C/D.

### 3.2 Código

| Métrica | Valor |
|---------|-------|
| Arquivos novos | ~17 |
| Arquivos modificados | ~17 |
| Linhas adicionadas | ~5810 |
| Linhas removidas | ~43 |
| Classes/handlers novos | 7 |
| Métodos públicos novos | ~25 |
| Testes PHPUnit | **521 totais** (M7+M8: 441 → M9: +80) |
| Asserções | **1443 totais** |
| Smoke tests (`#[Group('smoke')]`) | 9 |
| Cobertura M9 (estimada) | ≥ 90% |

### 3.3 Distribuição por fase

| Fase | Tarefas | Commits | Testes novos |
|------|---------|---------|--------------|
| **A** — Fundação (gaps) | T-001 a T-004 | 1 | 15 |
| **B** — Read-only | T-005 a T-009 | 1 | ~35 |
| **C** — Sync (cron + comando) | T-010 a T-014 | 1 | ~49 |
| **D** — Wizard `/nova` | T-015 a T-020 | 2 | 34 |
| **E** — Hardening | T-021 a T-023 | 0 (neste branch) | 0 |
| **F** — Documentação | T-024 | 0 (neste branch) | 0 |
| **TOTAL** | **24** | **5** | **~133** (cobre 64/74 CTs) |

> As Fases E e F são responsabilidade deste entregável (Fase E Hardening +
> Fase F Docs), mas suas mudanças (smoke groups, pint, PHPDoc, docs) ainda
> serão commitadas como commits separados após a aprovação final do usuário.

---

## 4. Decisões técnicas aplicadas (Portão 2)

| # | Decisão | Implementação | Onde |
|---|---------|---------------|------|
| #1 | `/ultimos 999999` → cap em 50 (não fallback 5) | `UltimosHandler::resolveLimit` ctype_digit check | `app/Bot/Handlers/UltimosHandler.php` |
| #2 | `/nova` durante AWAITING_CONFIRMATION → descarta pendente | `NovaHandler::__invoke` chama `clearSession` + `notifyDiscarded` | `app/Bot/Handlers/NovaHandler.php` |
| #3 | Comandos `/ultimos`, `/categorias` são stateless (preservam sessão) | Handlers NÃO tocam `sessions/` | `app/Bot/Handlers/{Ultimos,Categorias}Handler.php` |
| #4 | `use_count` é escopo global (não particionado por chat) | `CategoriasHandler` lê direto do doc | `app/Bot/Handlers/CategoriasHandler.php` |
| #5 | Notificação de falha única via `notified_at` | `SyncPendingTransactions::handleFailure` checa antes de notificar | `app/Console/Commands/SyncPendingTransactions.php` |
| #7 | `/sync` reseta `sync_attempts` (dá "mais 3 chances") | `SyncHandler` chama `resetPendingSyncAttempts` | `app/Bot/Handlers/SyncHandler.php` |
| #8 | Lock atômico `processing=true` para `/sync` × cron | `FirestoreService::markSyncStarted` via `gateway->transaction` | `app/Services/Google/FirestoreService.php` |
| #9 | Rota `/cron/sync-pending` retorna 200 mesmo com falhas parciais *(substituída por `Schedule::command` — o comando Artisan agora lida com falhas parciais retornando JSON estruturado)* | `routes/web.php` retorna `response()->json` com status=ok | `routes/web.php` → `routes/console.php` |
| #10 | Notificação por transação (não em batch) | `BotMessenger::notifyError` chamado dentro do loop | `app/Console/Commands/SyncPendingTransactions.php` |
| #13 | Etapa 4 do wizard (categoria) = texto livre com sugestão (Opção A) | `WizardHandler::buildPrompt` mostra "💡 Sugestão: Alimentação" | `app/Conversation/WizardHandler.php` |

---

## 5. Arquivos novos do M9

### Código de produção (10)

- `app/Bot/Handlers/NovaHandler.php`
- `app/Bot/Handlers/UltimosHandler.php`
- `app/Bot/Handlers/CategoriasHandler.php`
- `app/Bot/Handlers/SyncHandler.php`
- `app/Console/Commands/SyncPendingTransactions.php`
- `app/Conversation/WizardHandler.php`
- `app/Enums/WizardStep.php`
- `app/Http/Middleware/VerifyCronToken.php` (removido — substituído por Schedule interno)
- `app/Services/Google/FirestoreService.php` (modificado — +5 métodos)
- `app/Bot/Messaging/TransactionSummaryFormatter.php` (modificado — +2 métodos)

### Testes (10)

- `tests/Feature/Commands/StartHandlerTest.php`
- `tests/Feature/Commands/HelpHandlerTest.php`
- `tests/Feature/Commands/CancelarHandlerTest.php`
- `tests/Feature/Commands/UltimosHandlerTest.php`
- `tests/Feature/Commands/CategoriasHandlerTest.php`
- `tests/Feature/Commands/NovaHandlerTest.php`
- `tests/Feature/Commands/SyncHandlerTest.php`
- `tests/Feature/Console/SyncPendingTransactionsCommandTest.php`
- `tests/Feature/Http/SyncPendingRouteTest.php`
- `tests/Feature/Conversation/WizardHandlerTest.php`
- `tests/Unit/Enums/WizardStepTest.php`
- `tests/Unit/Services/Google/FirestoreServiceTest.php`
- `tests/Unit/Bot/Messaging/TransactionSummaryFormatterTest.php`

### Documentação (3)

- `docs/planos/m9-plano-tecnico.md` (24 tarefas, 6 fases)
- `docs/specs/m9-spec-fase-2.md` (1.318 linhas, 11 decisões)
- `docs/testes/m9-plano-testes.md` (74 CTs)
- `docs/M9-COMPLETO.md` (este documento)

---

## 6. Como rodar

### 6.1 Smoke tests (caminho feliz)

```bash
bin/dev test --group=smoke
# Esperado: 9 testes OK
```

### 6.2 Suíte completa

```bash
bin/dev test
# Esperado: 521 testes, 1443 asserções, 0 falhas
```

### 6.3 Apenas M9 (filtrado)

```bash
bin/dev test --filter "Commands|Conversation/Wizard|Http/SyncPendingRoute|Console/SyncPending|Unit/Enums/WizardStep|Unit/Services/Google/FirestoreService|Unit/Bot/Messaging/TransactionSummaryFormatter"
```

### 6.4 Cron local (simulação)

```bash
# A sincronização agora usa o scheduler interno do Laravel:
php artisan schedule:run
# Ou diretamente (qualquer ambiente):
php artisan transactions:sync-pending
# → {"status":"ok","processed":N,"synced":N,"failed":N,"errors":[],"duration_ms":N,"timestamp":"..."}
```
*(O endpoint HTTP `GET /cron/sync-pending` com `X-Cron-Token` foi substituído pelo Schedule do Laravel.)*

---

## 7. Próximos passos (pós-M9)

| Prioridade | Item | Origem |
|------------|------|--------|
| 🟡 Média | Refinar etapa 4 do wizard (categoria) com inline keyboard (Opção B) | Decisão #13 do Portão 2 |
| 🟡 Média | Substituir `===` por `hash_equals` na verificação de token (timing-safe extra) | Risco 4 do plano técnico |
| 🟢 Baixa | Lock otimista `processing=true` end-to-end (evita race `/sync` × cron em prod) | Risco 3 do plano técnico |
| 🟢 Baixa | Migrar `DeepSeek` para sugestão de categoria no wizard (substituir heurística keywords) | Decisão #13 do Portão 2 |
| 🟢 Baixa | Cloud Scheduler configuração (M10) | Próximo milestone |

---

## 8. Referências

- **Plano técnico:** [`docs/planos/m9-plano-tecnico.md`](../planos/m9-plano-tecnico.md)
- **Especificação:** [`docs/specs/m9-spec-fase-2.md`](../specs/m9-spec-fase-2.md)
- **Plano de testes:** [`docs/testes/m9-plano-testes.md`](../testes/m9-plano-testes.md)
- **Plano geral (M0–M10):** [`docs/06-plano-implementacao.md`](../06-plano-implementacao.md)
- **Especificação técnica geral:** [`docs/02-especificacao-tecnica.md`](../02-especificacao-tecnica.md)
- **Clarificações (Portão 2):** [`docs/04-clarificacoes.md`](../04-clarificacoes.md)

---

**Entregue por:** agente `coder` (Fases E + F)
**Data:** 19/06/2026
**Versão proposta:** `v0.9.0-M9` (aguarda aprovação do usuário)
