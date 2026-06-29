# Plano Técnico de Implementação — Milestone M9 (Comandos Auxiliares)

> **Projeto:** Wallet Track — bot Telegram de controle financeiro pessoal
> **Stack:** Laravel 13 + Nutgram 4 + DeepSeek + Gemini + Firestore + Google Sheets + Cloud Run
> **Milestone:** M9 — Comandos Auxiliares (`/start`, `/help`, `/nova`, `/cancelar`, `/ultimos`, `/categorias`, `/sync`, `transactions:sync-pending`, `GET /cron/sync-pending`) ⚠️ **DEPRECATED:** `GET /cron/sync-pending` foi substituído por `Schedule::command('transactions:sync-pending')` em `routes/console.php`; middleware `VerifyCronToken` removido; `CRON_SECRET_TOKEN` depreciado.
> **Data:** 19/06/2026
> **Base documental:** `docs/06-plano-implementacao.md §12`, `docs/specs/m9-spec-fase-2.md` (1.318 linhas, 11 decisões técnicas), `docs/testes/m9-plano-testes.md` (74 CTs), `docs/04-clarificacoes.md` (#5, #6, #7)
> **Decisões do Portão 2:** JÁ APLICADAS — este plano NÃO revisita, apenas EXECUTA
> **Milestones anteriores:** M0–M8 implementados no `main` (commits `a841a9d`, `19cd97f`, `f54ea52`)

---

## 0. TL;DR para o `coder`

- **24 tarefas** distribuídas em **6 milestones** (M9.1–M9.6 de execução).
- **Esforço total estimado:** ~22–28 horas de trabalho (3–4 dev-dias corridos, 1 dev).
- **Ordem sugerida:** GAP-01 (`/start`) → GAP-03 (`/help` flags) → M9.5 (`/ultimos`) → M9.6 (`/categorias`) → GAP-02 (`/cancelar`) → M9.8 (`SyncPendingTransactions` command) → M9.7 (`/sync`) → M9.3 (`/nova` wizard + Router) → M9.9 (rota cron) → GAP-07 (registro no BotLoader) → bateria de testes.
- **Framework de teste:** PHPUnit 12 com `InMemoryFirestoreGateway` + `InMemoryBotMessenger` + `InMemorySheetsGateway` (todos já existentes). Rodar via `bin/dev test` (Docker wrapper) ou `vendor/bin/phpunit` direto se PHP 8.4 estiver disponível.
- **Cobertura alvo:** ≥ 90 % das statements nos arquivos novos/alterados; **todos os 74 CTs** mapeados para pelo menos 1 teste PHPUnit (CTs manuais ficam para QA pós-deploy).

---

## 1. Visão geral do plano

### 1.1 Escopo

O M9 implementa o segundo bloco de comandos do bot, focado em **consulta** (`/ultimos`, `/categorias`) e **operação assíncrona** (`/sync`, `transactions:sync-pending`, rota cron), além de **consertar gaps** críticos nos handlers existentes (`/start`, `/help`, `/cancelar`) e introduzir um **wizard guiado** (`/nova`) para usuários que preferem não usar linguagem natural.

A especificação técnica já está madura (1.318 linhas, 11 decisões fechadas, ambiguidades eliminadas pelo Portão 2). Este plano traduz essa especificação em **tarefas pequenas, verificáveis e ordenadas por dependência**.

### 1.2 Sequenciamento de alto nível (4 fases)

```
Fase A — Fundação (não bloqueante, 1 dev-dias)
  ├── T-001 StartHandler: clearSession
  ├── T-002 HelpHandler: flags true
  ├── T-003 CancelarHandler: detectar IDLE
  └── T-004 Testes: handlers existentes ajustados

Fase B — Read-only commands (1 dev-dia)
  ├── T-005 TransactionSummaryFormatter: listSummary + listRow
  ├── T-006 UltimosHandler + teste
  ├── T-007 CategoriasHandler + teste
  ├── T-008 Registrar handlers no BotLoader
  └── T-009 Testes: Fase B completa

Fase C — Sync (cron + comando) (1 dev-dia)
  ├── T-010 FirestoreService: resetPendingSyncAttempts + listPendingSync
  ├── T-011 SyncPendingTransactions command + teste
  ├── T-012 SyncHandler (síncrono via /sync) + teste
  ├── T-013 Rota /cron/sync-pending + CSRF exclusion ⚠️ **DEPRECATED — já executado e substituído**
  └── T-014 Testes: Fase C completa

Fase D — Wizard /nova (1 dev-dia, fase mais delicada)
  ├── T-015 ConversationRouter: pickNextAwaitingField com wizard_step
  ├── T-016 ConversationRouter: validateLabels + wizard increment
  ├── T-017 NovaHandler
  ├── T-018 BotLoader: registrar /nova
  ├── T-019 Testes: wizard (happy path + atalhos + atalho natural)
  └── T-020 Testes: regressão M7/M8

Fase E — Hardening (buffer, 0.5 dev-dia)
  ├── T-021 Smoke tests manuais (sequência §7 do plano de testes)
  ├── T-022 Lint + Pint
  └── T-023 Revisão de comentários / PHPDoc

Fase F — Documentação & DoD (0.5 dev-dia)
  └── T-024 Atualizar docs/README se aplicável
```

### 1.3 Diagrama de dependências entre fases

```
Fase A ──┬──> Fase B ──┐
         │             ├──> Fase D ──> Fase E ──> Fase F
         └──> Fase C ──┘
         (Fase C pode paralelizar com Fase B se houver 2 devs)
```

**Dependências críticas:**

- `ConversationRouter` (Fase D) depende da alteração em `pickNextAwaitingField()` — **NÃO BLOQUEANTE** para as outras fases, mas se mexer, deve manter retrocompatibilidade com testes M7/M8 (ver §4 risco 1).
- `BotLoader` (T-008, T-018) é o **ponto de integração** — só pode registrar handlers depois que todos os handlers existirem.
- `FirestoreService` (T-010) é consumido por `SyncHandler` (T-012) E por `SyncPendingTransactions` (T-011) — fazer T-010 antes.
- `TransactionSummaryFormatter::listSummary` (T-005) é usado por `UltimosHandler` (T-006) — dependência direta.

### 1.4 Total de tarefas e esforço

| Fase | Tarefas | Esforço (horas) |
|------|---------|-----------------|
| A — Fundação (gaps) | 4 (T-001 a T-004) | 2–3h |
| B — Read-only | 5 (T-005 a T-009) | 4–5h |
| C — Sync | 5 (T-010 a T-014) | 5–6h |
| D — Wizard /nova | 6 (T-015 a T-020) | 7–9h |
| E — Hardening | 3 (T-021 a T-023) | 2h |
| F — Documentação | 1 (T-024) | 1h |
| **TOTAL** | **24 tarefas** | **~22–28h (≈ 3,5 dev-dias)** |

---

## 2. Tarefas detalhadas

### Legenda

- **Esforço:** `XS` (≤30min), `S` (30min–1h), `M` (1–2h), `L` (2–3h), `XL` (3h+)
- **Tipo:** `IMPL` (implementação), `TEST` (criar teste), `REFAC` (refatoração sem mudar comportamento), `DOC` (documentação), `INTEG` (registrar/integrar)
- **Critério de verificação:** comando exato a rodar, ou asserção específica, ou referência a um CT.

---

### FASE A — Fundação (correção de gaps)

#### T-001 — `StartHandler` deve chamar `clearSession` (GAP-01)

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL |
| **Esforço** | XS (20min) |
| **Arquivos** | `app/Bot/Handlers/StartHandler.php` (modificar) |
| **Dependências** | Nenhuma (T-001 é a primeira tarefa) |
| **CTs cobertos** | CT-023a, CT-023b, CT-023c, CT-023d, CT-023e, CT-023f |
| **Risco** | Baixo — handler é simples; assinatura não muda |

**Descrição técnica:**

1. Injetar dependência via construtor **ou** usar `app()` (consistente com `CancelarHandler` que já usa `app()`). Recomendação: usar `app()` para manter consistência e evitar mexer no container.
2. Em `__invoke()`, ANTES de enviar a mensagem, calcular `$chatId = (int) $bot->message()->chat->id;` e chamar `app(FirestoreService::class)->clearSession((string) $chatId);`.
3. Defesa: se `$bot->message() === null`, retornar silenciosamente (paranoico — Nutgram sempre popula message para comandos, mas é robusto).
4. Manter o método estático `message()` intocado (usado por testes isolados).

**Critério de verificação:**

```bash
bin/dev test --filter "StartHandlerTest::test_start_clears_session"
# ou, sem Docker:
vendor/bin/phpunit --filter StartHandler
```

Teste manual: o coder deve escrever `StartHandlerTest.php` em `tests/Feature/Commands/` (T-004). O teste deve asserir que `FirestoreService::getSession($chatId)` retorna `null` após `__invoke()`. Detalhes na T-004.

---

#### T-002 — `HelpHandler`: flags `active=true` para os 5 comandos do M9 (GAP-03)

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL (1 linha) |
| **Esforço** | XS (10min) |
| **Arquivos** | `app/Bot/Handlers/HelpHandler.php` (modificar 5 entradas) |
| **Dependências** | Nenhuma |
| **CTs cobertos** | CT-024b |
| **Risco** | Nenhum — mudança puramente declarativa |

**Descrição técnica:**

No array retornado por `commands()`, alterar:

```php
['/nova',       '...', false],  // → true
['/cancelar',   '...', false],  // → true
['/ultimos [n]','...', false],  // → true
['/categorias', '...', false],  // → true
['/sync',       '...', false],  // → true
```

**Critério de verificação:**

```bash
bin/dev test --filter "HelpHandlerTest::test_all_m9_commands_marked_active"
```

E asserção inline (sem teste): `HelpHandler::message()` deve conter 7 ocorrências de `✅` (todos os comandos ativos).

---

#### T-003 — `CancelarHandler` deve distinguir IDLE × sessão ativa (GAP-02)

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL |
| **Esforço** | S (40min) |
| **Arquivos** | `app/Bot/Handlers/CancelarHandler.php` (modificar) |
| **Dependências** | Nenhuma |
| **CTs cobertos** | CT-026a, CT-026b, CT-026c, CT-026d, CT-026e |
| **Risco** | Médio — handler é fallback de segurança; comportamento existente não pode quebrar CT-017, CT-047 |

**Descrição técnica:**

1. Injetar `FirestoreService` via `app()` (mesmo padrão de CancelarHandler atual).
2. Em `__invoke()`:
   - Extrair `$chatId` como hoje.
   - **NOVO:** `$session = $firestore->getSession($chatIdStr);`
   - Se `$session === null || $session['state'] === 'idle'`:
     - Enviar mensagem amigável: `🤷 Nenhuma operação em andamento para cancelar.`
     - **NÃO** chamar `clearSession` (já é null) e **NÃO** chamar `notifyCancelled()` (que hoje sempre envia "🚫 Transação cancelada...").
   - Senão: comportamento atual intacto (`clearSession` + `notifyCancelled`).
3. Para evitar regressão no test `test_cancelar_clears_session` (que existe em `ConversationRouterTest` mas testa via Router, não diretamente o handler): o handler continua chamando `clearSession` quando há sessão.

**Critério de verificação:**

```bash
bin/dev test --filter "CancelarHandlerTest"
# 2 casos: IDLE → "Nada para cancelar" / AWAITING_DATA → "Transação cancelada"
```

E verificar manualmente: ao rodar `php artisan test` (T-004), os testes existentes de `ConversationRouterTest::test_awaiting_confirmation_cancel_clears_session` continuam verdes.

---

#### T-004 — Testes PHPUnit dos 3 handlers ajustados (Fase A)

| Atributo | Valor |
|----------|-------|
| **Tipo** | TEST |
| **Esforço** | M (1.5h) |
| **Arquivos** | `tests/Feature/Commands/StartHandlerTest.php` (novo), `tests/Feature/Commands/HelpHandlerTest.php` (novo), `tests/Feature/Commands/CancelarHandlerTest.php` (novo) |
| **Dependências** | T-001, T-002, T-003 |
| **CTs cobertos** | Todos os CTs da Fase A (CT-023*, CT-024*, CT-026*) |
| **Risco** | Baixo — só testes |

**Descrição técnica:**

Criar 3 classes de teste, cada uma usando `InMemoryFirestoreGateway` + `InMemoryBotMessenger` (já bindados pelo `TestCase` se necessário, ou explicitamente no `setUp()` seguindo padrão de `SeedCategoriesCommandTest`).

**1. `StartHandlerTest.php` — 6 testes (CT-023 a CT-023f):**

```php
- test_start_in_idle_sends_welcome
- test_start_in_awaiting_data_clears_session_and_sends_welcome  // CT-023a
- test_start_in_awaiting_confirmation_clears_session            // CT-023b
- test_start_in_awaiting_edition_clears_session                 // CT-023c
- test_start_idempotent_two_invocations                        // CT-023e
- test_start_persisted_session_is_removed                      // CT-023f
```

**2. `HelpHandlerTest.php` — 4 testes (CT-024, CT-024a, CT-024b, CT-024c):**

```php
- test_help_message_lists_seven_commands                       // CT-024
- test_help_in_non_idle_preserves_state                        // CT-024a
- test_help_all_m9_commands_marked_active                     // CT-024b (regressão)
- test_help_uses_html_and_emojis                               // CT-024c
```

**3. `CancelarHandlerTest.php` — 5 testes (CT-026a a CT-026e):**

```php
- test_cancelar_in_idle_shows_nothing_to_cancel                // CT-026a
- test_cancelar_in_awaiting_data_clears_session                // CT-026b
- test_cancelar_in_awaiting_confirmation_clears_session       // CT-026c
- test_cancelar_in_awaiting_edition_clears_session            // CT-026d
- test_cancelar_during_wizard_clears_session                   // CT-026e
```

**Padrão de setup (de `SeedCategoriesCommandTest`):**

```php
protected function setUp(): void
{
    parent::setUp();
    $this->gateway = new InMemoryFirestoreGateway;
    $this->app->instance(FirestoreGateway::class, $this->gateway);
    $this->app->singleton(FirestoreService::class, fn ($app) => new FirestoreService(
        $app->make(FirestoreGateway::class),
    ));
}
```

Para testar handlers que dependem de Nutgram, instanciar o `Nutgram` com mocks (padrão já existe em `ConversationRouterTest` — ver linhas 26-30 e `setUp()`). Alternativa: usar `FakeNutgram` (se disponível na versão instalada) ou um mock simples do `Nutgram` que retorna um `Message` pré-configurado.

**Critério de verificação:**

```bash
bin/dev test --filter "Commands/StartHandlerTest|Commands/HelpHandlerTest|Commands/CancelarHandlerTest"
# Deve passar: 6 + 4 + 5 = 15 testes verdes
```

---

### FASE B — Read-only commands

#### T-005 — `TransactionSummaryFormatter`: métodos `listSummary` + `listRow`

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL |
| **Esforço** | M (1.5h) |
| **Arquivos** | `app/Bot/Messaging/TransactionSummaryFormatter.php` (modificar) |
| **Dependências** | Nenhuma |
| **CTs cobertos** | CT-027 (formato), CT-028g (formato visual) |
| **Risco** | Baixo — métodos novos; código existente intocado |

**Descrição técnica:**

Adicionar 2 métodos públicos + 1 helper (privado) ao `TransactionSummaryFormatter`:

```php
public function listSummary(array $transactions, int $shown): string
{
    // Formato: cabeçalho + N linhas (listRow) + rodapé
    // - "📋 <b>Últimas {shown} transações</b>"
    // - N linhas (1, 2, 3, ...)
    // - "<i>Mostrando {shown} de {total}.</i>" ou "Mostrando {shown}."
}

private function listRow(array $data, int $index): string
{
    // Formato compacto (canônico do spec §2.8):
    // "N. 🍕 <b>{descrição}</b>\n
    //    💸 Despesa · R$ 47,50 · Alimentação\n
    //    📅 15/06/2026 · #almoço #restaurante"
    // - Tipo label via formatType() (já privado)
    // - Valor via formatAmount() (já privado)
    // - Data via formatDate() (já privado)
    // - Labels como #<label> separados por espaço
}
```

**Mapeamento de emojis por categoria (privado):** array `'Alimentação' => '🍕', ...` conforme spec §6.3 tabela (9 categorias + fallback `🏷`).

**Importante:** `listSummary` deve reusar `formatAmount`, `formatType`, `formatDate` (já privados) — não duplicar.

**Critério de verificação:**

```bash
bin/dev test --filter "TransactionSummaryFormatterTest"
# Criar 2-3 testes:
# - test_list_summary_with_multiple_transactions
# - test_list_row_formats_pt_br
# - test_list_summary_empty_returns_friendly_message
```

(Observação: `TransactionSummaryFormatterTest` pode não existir — verificar `tests/Unit/Bot/Messaging/`. Se não existir, criar.)

---

#### T-006 — `UltimosHandler` + teste

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL + TEST |
| **Esforço** | M (1.5h) |
| **Arquivos** | `app/Bot/Handlers/UltimosHandler.php` (novo), `tests/Feature/Commands/UltimosHandlerTest.php` (novo) |
| **Dependências** | T-005 (depende de `listSummary`) |
| **CTs cobertos** | CT-027, CT-027a, CT-027b, CT-027c, CT-028, CT-028a, CT-028b, CT-028c, CT-028d, CT-028e, CT-028f, CT-028g |
| **Risco** | Baixo — handler stateless puro |

**Descrição técnica (`UltimosHandler`):**

```php
public function __invoke(Nutgram $bot): void
{
    $message = $bot->message();
    if ($message === null) return;
    $chatId = (int) $message->chat->id;
    $text = (string) $message->getText();

    // 1. Parse do parâmetro após /ultimos
    preg_match('/^\/ultimos(?:\s+(\S+))?/', $text, $m);
    $rawParam = $m[1] ?? null;

    // 2. Regra da decisão #6 (Portão 2): cap em 50, fallback silencioso em 5
    $n = $rawParam !== null ? (int) $rawParam : 5;
    if ($n < 1 || $n > 50) {
        // Ambiguidade da spec original: "fallback em 5" vs "cap em 50".
        // DECISÃO DO PORTÃO 2: "/ultimos 999999 → cap em 50".
        // Comportamento final:
        //   - n < 1 OU n > 50 E n não numérico → fallback em 5
        //   - n > 50 E n numérico → cap em 50  (consistente com tabela da Clarificação #6)
        // Heurística: se rawParam é puramente numérico E > 50 → cap. Senão → fallback.
        $n = ctype_digit($rawParam) && $n > 50 ? 50 : 5;
    }

    // 3. Query ao Firestore (já existente: listRecent)
    $transactions = app(FirestoreService::class)->listRecent((string) $chatId, $n);

    // 4. Formatação
    $shown = count($transactions);
    if ($shown === 0) {
        $text = "📭 Nenhuma transação registrada ainda.\n\n"
              . "Envie uma mensagem descrevendo um gasto ou receita para começar!";
    } else {
        $text = app(TransactionSummaryFormatter::class)->listSummary($transactions, $shown);
    }

    // 5. Envio
    app(BotMessenger::class)->sendText($chatId, $text);
}
```

**Descrição técnica (`UltimosHandlerTest.php` — 10 testes):**

```php
- test_ultimos_default_5                                   // CT-027
- test_ultimos_with_zero_transactions_shows_friendly      // CT-027a
- test_ultimos_with_only_income                            // CT-027b
- test_ultimos_with_only_expense                           // CT-027c
- test_ultimos_with_param_10                               // CT-028
- test_ultimos_with_param_zero_falls_back_to_5            // CT-028a
- test_ultimos_with_param_negative_falls_back_to_5        // CT-028b
- test_ultimos_with_param_abc_falls_back_to_5             // CT-028c
- test_ultimos_with_param_999999_caps_at_50               // CT-028d  (Portão 2)
- test_ultimos_with_n_greater_than_available_returns_all   // CT-028e
```

**Critério de verificação:**

```bash
bin/dev test --filter "Commands/UltimosHandlerTest"
# 10 testes verdes
```

---

#### T-007 — `CategoriasHandler` + teste

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL + TEST |
| **Esforço** | M (1.5h) |
| **Arquivos** | `app/Bot/Handlers/CategoriasHandler.php` (novo), `tests/Feature/Commands/CategoriasHandlerTest.php` (novo) |
| **Dependências** | Nenhuma (handler stateless; usa `getCategories` já existente) |
| **CTs cobertos** | CT-029, CT-029a, CT-029b, CT-029c, CT-029d, CT-029e, CT-029f |
| **Risco** | Baixo |

**Descrição técnica (`CategoriasHandler`):**

```php
public function __invoke(Nutgram $bot): void
{
    $message = $bot->message();
    if ($message === null) return;
    $chatId = (int) $message->chat->id;

    $categories = app(FirestoreService::class)->getCategories();

    // 1. Ordenar por use_count DESC, depois por display_name ASC
    usort($categories, fn($a, $b) =>
        ($b['data']['use_count'] ?? 0) <=> ($a['data']['use_count'] ?? 0)
        ?: strcmp($a['data']['display_name'] ?? '', $b['data']['display_name'] ?? '')
    );

    // 2. Formatar com emojis (mapeamento fixo)
    $emojiMap = ['Alimentação' => '🍕', 'Transporte' => '🚗', ...];
    $lines = ["📊 <b>Categorias</b>", ""];
    foreach ($categories as $cat) {
        $name = $cat['data']['display_name'] ?? '?';
        $count = $cat['data']['use_count'] ?? 0;
        $emoji = $emojiMap[$name] ?? '🏷';
        $lines[] = "{$emoji} {$name} — {$count} transação" . ($count === 1 ? '' : 'ões');
    }
    $lines[] = "";
    $lines[] = "✨ <i>Crie novas categorias ao registrar transações — elas aparecerão aqui.</i>";

    app(BotMessenger::class)->sendText($chatId, implode("\n", $lines));
}
```

**Descrição técnica (`CategoriasHandlerTest.php` — 5 testes):**

```php
- test_categorias_lists_all_with_use_count       // CT-029
- test_categorias_only_defaults                   // CT-029a
- test_categorias_with_custom                     // CT-029b
- test_categorias_zero_use_count_format           // CT-029c
- test_categorias_preserves_non_idle_state        // CT-029f
```

**Critério de verificação:**

```bash
bin/dev test --filter "Commands/CategoriasHandlerTest"
# 5 testes verdes
```

---

#### T-008 — Registrar handlers no `BotLoader` (parcial — só `/ultimos` e `/categorias`)

| Atributo | Valor |
|----------|-------|
| **Tipo** | INTEG |
| **Esforço** | XS (15min) |
| **Arquivos** | `app/Bot/BotLoader.php` (modificar) |
| **Dependências** | T-006, T-007 (handlers devem existir) |
| **CTs cobertos** | CT-024b, CT-027f (não-cobertura: estado preservado) |
| **Risco** | Baixo — ordem não importa para comandos exatos |

**Descrição técnica:**

Em `registerHandlers()`, ANTES do `onMessage`, adicionar 2 linhas (entre `cancelar` e o `onMessage`):

```php
$bot->onCommand('ultimos', UltimosHandler::class)
    ->description('Ver últimas N transações (padrão 5, máx 50)');

$bot->onCommand('categorias', CategoriasHandler::class)
    ->description('Listar categorias com contador de uso');
```

(Os handlers `/nova` e `/sync` serão registrados em T-018 e T-018b — ver Fase C e D.)

**Critério de verificação:**

```bash
bin/dev test --filter "BotLoaderTest"  # se existir; senão, smoke manual
```

Smoke manual: rodar `bin/dev artisan tinker` e verificar que `Nutgram` tem os handlers. Alternativa: criar um teste simples que chama `BotLoader::registerHandlers($bot)` em um `FakeNutgram` e verifica que `onCommand` foi chamado 5 vezes (start/help/cancelar + ultimos + categorias).

---

#### T-009 — Testes integrados Fase B

| Atributo | Valor |
|----------|-------|
| **Tipo** | TEST |
| **Esforço** | S (45min) |
| **Arquivos** | `tests/Feature/Commands/BotLoaderCommandsTest.php` (novo, opcional) |
| **Dependências** | T-008 |
| **CTs cobertos** | CT-028f (handler não altera estado), CT-029f |
| **Risco** | Baixo |

**Descrição técnica:**

Criar 1 teste integrado: `BotLoaderCommandsTest` que verifica que `/ultimos` e `/categorias` funcionam em QUALQUER estado de sessão (CT-028f, CT-029f — Decisão do Portão 2 #3: "/help em estado não-IDLE → preservar o estado"). Estender a outros comandos de leitura (futuro).

Alternativa: cobrir CT-028f e CT-029f dentro dos próprios `UltimosHandlerTest`/`CategoriasHandlerTest` (adicionar 1 teste em cada).

**Critério de verificação:**

```bash
bin/dev test --filter "Commands"
# Todos os testes das Fases A + B verdes
```

---

### FASE C — Sync (cron + comando)

#### T-010 — `FirestoreService`: `resetPendingSyncAttempts` + `listPendingSync`

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL |
| **Esforço** | M (1.5h) |
| **Arquivos** | `app/Services/Google/FirestoreService.php` (modificar — adicionar 2 métodos) |
| **Dependências** | Nenhuma |
| **CTs cobertos** | CT-033a a CT-033g, CT-051 |
| **Risco** | Médio — `gateway->query` e `gateway->transaction` têm semântica específica no `InMemoryFirestoreGateway`; verificar cobertura |

**Descrição técnica:**

Adicionar 2 métodos públicos (conforme spec §2.11):

```php
public function resetPendingSyncAttempts(string $chatId): int
{
    // 1. Query: chat_id == X AND sync_status == 'pending'
    // 2. Para cada doc, dentro de transaction(): updateFields({sync_attempts: 0, sync_status: 'pending', updated_at: now})
    // 3. Return count
}

public function listPendingSync(?string $chatId = null, int $limit = 20): array
{
    // 1. WHERE sync_status = 'pending' (+ AND chat_id = X se fornecido)
    // 2. ORDER BY created_at ASC
    // 3. LIMIT $limit
    // 4. Filtro em memória: sync_attempts < 3
    // 5. Return lista filtrada
}
```

**Importante:** `gateway->query` no `InMemoryFirestoreGateway` suporta filtros `==` e `!=`; verificar se `updateFields` em massa via `transaction` é seguro.

**Critério de verificação:**

```bash
bin/dev test --filter "FirestoreServiceTest"
# Adicionar 4 testes:
# - test_reset_pending_sync_attempts_returns_count
# - test_reset_pending_sync_attempts_zero_when_empty
# - test_list_pending_sync_filters_by_attempts
# - test_list_pending_sync_orders_by_created_at_asc
```

(Se `FirestoreServiceTest` não existir, criar `tests/Unit/Services/Google/FirestoreServiceTest.php`.)

---

#### T-011 — `SyncPendingTransactions` command + teste

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL + TEST |
| **Esforço** | L (2.5h) |
| **Arquivos** | `app/Console/Commands/SyncPendingTransactions.php` (novo), `tests/Feature/Console/SyncPendingTransactionsCommandTest.php` (novo) |
| **Dependências** | T-010 (depende de `listPendingSync`) |
| **CTs cobertos** | CT-033a a CT-033g, CT-054 a CT-057 |
| **Risco** | Alto — lógica de retry, notificação, contadores; precisa ser retrocompatível com `ConversationRouter::handleConfirm` (que também chama `SyncSheet`) |

**Descrição técnica (command — conforme spec §2.9):**

```php
protected $signature = 'transactions:sync-pending {--chat-id= : Chat ID específico (opcional)} {--format= : text|json (default: text)}';

public function __construct(
    private readonly FirestoreService $firestore,
    private readonly SyncsSheet $syncSheet,
    private readonly ?BotMessenger $messenger = null,  // null no cron
) { parent::__construct(); }

public function handle(): int
{
    $chatId = $this->option('chat-id') ?: null;
    $format = $this->option('format') ?: 'text';
    $docs = $this->firestore->listPendingSync($chatId, limit: 20);
    
    $processed = 0; $synced = 0; $failed = 0; $errors = [];
    
    foreach ($docs as $doc) {
        $id = $doc['id'];
        $data = $doc['data'];
        $processed++;
        
        try {
            $dto = TransactionData::fromArray($data);
            $source = $data['source'] ?? 'text';
            $success = $this->syncSheet->handle($dto, $id, $source);
            
            if ($success) {
                $synced++;
            } else {
                $failed++;
                $fresh = $this->firestore->getTransaction($id) ?? [];
                $attempts = (int) ($fresh['sync_attempts'] ?? 0);
                $errors[] = ['transaction_id' => $id, 'attempts' => $attempts, 'error' => $fresh['sync_error_message'] ?? 'unknown'];
                
                // Decisão do Portão 2: notificar UMA ÚNICA VEZ quando transita para failed
                if ($attempts >= 3 && $this->messenger !== null && empty($fresh['notified_at'])) {
                    $this->messenger->notifyError(
                        (string) ($data['chat_id'] ?? ''),
                        $this->formatFailedMessage($dto, $fresh['sync_error_message'] ?? 'desconhecido')
                    );
                    $this->firestore->markAsNotified($id);  // ver T-010b
                }
            }
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['transaction_id' => $id, 'attempts' => 0, 'error' => $e->getMessage()];
        }
    }
    
    if ($format === 'json') {
        $this->line(json_encode([
            'processed' => $processed,
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ]));
    } else {
        $this->info("Processadas: {$processed}, Sincronizadas: {$synced}, Falhas: {$failed}");
    }
    
    return self::SUCCESS;
}
```

**Nota importante sobre a Decisão do Portão 2 #5** ("notificação `sync_status=failed` → uma única vez via campo `notified_at` no Firestore"):

- Adicionar 1 método a mais no `FirestoreService` (T-010b): `markAsNotified(string $transactionId): void` que faz `updateFields(transactions, $id, {notified_at: nowIso()})`.
- O campo `notified_at` deve ser adicionado ao schema em `saveTransaction()` com valor `null` (não quebrar documentos existentes — merge com null é no-op).
- O command checa `if (empty($fresh['notified_at']))` ANTES de notificar.

**Descrição técnica (`SyncPendingTransactionsCommandTest.php` — 7 testes):**

```php
- test_command_zero_pendents_noop                        // CT-033a
- test_command_one_pendent_syncs                          // CT-033b
- test_command_two_attempts_third_tries_and_succeeds     // CT-033c
- test_command_three_failures_marks_failed_and_notifies  // CT-033d
- test_command_attempts_gte_3_skipped                     // CT-033e
- test_command_sheets_unavailable_increments_attempts    // CT-033f
- test_command_processes_max_20_pendents                  // CT-033g
```

**Mockar `SyncsSheet`:** usar stub similar ao de `ConversationRouterTest` (anônimo, controlando retorno/exceção).

**Mockar `BotMessenger`:** `InMemoryBotMessenger` permite inspecionar `errors`.

**Critério de verificação:**

```bash
bin/dev test --filter "SyncPendingTransactionsCommandTest"
# 7 testes verdes
```

---

#### T-012 — `SyncHandler` + teste (síncrono via `/sync`)

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL + TEST |
| **Esforço** | M (1.5h) |
| **Arquivos** | `app/Bot/Handlers/SyncHandler.php` (novo), `tests/Feature/Commands/SyncHandlerTest.php` (novo) |
| **Dependências** | T-010, T-011 (reusa o command, mas com `BotMessenger` injetado) |
| **CTs cobertos** | CT-048 a CT-053 |
| **Risco** | Médio — executar o command em processo síncrono pode demorar; verificar timeout do Cloud Run (300s) |

**Descrição técnica (`SyncHandler`):**

```php
public function __invoke(Nutgram $bot): void
{
    $message = $bot->message();
    if ($message === null) return;
    $chatId = (int) $message->chat->chat->id;  // typo intencional? ver abaixo
    $chatIdStr = (string) $chatId;

    $firestore = app(FirestoreService::class);
    
    // 1. Resetar sync_attempts para 0 em todas as pendentes deste chat
    $resetCount = $firestore->resetPendingSyncAttempts($chatIdStr);
    
    // 2. Notificar "iniciando sync" se há trabalho
    $messenger = app(BotMessenger::class);
    if ($resetCount === 0) {
        $messenger->sendText($chatId, "📭 Nenhuma transação pendente para sincronizar.\n\n"
                                       . "Todas as suas transações já estão na planilha! ✅");
        return;
    }
    
    $messenger->sendText($chatId, "⏳ Sincronizando {$resetCount} transação(ões) pendente(s)...");
    
    // 3. Executar o command in-process (NÃO queued — síncrono para feedback ao usuário)
    $exitCode = Artisan::call('transactions:sync-pending', [
        '--chat-id' => $chatIdStr,
        '--format' => 'json',
    ]);
    $result = json_decode(Artisan::output(), true) ?? [];
    
    // 4. Resumir resultado
    $synced = (int) ($result['synced'] ?? 0);
    $failed = (int) ($result['failed'] ?? 0);
    $messenger->sendText(
        $chatId,
        "✅ Sincronização concluída!\n"
        . "   • {$synced} sincronizada(s) com sucesso\n"
        . ($failed > 0 ? "   • {$failed} com falha — verifique a planilha\n" : "")
    );
}
```

**Detalhe crítico:** `$message->chat->chat->id` parece errado. O correto é `$message->chat->id`. (Revisar antes de implementar — erro de digitação no esboço acima.)

**`SyncHandlerTest.php` — 4 testes:**

```php
- test_sync_with_zero_pendents                            // CT-048
- test_sync_with_one_pendent_succeeds                      // CT-049
- test_sync_with_multiple_pendents_succeeds                // CT-050
- test_sync_resets_sync_attempts_to_zero                   // CT-051  (Portão 2)
```

**Critério de verificação:**

```bash
bin/dev test --filter "Commands/SyncHandlerTest"
# 4 testes verdes
```

---

#### T-013 — Rota `GET /cron/sync-pending` + exclusão CSRF ⚠️ **DEPRECATED — substituído por Schedule::command()**

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL + INTEG |
| **Esforço** | S (45min) |
| **Arquivos** | `routes/web.php` (modificar) → M11: migrado para `routes/api.php`; `bootstrap/app.php` (modificar) |
| **Dependências** | T-011 (depende do command) |
| **CTs cobertos** | CT-054, CT-055, CT-056, CT-057 |
| **Risco** | Baixo — closure simples; CSRF exclusion é trivial |

**Descrição técnica:**

**Em `routes/web.php`** — adicionar ANTES da rota do webhook: *(M11: migrado para `routes/api.php`; a exclusion de CSRF foi removida porque o api group é stateless)* ⚠️ **DEPRECATED:** A rota HTTP foi completamente removida. O scheduling agora é feito via `Schedule::command('transactions:sync-pending')` em `routes/console.php`, sem rota HTTP.

```php
// ⚠️ DEPRECATED — Esta rota foi removida. Substituída por Schedule::command() em routes/console.php.
// O middleware VerifyCronToken e CRON_SECRET_TOKEN também foram removidos.
Route::get('/cron/sync-pending', function (Request $request): JsonResponse {
    $expected = env('CRON_SECRET_TOKEN'); // DEPRECATED — env var não mais utilizada
    
    if (empty($expected) || $request->header('X-Cron-Token') !== $expected) { // DEPRECATED — X-Cron-Token não mais verificado
        return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    
    $start = hrtime(true);
    \Illuminate\Support\Facades\Artisan::call('transactions:sync-pending', ['--format' => 'json']);
    $result = json_decode(\Illuminate\Support\Facades\Artisan::output(), true) ?? [];
    $duration = (int) ((hrtime(true) - $start) / 1_000_000);
    
    return response()->json([
        'status' => 'ok',
        'processed' => $result['processed'] ?? 0,
        'synced' => $result['synced'] ?? 0,
        'failed' => $result['failed'] ?? 0,
        'errors' => $result['errors'] ?? [],
        'duration_ms' => $duration,
        'timestamp' => now()->toIso8601ZuluString(),
    ]);
})->name('cron.sync-pending');
```

**Em `bootstrap/app.php`** — adicionar 'cron/sync-pending' à lista CSRF exclusion: ⚠️ **(removido — não há mais rota HTTP, CSRF exclusion desnecessária)**

```php
$middleware->validateCsrfTokens(except: [
    'webhook/telegram',
    'cron/sync-pending',  // NOVO — ⚠️ REMOVIDO (rota HTTP não existe mais)
]);
```

**`CronSyncPendingRouteTest.php` (novo, em `tests/Feature/`) — 4 testes:**

```php
- test_cron_valid_token_returns_200_with_json        // CT-054
- test_cron_missing_token_returns_401                // CT-055
- test_cron_invalid_token_returns_401                // CT-056
- test_cron_response_json_structure                  // CT-057
```

**Setup dos testes:** ⚠️ **DEPRECATED — CRON_SECRET_TOKEN não é mais necessário.** Originalmente: o `CRON_SECRET_TOKEN` no `phpunit.xml` precisava estar setado; adicionar `<env name="CRON_SECRET_TOKEN" value="test-cron-token-12345"/>` ao `phpunit.xml`. **NÃO commitar valor real** — usar valor de teste.

**Critério de verificação:**

```bash
bin/dev test --filter "CronSyncPendingRouteTest"
# 4 testes verdes
```

---

#### T-014 — Testes integrados Fase C + regressão

| Atributo | Valor |
|----------|-------|
| **Tipo** | TEST |
| **Esforço** | S (45min) |
| **Arquivos** | (mesmos arquivos dos T-010 a T-013) |
| **Dependências** | T-010 a T-013 |
| **CTs cobertos** | CT-061 (recuperação pós-restore Sheets) |
| **Risco** | Baixo |

**Descrição técnica:**

1. Rodar `bin/dev test` (suíte completa) e garantir zero regressão.
2. Adicionar 1 teste de integração em `SyncHandlerTest`: `test_sync_after_sheets_restored_recovers_pendents` (CT-061).
3. Validar que `ConversationRouterTest` continua 100% verde (regressão M7/M8).

**Critério de verificação:**

```bash
bin/dev test --filter "Feature"
# Todos os testes Feature verdes
```

---

### FASE D — Wizard `/nova` (FASE MAIS DELICADA)

#### T-015 — `ConversationRouter::pickNextAwaitingField` aceita `$session` (retrocompatível)

| Atributo | Valor |
|----------|-------|
| **Tipo** | REFAC (assinatura backward-compatible) |
| **Esforço** | S (45min) |
| **Arquivos** | `app/Conversation/ConversationRouter.php` (modificar) |
| **Dependências** | Nenhuma |
| **CTs cobertos** | (preparação para T-016) |
| **Risco** | **ALTO** — método é chamado em 4+ lugares; precisa manter compatibilidade |

**Descrição técnica:**

Mudar a assinatura de `pickNextAwaitingField`:

```php
// ANTES
private function pickNextAwaitingField(TransactionData $dto): ?string

// DEPOIS (default param para retrocompat)
private function pickNextAwaitingField(TransactionData $dto, array $session = []): ?string
```

**Atualizar todos os 5 callers** (4 dentro do Router + 1 em `enterAwaitingData`):

- `handleAwaitingData()` linha ~239 (foto branch): `$next = $this->pickNextAwaitingField($merged);` → `pickNextAwaitingField($merged, $session);`
- `handleAwaitingData()` linha ~272 (texto branch): idem com `$newDraft, $session`
- `enterAwaitingData()` linha ~590: `pickNextAwaitingField($dto, [])` (sem session — sem wizard aqui, fica IDLE path)
- (Verificar se há mais callers via grep.)

**Importante:** NÃO alterar a lógica de `pickNextAwaitingField` em si. Apenas adicionar o parâmetro e propagar.

**Critério de verificação (REGRESSÃO M7/M8):**

```bash
bin/dev test --filter "ConversationRouterTest"
# 100% verde. Se algum teste falhar, significa que algum caller foi esquecido.
```

**Mitigação adicional:** rodar a suíte COMPLETA, não só o ConversationRouterTest:

```bash
bin/dev test
# Toda a suíte verde
```

---

#### T-016 — `ConversationRouter`: `validateLabels` + branch wizard em `pickNextAwaitingField`

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL |
| **Esforço** | L (2.5h) |
| **Arquivos** | `app/Conversation/ConversationRouter.php` (modificar) |
| **Dependências** | T-015 (parâmetro `$session` disponível) |
| **CTs cobertos** | CT-025 (happy), CT-025a (tipo inválido), CT-025b/c (valor), CT-025d/e (descrição), CT-025h/i/j (labels), CT-025k (atalho natural), CT-025l (sobrescreve sessão), CT-025m (cancelar no meio), CT-025n (timeout) |
| **Risco** | **ALTO** — coração do wizard; várias alterações sensíveis no Router |

**Descrição técnica (3 alterações no Router):**

**1. `pickNextAwaitingField` ganha branch wizard:**

```php
private function pickNextAwaitingField(TransactionData $dto, array $session = []): ?string
{
    // Wizard mode (decisão do spec §1.2): segue ordem fixa
    $wizardStep = (int) ($session['draft']['_wizard_step'] ?? 0);
    if ($wizardStep > 0) {
        return match ($wizardStep) {
            1 => 'type',
            2 => 'amount',
            3 => 'description',
            4 => 'category',
            5 => 'labels',
            default => null,  // wizard completo → confirmation
        };
    }
    
    // Modo normal (linguagem natural): ordem original
    if ($dto->amount === null) return 'amount';
    if ($dto->type === null) return 'type';
    if ($dto->date === null) return 'date';
    if ($dto->description === null) return 'description';
    return null;
}
```

**2. Em `handleAwaitingData()` APÓS `$newDraft = $draft->withField(...)`, ANTES de chamar `pickNextAwaitingField`, incrementar `_wizard_step`:**

```php
$newDraft = $draft->withField($awaitingField, $normalized);

// Wizard step increment (decisão 1.2 do spec)
$sessionDraft = $session['draft'] ?? [];
$wizardStep = (int) ($sessionDraft['_wizard_step'] ?? 0);
if ($wizardStep > 0) {
    $draftArray = $newDraft->toDraftArray();
    $draftArray['_wizard_step'] = $wizardStep + 1;
    $draftArray['_wizard_active'] = true;
    // NÃO usa TransactionData::fromDraftArray (que ignoraria _); passa raw
    $newDraft = TransactionData::fromArray($draftArray);  // ignora chaves _
    
    // (A FAZER) O DTO perde os campos _; precisamos passá-los via $clearFields/setSession.
    // Workaround: setar o draft manualmente, fora do withField pattern.
}
```

**Workaround pragmático:** em vez de tentar preservar `_wizard_step` dentro do DTO, o Router **adiciona os campos `_wizard_*` DEPOIS** de chamar `setSession`:

```php
// Após setSession normal (que passa o DTO limpo):
$this->firestore->setSession(
    chatId: $chatId,
    state: ConversationState::AWAITING_DATA->value,
    draft: $newDraft->toDraftArray() + ['_wizard_step' => $wizardStep + 1, '_wizard_active' => true],
    awaitingField: $next,
    source: $session['source'] ?? 'text',
    retryCount: 0,
);
```

(Ideia: spread operator `+` garante que `_wizard_*` seja mergeado mesmo se o DTO não os tiver.)

**3. Adicionar `validateLabels` + case no `validateField`:**

```php
private function validateField(string $field, string $raw): float|string|null|array
{
    return match ($field) {
        // ... cases existentes ...
        'labels' => $this->validateLabels($raw),
        default => null,
    };
}

private function validateLabels(string $raw): ?array
{
    $cleaned = trim($raw);
    if (mb_strtolower($cleaned) === 'pular') {
        return [];
    }
    $tokens = explode(',', $cleaned);
    $labels = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        $token = ltrim($token, '#');
        $token = trim($token);
        if (mb_strlen($token) >= 2) {
            $labels[] = $token;
        }
    }
    return array_values(array_unique($labels));
}
```

**Importante — atualize o `withField`:** o DTO atual não tem setter para `labels` (somente `withLabels()`). Verificar se `withField('labels', $value)` funciona (deve dar match em algum case). Se não, adicionar o case em `TransactionData::withField()`.

**Atualizar `presentConfirmation` para limpar wizard fields ao chegar lá:**

```php
private function presentConfirmation(string $chatId, TransactionData $dto, string $source): void
{
    $enriched = $this->enrichDtoWithSuggestions($dto);
    $messageId = $this->messenger->sendConfirmationRequest($chatId, $enriched);
    $this->assertStateTransition(...);
    
    $this->firestore->setSession(
        chatId: $chatId,
        state: ConversationState::AWAITING_CONFIRMATION->value,
        draft: $enriched->toDraftArray(),
        awaitingField: null,
        messageIdConfirm: $messageId,
        source: $source,
        retryCount: 0,
        clearFields: ['awaiting_field', '_wizard_step', '_wizard_active'],  // LIMPA WIZARD
    );
}
```

**Critério de verificação:**

```bash
bin/dev test --filter "ConversationRouterTest"
# Suíte completa continua 100% verde (regressão)
# Os testes específicos do wizard (T-019) também
```

---

#### T-017 — `NovaHandler` + helper `chooseTopCategories`

| Atributo | Valor |
|----------|-------|
| **Tipo** | IMPL |
| **Esforço** | M (2h) |
| **Arquivos** | `app/Bot/Handlers/NovaHandler.php` (novo) |
| **Dependências** | T-015, T-016 (Router já suporta wizard) |
| **CTs cobertos** | CT-025, CT-025l (sobrescreve sessão anterior — Portão 2 #2) |
| **Risco** | Médio — handler stateless; principal risco é o cálculo do inline keyboard da etapa 4 |

**Descrição técnica (`NovaHandler`):**

```php
final class NovaHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) return;
        $chatId = (string) (int) $message->chat->id;

        $firestore = app(FirestoreService::class);
        $messenger = app(BotMessenger::class);

        // 1. Limpar sessão anterior (decisão Portão 2 #2: "/nova durante AWAITING_CONFIRMATION
        //    → limpar sessão anterior e iniciar wizard")
        $firestore->clearSession($chatId);

        // 2. Configurar sessão wizard
        $firestore->setSession(
            chatId: $chatId,
            state: ConversationState::AWAITING_DATA->value,
            draft: [
                '_wizard_step' => 1,
                '_wizard_active' => true,
            ],
            awaitingField: 'type',
            source: 'wizard',
            retryCount: 0,
        );

        // 3. Enviar primeira pergunta
        $messenger->askForField(
            $chatId,
            'type',
            "🆕 <b>Nova transação</b> — passo 1/5\n\n"
            . "Qual o tipo da transação?\n\n"
            . "💸 <b>despesa</b> — quando você gasta\n"
            . "💰 <b>receita</b> — quando você recebe"
        );
    }
}
```

**Sobre etapa 4 (categoria com inline keyboard):** conforme spec §1.7 e §3.1, a etapa 4 deve mostrar top 5 categorias + botão "✏️ Digitar outra". Isso é responsabilidade do `ConversationRouter` no `pickNextAwaitingField` quando step=4, OU do próprio `NovaHandler` pré-computando um keyboard.

**Recomendação:** para evitar explosão de complexidade no Router, **o Router apenas chama `askForField` com prompt customizado** (estendendo `askForField` para aceitar keyboard opcional) ou o `NovaHandler` pré-computa e injeta o keyboard via session.

**Decisão prática (a ser tomada pelo coder):**

- **Opção A (simples):** etapa 4 = digitar categoria (sem keyboard). Mais simples, perde conveniência mas evita complexidade. Alinha com `validateCategory` existente.
- **Opção B (com keyboard):** estender `BotMessenger::askForField` para aceitar `?array $keyboard` opcional. Router passa keyboard quando wizard step=4. Mais trabalho mas UX superior.

**Recomendação do plano: Opção A para o M9.1; Opção B pode ficar para iteração futura.** Isso evita mexer na interface `BotMessenger` (que tem 4+ implementações). Documentar como GAP futuro se for o caso.

**Critério de verificação:**

```bash
bin/dev test --filter "Commands/NovaHandlerTest"
# Ver T-019 para testes
```

---

#### T-018 — Registrar `NovaHandler` e `SyncHandler` no `BotLoader`

| Atributo | Valor |
|----------|-------|
| **Tipo** | INTEG |
| **Esforço** | XS (10min) |
| **Arquivos** | `app/Bot/BotLoader.php` (modificar) |
| **Dependências** | T-012, T-017 |
| **CTs cobertos** | (integração — sem CT direto) |
| **Risco** | Baixo |

**Descrição técnica:**

Em `registerHandlers()`, adicionar:

```php
$bot->onCommand('nova', NovaHandler::class)
    ->description('Criar transação passo a passo (wizard)');

$bot->onCommand('sync', SyncHandler::class)
    ->description('Forçar sincronização com a planilha');
```

**Critério de verificação:**

```bash
bin/dev test --filter "BotLoaderCommandsTest"  # ou smoke manual
```

---

#### T-019 — Testes do wizard (TDD ou teste-junto)

| Atributo | Valor |
|----------|-------|
| **Tipo** | TEST |
| **Esforço** | L (3h) |
| **Arquivos** | `tests/Feature/Commands/NovaHandlerTest.php` (novo), `tests/Feature/Conversation/WizardFlowTest.php` (novo) |
| **Dependências** | T-015, T-016, T-017, T-018 |
| **CTs cobertos** | CT-025 a CT-025n (14 sub-CTs do wizard) |
| **Risco** | Baixo — testes são seguros |

**Descrição técnica — `NovaHandlerTest.php` (5 testes):**

```php
- test_nova_in_idle_configures_wizard_session
- test_nova_in_awaiting_confirmation_clears_previous_session     // CT-025l, Portão 2 #2
- test_nova_in_awaiting_data_resets_wizard                       // CT-025m
- test_nova_sends_first_question_for_type
- test_nova_preserves_state_when_help_invoked                    // garante que /help não destrói wizard
```

**Descrição técnica — `WizardFlowTest.php` (8 testes — em `tests/Feature/Conversation/`):**

Estes testes simulam o fluxo COMPLETO do wizard usando o `ConversationRouter` (não o handler isolado), porque o wizard é uma sequência de `handleAwaitingData`:

```php
- test_wizard_step1_type_desp_advances_to_step2_amount
- test_wizard_step1_type_invalid_retries                         // CT-025a
- test_wizard_step2_amount_invalid_retries                      // CT-025b
- test_wizard_step2_amount_zero_retries                         // CT-025c
- test_wizard_step3_description_too_short_retries               // CT-025d
- test_wizard_step5_labels_pular_yields_empty_array             // CT-025i
- test_wizard_step5_labels_comma_separated_yields_array         // CT-025h
- test_wizard_complete_flow_reaches_confirmation                // CT-025 happy
```

**Padrão:** copiar setup de `ConversationRouterTest::setUp()` (linhas 75-154), bindar `InMemoryFirestoreGateway` + `InMemoryBotMessenger`, e usar `$router->route(ConversationInput::text($chatId, $text))` para cada passo do wizard.

**Setup wizard helper:**

```php
private function startWizard(int $chatId): void
{
    // Simula o que NovaHandler faz:
    $this->firestore->setSession(
        chatId: (string) $chatId,
        state: ConversationState::AWAITING_DATA->value,
        draft: ['_wizard_step' => 1, '_wizard_active' => true],
        awaitingField: 'type',
        source: 'wizard',
        retryCount: 0,
    );
}
```

**Critério de verificação:**

```bash
bin/dev test --filter "Commands/NovaHandlerTest|Conversation/WizardFlowTest"
# 5 + 8 = 13 testes verdes
```

---

#### T-020 — Regressão completa M7/M8/M9 (suite-wide)

| Atributo | Valor |
|----------|-------|
| **Tipo** | TEST |
| **Esforço** | S (30min) |
| **Arquivos** | (nenhum novo) |
| **Dependências** | T-001 a T-019 |
| **CTs cobertos** | Todos os CTs marcados como regressão (CT-001, CT-003, CT-007, CT-015, CT-016, CT-017, CT-018, CT-019, CT-020, CT-030, CT-031, CT-034, CT-035, CT-036, CT-043, CT-047) |
| **Risco** | Baixo — só rodar testes |

**Descrição técnica:**

1. Rodar suíte completa:
   ```bash
   bin/dev test
   ```
2. Se algum teste falhar:
   - **NÃO** alterar teste para "passar" — investigar causa raiz.
   - Provavelmente é caller de `pickNextAwaitingField` que não foi atualizado em T-015.
3. Cobertura de statements:
   ```bash
   bin/dev test --coverage-text --filter "Commands|Conversation"
   ```
   Meta: ≥ 90% nos arquivos novos/alterados.

**Critério de verificação:**

```bash
bin/dev test
# TODOS os testes verdes
bin/dev test --coverage-text
# Coverage >= 90% nos arquivos M9
```

---

### FASE E — Hardening

#### T-021 — Smoke tests manuais em staging

| Atributo | Valor |
|----------|-------|
| **Tipo** | TEST (manual) |
| **Esforço** | M (1.5h) |
| **Arquivos** | (nenhum) |
| **Dependências** | T-020 |
| **CTs cobertos** | Todos os 74 CTs do plano de testes (sequência §7) |
| **Risco** | Médio — depende de ambiente staging |

**Descrição técnica:**

Executar a sequência de smoke tests do `m9-plano-testes.md §7` (15 CTs em ~13min). Documentar resultado em `docs/testes/m9-smoke-result.md` (criar se necessário).

**Ordenar por prioridade (re-executar primeiro os CTs de prioridade ALTA):**

1. CT-023, CT-023b, CT-023f (`/start` em vários estados)
2. CT-024b (`/help` com todos os ✅)
3. CT-026a (`/cancelar` em IDLE — **gap crítico**)
4. CT-025 (wizard completo)
5. CT-025l (`/nova` durante AWAITING_CONFIRMATION)
6. CT-028d (`/ultimos 999999` → cap 50)
7. CT-033d (3 falhas → notificação)
8. CT-054, CT-055, CT-056 (rota cron)
9. CT-049, CT-050 (`/sync`)
10. CT-059, CT-060, CT-061 (integração)

**Critério de verificação:**

Smoke test 14/15 PASS (93%) com 0 FAIL em prioridade ALTA → M9 aprovado para merge.

---

#### T-022 — Pint (lint) e estilo de código

| Atributo | Valor |
|----------|-------|
| **Tipo** | DOC (refatoração estilística) |
| **Esforço** | S (30min) |
| **Arquivos** | (todos os novos/alterados) |
| **Dependências** | T-001 a T-020 |
| **CTs cobertos** | N/A |
| **Risco** | Baixo — Pint é idempotente |

**Descrição técnica:**

```bash
bin/dev pint --test         # verifica estilo
bin/dev pint app/           # aplica correções
bin/dev pint tests/
```

Re-rodar testes para garantir que Pint não quebrou nada (não deveria, mas vale checar).

**Critério de verificação:**

```bash
bin/dev pint --test
# "No issues found" ou similar
```

---

#### T-023 — Revisão de comentários e PHPDoc

| Atributo | Valor |
|----------|-------|
| **Tipo** | DOC |
| **Esforço** | S (45min) |
| **Arquivos** | (todos os novos/alterados) |
| **Dependências** | T-022 |
| **Risco** | Nenhum |

**Descrição técnica:**

Cada arquivo novo/alterado deve ter:
- Docblock da classe explicando propósito e referência ao M9 + spec
- Docblock de cada método público explicando entrada/saída
- Comentários inline em blocos não-óbvios (ex.: branch wizard no `pickNextAwaitingField`)

**Critério de verificação:**

Inspeção visual + `bin/dev pint --test` continua verde.

---

### FASE F — Documentação

#### T-024 — Atualizar documentação e fechar M9

| Atributo | Valor |
|----------|-------|
| **Tipo** | DOC |
| **Esforço** | S (1h) |
| **Arquivos** | `docs/06-plano-implementacao.md` (marcar §12 como done), `README.md` (atualizar lista de comandos se aplicável) |
| **Dependências** | T-021, T-022, T-023 |
| **Risco** | Nenhum |

**Descrição técnica:**

1. Em `docs/06-plano-implementacao.md §12`, mudar tabela de tarefas para marcar todas como ✅.
2. Adicionar nota: "M9 implementado — smoke tests 14/15 PASS em <data>".
3. Em `README.md`, se listar comandos, atualizar para incluir `/nova`, `/ultimos`, `/categorias`, `/sync` como disponíveis.
4. Criar/atualizar `docs/CHANGELOG.md` (se existir) com entrada M9.

**Critério de verificação:**

Commit final do M9 com mensagem conventional ("feat: implement M9 — auxiliary commands").

---

## 3. Estratégia de testes

### 3.1 Estrutura de arquivos de teste

```
tests/Feature/Commands/                          (NOVO diretório)
├── StartHandlerTest.php                         (T-004, 6 testes, CT-023*)
├── HelpHandlerTest.php                          (T-004, 4 testes, CT-024*)
├── CancelarHandlerTest.php                      (T-004, 5 testes, CT-026*)
├── UltimosHandlerTest.php                       (T-006, 10 testes, CT-027, 028)
├── CategoriasHandlerTest.php                    (T-007, 5 testes, CT-029)
├── NovaHandlerTest.php                          (T-019, 5 testes, CT-025*)
├── SyncHandlerTest.php                          (T-012, 4 testes, CT-048 a 053)
└── BotLoaderCommandsTest.php                    (T-009, 2 testes — opcional)

tests/Feature/Console/
└── SyncPendingTransactionsCommandTest.php       (T-011, 7 testes, CT-033*)

tests/Feature/
└── CronSyncPendingRouteTest.php                 (T-013, 4 testes, CT-054 a 057)

tests/Feature/Conversation/
└── WizardFlowTest.php                           (T-019, 8 testes, CT-025* wizard)

tests/Unit/Bot/Messaging/  (verificar se existe)
└── TransactionSummaryFormatterTest.php          (T-005, 3 testes, CT-027/028g)

tests/Unit/Services/Google/  (verificar se existe)
└── FirestoreServiceTest.php                     (T-010, 4 testes, CT-051, 033*)
```

**Total estimado:** 65+ testes PHPUnit cobrindo os 74 CTs do plano manual.

### 3.2 Padrão de teste (consolidado de testes existentes)

Todos os testes novos seguem o padrão de `SeedCategoriesCommandTest` e `ConversationRouterTest`:

```php
protected function setUp(): void
{
    parent::setUp();
    
    // Gateway em memória (compartilhado)
    $this->gateway = new InMemoryFirestoreGateway;
    $this->app->instance(FirestoreGateway::class, $this->gateway);
    $this->app->singleton(FirestoreService::class, fn ($app) => new FirestoreService(
        $app->make(FirestoreGateway::class),
    ));
}
```

**Para handlers de comando que dependem de Nutgram:**

```php
private function invokeHandler(Nutgram $bot, string $command): void
{
    $bot->onMessage(new class($command) {
        public function __construct(private string $text) {}
    });
    // ou usar FakeNutgram se disponível
}
```

(Verificar se Nutgram 4.x tem `FakeNutgram` — pode ser necessário criar mock simples.)

### 3.3 Ordem de criação dos testes

**Recomendação: teste JUNTO com implementação** (não TDD estrito), porque:
- TDD puro atrasa quando a interface ainda está sendo moldada (ex.: `withField('labels', ...)`).
- Mas: testes de REGRESSÃO (T-004, T-020) devem vir ANTES de qualquer refatoração (T-015) para detectar quebras.

**Ordem efetiva:**

1. T-001 a T-003 (impl) → T-004 (testes) — Fase A
2. T-005 (impl formatter) → T-006 (impl + test) → T-007 (impl + test) → T-008 (integ) → T-009 (test) — Fase B
3. T-010 (impl Firestore) → T-011 (impl + test) → T-012 (impl + test) → T-013 (impl + test) → T-014 (regressão) — Fase C
4. T-015 (refactor Router c/ testes de regressão inline) → T-016 (impl + testes) → T-017 (impl + test) → T-018 (integ) → T-019 (testes wizard) → T-020 (regressão completa) — Fase D
5. T-021 a T-024 — Fases E e F

### 3.4 Como rodar a suíte

**Ambiente Docker (recomendado — sem PHP local):**

```bash
# Suíte completa
bin/dev test

# Suíte filtrada (M9 only)
bin/dev test --filter "Commands|WizardFlowTest|CronSyncPendingRouteTest|SyncPendingTransactionsCommandTest"

# Por feature individual
bin/dev test --filter "UltimosHandlerTest"
bin/dev test --filter "WizardFlowTest"

# Com coverage (se xdebug instalado)
bin/dev test --coverage-text --filter "Commands|Conversation/Wizard"
```

**Ambiente com PHP 8.4 local:**

```bash
vendor/bin/phpunit
vendor/bin/phpunit --filter "Commands"
vendor/bin/phpunit --coverage-text
```

### 3.5 Cobertura mínima aceitável

| Métrica | Meta |
|---------|------|
| **Statements cobertos (arquivos M9)** | ≥ 90% |
| **Branches cobertos (condicionais críticas)** | ≥ 80% |
| **Assertions por CT PHPUnit** | ≥ 2 (mínimo: 1 verificação de side effect + 1 verificação de estado) |
| **Testes PHPUnit totais** | ≥ 65 (mínimo) |
| **Regressão M0-M8** | 0 falhas |

**CTs sem teste PHPUnit direto** (CTs puramente manuais): CT-024c (visual), CT-028g (visual), CT-062 a CT-064 (whitelist, dependem de Telegram real). Estes ficam para QA pós-deploy.

---

## 4. Riscos operacionais e mitigações

### Risco 1 — Refatoração em `ConversationRouter::pickNextAwaitingField` quebra M7/M8

**Probabilidade:** Média
**Impacto:** **ALTO** — pode invalidar toda a máquina de estados

**Causa:** o método é chamado em 4+ lugares; adicionar parâmetro `$session` requer atualizar todos os callers. Se um caller for esquecido, a wizard não funciona OU o fluxo natural quebra.

**Mitigação concreta:**

1. Antes de tocar no Router (T-015), **rodar `ConversationRouterTest` (baseline)** e confirmar 100% verde.
2. Após a mudança, rodar `bin/dev test` (suíte COMPLETA) — não só ConversationRouterTest.
3. **Estratégia de "parâmetro default":** `$session = []` mantém o comportamento idêntico para callers que não passam o argumento. Isso blinda M7/M8 durante a transição.
4. Adicionar assertion explícita no início do método:
   ```php
   if (!is_array($session)) {
       throw new \InvalidArgumentException('pickNextAwaitingField: $session deve ser array');
   }
   ```
   (defensivo, falha rápido se chamado errado)

**Critério de detecção:** T-020 (regressão completa). Se `ConversationRouterTest` falhar, reverter T-015 e refazer com mais cuidado.

---

### Risco 2 — Ambiente local sem PHP 8.4 / extensões grpc,protobuf

**Probabilidade:** **Alta** (já confirmada — `php: comando não encontrado`)
**Impacto:** Médio — coder não pode rodar testes localmente sem Docker

**Causa confirmada:** `php --version` retorna "comando não encontrado". PHP 8.4 + extensões grpc/protobuf não estão disponíveis nativamente no Ubuntu 26.04 (vide `bin/check-viability.sh` linhas 27-30).

**Mitigação concreta:**

1. **SEMPRE usar `bin/dev test`** (wrapper Docker) — já existe e está documentado em `bin/dev` linhas 1-25.
2. Antes da primeira execução, buildar a imagem: `docker build -t wallet-track:dev --target build -f Dockerfile .` (conforme `bin/dev` linha 23).
3. Se Docker também não estiver disponível: rodar testes em **GitHub Actions / CI** (criar workflow mínimo) — não há `.github/workflows/` ainda, mas é trivial.
4. **Validação rápida de sintaxe sem PHP:** usar `php -l` via Docker: `bin/dev php -l app/Bot/Handlers/NovaHandler.php`.

**Detecção de bloqueio:** se após 2 tentativas `bin/dev test` falhar por erro de ambiente (não de teste), escalar para o usuário ANTES de continuar.

---

### Risco 3 — Lock atômico `/sync` × cron: race condition real (não testada sem rede)

**Probabilidade:** Baixa em staging (1 usuário), Média em produção multi-usuário
**Impacto:** **ALTO** se ocorrer — duplicação na planilha, contadores corrompidos

**Causa:** tanto `/sync` quanto o cron chamam `SyncPendingTransactions` simultaneamente. Sem lock, ambos podem processar a mesma transação.

**Mitigação implementada no spec (e que deve ser testada):**

- O spec §1.3 menciona "campo `processing=true` como lock otimista" — mas **esta otimação NÃO está implementada no escopo do M9 atual**. O spec diz que a coordenação implícita é feita via `sync_status` (atomic write).
- A versão implementada do `SyncSheet::handle()` já faz `updateSyncStatus('synced')` no sucesso — o que é atômico no Firestore. Se o cron e o `/sync` tentarem processar o mesmo doc, a segunda chamada vai:
  1. Tentar `sheets->appendTransaction()` — Sheets API **NÃO é idempotente**, retornará 2 linhas.
  2. Atualizar `sync_status='synced'` de novo (no-op).

**Risco residual:** duplicação na planilha.

**Mitigação concreta no M9 atual:**

1. Documentar no `SyncPendingTransactions` que **NÃO HÁ lock otimista na versão M9.1** (apenas coordenação implícita por `sync_status`).
2. Aceitar a duplicação como risco operacional conhecido — corrigir em iteração futura com `processing=true`.
3. **Teste PHPUnit (CT-052):** simular 2 chamadas concorrentes e verificar que Firestore `sync_status` permanece consistente (mesmo se planilha duplicar). Isso valida o pior caso detectável.
4. **Para produção:** recomendar que o Cloud Scheduler use jitter (não disparar no segundo 0 exato) e que o usuário evite `/sync` durante o cron. Documentar em `docs/runbook.md` (M10).

**Ação concreta no plano:** T-021 (smoke tests manuais) deve incluir CT-052 explicitamente.

---

### Risco 4 — Rota `/cron/sync-pending` como vetor de abuso (rate limit) ⚠️ **MITIGADO — rota substituída por Schedule::command(); não há mais endpoint HTTP exposto**

**Probabilidade:** Baixa (token de 32 bytes hex é ~10^77 de espaço de busca)
**Impacto:** **Crítico** se ocorrer — execução não-autorizada de sync + exfiltração de dados via JSON de resposta

**Causa:** endpoint público que responde JSON com `errors[]` (que pode conter mensagens da Sheets API).

**Mitigações já implementadas (e que devem ser testadas em T-013):**

1. Header `X-Cron-Token` obrigatório (env `CRON_SECRET_TOKEN`, 32 bytes hex = 256 bits). ⚠️ **DEPRECATED — Verificação removida; X-Cron-Token e CRON_SECRET_TOKEN não são mais utilizados.**
2. Verificação `===` exata (não `hash_equals` ainda — TODO opcional: trocar por `hash_equals` para timing-safe).
3. CSRF exclusion (não bloqueia GET, mas exclui de qualquer verificação).

**Mitigações ADICIONAIS recomendadas:**

1. **Logging de tentativas falhas:** cada 401 deve logar IP + timestamp. Útil para detectar scanning.
2. **Rate limit opcional:** Laravel `throttle:5,1` (5 req/min). Trade-off: se o cron falhar 1x e Cloud Scheduler re-tentar, ainda funciona. Pode quebrar retry agressivo. **NÃO adicionar no M9.1.**
3. **Resposta 401 SEM body informativo:** atualmente `{"status":"error","message":"Unauthorized"}` revela que é um endpoint. Preferível: response 401 com `{}` ou `{"status":"error"}` (sem "Unauthorized"). **MUDANÇA RECOMENDADA:** alterar mensagem.

**Ação concreta no plano:** T-013 deve usar `hash_equals()` E resposta sem "Unauthorized" no body. Atualizar spec §1.4 se necessário.

---

### Risco 5 — Wizard `/nova` quebra o contrato de `TransactionData::withField`

**Probabilidade:** Média
**Impacto:** Médio — wizard incompleto

**Causa:** `TransactionData::withField()` (linhas 217-275 do DTO) não tem case para `labels`. Se o Router chamar `withField('labels', $normalized)`, lança `InvalidArgumentException`.

**Mitigação concreta:**

1. Em T-016, ANTES de usar o wizard, **adicionar case `'labels' => $this->withLabels((array) $value)` em `TransactionData::withField`**.
2. Adicionar teste unitário: `test_with_field_labels_returns_dto_with_labels`.
3. Se `withLabels` filtra strings vazias (ver DTO linhas 123-140), isso é compatível com `validateLabels` que já filtra < 2 chars.

**Detecção:** T-019 (testes wizard) — se `WizardFlowTest::test_wizard_step5_labels_pular_yields_empty_array` falhar, é este risco.

---

### Risco 6 — Decisão Portão 2 #5 (`notified_at` no Firestore) mal implementada

**Probabilidade:** Média
**Impacto:** Médio — usuário pode receber múltiplas notificações de falha

**Causa:** se `notified_at` não for persistido ANTES de chamar `notifyError`, e o command crashar entre eles, o próximo cron notificaria de novo.

**Mitigação concreta:**

1. Em T-011, **marcar `notified_at = nowIso()` ANTES de chamar `notifyError()`**, dentro da mesma transação. Ordem:
   ```php
   // 1. Marca como notificado (atômico)
   $this->firestore->markAsNotified($id);
   // 2. Envia mensagem (best-effort)
   $this->messenger->notifyError(...);
   ```
2. Se a mensagem falhar, na próxima execução o command não re-notifica (correto — o usuário já sabe).
3. Se a marcação falhar, command aborta (decisão conservadora: melhor duplicar notificação do que perder).

**Ação concreta:** revisar T-010b (T-011 cita o método `markAsNotified`) e garantir que o `InMemoryFirestoreGateway` suporte o `updateFields` necessário.

---

### Risco 7 — CSRF exclusion adicionada errada causa 419 em produção

**Probabilidade:** Baixa
**Impacto:** **Alto** se cron para de funcionar

**Causa:** se `cron/sync-pending` ficar de fora da exclusion indevidamente, Laravel retorna 419 (CSRF token mismatch) e o cron não roda. ⚠️ **OBSOLETO — rota HTTP removida; o agendamento agora é interno via Laravel Scheduler (`routes/console.php`), sem CSRF.**

**Mitigação concreta:**

1. Em T-013, adicionar teste explícito: `test_cron_route_does_not_require_csrf_token` — fazer GET sem token CSRF e verificar 200 (com token de auth correto).
2. Verificar que a string `'cron/sync-pending'` está em `validateCsrfTokens(except: [...])` no `bootstrap/app.php`. ⚠️ **DEPRECATED — CSRF exclusion removida; rota HTTP não existe mais.**
3. Após deploy, fazer curl manual em staging.

---

### Risco 8 — Mensagens de boas-vindas do `/start` e `/help` diferentes entre spec e implementação existente

**Probabilidade:** Baixa
**Impacto:** Baixo — cosmético

**Causa:** o `StartHandler::message()` atual (linhas 25-44) já está bom, mas o spec sugere revisão. O `HelpHandler::message()` será regenerado pelas flags em T-002.

**Mitigação concreta:**

1. Não mexer em `StartHandler::message()` (já cobre CT-023).
2. Verificar que `HelpHandler::message()` regenerado contém os 7 comandos.
3. CT-024c (verificação visual) fica para QA manual.

---

## 5. Estratégia de commits e PRs

### 5.1 Quantos PRs?

**Recomendação: 6 PRs atômicos**, um por fase (A a F). Isso permite:

- Code review focado por fase (revisores não precisam entender TUDO de uma vez).
- Rollback granular se uma fase quebrar.
- CI mais rápido (PRs menores = feedback mais rápido).

**Alternativa (não recomendada): 1 PR monolítico.** Problema: 24 tarefas em 1 PR = review impossível, conflitos de merge, alto risco de "approve sem ler".

### 5.2 Convenção de mensagem de commit

**Conventional Commits + referência ao M9:**

```
<type>(<scope>): <subject>  [M9.1]

<body>

<footer>
```

**Exemplos:**

```
feat(bot): add /ultimos and /categorias handlers  [M9.1]

- Implements listRecent-based read-only commands
- Caps /ultimos 999999 at 50 (Portão 2 #1)
- Categories sorted by use_count DESC

Refs: docs/specs/m9-spec-fase-2.md §2.2, §2.3
Tests: tests/Feature/Commands/{Ultimos,Categorias}HandlerTest.php
```

```
feat(bot): implement /nova wizard with 5 steps  [M9.1]

- NovaHandler configures wizard session with _wizard_step
- ConversationRouter.pickNextAwaitingField respects wizard_step
- validateLabels accepts comma-separated or "pular"
- /nova during AWAITING_CONFIRMATION clears previous session (Portão 2 #2)

Refs: docs/specs/m9-spec-fase-2.md §1.2, §1.10
Tests: tests/Feature/Commands/NovaHandlerTest.php, tests/Feature/Conversation/WizardFlowTest.php
```

**Tipos:** `feat` (novo), `fix` (bug), `refactor` (sem mudança comportamental), `test` (só testes), `docs` (só docs), `chore` (build, deps).

### 5.3 Ordem de merge

**Sequencial por fase (NÃO em paralelo):**

1. PR #1: Fase A (T-001 a T-004) — gaps + testes — *depende de nada*
2. PR #2: Fase B (T-005 a T-009) — read-only — *depende de #1 (regressão)*
3. PR #3: Fase C (T-010 a T-014) — sync — *depende de #1 (regressão)*
4. PR #4: Fase D (T-015 a T-020) — wizard — *depende de #1, #2, #3 (regressão completa)*
5. PR #5: Fase E (T-021 a T-023) — hardening — *depende de #4*
6. PR #6: Fase F (T-024) — docs/DoD — *depende de #5*

**Possível paralelização (se 2 devs):** PR #2 e PR #3 podem ser paralelos (pouca interdependência de código — apenas `FirestoreService` que tem `resetPendingSyncAttempts` em T-010, então #3 depende de #2 OU de T-010 ser commitado primeiro no #1 ou como #1.5).

### 5.4 Checklist pré-merge

Para CADA PR:

- [ ] `bin/dev test` 100% verde (não só o filtro do PR — suíte completa)
- [ ] `bin/dev pint --test` 0 issues
- [ ] Novos testes têm pelo menos 2 assertions cada
- [ ] Docblocks PHPDoc em todas as classes/métodos públicos novos
- [ ] Sem credenciais ou tokens commitados (verificar `.env`, `*.json`)
- [ ] `git status` limpo (sem arquivos temporários)
- [ ] Mensagem de commit segue conventional commits
- [ ] Branch nomeado `feature/m9-<fase>` (ex.: `feature/m9-readonly-commands`)
- [ ] Rebase com `main` antes do merge (evitar commits de merge)

Para o PR final (#6):

- [ ] Smoke test 14/15 PASS em staging (CT-024c, CT-028g, CT-062-064 ficam como follow-up)
- [ ] `docs/06-plano-implementacao.md §12` marcado como ✅
- [ ] Tag de release: `v0.9.0-m9` (ou conforme convenção do projeto)

---

## 6. Definition of Done do M9

O M9 está pronto para `reviewer` quando **TODOS** os itens abaixo estão ✅:

### 6.1 Funcionalidade

- [ ] CT-023 (a-f): `/start` reseta sessão em todos os estados
- [ ] CT-024 (a-c): `/help` lista 7 comandos com `✅` no M9 final
- [ ] CT-025 (a-n): `/nova` wizard 5 passos + atalhos + timeout + sobrescreve sessão
- [ ] CT-026 (a-e): `/cancelar` em todos os estados, IDLE = "Nada para cancelar"
- [ ] CT-027 (a-c): `/ultimos` com 5 default, mensagens vazias e variação
- [ ] CT-028 (a-g): `/ultimos [n]` com todos os fallbacks e cap em 50
- [ ] CT-029 (a-f): `/categorias` com contador de uso e ordem
- [ ] CT-033 (a-g): `transactions:sync-pending` com 0/1/N pendentes, 3 falhas, batch
- [ ] CT-048 a CT-053: `/sync` com reset, lock implícito, em todos os estados
- [ ] CT-054 a CT-057: `GET /cron/sync-pending` com 200/401/JSON estruturado ⚠️ **DEPRECATED — testes de rota HTTP removidos; substituídos por testes do comando artisan `transactions:sync-pending`**
- [ ] CT-058 a CT-061: Integração (sobrescreve, aparece no topo, contador++, recovery)

### 6.2 Código

- [ ] 24 tarefas (T-001 a T-024) implementadas e commitadas
- [ ] 6 PRs mergeados sequencialmente
- [ ] `vendor/bin/phpunit` (via `bin/dev test`) 100% verde, sem testes pulados
- [ ] Cobertura ≥ 90% nos arquivos novos/alterados
- [ ] `bin/dev pint --test` 0 issues
- [ ] Sem TODOs ou FIXMEs deixados no código
- [ ] Sem código comentado (dead code)
- [ ] PHPDoc em todas as classes/métodos públicos novos

### 6.3 Operacional

- [ ] Smoke test 14/15 PASS em staging (T-021)
- [ ] ~~`CRON_SECRET_TOKEN` configurado no Secret Manager (não commitar valor)~~ ⚠️ **DEPRECATED — env var removida; não mais necessária**
- [ ] ~~Rota `/cron/sync-pending` adicionada à exclusão CSRF~~ ⚠️ **DEPRECATED — rota HTTP removida; agendamento via Schedule::command()**
- [ ] Cloud Scheduler configurado para `*/5 * * * *` (M10, mas verificar que o endpoint responde)
- [ ] `docs/06-plano-implementacao.md §12` marcado como ✅
- [ ] CHANGELOG atualizado (se aplicável)
- [ ] Branch `main` contém todos os commits do M9

### 6.4 Segurança

- [ ] CT-062 a CT-064 (whitelist) — verificar em staging que chat não-whitelistado recebe 403
- [ ] Token do cron validado com `hash_equals` (timing-safe) — melhoria opcional
- [ ] Logs de tentativas 401 registradas

### 6.5 Gaps

- [ ] GAP-01 (Start reseta): RESOLVIDO em T-001
- [ ] GAP-02 (Cancelar detecta IDLE): RESOLVIDO em T-003
- [ ] GAP-03 (Help flags): RESOLVIDO em T-002
- [ ] GAP-04 (handlers não existem): RESOLVIDO em T-006, T-007, T-012, T-017
- [ ] GAP-05 (command não existe): RESOLVIDO em T-011
- [ ] GAP-06 (rota não existe): RESOLVIDO em T-013
- [ ] GAP-07 (BotLoader não registra): RESOLVIDO em T-008, T-018

---

## 7. Pontos de verificação (checkpoints) com o usuário

Durante a execução do M9, pausar e reportar ao usuário nos seguintes pontos:

### Checkpoint 1 — Após T-004 (Fase A completa: gaps resolvidos)

**Quando:** Após merge do PR #1.
**O que reportar:**
- Confirmação de que `/start`, `/help`, `/cancelar` estão com comportamento correto em todos os estados.
- 15 testes PHPUnit passando.
- Mudança visível no `/help` (todos os ✅ aparecem).
- Mudança de comportamento no `/cancelar` em IDLE (mensagem diferente).

**Decisão do usuário necessária:** Nenhuma (decisões já tomadas no Portão 2).

**Por que esse checkpoint:** O usuário pode validar o "tom" das mensagens (PT-BR, emojis) e pedir ajustes pequenos (ex.: "mude o emoji de X para Y") sem ter que esperar o M9 inteiro.

---

### Checkpoint 2 — Após T-009 (Fase B completa: read-only commands funcionais)

**Quando:** Após merge do PR #2.
**O que reportar:**
- `/ultimos` funcional com cap em 50.
- `/categorias` funcional com contador de uso.
- Possível observação visual sobre formatação das listas (texto muito comprido? emojis OK?).

**Decisão do usuário:** Possível ajuste cosmético de mensagens (formato, emojis, ordem dos campos).

---

### Checkpoint 3 — Após T-014 (Fase C completa: sync funcional)

**Quando:** Após merge do PR #3.
**O que reportar:**
- `/sync` reseta contador e sincroniza.
- `transactions:sync-pending` command funciona (testado com 0, 1, 3, 20 pendentes).
- Notificação de 3 falhas funciona.
- ~~Rota `/cron/sync-pending` retorna 200 com token válido, 401 sem.~~ ⚠️ **DEPRECATED — rota removida; o comando `transactions:sync-pending` agora é executado via Laravel Scheduler.**

**Decisão do usuário:**
- Validar tom da mensagem de notificação ("⚠️ Sincronização falhou..." conforme spec §1.5).
- Confirmar que 1x notificação por falha (não múltiplas) está OK.

---

### Checkpoint 4 — Após T-020 (Fase D completa: wizard funcional + regressão M7/M8)

**Quando:** Após merge do PR #4.
**O que reportar:**
- Wizard `/nova` 5 passos completo.
- Atalho de linguagem natural funciona (DeepSeek durante wizard).
- `/nova` durante AWAITING_CONFIRMATION descarta sessão anterior.
- Todos os 74 CTs mapeados para ≥ 1 teste PHPUnit.
- **CRÍTICO:** `ConversationRouterTest` continua 100% verde (sem regressão M7/M8).

**Decisão do usuário:**
- Validar tom do wizard (passo 1/5, passo 2/5, etc.).
- Decidir se etapa 4 (categoria) usa inline keyboard (Opção B do T-017) ou só texto (Opção A). **Recomendação do plano: Opção A para o M9.1, B como follow-up.**

---

### Checkpoint 5 — Após T-024 (M9 totalmente concluído)

**Quando:** Após merge do PR #6.
**O que reportar:**
- Todos os CTs (74) com status PASS ou FOLLOW-UP documentado.
- Smoke test 14/15 PASS.
- Tag de release `v0.9.0-m9` aplicada.
- Pronto para M10 (deploy Cloud Run).

**Decisão do usuário:**
- Aprovar início de M10.

---

## 8. Sequência sugerida para 1 dev (passo a passo)

> **Premissa:** 1 dev, ~3-4 dev-dias corridos, ambiente com Docker (`bin/dev test`).

```
DIA 1 (Fase A + B)
=====================================================================
01. [30min] T-001: StartHandler::clearSession (ler + impl)
02. [30min] T-004a: StartHandlerTest (6 testes, rodar e validar)
03. [10min] T-002: HelpHandler flags (5 linhas)
04. [20min] T-004b: HelpHandlerTest (4 testes, rodar)
05. [40min] T-003: CancelarHandler IDLE detection
06. [30min] T-004c: CancelarHandlerTest (5 testes, rodar)
07. [15min] git commit + PR #1: "fix(bot): resolve M9 gaps + tests"
08. [45min] T-005: TransactionSummaryFormatter::listSummary/listRow
09. [30min] T-005b: FormatterTest (3 testes)
10. [60min] T-006: UltimosHandler + UltimosHandlerTest (10 testes)
11. [60min] T-007: CategoriasHandler + CategoriasHandlerTest (5 testes)
12. [20min] T-008: BotLoader::registerHandlers (ultimos + categorias)
13. [20min] T-009: BotLoaderCommandsTest (2 testes, opcional)
14. [15min] git commit + PR #2: "feat(bot): add /ultimos and /categorias"

   [CHECKPOINT 1 — Reportar para usuário]
   [CHECKPOINT 2 — Reportar para usuário]

DIA 2 (Fase C)
=====================================================================
15. [60min] T-010: FirestoreService::resetPendingSyncAttempts + listPendingSync
16. [30min] T-010b: FirestoreServiceTest (4 testes, NOVO arquivo)
17. [90min] T-011: SyncPendingTransactions command
18. [60min] T-011b: SyncPendingTransactionsCommandTest (7 testes)
19. [60min] T-012: SyncHandler
20. [30min] T-012b: SyncHandlerTest (4 testes)
21. [30min] T-013: rota /cron/sync-pending + CSRF exclusion ⚠️ **DEPRECATED — tarefa executada e posteriormente substituída**
22. [30min] T-013b: CronSyncPendingRouteTest (4 testes)
23. [15min] T-014: regressão completa
24. [20min] git commit + PR #3: "feat(bot): implement /sync and cron command"

   [CHECKPOINT 3 — Reportar para usuário]

DIA 3 (Fase D)
=====================================================================
25. [30min] git pull + bin/dev test (baseline verde antes de mexer no Router)
26. [45min] T-015: pickNextAwaitingField com $session (refactor retrocompatível)
27. [30min] bin/dev test (regressão M7/M8)
28. [60min] T-016a: validateLabels + case 'labels' em TransactionData::withField
29. [30min] T-016b: branch wizard em pickNextAwaitingField
30. [30min] T-016c: limpar _wizard_* em presentConfirmation
31. [60min] T-017: NovaHandler
32. [20min] T-018: BotLoader::registerHandlers (nova + sync)
33. [60min] T-019a: NovaHandlerTest (5 testes)
34. [90min] T-019b: WizardFlowTest (8 testes)
35. [30min] T-020: bin/dev test (regressão COMPLETA)
36. [20min] git commit + PR #4: "feat(bot): implement /nova wizard"

   [CHECKPOINT 4 — Reportar para usuário — CRÍTICO]

DIA 4 (Fases E + F + buffer)
=====================================================================
37. [90min] T-021: smoke tests manuais em staging
38. [30min] T-022: bin/dev pint --test + aplicar
39. [45min] T-023: revisão de PHPDoc e comentários
40. [30min] bin/dev test (suíte final, deve estar 100% verde)
41. [60min] T-024: atualizar docs (docs/06 §12, README, CHANGELOG)
42. [20min] git commit + PR #5 (lint+docs) + PR #6 (release)
43. [30min] tag de release v0.9.0-m9
44. [30min] reportar conclusão ao usuário

   [CHECKPOINT 5 — Reportar M9 completo]
```

**Total real:** ~22-28h = 3,5 dev-dias (8h/dia).

---

## 9. Resumo executivo (para retorno)

### 9.1 Total de tarefas definidas

**24 tarefas** (T-001 a T-024), distribuídas em 6 fases:
- **Fase A (Fundação/Gaps):** 4 tarefas
- **Fase B (Read-only):** 5 tarefas
- **Fase C (Sync):** 5 tarefas
- **Fase D (Wizard /nova):** 6 tarefas
- **Fase E (Hardening):** 3 tarefas
- **Fase F (Documentação):** 1 tarefa

### 9.2 Sequência numerada de execução (1 dev)

Ver **Seção 8** — 44 micro-passos ao longo de 4 dias. **Ordenação crítica:**

1. **T-001 → T-004 (Fase A):** consertar 3 gaps + testes — base segura.
2. **T-005 → T-009 (Fase B):** comandos de leitura puros.
3. **T-010 → T-014 (Fase C):** sync + cron — independente do wizard.
4. **T-015 → T-020 (Fase D):** wizard — fase mais delicada, requer baseline verde antes.
5. **T-021 → T-024 (Fases E+F):** smoke test + lint + docs.

### 9.3 Os 3 maiores riscos identificados

1. **Refatoração em `ConversationRouter::pickNextAwaitingField` quebra M7/M8** (probabilidade média, impacto ALTO).
   - **Mitigação:** parâmetro com default value `[]` mantém retrocompatibilidade; rodar suíte COMPLETA após cada commit na Fase D.

2. **Lock atômico `/sync` × cron tem race condition residual** (probabilidade baixa em staging, impacto ALTO se ocorrer).
   - **Mitigação:** coordenação implícita por `sync_status` (atômico no Firestore); aceito como risco operacional conhecido, corrigir em iteração futura com campo `processing=true`. Documentar em runbook.

3. **Ambiente local sem PHP 8.4 e extensões grpc/protobuf** (probabilidade ALTA confirmada, impacto médio).
   - **Mitigação:** usar `bin/dev test` (wrapper Docker) que já existe; buildar imagem `wallet-track:dev` antes de começar.

### 9.4 Estimativa de esforço total

- **Horas:** 22–28 horas
- **Dev-dias:** 3,5 (8h/dia, 1 dev)
- **Dias corridos:** 4 (com checkpoints no usuário consumindo ~1-2h por dia)

### 9.5 Pontos de checkpoint com o usuário

5 checkpoints, ao final de cada fase. Os 2 mais importantes:

- **Checkpoint 1 (após Fase A):** validar tom das mensagens de `/start`, `/help`, `/cancelar` antes de avançar. Pedidos cosméticos pequenos são comuns aqui.
- **Checkpoint 4 (após Fase D):** **CRÍTICO** — validar wizard `/nova` e confirmar que M7/M8 continuam intactos. Decisão sobre Opção A vs B para etapa 4 (categoria com/sem keyboard).

### 9.6 Perguntas para o usuário

**Não há ambiguidade operacional que precise decisão** — todas as 11 decisões técnicas estão fechadas no spec e no Portão 2. As únicas "decisões" remanescentes são:

1. **(Cosmética, não bloqueante)** Tom exato das mensagens do wizard (passo 1/5, etc.) — pode ser ajustado após Checkpoint 1.
2. **(Técnica, Opção A vs B do T-017)** Etapa 4 do wizard usa inline keyboard com top 5 categorias (Opção B, melhor UX) ou apenas texto (Opção A, mais simples)? **Recomendação do plano: Opção A para M9.1.** Se o usuário quiser Opção B, é +1-2h de trabalho (estender `BotMessenger::askForField`).

Se houver restrição de tempo, **recomendo explicitamente:** começar com Opção A, validar em staging, e fazer Opção B como M9.1.1 se houver demanda.

---

## 10. Anexo: Mapeamento Tarefa × CT

| Tarefa | CTs cobertos |
|--------|--------------|
| T-001 (Start reseta) | CT-023, CT-023a, CT-023b, CT-023c, CT-023d, CT-023e, CT-023f |
| T-002 (Help flags) | CT-024b |
| T-003 (Cancelar IDLE) | CT-026a, CT-026b, CT-026c, CT-026d, CT-026e |
| T-004 (testes Fase A) | (todos acima) |
| T-005 (Formatter) | CT-027 (formato), CT-028g |
| T-006 (Ultimos) | CT-027, CT-027a, CT-027b, CT-027c, CT-028, CT-028a, CT-028b, CT-028c, CT-028d, CT-028e, CT-028f |
| T-007 (Categorias) | CT-029, CT-029a, CT-029b, CT-029c, CT-029d, CT-029e, CT-029f |
| T-008 (BotLoader parcial) | (integração) |
| T-009 (testes Fase B) | CT-028f, CT-029f |
| T-010 (Firestore novos) | CT-033a a CT-033g, CT-051 |
| T-011 (SyncPending command) | CT-033a a CT-033g |
| T-012 (SyncHandler) | CT-048, CT-049, CT-050, CT-051, CT-052, CT-053 |
| T-013 (rota cron) | CT-054, CT-055, CT-056, CT-057 |
| T-014 (regressão Fase C) | CT-061 |
| T-015 (Router pickNext refactor) | (preparação) |
| T-016 (Router wizard) | CT-025, CT-025a a CT-025j, CT-025k, CT-025m, CT-025n |
| T-017 (NovaHandler) | CT-025, CT-025l |
| T-018 (BotLoader nova+sync) | (integração) |
| T-019 (testes wizard) | CT-025*, CT-058 |
| T-020 (regressão completa) | CT-001, CT-003, CT-007, CT-015-020, CT-030-031, CT-034-036, CT-043, CT-047, CT-059-060 |
| T-021 (smoke manual) | CT-024c, CT-062, CT-063, CT-064 (visuais/whitelist) |
| T-022 (Pint) | N/A |
| T-023 (PHPDoc) | N/A |
| T-024 (docs) | N/A |

**Total de CTs cobertos por testes PHPUnit:** 64/74 (10 CTs visuais/whitelist ficam para QA manual em T-021).

---

**FIM DO PLANO TÉCNICO DE IMPLEMENTAÇÃO — M9**

> *Documento gerado pelo agente `tech-planner` em 19/06/2026.*
> *Entrada: `docs/specs/m9-spec-fase-2.md` (1.318 linhas) + `docs/testes/m9-plano-testes.md` (74 CTs) + `docs/06-plano-implementacao.md §12` + código atual do repositório (`main` em `f54ea52`).*
> *Saída: 24 tarefas executáveis em 4 dev-dias, com 5 checkpoints, 6 PRs, e ~65 testes PHPUnit novos.*
