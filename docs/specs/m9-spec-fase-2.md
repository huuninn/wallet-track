# Especificação Técnica: M9 — Comandos Auxiliares (Fase 2)

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

> **Status:** Fase 2 — Especificação Técnica executável.
> **Data:** 19/06/2026
> **Base:** Plano de implementação §12 + Clarificações (#5, #6, #7) + Especificação Técnica §5–§7.
> **Milestones anteriores implementados:** M0–M8 no branch `main`.

---

## 0. Contexto e Escopo

Este documento é a **Fase 2 (Especificação Técnica)** do agente `spec-designer` para o M9. A Fase 1 (análise de negócio) já foi aprovada pelo usuário; este documento **NÃO revisita decisões já tomadas** — apenas especifica detalhes técnicos para implementação direta.

### O que já existe (não será refeito)

| Item | Arquivo | Estado |
|------|---------|--------|
| `/start` | `app/Bot/Handlers/StartHandler.php` | ✅ Implementado — mensagem estática |
| `/help` | `app/Bot/Handlers/HelpHandler.php` | ✅ Implementado — lista comandos (alguns `active=false`) |
| `/cancelar` | `app/Bot/Handlers/CancelarHandler.php` | ✅ Implementado — clearSession + notifyCancelled |
| ConversationRouter | `app/Conversation/ConversationRouter.php` | ✅ Implementado — máquina de estados completa |
| BotMessenger | `app/Bot/Messaging/BotMessenger.php` | ✅ Interface completa |
| NutgramBotMessenger | `app/Bot/Messaging/NutgramBotMessenger.php` | ✅ Implementação concreta |
| TransactionSummaryFormatter | `app/Bot/Messaging/TransactionSummaryFormatter.php` | ✅ Formatador PT-BR |
| FirestoreService | `app/Services/Google/FirestoreService.php` | ✅ CRUD + listRecent + updateSyncStatus |
| SyncSheet / SyncsSheet | `app/Actions/SyncSheet.php` / `SyncsSheet.php` | ✅ Interface + implementação |
| BotLoader | `app/Bot/BotLoader.php` | ✅ Registro de handlers |

### O que o M9 precisa entregar de novo

| # | Tarefa | Arquivo(s) |
|---|--------|------------|
| M9.2 | Atualizar HelpHandler (flags `active`) | `app/Bot/Handlers/HelpHandler.php` |
| M9.3 | `/nova` wizard | `app/Bot/Handlers/NovaHandler.php` + alteração em `ConversationRouter` |
| M9.5 | `/ultimos [n]` | `app/Bot/Handlers/UltimosHandler.php` + `FirestoreService` (sem alteração) |
| M9.6 | `/categorias` | `app/Bot/Handlers/CategoriasHandler.php` + `FirestoreService` (sem alteração) |
| M9.7 | `/sync` | `app/Bot/Handlers/SyncHandler.php` + `FirestoreService` (novo método) |
| M9.8 | `transactions:sync-pending` | `app/Console/Commands/SyncPendingTransactions.php` |
| M9.9 | `GET /cron/sync-pending` | Controller ou closure em `routes/web.php` (M11: web.php → api.php) |
| M9.10 | Testes | `tests/Feature/Commands/` (vários arquivos) |

---

## 1. Decisões Técnicas

### 1.1 Onde mora a lógica de listagem de transações?

**Recomendação:** Usar diretamente `FirestoreService::listRecent(chatId, limit, type)` — sem nova Action.

**Justificativa:** O método já existe e é bem testado (`FirestoreServiceTest`). A listagem é uma leitura simples (query → format → send), sem regra de negócio que justifique extrair para uma Action. O padrão "Controllers magros → Services especializados → Actions" não exige que toda chamada de Service passe por uma Action — apenas operações com lógica de negócio cross-cutting. Listar é projeção pura.

```php
// Assinatura existente:
public function listRecent(string $chatId, int $limit = 10, ?string $type = null): array
// Retorna: list<array{id: string, data: array<string, mixed>}>
```

Os índices compostos `chat_id ASC, date DESC` e `chat_id ASC, type ASC, date DESC` já existem em `firestore.indexes.json` — sem necessidade de novos índices.

### 1.2 Como o `/nova` integra com o ConversationRouter?

**Recomendação:** O `NovaHandler` configura a sessão no estado `AWAITING_DATA` com um campo adicional `wizard_step` e deixa o `ConversationRouter` gerenciar o restante. O Router recebe uma modificação **mínima** em `pickNextAwaitingField()` para respeitar a ordem do wizard quando `wizard_step` está presente.

**Justificativa:** A alternativa (máquina de estados separada) duplicaria toda a lógica de validação de campos (`validateAmount`, `validateType`, `validateDate`, `validateDescription`, `validateCategory`), retry, timeout, merge de drafts e `presentConfirmation` que já está madura no Router. A alteração necessária no Router é de ~10 linhas — um hook condicional que só se ativa quando `wizard_step` está definido.

**Campos de sessão adicionados (apenas quando wizard ativo):**

| Campo | Tipo | Significado |
|-------|------|-------------|
| `wizard_step` | `int` (1–5) | Passo atual do wizard (1=type, 2=amount, 3=description, 4=category, 5=labels) |
| `wizard_active` | `bool` | Flag booleana para distinguir wizard do fluxo AWAITING_DATA normal |

**Ordem do wizard mapeada para `awaiting_field`:**

| Step | `awaiting_field` | Pergunta |
|------|------------------|----------|
| 1 | `type` | "Qual o tipo da transação? (despesa / receita)" |
| 2 | `amount` | "Qual o valor? Ex: `R$ 50,00` ou `50,00`" |
| 3 | `description` | "Descreva a transação em poucas palavras:" |
| 4 | `category` | "Qual a categoria?" + inline keyboard com top categorias |
| 5 | `labels` | "Quer adicionar labels? (separadas por vírgula, ou 'pular')" |

Após o step 5, o draft está completo → `presentConfirmation()` é chamado normalmente.

**Como o Router sabe a ordem:** `pickNextAwaitingField()` atual para testar `amount → type → date → description`. Quando `wizard_active=true`, um branch alternativo consulta `wizard_step` e devolve o campo correspondente ao próximo passo. A transição de step é feita pelo Router após validação bem-sucedida — incrementa `wizard_step` e persiste na sessão.

**Validation de labels (step 5):** As labels do wizard são recebidas como string separada por vírgula (ex: `#almoco, #restaurante`). O Router NÃO tem `validateLabels()` hoje. Precisamos adicionar um validador mínimo: split por `,`, trim, remove `#` prefix, filtra tokens com < 2 chars, retorna `array<string>`. Se o usuário digitar `pular` → array vazio. Labels não passam por `isComplete()` (não são obrigatórias), então após validar labels o DTO sempre está "completo" (se os 4 campos obrigatórios estiverem preenchidos).

### 1.3 Como o `/sync` reseta o contador?

**Recomendação:** Novo método `FirestoreService::resetPendingSyncAttempts(string $chatId): int` que faz update atômico em lote nos documentos da collection `transactions` com `chat_id = $chatId AND sync_status = 'pending'`. Retorna o número de documentos resetados.

**Lock atômico entre `/sync` e cron:** Ambos usam o campo `sync_status` como coordenação implícita:
- `transactions:sync-pending` consulta `sync_status='pending' AND sync_attempts < 3`.
- Dentro do loop, antes de processar cada transação, faz `updateSyncStatus(id, 'pending', ...)` com um campo adicional `processing=true` (via `updateFields` no gateway).
- Se o documento já tiver `processing=true` (setado por outra execução concorrente), pula.
- Após o sync (sucesso ou falha), atualiza `sync_status` para `synced`/`pending`/`failed` e limpa `processing`.

Isso evita race condition sem precisar de lock global. O `FirestoreService` já tem `updateFields` disponível no gateway.

**Novo método no FirestoreService:**

```php
/**
 * Reseta sync_attempts para 0 e sync_status para 'pending' em todas
 * as transações pendentes do chat. Retorna o número de documentos afetados.
 *
 * Usa transação atômica do Firestore para garantir consistência.
 */
public function resetPendingSyncAttempts(string $chatId): int
```

**Implementação:** Query `sync_status='pending'` + `chat_id=$chatId` (precisa de índice composto — mas o índice `chat_id ASC, date DESC` já existe e cobre `chat_id`; Firestore permite filtrar por `chat_id` e depois por `sync_status` sem índice extra desde que o campo de igualdade (`sync_status`) venha depois do range/order — verificar). Alternativa: iterar documentos e chamar `updateFields` em cada um dentro de `transaction()`.

### 1.4 Qual o formato do payload de resposta da rota `/cron/sync-pending`?

**Recomendação:** JSON estruturado com status code 200 (sempre — mesmo que nada processado, é uma execução bem-sucedida da rota). 401 apenas para token inválido.

```json
// Sucesso (200 OK):
{
  "status": "ok",
  "processed": 3,
  "synced": 2,
  "failed": 1,
  "errors": [
    {"transaction_id": "abc123", "attempts": 3, "error": "Google_Service_Exception: 403 Forbidden"}
  ],
  "duration_ms": 1234,
  "timestamp": "2026-06-19T12:00:00.000000Z"
}

// Token inválido (401 Unauthorized):
{
  "status": "error",
  "message": "Unauthorized"
}
```

**Justificativa:** Texto puro seria difícil de parsear pelo Cloud Scheduler (que só verifica HTTP status). JSON estruturado permite monitoramento (Cloud Monitoring pode ingerir métricas do body). O campo `errors` só aparece se `failed > 0`.

### 1.5 Como notificar o usuário após 3 falhas de sync?

**Recomendação:** Notificação individual por transação via `BotMessenger::notifyError(chatId, mensagem)` no momento em que `sync_attempts` atinge 3 (durante a execução do `transactions:sync-pending`). Sem digest acumulado.

**Mensagem canônica:**
```
⚠️ Sincronização falhou após 3 tentativas: "{descrição}" (R$ {valor}) de {data}.
Erro: {mensagem_curta}. Verifique a planilha ou use /sync para tentar novamente.
```

**Justificativa:** Com limite de 20 transações por execução do cron e 1 único usuário, o pior caso são 20 notificações em 5 minutos — volume aceitável. Digest seria complexidade desnecessária (agendar, acumular, enviar). Se múltiplas transações falharem ao mesmo tempo, o usuário recebe cada notificação separadamente e pode agir em cada uma.

**Como obter o `chat_id`:** O documento `transactions/{id}` já tem campo `chat_id` (salvo em `saveTransaction`). O comando lê `data['chat_id']` de cada documento e usa para notificar.

### 1.6 Onde mora a formatação PT-BR das listas?

**Recomendação:** Estender `TransactionSummaryFormatter` com dois novos métodos:

```php
/**
 * Formata uma lista de transações para exibição.
 *
 * @param  list<array{id: string, data: array}>  $transactions
 * @return string HTML formatado (ParseMode::HTML)
 */
public function listSummary(array $transactions, int $limit): string

/**
 * Formata uma única linha de transação para listagem (formato compacto).
 */
public function listRow(array $data): string
```

**Justificativa:** `TransactionSummaryFormatter` já é o ponto central de formatação PT-BR e já tem os helpers `formatAmount()`, `formatType()`, `formatDate()` como métodos privados. Extrair para um `ListFormatter` separado criaria duplicação desses helpers ou acoplamento desnecessário. O escopo de responsabilidade atual ("formatação de transações para exibição PT-BR") cobre naturalmente tanto resumo individual quanto listagem.

### 1.7 Como o `/categorias` mostra o contador de uso?

**Recomendação:** Usar `FirestoreService::getCategories()` como está. O campo `use_count` já está presente na collection `categories` (schema §5 da especificação técnica). Se o `getCategories()` atual não retorna `use_count`, verificar a query — o método provavelmente já retorna o documento completo.

**Verificação necessária:** Conferir se `FirestoreService::getCategories()` faz `gateway->query('categories')` sem `select` (traz todos os campos). Se não trouxer `use_count`, adicionar o campo na query. NÃO computar de `transactions` — o `use_count` é mantido atomicamente pelo `incrementCategoryUse` (ou similar) no momento de confirmação da transação.

**Formato da resposta:**
```
📊 <b>Categorias</b>

🏷 Alimentação — 12 transações
🚗 Transporte — 8 transações
🏠 Moradia — 5 transações
...
✨ <i>Crie novas categorias ao registrar transações — elas aparecerão aqui automaticamente.</i>
```

Ordenação: `use_count DESC` (mais usadas primeiro), depois alfabética para empatadas.

### 1.8 Como tratar `/nova` quando o usuário já tem sessão ativa?

**Recomendação:** Limpar a sessão anterior e iniciar o wizard — sem mensagem de bloqueio.

**Justificativa:** Consistente com o comportamento atual do `CancelarHandler` (sempre funciona, mesmo em estados inconsistentes) e com o `ConversationRouter` em `AWAITING_CONFIRMATION` (texto/foto → cancela e re-começa). A clarificação #5 estabelece que o usuário pode usar o wizard a qualquer momento. Bloquear com "use /cancelar primeiro" adicionaria atrito desnecessário.

**Implementação:** O `NovaHandler::__invoke()` chama `FirestoreService::clearSession($chatId)` incondicionalmente (idêntico ao `CancelarHandler`), depois configura a sessão wizard.

### 1.9 Como tratar `/ultimos` quando não há transações?

**Recomendação:** Mensagem amigável: _"📭 Nenhuma transação registrada ainda. Envie uma mensagem descrevendo um gasto ou receita para começar!"_

**Justificativa:** Melhor UX que lista vazia ou silêncio. Mantém o tom do bot (amigável, PT-BR, com emoji). NÃO é erro — é estado normal para usuário novo.

### 1.10 Qual a estrutura final do estado da sessão para o wizard `/nova`?

**Recomendação:** Reusar `AWAITING_DATA` com campos extras na sessão:

```php
// NovaHandler configura:
$this->firestore->setSession(
    chatId: $chatId,
    state: ConversationState::AWAITING_DATA->value,
    draft: new TransactionData(description: null)->toDraftArray(),
    awaitingField: 'type',
    source: 'wizard',
    retryCount: 0,
);
// + campos wizard via updateFields:
//   wizard_step: 1
//   wizard_active: true
```

**Campos de sessão usados pelo wizard (extensão do schema atual):**

| Campo | Tipo | Quando presente | Significado |
|-------|------|-----------------|-------------|
| `wizard_step` | `int` | Só wizard | Passo atual (1–5) |
| `wizard_active` | `bool` | Só wizard | Flag para distinguir no Router |

**Transições de `wizard_step` gerenciadas pelo Router:**

Após `handleAwaitingData()` validar com sucesso o campo do step atual, incrementa `wizard_step` e atualiza `awaiting_field` para o próximo campo da sequência wizard. Após step 5 validado, chama `presentConfirmation()`.

**Limpeza:** Quando a sessão transita para `AWAITING_CONFIRMATION` ou `IDLE`, os campos `wizard_step` e `wizard_active` são removidos via `clearFields`.

### 1.11 (Extra) Modificação necessária no `FirestoreService::setSession()`

O método `setSession()` atual não aceita campos extras como `wizard_step`. No entanto, o método já aceita `$draft` como `?array` e o persiste via merge. Para campos top-level da sessão como `wizard_step` e `wizard_active`, precisamos de uma alternativa. Duas opções:

**Opção A (recomendada):** Adicionar um parâmetro `array $extraFields = []` ao `setSession()` que faz merge adicional após os campos principais.

**Opção B:** O Router chama `updateFields` diretamente no gateway após `setSession()`.

A Opção A é mais limpa e mantém o encapsulamento. Alternativamente, podemos armazenar `wizard_step` e `wizard_active` **dentro** do `draft` como chaves especiais (`_wizard_step`, `_wizard_active`), evitando qualquer alteração no `setSession()`. O `setSession` já aceita `$draft` como array arbitrário.

**Decisão final:** Armazenar wizard metadata dentro do `draft` como campos prefixados com `_`. O `TransactionData::fromDraftArray()` já ignora campos desconhecidos. O Router lê `$draft['_wizard_step']` e `$draft['_wizard_active']` diretamente do array da sessão (não do DTO).

---

## 2. Especificação das Classes/Métodos

### 2.1 NOVO: `app/Bot/Handlers/NovaHandler.php`

**Propósito:** Handler do comando `/nova` — wizard passo-a-passo para criar transação manualmente.

**Padrão de referência:** `CancelarHandler.php` (handler de comando stateless que manipula sessão).

```php
final class NovaHandler
{
    // Construtor: sem dependências injetadas (usa app() como CancelarHandler)
    
    // Método público:
    public function __invoke(Nutgram $bot): void
    // 1. Extrai chatId do update
    // 2. Limpa sessão existente: app(FirestoreService::class)->clearSession($chatId)
    // 3. Cria draft vazio: new TransactionData(description: null)
    // 4. Configura sessão wizard:
    //    - state: AWAITING_DATA
    //    - awaiting_field: 'type'
    //    - draft: ['_wizard_step' => 1, '_wizard_active' => true]
    //    - source: 'wizard'
    //    - retryCount: 0
    // 5. Envia primeira pergunta:
    //    app(BotMessenger::class)->askForField($chatId, 'type', "Qual o tipo da transação?\n\n💸 <b>despesa</b> — quando você gasta\n💰 <b>receita</b> — quando você recebe")
    // 6. Retorna void (o fluxo continua via MessageRouterHandler → ConversationRouter)
}
```

### 2.2 NOVO: `app/Bot/Handlers/UltimosHandler.php`

**Propósito:** Handler do comando `/ultimos [n]` — lista as últimas N transações.

**Padrão de referência:** `StartHandler.php` (handler de comando stateless, sem dependências injetadas).

```php
final class UltimosHandler
{
    // Construtor: sem dependências (usa app())
    
    public function __invoke(Nutgram $bot): void
    // 1. Extrai chatId do update
    // 2. Extrai parâmetro: $param = $bot->message()?->getText()
    // 3. Parse: preg_match('/^\/ultimos(?:\s+(\S+))?/', $param, $m)
    // 4. Aplica regra da clarificação #6:
    //    $n = isset($m[1]) ? intval($m[1]) : 5;
    //    if ($n < 1 || $n > 50) { $n = 5; }
    // 5. Chama FirestoreService::listRecent($chatId, $n) (sem filtro de type)
    // 6. Se array vazio → mensagem "Nenhuma transação..."
    // 7. Senão → formata via TransactionSummaryFormatter::listSummary()
    // 8. Envia via BotMessenger::sendText($chatId, $texto)
}
```

### 2.3 NOVO: `app/Bot/Handlers/CategoriasHandler.php`

**Propósito:** Handler do comando `/categorias` — lista categorias com contador de uso.

**Padrão de referência:** `UltimosHandler.php` (mesmo padrão — query + format + send).

```php
final class CategoriasHandler
{
    // Construtor: sem dependências (usa app())
    
    public function __invoke(Nutgram $bot): void
    // 1. Extrai chatId
    // 2. Chama FirestoreService::getCategories()
    // 3. Ordena por use_count DESC, display_name ASC
    // 4. Formata mensagem com emojis por categoria (mapeamento fixo)
    // 5. Envia via BotMessenger::sendText()
}
```

### 2.4 NOVO: `app/Bot/Handlers/SyncHandler.php`

**Propósito:** Handler do comando `/sync` — dispara sync manual de transações pendentes.

**Padrão de referência:** `CancelarHandler.php` (handler de comando simples).

```php
final class SyncHandler
{
    // Construtor: sem dependências (usa app())
    
    public function __invoke(Nutgram $bot): void
    // 1. Extrai chatId
    // 2. Reseta contador: FirestoreService::resetPendingSyncAttempts($chatId)
    // 3. Dispara sync: chama o mesmo loop de SyncPendingTransactions
    //    (pode instanciar o command ou extrair a lógica para uma Action)
    // 4. Notifica resultado: "✅ Sincronização concluída: X sincronizadas, Y falhas."
    //    ou "📭 Nenhuma transação pendente para sincronizar."
}
```

### 2.5 MODIFICADO: `app/Conversation/ConversationRouter.php`

**Alterações necessárias:**

1. **`pickNextAwaitingField()`** — adicionar branch wizard:

```php
private function pickNextAwaitingField(TransactionData $dto, array $session = []): ?string
{
    // Wizard mode: segue ordem fixa (type → amount → description → category → labels)
    $wizardStep = (int) ($session['draft']['_wizard_step'] ?? 0);
    if ($wizardStep > 0) {
        return match ($wizardStep) {
            1 => 'type',
            2 => 'amount',
            3 => 'description',
            4 => 'category',
            5 => 'labels',
            default => null, // wizard completo → vai para confirmation
        };
    }
    
    // Modo normal (natural language): ordem amount → type → date → description
    if ($dto->amount === null) return 'amount';
    if ($dto->type === null) return 'type';
    if ($dto->date === null) return 'date';
    if ($dto->description === null) return 'description';
    return null;
}
```

2. **`handleAwaitingData()`** — após validação bem-sucedida e antes de chamar `pickNextAwaitingField()`, incrementar `wizard_step` se wizard ativo:

```php
// Após $newDraft = $draft->withField($awaitingField, $normalized):
$wizardStep = (int) ($session['draft']['_wizard_step'] ?? 0);
if ($wizardStep > 0) {
    // Incrementa wizard_step no draft
    $wizardDraft = $newDraft->toDraftArray();
    $wizardDraft['_wizard_step'] = $wizardStep + 1;
    $wizardDraft['_wizard_active'] = true;
    // Re-constrói o DTO (mantendo campos originais)
    // ... (precisa reaplicar o draft ao DTO)
}
```

3. **`validateField()`** — adicionar case `'labels'`:

```php
'labels' => $this->validateLabels($raw),
```

4. **Novo método privado `validateLabels()`**:

```php
/**
 * Valida labels do wizard: split por vírgula, trim, remove prefixo #,
 * filtra tokens com < 2 chars. "pular" → array vazio.
 *
 * @return array<string>|null Array de labels normalizadas, ou null se inválido.
 */
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

5. **`handleAwaitingData()` — foto no wizard**: durante o wizard, se o usuário envia foto, ela pode trazer campos extras. O merge deve preservar `_wizard_step` e `_wizard_active` no draft array.

6. **`presentConfirmation()` — limpeza**: após confirmação ou ao sair do wizard, remover `_wizard_step` e `_wizard_active` do draft. Isso já acontece naturalmente porque `clearSession()` limpa tudo.

**Nota importante:** A assinatura de `pickNextAwaitingField()` muda para receber `$session` adicional. Todos os callers precisam ser atualizados (cerca de 4 chamadas em `handleAwaitingData`, `handleAwaitingEdition`, `handleTextExtraction`, `handlePhotoExtraction`). O parâmetro pode ser opcional (`array $session = []`) para manter compatibilidade com testes existentes.

### 2.6 MODIFICADO: `app/Bot/Handlers/HelpHandler.php`

**Alteração:** No array `commands()`, mudar as flags `active` dos comandos implementados no M9:

```php
['/nova', 'Criar transação passo a passo (wizard)', true],       // era false
['/cancelar', 'Cancelar operação atual', true],                    // já true
['/ultimos [n]', 'Ver últimas transações (padrão 5, máx 50)', true], // era false
['/categorias', 'Listar categorias com contador de uso', true],     // era false
['/sync', 'Forçar sincronização com a planilha', true],            // era false
```

**Regra:** À medida que cada handler é implementado, marcar como `true`. Para o PR final do M9, todos os 7 comandos devem estar `true`.

### 2.7 MODIFICADO: `app/Bot/BotLoader.php`

**Alteração:** Adicionar registro dos novos handlers de comando, **antes** do `MessageRouterHandler` (ordem importa — comandos exatos devem ter precedência):

```php
public static function registerHandlers(Nutgram $bot): void
{
    // Comandos exatos (ordem: mais específicos primeiro não importa —
    // Nutgram faz match exato pelo comando, não pela ordem)
    $bot->onCommand('start', StartHandler::class);
    $bot->onCommand('help', HelpHandler::class);
    $bot->onCommand('cancelar', CancelarHandler::class);
    $bot->onCommand('nova', NovaHandler::class);        // NOVO
    $bot->onCommand('ultimos', UltimosHandler::class);   // NOVO
    $bot->onCommand('categorias', CategoriasHandler::class); // NOVO
    $bot->onCommand('sync', SyncHandler::class);         // NOVO
    
    // Catch-all (não comandos)
    $bot->onMessage(MessageRouterHandler::class);
    $bot->onCallbackQuery(CallbackQueryRouterHandler::class);
}
```

### 2.8 MODIFICADO: `app/Bot/Messaging/TransactionSummaryFormatter.php`

**Novos métodos:**

```php
/**
 * Formata lista de transações para exibição compacta.
 *
 * @param  list<array{id: string, data: array}>  $transactions
 */
public function listSummary(array $transactions, int $shown): string

/**
 * Formata uma linha individual de transação na listagem.
 */
private function listRow(array $data, int $index): string
```

**Formato de saída esperado:**

```
📋 <b>Últimas 5 transações</b>

1. 🍕 <b>Almoço restaurante</b>
   💸 Despesa · R$ 47,50 · Alimentação
   📅 15/06/2026 · #almoço #restaurante

2. 💰 <b>Salário Junho</b>
   📈 Receita · R$ 5.000,00 · Salário
   📅 01/06/2026

...

<i>Mostrando 5 de 23 transações. Use /ultimos N para ver mais.</i>
```

### 2.9 NOVO: `app/Console/Commands/SyncPendingTransactions.php`

**Propósito:** Artisan command `transactions:sync-pending` — processa transações com `sync_status=pending` e `sync_attempts < 3`.

**Padrão de referência:** `app/Console/Commands/SeedCategories.php` (já existente — mesmo padrão de artisan command com Firestore).

```php
final class SyncPendingTransactions extends Command
{
    protected $signature = 'transactions:sync-pending {--chat-id= : Chat ID específico (opcional)}';
    protected $description = 'Sincroniza transações pendentes com Google Sheets';
    
    // Construtor:
    public function __construct(
        private readonly FirestoreService $firestore,
        private readonly SyncsSheet $syncSheet,
        private readonly ?BotMessenger $messenger = null, // null no cron, presente no /sync
    ) {}
    
    public function handle(): int
    // 1. Query: gateway->query('transactions', [
    //      ['field' => 'sync_status', 'op' => '=', 'value' => 'pending'],
    //      ['field' => 'sync_attempts', 'op' => '<', 'value' => 3],
    //    ], [['field' => 'created_at', 'direction' => 'ASC']], 20)
    //    NOTA: Precisa de índice composto sync_status ASC, sync_attempts ASC, created_at ASC
    //    OU usa filtro apenas por sync_status='pending' e filtra attempts < 3 em memória
    //    (mais simples, sem índice extra; 20 docs é insignificante)
    //
    // 2. Se --chat-id informado, filtra adicionalmente por chat_id
    //
    // 3. Para cada documento:
    //    a. Constrói TransactionData::fromArray($data)
    //    b. Tenta SyncsSheet::handle($dto, $id, $data['source'] ?? 'text')
    //    c. Sucesso → sync_status='synced' (já feito pelo SyncSheet)
    //    d. Falha → sync_attempts incrementado (já feito pelo SyncSheet)
    //       Se sync_attempts >= 3 → sync_status='failed' + notificar usuário
    //
    // 4. Output: "Processadas: X, Sincronizadas: Y, Falhas: Z"
    // 5. Return SUCCESS ou FAILURE
}
```

**Índice adicional necessário:** Nenhum — a query filtra apenas por `sync_status='pending'` (igualdade simples, sem order composto que exija índice). O filtro `sync_attempts < 3` é aplicado em memória (máximo 20 documentos). Se quisermos query composta com order, precisaríamos de índice `sync_status ASC, created_at ASC` — mas para 20 documentos, order by client-side é aceitável.

### 2.10 NOVO: Controller ou Closure para `GET /cron/sync-pending`

**Opção escolhida:** Closure em `routes/web.php` (como o `/health` existente). Não justifica um Controller dedicado para 1 rota de cron. *(M11: migrado para `routes/api.php` — web.php não existe mais.)*

```php
// Em routes/web.php (M11: web.php → api.php):
Route::get('/cron/sync-pending', function (Request $request): JsonResponse {
    $expectedToken = env('CRON_SECRET_TOKEN');
    
    if (empty($expectedToken) || $request->header('X-Cron-Token') !== $expectedToken) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized',
        ], 401);
    }
    
    $start = microtime(true);
    
    $exitCode = Artisan::call('transactions:sync-pending');
    $output = Artisan::output();
    
    // Parseia output do comando para extrair contadores
    // (alternativa: comando retorna JSON via --format=json)
    preg_match('/Processadas: (\d+)/', $output, $processed);
    preg_match('/Sincronizadas: (\d+)/', $output, $synced);
    preg_match('/Falhas: (\d+)/', $output, $failed);
    
    $duration = (int) ((microtime(true) - $start) * 1000);
    
    return response()->json([
        'status' => 'ok',
        'processed' => (int) ($processed[1] ?? 0),
        'synced' => (int) ($synced[1] ?? 0),
        'failed' => (int) ($failed[1] ?? 0),
        'duration_ms' => $duration,
        'timestamp' => now()->toIso8601ZuluString(),
    ]);
})->name('cron.sync-pending');
```

**Alternativa (mais limpa):** O comando `transactions:sync-pending` aceita `--format=json` e retorna JSON no stdout. O closure lê `Artisan::output()` e faz `json_decode`. Isso evita regex frágil.

### 2.11 MODIFICADO: `app/Services/Google/FirestoreService.php`

**Novos métodos:**

```php
/**
 * Reseta sync_attempts para 0 e sync_status para 'pending' em todas
 * as transações pendentes do chat especificado.
 *
 * @return int Número de documentos resetados.
 */
public function resetPendingSyncAttempts(string $chatId): int

/**
 * Lista transações pendentes de sync (para o cron / /sync).
 *
 * @param  string|null  $chatId  Se null, lista de todos os chats.
 * @param  int          $limit
 * @return list<array{id: string, data: array<string, mixed>}>
 */
public function listPendingSync(?string $chatId = null, int $limit = 20): array
```

**Novo método `listPendingSync`** — evita que o comando artisan faça query manual no gateway:

```php
public function listPendingSync(?string $chatId = null, int $limit = 20): array
{
    $wheres = [
        ['field' => 'sync_status', 'op' => '=', 'value' => self::SYNC_PENDING],
    ];
    
    if ($chatId !== null) {
        $wheres[] = ['field' => 'chat_id', 'op' => '=', 'value' => $chatId];
    }
    
    $results = $this->gateway->query(
        self::COLLECTION_TRANSACTIONS,
        $wheres,
        [['field' => 'created_at', 'direction' => 'ASC']],
        $limit,
    );
    
    // Filtra sync_attempts < 3 em memória (evita índice composto extra)
    return array_values(array_filter(
        $results,
        fn(array $doc): bool => ($doc['data']['sync_attempts'] ?? 0) < 3,
    ));
}
```

---

## 3. Fluxo Detalhado do `/nova` (Wizard)

### 3.1 Diagrama de sequência textual

```
1. User: /nova
2. NovaHandler::__invoke()
   ├── clearSession(chatId)
   ├── setSession(state=AWAITING_DATA, awaiting_field='type', draft={_wizard_step:1, _wizard_active:true}, source='wizard')
   └── askForField(chatId, 'type', "Qual o tipo da transação?\n\n💸 despesa\n💰 receita")

3. User: "despesa"
4. MessageRouterHandler → ConversationRouter::route(ConversationInput::text(chatId, "despesa"))
   ├── Load session → state=AWAITING_DATA, awaiting_field='type', _wizard_step=1
   ├── handleAwaitingData():
   │   ├── validateField('type', "despesa") → "expense"
   │   ├── newDraft = draft.withField('type', "expense")
   │   ├── _wizard_step=2 (incrementa)
   │   ├── setSession(state=AWAITING_DATA, awaiting_field='amount', draft={..., _wizard_step:2})
   │   └── askForField(chatId, 'amount', "Qual o valor? Ex: R$ 50,00 ou 50,00")
   └── END

5. User: "47,50"
6. ConversationRouter::route(texto)
   ├── handleAwaitingData():
   │   ├── validateField('amount', "47,50") → 47.5
   │   ├── newDraft = draft.withField('amount', 47.5)
   │   ├── _wizard_step=3
   │   ├── setSession(..., awaiting_field='description', draft={..., _wizard_step:3})
   │   └── askForField(chatId, 'description', "Descreva a transação em poucas palavras:")
   └── END

7. User: "Almoço no restaurante italiano"
8. ConversationRouter::route(texto)
   ├── handleAwaitingData():
   │   ├── validateField('description', "Almoço...") → "Almoço no restaurante italiano"
   │   ├── newDraft = draft.withField('description', "Almoço...")
   │   ├── _wizard_step=4
   │   ├── setSession(..., awaiting_field='category', draft={..., _wizard_step:4})
   │   └── sendText(chatId, "Qual a categoria?") + inline keyboard top categories
   └── END

9. User: (toca button "Alimentação" → callback_data: "category:Alimentação")
   OU digita: "Alimentação"
10. ConversationRouter::route(texto ou callback?)
    ├── Se texto: handleAwaitingData() → validateField('category', "Alimentação")
    ├── _wizard_step=5
    ├── setSession(..., awaiting_field='labels', draft={..., _wizard_step:5})
    └── askForField(chatId, 'labels', "Quer adicionar labels? (separadas por vírgula, ou 'pular')")

11. User: "almoço, italiano, #restaurante" OU "pular"
12. ConversationRouter::route(texto)
    ├── handleAwaitingData():
    │   ├── validateField('labels', "almoço, italiano...") → ["almoço", "italiano", "restaurante"]
    │   ├── newDraft = draft.withField('labels', ["almoço", "italiano", "restaurante"])
    │   ├── _wizard_step=6 (wizard completo)
    │   ├── newDraft.isComplete() → true (amount, type, description, date definidos)
    │   └── presentConfirmation(chatId, newDraft, 'wizard')
    └── END

13. Router → presentConfirmation():
    ├── enrichDtoWithSuggestions(dto) → category/labels refinados
    ├── sendConfirmationRequest(chatId, enriched)
    └── setSession(state=AWAITING_CONFIRMATION, ..., clearFields=['_wizard_step', '_wizard_active'])

14. User: (toca "✅ Confirmar")
15. → handleConfirm() → persiste Firestore + sync Sheets + notifySuccess + clearSession
```

### 3.2 Tratamento de atalho (texto livre durante wizard)

Se o usuário, no meio do wizard (ex: step 2 aguardando `amount`), digitar `Paguei R$ 47,50 no almoço hoje`:

1. `MessageRouterHandler` converte para `ConversationInput::text(chatId, texto_completo)`
2. `ConversationRouter::route()` → estado `AWAITING_DATA`
3. `handleAwaitingData()` → `awaiting_field='amount'`, mas o texto é completo
4. `validateField('amount', "Paguei R$ 47,50 no almoço hoje")` → **FALHA** (não é um valor numérico puro)
5. ⚠️ **Problema:** O texto completo NÃO é reconhecido como atalho — o Router tenta validar como `amount` e falha.

**Solução:** Antes de validar o campo específico, detectar se o texto parece ser uma descrição completa (contém espaços, palavras, possivelmente valor embutido). Se sim, tentar extração via DeepSeek:

```php
// Em handleAwaitingData(), antes de validateField():
if ($this->looksLikeNaturalLanguage((string) $input->text)) {
    try {
        $extracted = $this->extractText->handle((string) $input->text);
        $merged = $this->mergeDrafts($draft, $extracted);
        // Continua com o merged draft...
    } catch (ExtractionException $e) {
        // Fallback: tenta validar como campo específico
    }
}
```

**Heurística `looksLikeNaturalLanguage()`:** Se o texto contém 3+ palavras (separadas por espaço) OU corresponde ao regex de valor monetário + palavras → trata como linguagem natural. Se o texto é curto (1-2 tokens) → trata como resposta ao campo atual.

Esta heurística já existe parcialmente no Router (em IDLE, todo texto vai para DeepSeek). O desafio é aplicá-la também em AWAITING_DATA.

**Implementação pragmática:** No `NovaHandler`, configurar a sessão com um campo adicional `_wizard_allow_natural: true`. Em `handleAwaitingData()`, se este flag estiver presente e o texto tiver 3+ palavras, tentar extração completa (DeepSeek). Se DeepSeek retornar DTO completo → `presentConfirmation` direto (pula steps restantes). Se falhar → continuar wizard normalmente (valida o campo atual).

### 3.3 Tratamento de data no wizard

O wizard NÃO pergunta data explicitamente — usa `hoje` como default. A sequência da clarificação #5 é: Tipo → Valor → Descrição → Categoria → Labels. Sem etapa de data.

**Data default:** Quando o wizard chega em `presentConfirmation()`, se `dto->date === null`, o Router preenche com `date('Y-m-d')` (hoje). Verificar se `presentConfirmation()` já faz isso — se não, adicionar.

---

## 4. Fluxo Detalhado do `/sync` e `transactions:sync-pending`

### 4.1 Fluxo do comando `/sync`

```
1. User: /sync
2. SyncHandler::__invoke()
   ├── chatId = extract from update
   ├── resetCount = FirestoreService::resetPendingSyncAttempts(chatId)
   │   └── Query: transactions where chat_id=X AND sync_status='pending'
   │       For each: updateFields(id, {sync_attempts: 0, sync_status: 'pending', updated_at: now})
   │       Return count
   ├── if resetCount == 0:
   │   └── sendText(chatId, "📭 Nenhuma transação pendente para sincronizar.")
   │       return
   ├── sendText(chatId, "⏳ Sincronizando {resetCount} transação(ões)...")
   ├── $result = dispatch SyncPendingTransactions command (in-process, not queued)
   │   └── $command = new SyncPendingTransactions($firestore, $syncSheet, $messenger)
   │       $command->setLaravel(app())
   │       $exitCode = $command->handle()
   ├── if exitCode == SUCCESS:
   │   └── sendText(chatId, "✅ Sincronização concluída!")
   └── if exitCode == FAILURE:
       └── sendText(chatId, "⚠️ Sincronização concluída com falhas. Use /ultimos para verificar.")
```

### 4.2 Fluxo do `transactions:sync-pending`

```
Command: php artisan transactions:sync-pending [--chat-id=X] [--format=json]

1. Query pending: FirestoreService::listPendingSync($chatId, $limit=20)
   └── WHERE sync_status='pending'
       ORDER BY created_at ASC
       LIMIT 20
       FILTER (in memory): sync_attempts < 3

2. Initialize counters: $processed=0, $synced=0, $failed=0, $errors=[]

3. FOR EACH document:
   ├── $id = $doc['id']
   ├── $data = $doc['data']
   ├── $chatId = $data['chat_id']
   ├── $dto = TransactionData::fromArray($data)
   ├── $source = $data['source'] ?? 'text'
   ├──
   ├── TRY:
   │   ├── $success = $syncSheet->handle($dto, $id, $source)
   │   ├── IF $success:
   │   │   ├── $synced++
   │   │   └── CONTINUE (SyncSheet já atualizou sync_status='synced')
   │   └── ELSE:
   │       └── (SyncSheet já tratou: sync_status incrementado ou 'failed')
   └── CATCH (Throwable $e):
       ├── $attempts = ($data['sync_attempts'] ?? 0) + 1
       ├── IF $attempts >= 3:
       │   ├── $firestore->updateSyncStatus($id, 'failed', $e->getMessage())
       │   ├── $failed++
       │   ├── $errors[] = ['transaction_id' => $id, 'attempts' => $attempts, 'error' => $e->getMessage()]
       │   └── IF $messenger !== null:
       │       └── $messenger->notifyError($chatId, formatSyncFailedMessage($dto, $e))
       └── ELSE:
           ├── $firestore->updateSyncStatus($id, 'pending', $e->getMessage())
           └── (sync_attempts incrementado por updateSyncStatus)
   └── $processed++

4. Output:
   └── "Processadas: $processed, Sincronizadas: $synced, Falhas: $failed"
       Se --format=json: output JSON com detalhes
```

### 4.3 Critério para notificar usuário

Notificar APENAS quando `sync_attempts >= 3` e `sync_status` transita para `failed`. Não notificar em tentativas 1 e 2 (seria spam a cada 5 minutos). A mensagem é enviada uma única vez por transação (quando atinge 3 falhas).

**Mensagem exata:**
```
⚠️ Não foi possível sincronizar com a planilha após 3 tentativas:

<b>{descrição}</b>
💰 R$ {valor} · {tipo} · {categoria}
📅 {data}

Erro: {mensagem_curta}

Use /sync para tentar novamente quando resolver o problema.
```

---

## 5. Especificação da Rota `/cron/sync-pending`

| Atributo | Valor |
|----------|-------|
| **URL completa** | `GET /cron/sync-pending` |
| **Método HTTP** | `GET` |
| **Headers esperados** | `X-Cron-Token: <CRON_SECRET_TOKEN>` |
| **Status code sucesso** | `200 OK` |
| **Status code token inválido** | `401 Unauthorized` |
| **Body sucesso** | `{"status":"ok","processed":3,"synced":2,"failed":1,"errors":[...], "duration_ms":1234,"timestamp":"..."}` |
| **Body erro auth** | `{"status":"error","message":"Unauthorized"}` |
| **Código** | Closure em `routes/web.php` (M11: web.php → api.php) |
| **Middleware** | Nenhum além do validation inline (não usa `ValidateTelegramWebhook`) |
| **CSRF** | Rota excluída da verificação CSRF (adicionar em `bootstrap/app.php` `->withMiddleware()`) — *M11: removido; api group é stateless, CSRF não se aplica* |

**Registro em `routes/web.php` (M11: web.php → api.php):**

```php
Route::get('/cron/sync-pending', function (Request $request): JsonResponse {
    // Validar token
    $expected = env('CRON_SECRET_TOKEN');
    if (empty($expected) || $request->header('X-Cron-Token') !== $expected) {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    
    $start = hrtime(true);
    
    // Executa o comando com output JSON
    Artisan::call('transactions:sync-pending', ['--format' => 'json']);
    $result = json_decode(Artisan::output(), true);
    
    $duration = (int) ((hrtime(true) - $start) / 1_000_000); // ms
    
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

**Alteração em `bootstrap/app.php`:** Adicionar `/cron/sync-pending` à lista de exclusão CSRF (ao lado de `webhook/telegram`).

**Nota sobre Cloud Scheduler:** O Cloud Scheduler NÃO consegue injetar headers customizados via console UI em algumas versões. O header `X-Cron-Token` é suportado via `gcloud` CLI ou via campo `headers` no console. Alternativa: passar token como query parameter `?token=...` — mas header é mais seguro (não aparece em logs de acesso).

---

## 6. Mensagens PT-BR Canônicas

### 6.1 `/nova`

**Primeira mensagem (pergunta tipo):**
```
🆕 <b>Nova transação</b> — passo 1/5

Qual o tipo da transação?

💸 <b>despesa</b> — quando você gasta dinheiro
💰 <b>receita</b> — quando você recebe dinheiro
```

**Pergunta valor (step 2):**
```
💵 <b>Passo 2/5</b> — Qual o valor?

Exemplos:
  <code>50,00</code>
  <code>R$ 1.234,56</code>
  <code>47.50</code>
```

**Pergunta descrição (step 3):**
```
📝 <b>Passo 3/5</b> — Descreva a transação:

Exemplos:
  <code>Almoço no restaurante</code>
  <code>Conta de luz Enel</code>
  <code>Uber para o trabalho</code>
```

**Pergunta categoria (step 4):**
```
🏷 <b>Passo 4/5</b> — Escolha a categoria:

[Inline keyboard com top 5 categorias + botão "✏️ Digitar outra"]
```

**Pergunta labels (step 5):**
```
🏷 <b>Passo 5/5</b> — Quer adicionar labels?

Separe por vírgula:
  <code>almoço, restaurante, fds</code>

Ou digite <b>pular</b> para não usar labels.
```

**Atalho (texto livre reconhecido):**
```
💡 Detectei uma descrição completa! Extraindo os dados...

[→ resultado da extração → keyboard confirmar/editar/cancelar]
```

### 6.2 `/ultimos`

**Com transações:**
```
📋 <b>Últimas {N} transações</b>

1. 🍕 <b>Almoço restaurante</b>
   💸 Despesa · R$ 47,50 · Alimentação
   📅 15/06/2026 · #almoço #restaurante

2. 💰 <b>Salário</b>
   📈 Receita · R$ 5.000,00 · Salário
   📅 01/06/2026

...

<i>Mostrando {shown} de {total} transações.</i>
```

**Sem transações:**
```
📭 Nenhuma transação registrada ainda.

Envie uma mensagem descrevendo um gasto ou receita para começar! Exemplo:

<i>Paguei R$ 47,50 no almoço hoje</i>
```

### 6.3 `/categorias`

**Com categorias:**
```
📊 <b>Categorias</b>

🍕 Alimentação — 12 transações
🚗 Transporte — 8 transações
🏠 Moradia — 5 transações
❤️ Saúde — 4 transações
📚 Educação — 3 transações
🎮 Lazer — 3 transações
💰 Salário — 2 transações
💻 Freelance — 1 transação
📦 Outros — 1 transação

✨ <i>Crie novas categorias ao registrar transações — elas aparecerão aqui automaticamente.</i>
```

**Mapeamento de emojis por categoria (fixo):**

| Categoria | Emoji |
|-----------|-------|
| Alimentação | 🍕 |
| Transporte | 🚗 |
| Moradia | 🏠 |
| Saúde | ❤️ |
| Educação | 📚 |
| Lazer | 🎮 |
| Salário | 💰 |
| Freelance | 💻 |
| Outros | 📦 |
| (desconhecida) | 🏷 |

### 6.4 `/sync`

**Com pendentes:**
```
⏳ Sincronizando {N} transação(ões) pendente(s)...

✅ Sincronização concluída!
   • {X} sincronizada(s) com sucesso
   • {Y} com falha — verifique a planilha
```

**Sem pendentes:**
```
📭 Nenhuma transação pendente para sincronizar.

Todas as suas transações já estão na planilha! ✅
```

### 6.5 `/cancelar` (já existe — mantido)

```
🚫 Transação cancelada. Você pode começar de novo quando quiser — é só me mandar uma mensagem.
```

**Em IDLE ("Nada para cancelar"):**
```
🤷 Nenhuma operação em andamento para cancelar.
```

### 6.6 `/start` (já existe — mantido)

Mensagem atual do `StartHandler::message()`. Sem alterações necessárias. No entanto, verificar: o `/start` atualmente NÃO limpa a sessão. Deveria? Justificativa: se o usuário está em AWAITING_CONFIRMATION e digita `/start`, o comportamento esperado é resetar para IDLE e mostrar boas-vindas (consistente com `/nova`). **Recomendação:** Adicionar `clearSession($chatId)` no `StartHandler::__invoke()` antes de enviar a mensagem.

### 6.7 `/help` (já existe — atualizar flags)

Mensagem atual com todos os comandos ativos (`✅`). Ver seção 2.6.

---

## 7. Mudanças no `HelpHandler` (M9.2)

Arquivo: `app/Bot/Handlers/HelpHandler.php`

**Mudança:** No array retornado por `commands()`, alterar de `false` para `true`:

| Índice | Comando | Flag atual | Nova flag |
|--------|---------|-----------|-----------|
| 2 | `/nova` | `false` | `true` |
| 3 | `/cancelar` | `false` → já `true`? | Verificar — se false, mudar para true |
| 4 | `/ultimos [n]` | `false` | `true` |
| 5 | `/categorias` | `false` | `true` |
| 6 | `/sync` | `false` | `true` |

**Sugestão de implementação:** À medida que cada handler é criado, mudar para `true` no mesmo commit. No PR final do M9, todos estarão `true`.

**Texto de descrição sugerido para cada comando (alinhado com as mensagens canônicas):**

```php
['/nova', 'Criar transação passo a passo (wizard)', true],
['/cancelar', 'Cancelar operação atual', true],
['/ultimos [n]', 'Ver últimas transações (padrão 5, máx 50)', true],
['/categorias', 'Listar categorias com contador de uso', true],
['/sync', 'Forçar sincronização com a planilha', true],
```

---

## 8. Configurações

### 8.1 `.env.example`

O arquivo `.env.example` JÁ contém `CRON_SECRET_TOKEN=` (linha 151). Nenhuma alteração necessária.

### 8.2 `config/cron.php`

**NÃO criar.** Não há configuração suficiente para justificar um arquivo de config dedicado. O `CRON_SECRET_TOKEN` é lido diretamente via `env()` na closure da rota. O intervalo de 5 minutos é configurado no Cloud Scheduler (GCP), não no código.

### 8.3 `config/conversation.php`

**Sem alterações.** As configs existentes (`timeout_minutes=15`, `max_data_retries=3`) são suficientes.

### 8.4 `bootstrap/app.php`

Adicionar exclusão CSRF para a rota `/cron/sync-pending`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'webhook/telegram',
        'cron/sync-pending',  // NOVO
    ]);
    $middleware->alias([
        'telegram.webhook' => \App\Http\Middleware\ValidateTelegramWebhook::class,
    ]);
})
```

### 8.5 `firestore.indexes.json`

**Sem novos índices necessários.** A query de transações pendentes usa apenas `sync_status='pending'` (filtro de igualdade simples) e o filtro `sync_attempts < 3` é aplicado em memória. O `listRecent` já é coberto pelos índices existentes.

### 8.6 `app/Providers/ConversationServiceProvider.php`

**Sem alterações.** Os novos handlers (`NovaHandler`, `UltimosHandler`, `CategoriasHandler`, `SyncHandler`) usam `app()` (service locator) como os handlers existentes (`CancelarHandler`, `StartHandler`). Não precisam ser registrados no container.

Se o `SyncPendingTransactions` command precisar de `BotMessenger`, ele pode ser opcional (null no cron, injetado pelo `/sync`). O comando usa `app()` para resolver FirestoreService e SyncsSheet.

---

## 9. Testes

### 9.1 Estrutura de arquivos de teste

```
tests/Feature/Commands/
├── NovaHandlerTest.php          # CT-025
├── UltimosHandlerTest.php       # CT-027, CT-028
├── CategoriasHandlerTest.php    # CT-029
├── SyncHandlerTest.php          # /sync
├── HelpHandlerTest.php          # CT-024 (verificar flags)
└── StartHandlerTest.php         # CT-023 (verificar clearSession)
```

### 9.2 Padrão de teste

Seguir o padrão de `tests/Feature/Console/SeedCategoriesCommandTest.php`:
- Usar `InMemoryFirestoreGateway` bindado como singleton
- Usar `InMemoryBotMessenger` para capturar chamadas de I/O
- Handlers usam `FakeNutgram` ou mock do Nutgram
- Sem rede, sem credenciais GCP

### 9.3 Cobertura por caso de teste

| CT | Teste | O que verifica |
|----|-------|----------------|
| CT-023 | `StartHandlerTest::test_start_clears_session_and_sends_welcome` | clearSession chamado, mensagem enviada |
| CT-024 | `HelpHandlerTest::test_help_lists_all_commands_active` | Todos os 7 comandos com flag `true` |
| CT-025 | `NovaHandlerTest::test_nova_starts_wizard_flow` | Sessão configurada com wizard_step=1, awaiting_field='type' |
| CT-025 | `NovaHandlerTest::test_wizard_complete_flow` | Wizard completo via mocked Router (5 steps + confirmation) |
| CT-026 | `CancelarHandlerTest::test_cancelar_in_idle_shows_nothing` | Mensagem "Nada para cancelar" |
| CT-027 | `UltimosHandlerTest::test_ultimos_default_5` | listRecent chamado com limit=5, saída formatada |
| CT-028 | `UltimosHandlerTest::test_ultimos_invalid_param_fallback` | /ultimos abc → limit=5, /ultimos 999999 → limit=50 |
| CT-028 | `UltimosHandlerTest::test_ultimos_empty` | listRecent vazio → mensagem amigável |
| CT-029 | `CategoriasHandlerTest::test_categorias_lists_with_counts` | getCategories chamado, ordenado por use_count DESC |
| CT-033 | `SyncPendingTransactionsTest::test_sync_pending_retries_and_notifies` | 3 falhas → status=failed + notifyError chamado |

### 9.4 Teste do ConversationRouter com wizard

Adicionar ao `ConversationRouterTest` (ou criar `WizardFlowTest`):

- `test_wizard_step1_type_validation`: wizard_step=1, awaiting_field='type', resposta "despesa" → transita para step 2 (amount)
- `test_wizard_step2_amount_validation`: step=2, "47,50" → step 3 (description)
- `test_wizard_step5_labels_pular`: step=5, "pular" → labels vazias → presentConfirmation
- `test_wizard_natural_language_shortcut`: step=2, texto "Paguei R$ 47,50 almoço" → DeepSeek extrai → presentConfirmation (pula steps restantes)
- `test_wizard_complete_to_confirmation`: steps 1-5 → presentConfirmation com wizard_step limpo

---

## 10. Riscos e Mitigações

| # | Risco | Prob. | Impacto | Mitigação |
|---|-------|-------|---------|-----------|
| 1 | **Índice Firestore `sync_status` inexistente** — query por `sync_status='pending'` sem índice composto pode ser lenta com muitos documentos | Baixa | Média | A query é simples (igualdade em campo único); para 1 usuário, < 1000 docs é instantâneo. Se ficar lento, criar índice `sync_status ASC, created_at ASC`. |
| 2 | **Wizard quebra o fluxo natural** — usuário acostumado a digitar texto livre fica confuso com perguntas passo a passo | Baixa | Baixa | O wizard só é ativado via `/nova` explícito. O fluxo principal (texto livre → DeepSeek) continua inalterado. Além disso, o atalho de "texto livre durante wizard" (seção 3.2) mantém a porta aberta. |
| 3 | **Concorrência `/sync` manual + cron** — ambos processam a mesma transação ao mesmo tempo | Baixa | Média | O `SyncSheet` usa `updateSyncStatus` atômico (via `updateFields`). Se o cron e o `/sync` tentarem processar o mesmo documento, a segunda tentativa verá `sync_status != 'pending'` e pulará. Adicionar campo `processing=true` como lock otimista. |
| 4 | **`validateLabels()` aceita input vazio** — usuário digita `, , ,` e labels ficam vazias | Média | Baixa | `validateLabels()` já filtra tokens < 2 chars; vírgulas vazias produzem tokens vazios que são descartados. Array final pode ser vazio (aceitável — labels são opcionais). |
| 5 | **Handler `/sync` bloqueia webhook** — se houver muitas transações pendentes, o sync síncrono pode demorar | Baixa | Média | Limitar a 20 transações por execução. O Cloud Run timeout é 300s — Sheets API responde em ~500ms por chamada, então 20 × 500ms = 10s. Com retries e overhead, máximo ~60s. Bem dentro do timeout. |

---

## 11. Critérios de Aceitação Verificáveis

Reformulados do §12.5 do plano de implementação no formato **"Eu verifico que [comportamento observável] quando [pré-condição] e [ação]"**:

### CA-M9-01 — `/start` reseta sessão
**Eu verifico que** a sessão do usuário é limpa (estado volta a IDLE) e a mensagem de boas-vindas é exibida
**quando** o usuário tem uma sessão ativa (ex: AWAITING_CONFIRMATION)
**e** envia o comando `/start`.

### CA-M9-02 — `/help` lista todos os comandos ativos
**Eu verifico que** todos os 7 comandos (`/start`, `/help`, `/nova`, `/cancelar`, `/ultimos`, `/categorias`, `/sync`) aparecem com indicador ✅ (ativo)
**quando** o servidor está rodando com os handlers M9 registrados
**e** o usuário envia o comando `/help`.

### CA-M9-03 — `/nova` inicia wizard
**Eu verifico que** o bot pergunta "Qual o tipo da transação? (despesa / receita)" e a sessão avança para step 2 após resposta "despesa"
**quando** o usuário está em IDLE
**e** envia o comando `/nova` seguido de "despesa".

### CA-M9-04 — `/nova` wizard completo chega à confirmação
**Eu verifico que** após responder tipo, valor, descrição, categoria e labels, o bot exibe o resumo com inline keyboard [Confirmar/Editar/Cancelar]
**quando** o usuário segue todos os 5 passos do wizard
**e** todos os campos são válidos.

### CA-M9-05 — `/cancelar` em IDLE
**Eu verifico que** o bot responde "Nenhuma operação em andamento para cancelar"
**quando** o usuário está em estado IDLE
**e** envia o comando `/cancelar`.

### CA-M9-06 — `/cancelar` em qualquer estado
**Eu verifico que** a sessão é limpa e o estado volta a IDLE
**quando** o usuário está em AWAITING_DATA, AWAITING_CONFIRMATION ou AWAITING_EDITION
**e** envia o comando `/cancelar`.

### CA-M9-07 — `/ultimos` default 5
**Eu verifico que** as 5 transações mais recentes são exibidas, ordenadas por data decrescente
**quando** o usuário tem 10+ transações registradas
**e** envia `/ultimos` (sem parâmetro).

### CA-M9-08 — `/ultimos` com parâmetro inválido
**Eu verifico que** parâmetros inválidos sofrem fallback silencioso para 5 (`/ultimos abc`, `/ultimos -3`, `/ultimos 0`) e parâmetros > 50 são capados em 50
**quando** o usuário envia `/ultimos` com valor não numérico, negativo, zero, ou > 50
**e** o bot trata sem mensagem de erro.

### CA-M9-09 — `/ultimos` sem transações
**Eu verifico que** o bot exibe "Nenhuma transação registrada ainda"
**quando** o usuário não tem transações no Firestore
**e** envia `/ultimos`.

### CA-M9-10 — `/categorias` lista com contador
**Eu verifico que** todas as categorias (padrão + personalizadas) são listadas com seu respectivo `use_count`
**quando** existem categorias com transações registradas
**e** o usuário envia `/categorias`.

### CA-M9-11 — `/sync` com pendentes
**Eu verifico que** as transações pendentes são sincronizadas com a planilha e o contador é resetado
**quando** existem transações com `sync_status=pending` e `sync_attempts > 0`
**e** o usuário envia `/sync`.

### CA-M9-12 — `/sync` sem pendentes
**Eu verifico que** o bot responde "Nenhuma transação pendente"
**quando** não há transações com `sync_status=pending`
**e** o usuário envia `/sync`.

### CA-M9-13 — Cron recupera pendentes
**Eu verifico que** transações com `sync_status=pending` e `sync_attempts < 3` são sincronizadas
**quando** o cron (ou chamada a `GET /cron/sync-pending`) é acionado
**e** a planilha está acessível.

### CA-M9-14 — Falha após 3 tentativas
**Eu verifico que** `sync_status` transita para `failed` e o usuário recebe notificação
**quando** uma transação falha sincronização 3 vezes consecutivas
**e** o cron ou `/sync` processa a transação pela 3ª vez.

### CA-M9-15 — Cron token inválido
**Eu verifico que** a rota retorna HTTP 401 com JSON `{"status":"error","message":"Unauthorized"}`
**quando** uma requisição é feita a `GET /cron/sync-pending` sem o header `X-Cron-Token` ou com token inválido.

---

## 12. Sumário para o Coder

### 12.1 Arquivos a CRIAR

| # | Arquivo | Propósito |
|---|---------|-----------|
| 1 | `app/Bot/Handlers/NovaHandler.php` | Handler `/nova` — wizard 5 passos |
| 2 | `app/Bot/Handlers/UltimosHandler.php` | Handler `/ultimos [n]` |
| 3 | `app/Bot/Handlers/CategoriasHandler.php` | Handler `/categorias` |
| 4 | `app/Bot/Handlers/SyncHandler.php` | Handler `/sync` |
| 5 | `app/Console/Commands/SyncPendingTransactions.php` | Artisan command `transactions:sync-pending` |
| 6 | `tests/Feature/Commands/NovaHandlerTest.php` | CT-025 |
| 7 | `tests/Feature/Commands/UltimosHandlerTest.php` | CT-027, CT-028 |
| 8 | `tests/Feature/Commands/CategoriasHandlerTest.php` | CT-029 |
| 9 | `tests/Feature/Commands/SyncHandlerTest.php` | `/sync` |
| 10 | `tests/Feature/Commands/HelpHandlerTest.php` | CT-024 |
| 11 | `tests/Feature/Commands/StartHandlerTest.php` | CT-023 |
| 12 | `tests/Feature/Commands/SyncPendingTransactionsTest.php` | CT-033 |

### 12.2 Arquivos a MODIFICAR

| # | Arquivo | Mudança |
|---|---------|---------|
| 1 | `app/Bot/BotLoader.php` | Registrar 4 novos handlers de comando |
| 2 | `app/Bot/Handlers/HelpHandler.php` | Mudar flags `active` para `true` |
| 3 | `app/Bot/Handlers/StartHandler.php` | Adicionar `clearSession()` antes de enviar mensagem |
| 4 | `app/Conversation/ConversationRouter.php` | Adicionar suporte a wizard (`_wizard_step`, `validateLabels`, `pickNextAwaitingField` estendido) |
| 5 | `app/Bot/Messaging/TransactionSummaryFormatter.php` | Adicionar `listSummary()` e `listRow()` |
| 6 | `app/Services/Google/FirestoreService.php` | Adicionar `resetPendingSyncAttempts()` e `listPendingSync()` |
| 7 | `routes/web.php` | Adicionar rota `GET /cron/sync-pending` (M11: migrado para `routes/api.php`) |
| 8 | `bootstrap/app.php` | Adicionar `cron/sync-pending` à exclusão CSRF (M11: removido; api group é stateless) |

### 12.3 Ordem de implementação sugerida (1 dev, ~3 dias)

1. **Dia 1:** M9.5 (`/ultimos`) + M9.6 (`/categorias`) — são os mais simples (query → format → send)
2. **Dia 2:** M9.3 (`/nova`) — alteração no ConversationRouter + handler + testes de wizard
3. **Dia 3:** M9.7 (`/sync`) + M9.8 (command) + M9.9 (rota cron) + M9.2 (HelpHandler flags) + M9.10 (testes restantes)
4. **Dia 4 (buffer):** Revisão, `composer test` verde, ajustes de edge cases

---

## 13. Perguntas para o Usuário

**Nenhuma.** Todas as decisões foram cobertas pelas Clarificações (#5, #6, #7) ou são estritamente técnicas. Se houver alguma divergência com o plano aprovado, o `coder` deve reportar como impedimento.
