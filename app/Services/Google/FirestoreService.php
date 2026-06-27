<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Support\LabelFormatter;
use App\Support\TextNormalizer;

/**
 * Camada de persistência do Wallet Track (M5).
 *
 * Encapsula o modelo de dados Firestore da spec §5. Tudo que toca Firestore
 * passa por aqui. Depende apenas de {@see FirestoreGateway} (interface),
 * nunca da SDK bruta — o que torna o serviço trivialmente testável com
 * {@see InMemoryFirestoreGateway}.
 *
 * Coleções geridas por esta classe (vide spec §5):
 *   - `transactions/{auto_id}`  — lançamentos financeiros.
 *   - `categories/{name_lower}` — catálogo de categorias.
 *   - `labels/{name_lower}`     — catálogo de etiquetas + contadores.
 *   - `sessions/{chat_id}`      — máquina de estados conversacional.
 *
 * Convenção de timestamps: campos `_at` trafegam como strings ISO 8601 UTC.
 * O gateway real converte para dentro/fora de `Google\Cloud\Core\Timestamp`.
 *
 * **sync_status é gerenciado aqui mas consumido pelo M6 (Sheets)**: o M5
 * cria a transação em `pending` e o M6 chama {@see updateSyncStatus()} ao
 * espelhar a linha na planilha.
 */
final class FirestoreService
{
    /** Coleções expostas como constantes para uso por commands e testes. */
    public const string COLLECTION_TRANSACTIONS = 'transactions';

    public const string COLLECTION_CATEGORIES = 'categories';

    public const string COLLECTION_LABELS = 'labels';

    public const string COLLECTION_SESSIONS = 'sessions';

    /** Valores válidos para `sync_status` (spec §5). */
    public const string SYNC_PENDING = 'pending';

    public const string SYNC_SYNCED = 'synced';

    public const string SYNC_FAILED = 'failed';

    public function __construct(
        private readonly FirestoreGateway $gateway,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Transações
    |--------------------------------------------------------------------------
    */

    /**
     * Persiste um lançamento a partir do DTO extraído pelo DeepSeek/Gemini.
     *
     * Monta o array completo do schema `transactions/`, inicializa os campos
     * de sincronização (`sync_status='pending'`, `sync_attempts=0`) e cria
     * o documento (auto-id). Devolve o id gerado.
     *
     * **Guarda de completude (FIX-3)**: o schema `transactions/` exige
     * `amount` e `type` como NOT NULL. Como o DTO aceita esses campos como
     * nullable (M3/M4 permitem fluxo conversacional com dados pedindo),
     * validar aqui evita corrupção silenciosa de documento — o caller deve
     * usar {@see TransactionData::isComplete()} ou completar o DTO antes
     * de chamar este método.
     *
     * @param  string  $chatId  ID do chat do Telegram (spec §5: `chat_id`).
     * @return string ID do documento criado em `transactions/`.
     *
     * @throws \InvalidArgumentException Quando o DTO não tem `amount`/`type`.
     */
    public function saveTransaction(string $chatId, TransactionData $dto): string
    {
        if ($dto->amount === null || $dto->type === null) {
            throw new \InvalidArgumentException(
                'TransactionData incompleto: amount e type são obrigatórios para persistência. '
                .'O caller deve garantir DTO completo antes de chamar saveTransaction().'
            );
        }

        $now = $this->nowIso();

        $data = [
            'chat_id' => $chatId,
            'date' => $dto->date,
            'description' => $dto->description,
            'amount' => $dto->amount,
            'type' => $dto->type,
            'category' => $dto->category,
            'labels' => $dto->labels,
            'observations' => $dto->observations,
            'items' => $dto->items,

            // Campos de sincronização com Sheets (consumidos pelo M6).
            'sync_status' => self::SYNC_PENDING,
            'sync_attempts' => 0,
            'sync_last_attempt_at' => null,
            'sync_error_message' => null,
            'spreadsheet_row_id' => null,
            'processing' => false,
            'notified_at' => null,

            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $this->gateway->createDocument(self::COLLECTION_TRANSACTIONS, $data);
    }

    /**
     * Recupera um lançamento pelo id. Devolve `data` ou null se não existe.
     *
     * **Retrocompatibilidade (Decisão D8):** documentos criados ANTES da
     * feature items NÃO têm o campo `items`. Callers devem usar
     * `$doc['items'] ?? []` para evitar `Undefined index`. O serviço não
     * normaliza isso na leitura.
     *
     * @return array<string, mixed>|null
     */
    public function getTransaction(string $id): ?array
    {
        return $this->gateway->getDocument(self::COLLECTION_TRANSACTIONS, $id);
    }

    /**
     * Lista os lançamentos mais recentes do chat, ordenados por `date DESC`.
     *
     * Opcionalmente filtra por `type` ("expense"|"income"). Limite padrão 10
     * (especificado no spec §5/§8).
     *
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function listRecent(string $chatId, int $limit = 10, ?string $type = null): array
    {
        $wheres = [['field' => 'chat_id', 'op' => '==', 'value' => $chatId]];

        if ($type !== null) {
            $wheres[] = ['field' => 'type', 'op' => '==', 'value' => $type];
        }

        return $this->gateway->query(
            collection: self::COLLECTION_TRANSACTIONS,
            wheres: $wheres,
            orderBys: [['field' => 'date', 'direction' => 'DESC']],
            limit: $limit,
        );
    }

    /**
     * Atualiza o status de sincronização (usado pelo M6).
     *
     *  - Atualiza `sync_status`.
     *  - Se `$errorMessage` for fornecido, marca também `sync_error_message`,
     *    incrementa `sync_attempts` e seta `sync_last_attempt_at=agora`.
     *  - Em sucesso (synced), o M6 pode passar errorMessage=null para limpar
     *    o motivo de erro anterior mas o `sync_attempts` só é incrementado
     *    quando há errorMessage (toda tentativa com erro conta).
     *  - **Sempre limpa o flag `processing`**: transições de status implicam
     *    que a tentativa de sync terminou (M9.8). Sem isto, o lock otimista
     *    ficaria preso entre tentativas.
     *
     * **Atomicidade (FIX-5)**: o `updateFields` + `incrementField` rodam
     * dentro de `transaction()` para garantir que um falhe/role back ambos.
     * Sem isso, se `updateFields` commitsse e `incrementField` falhasse,
     * o documento ficaria inconsistente (status atualizado mas contador
     * não). Com FIX-1 no gateway, escritas dentro de `transaction()`
     * compartilham commit/rollback.
     *
     * OBSERVAÇÃO: O Firestore real exige que o documento exista para usar
     * `update()` — caso contrário, lança exceção. Aqui não validamos isso
     * (o serviço caller é responsável por passar um id existente).
     */
    public function updateSyncStatus(string $id, string $status, ?string $errorMessage = null): void
    {
        $fields = [
            'sync_status' => $status,
            'processing' => false,
            'updated_at' => $this->nowIso(),
        ];

        if ($errorMessage !== null) {
            $fields['sync_error_message'] = $errorMessage;
            $fields['sync_last_attempt_at'] = $this->nowIso();
        }

        // Atomicidade: se incrementField falhar depois de updateFields,
        // a transação faz rollback das duas (FIX-5).
        $this->gateway->transaction(function (FirestoreGateway $gw) use ($id, $fields, $errorMessage): void {
            // updateFields lança exceção se o doc não existe; por contrato do
            // serviço, espera-se um id existente (criado por saveTransaction).
            $gw->updateFields(self::COLLECTION_TRANSACTIONS, $id, $fields);

            // Incremento atômico de sync_attempts só quando há erro (tentativa
            // contabiliza quando falhou — success não conta como "tentativa").
            if ($errorMessage !== null) {
                $gw->incrementField(
                    self::COLLECTION_TRANSACTIONS,
                    $id,
                    'sync_attempts',
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Sincronização em lote (M9.7 + M9.8)
    |--------------------------------------------------------------------------
    */

    /**
     * Reseta o contador `sync_attempts` para 0 em todas as transações
     * pendentes do chat (ou globalmente, se `$chatId` for null).
     *
     * Usado pelo handler `/sync` quando o usuário solicita uma nova tentativa
     * manual após falhas consecutivas. A intenção é "dar mais 3 chances" —
     * sem o reset, uma transação que já teve 2 falhas seria pulada na próxima
     * execução (filtro `sync_attempts < 3`).
     *
     * Mantém `sync_status='pending'` (idempotente) e zera `sync_error_message`
     * para que a próxima execução não carregue mensagem de erro stale.
     *
     * **Lock atômico (Decisão Portão 2 #8)**: a operação NÃO adquire o flag
     * `processing` — é seguro rodar concorrentemente com o cron porque cada
     * doc é atualizado individualmente. Se houver race, o pior caso é o cron
     * ler `sync_attempts=N` (já incrementado) e pular o doc, ou ler `N=0`
     * e processá-lo normalmente. Ambos são corretos.
     *
     * @param  string|null  $chatId  Se fornecido, reseta apenas deste chat.
     *                               Se null, reseta globalmente (uso do cron).
     * @return int Número de documentos efetivamente resetados.
     */
    public function resetPendingSyncAttempts(?string $chatId = null): int
    {
        $wheres = [
            ['field' => 'sync_status', 'op' => '==', 'value' => self::SYNC_PENDING],
        ];

        if ($chatId !== null) {
            $wheres[] = ['field' => 'chat_id', 'op' => '==', 'value' => $chatId];
        }

        // Limite alto (pragmatismo): para 1 usuário com poucos meses de uso,
        // o número de pendentes é tipicamente < 100. Aumentar o limite evita
        // paginação em casos extremos sem causar lentidão.
        $results = $this->gateway->query(
            collection: self::COLLECTION_TRANSACTIONS,
            wheres: $wheres,
            orderBys: [],
            limit: 1000,
        );

        $now = $this->nowIso();
        $count = 0;

        foreach ($results as $doc) {
            $this->gateway->updateFields(self::COLLECTION_TRANSACTIONS, $doc['id'], [
                'sync_attempts' => 0,
                'sync_status' => self::SYNC_PENDING,
                'sync_error_message' => null,
                'updated_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Lista transações com `sync_status='pending'` e `sync_attempts < 3`,
     * ordenadas por `created_at ASC` (mais antigas primeiro — FIFO).
     *
     * Alimenta o comando `transactions:sync-pending` (cron) e o handler
     * `/sync` (manual). O filtro `sync_attempts < 3` é aplicado em memória
     * para evitar índice composto extra — com limite de 100 docs, o custo
     * é desprezível.
     *
     * @param  string|null  $chatId  Se fornecido, filtra por chat específico
     *                               (uso do `/sync`); se null, lista de todos
     *                               os chats (uso do cron).
     * @param  int  $limit  Tamanho máximo do batch.
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function listPendingSync(?string $chatId = null, int $limit = 100): array
    {
        $wheres = [
            ['field' => 'sync_status', 'op' => '==', 'value' => self::SYNC_PENDING],
        ];

        if ($chatId !== null) {
            $wheres[] = ['field' => 'chat_id', 'op' => '==', 'value' => $chatId];
        }

        $results = $this->gateway->query(
            collection: self::COLLECTION_TRANSACTIONS,
            wheres: $wheres,
            orderBys: [['field' => 'created_at', 'direction' => 'ASC']],
            limit: $limit,
        );

        // Filtra sync_attempts < 3 em memória (evita índice composto extra).
        return array_values(array_filter(
            $results,
            static fn (array $doc): bool => (int) ($doc['data']['sync_attempts'] ?? 0) < 3,
        ));
    }

    /**
     * Tenta adquirir atomicamente o lock `processing=true` na transação
     * (Decisão Portão 2 #8 — coordenação `/sync` × cron).
     *
     * Caso de uso: o cron e o `/sync` manual podem ser disparados quase
     * simultaneamente. Sem lock, ambos processariam a mesma transação,
     * potencialmente duplicando linhas na planilha (Sheets API não é
     * idempotente — cada `append` gera nova linha).
     *
     * Read-modify-write atômico via `transaction()`: lê o doc, verifica
     * `processing`. Se já `true` → retorna `false` (alguém pegou primeiro).
     * Se `false`/ausente → seta `true` e retorna `true`.
     *
     * O caller é responsável por liberar o lock (chamando
     * {@see markSyncSuccess()} ou {@see markSyncFailed()}, ou
     * {@see updateSyncStatus()} que limpa o flag automaticamente).
     *
     * **Idempotência**: a segunda chamada para o mesmo id (enquanto a
     * primeira ainda está em andamento) retorna `false` — exatamente o
     * comportamento que evita duplicação.
     *
     * @return bool `true` se o lock foi adquirido (esta chamada pode
     *              processar), `false` se já estava sendo processado.
     */
    public function markSyncStarted(string $id): bool
    {
        $acquired = false;

        $this->gateway->transaction(function (FirestoreGateway $gw) use ($id, &$acquired): void {
            $doc = $gw->getDocument(self::COLLECTION_TRANSACTIONS, $id) ?? [];

            if (($doc['processing'] ?? false) === true) {
                return; // Outra execução já tem o lock.
            }

            $gw->updateFields(self::COLLECTION_TRANSACTIONS, $id, [
                'processing' => true,
                'updated_at' => $this->nowIso(),
            ]);

            $acquired = true;
        });

        return $acquired;
    }

    /**
     * Marca a transação como sincronizada com sucesso e libera o lock
     * `processing`.
     *
     * Idempotente em relação a `sync_status`: se já estiver como `synced`,
     * o `updateFields` apenas sobrescreve com os mesmos valores. Diferente
     * de {@see updateSyncStatus()} (que NÃO altera `spreadsheet_row_id`),
     * este método registra o id da linha na planilha para auditoria futura.
     *
     * @param  string  $id  ID do documento em `transactions/`.
     * @param  string  $spreadsheetRowId  Identificador da linha na planilha
     *                                    (por ora: o próprio Firestore id;
     *                                    evolução futura pode usar o número
     *                                    da linha real retornado pelo Sheets).
     */
    public function markSyncSuccess(string $id, string $spreadsheetRowId): void
    {
        $this->gateway->updateFields(self::COLLECTION_TRANSACTIONS, $id, [
            'sync_status' => self::SYNC_SYNCED,
            'spreadsheet_row_id' => $spreadsheetRowId,
            'processing' => false,
            'updated_at' => $this->nowIso(),
        ]);
    }

    /**
     * Re-enfileira a transação como `pending` para a próxima execução do
     * cron/sync, sem alterar `sync_attempts` (Decisão Portão 2 #7).
     *
     * Caso de uso: `SyncSheet::handle()` retornou `false` (falha recuperável)
     * e já incrementou o contador via `updateSyncStatus(FAILED, $error)`.
     * O command decide re-tentar: precisa mover o status de `failed` de
     * volta para `pending` SEM incrementar de novo.
     *
     * Por que não usar {@see updateSyncStatus($id, 'pending', $error)}?
     * Porque esse método incrementa `sync_attempts` quando recebe
     * `errorMessage` (regra do M6 preservada para não quebrar testes
     * legados). Aqui o incremento já foi feito por `SyncSheet` — queremos
     * apenas "desfazer" o status.
     *
     * O `sync_error_message` e `sync_last_attempt_at` são preservados
     * (úteis para debug) — não há reset.
     *
     * @param  string  $id  ID do documento em `transactions/`.
     */
    public function requeuePendingSync(string $id): void
    {
        $this->gateway->updateFields(self::COLLECTION_TRANSACTIONS, $id, [
            'sync_status' => self::SYNC_PENDING,
            'processing' => false,
            'updated_at' => $this->nowIso(),
        ]);
    }

    /**
     * Marca a transação como falha definitiva e libera o lock `processing`.
     *
     * Comportamento atômico via `transaction()`: em uma única operação,
     * persiste o status final, registra o motivo do erro e, **apenas na
     * 1ª transição para `failed`**, carimba o campo `notified_at` para que
     * o command saiba que já enviou a notificação ao usuário (Decisão
     * Portão 2 #5 — sem spam).
     *
     * **NÃO incrementa `sync_attempts`**: presume-se que o caller já
     * incrementou via `SyncSheet::handle()` (que chama
     * `updateSyncStatus(FAILED, $error)` no caminho de erro, contabilizando
     * a tentativa). Incrementar de novo aqui causaria contagem dobrada
     * (ex.: 3ª tentativa mostraria 4). Se este método for chamado sem
     * `SyncSheet` ter rodado antes, o caller é responsável por incrementar.
     *
     * O caller (command) deve checar `$doc['notified_at']` ANTES de
     * chamar este método para decidir se envia `BotMessenger::notifyError()`
     * — esta função apenas carimba o flag como efeito colateral.
     *
     * **Idempotência de `notified_at`**: se já tem valor (transação já
     * tinha sido marcada como failed em execução anterior e o command
     * re-chamou por algum motivo), o campo NÃO é sobrescrito — preserva
     * o carimbo original (rastreabilidade).
     *
     * @param  string  $id  ID do documento em `transactions/`.
     * @param  string  $error  Mensagem de erro (curta, exibida ao usuário
     *                         na notificação e armazenada para debug).
     */
    public function markSyncFailed(string $id, string $error): void
    {
        $now = $this->nowIso();

        $this->gateway->transaction(function (FirestoreGateway $gw) use ($id, $error, $now): void {
            $doc = $gw->getDocument(self::COLLECTION_TRANSACTIONS, $id) ?? [];

            $fields = [
                'sync_status' => self::SYNC_FAILED,
                'sync_error_message' => $error,
                'sync_last_attempt_at' => $now,
                'processing' => false,
                'updated_at' => $now,
            ];

            // Carimba notified_at apenas na 1ª transição para failed.
            // Empty check (null|string vazia) cobre docs antigos que não
            // tinham o campo antes do M9.
            if (empty($doc['notified_at'])) {
                $fields['notified_at'] = $now;
            }

            $gw->updateFields(self::COLLECTION_TRANSACTIONS, $id, $fields);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Sessões (máquina de estados conversacional)
    |--------------------------------------------------------------------------
    */

    /**
     * Lê a sessão do chat. Devolve data ou null se não existe.
     *
     * @return array<string, mixed>|null
     */
    public function getSession(string $chatId): ?array
    {
        return $this->gateway->getDocument(self::COLLECTION_SESSIONS, $chatId);
    }

    /**
     * Atualiza (merge) a sessão do chat. Cria se não existe.
     *
     * Campos null em {@see SessionData} não sobrescrevem valores existentes
     * (são omitidos do merge). O carimbo `updated_at` sempre é setado para
     * "agora".
     *
     * **Não reseta `retry_count`** (FIX-4): o contador de retentativas
     * deve persistir entre mudanças de estado. Se for necessário resetá-lo
     * (ex.: transição para estado de sucesso), use
     * {@see incrementSessionRetry()} ou passe explicitamente
     * `retryCount: 0` no DTO. Sem isso, uma lógica de limite de retentativas
     * (M7/M8) seria burlada — toda mudança de estado zeraria o contador,
     * permitindo retries infinitos.
     *
     * M7 adiciona `source` (text|image|wizard — para encaminhar ao SyncSheet
     * no confirm) e permite resetar explicitamente `retry_count` passando 0
     * (null = não mexer). `processing` é gerenciado por
     * {@see tryAcquireSessionProcessingFlag()}.
     *
     * **Limpeza explícita de campos (W-3 da revisão)**: o parâmetro
     * `$clearFields` aceita uma lista de chaves a serem **removidas** do
     * documento via `FieldValue::delete()`. Necessário porque o merge com
     * `null` apenas omite — não apaga — campos existentes. Use ao sair de
     * AWAITING_DATA/EDITION (campo `awaiting_field` deve desaparecer).
     *
     * **P7-A-4 — refator de assinatura**: até P7-A-3 este método recebia
     * 10 parâmetros nomeados. O DTO {@see SessionData} encapsula os 8
     * campos "mutáveis" da sessão; `$clearFields` continua como segundo
     * argumento por ser dependente de contexto externo ao DTO.
     *
     * @param  list<string>  $clearFields  Campos a serem removidos do doc (ex.: ["awaiting_field"]).
     */
    public function setSession(string $chatId, SessionData $data, array $clearFields = []): void
    {
        $this->gateway->mergeDocument(
            self::COLLECTION_SESSIONS,
            $chatId,
            // S3: o filtro de campos (`null` omitido, `message_id_* === 0`
            // omitido) vive em SessionData::toMergeArray() — comportamento
            // idêntico ao P7-A-2.
            $data->toMergeArray($this->nowIso()),
        );

        // Limpeza explícita de campos stale (W-3). Merge com null não
        // apaga no Firestore — só `FieldValue::delete()` remove o campo.
        foreach ($clearFields as $field) {
            $this->gateway->deleteField(self::COLLECTION_SESSIONS, $chatId, $field);
        }
    }

    /**
     * Incrementa atômicamente `retry_count` da sessão do chat.
     *
     * Faz read-modify-write dentro de `transaction()` do gateway para
     * garantir que dois incrementos concorrentes não sejam perdidos
     * (lost update). Retorna o novo valor do contador.
     *
     * Caso de uso (M7/M8): antes de re-pedir um campo ao usuário, o
     * fluxo conversacional chama este método para registrar a tentativa;
     * se o retorno exceder o limite, o bot deve abortar/desistir em vez
     * de continuar pedindo — evitando loop infinito.
     */
    public function incrementSessionRetry(string $chatId): int
    {
        $newCount = 0;

        $this->gateway->transaction(function (FirestoreGateway $gw) use ($chatId, &$newCount): void {
            $session = $gw->getDocument(self::COLLECTION_SESSIONS, $chatId) ?? [];
            $newCount = ((int) ($session['retry_count'] ?? 0)) + 1;

            $gw->mergeDocument(self::COLLECTION_SESSIONS, $chatId, [
                'retry_count' => $newCount,
                'updated_at' => $this->nowIso(),
            ]);
        });

        return $newCount;
    }

    /**
     * Remove a sessão do chat. Idempotente: não lança se não existe.
     */
    public function clearSession(string $chatId): void
    {
        $this->gateway->deleteDocument(self::COLLECTION_SESSIONS, $chatId);
    }

    /**
     * Tenta adquirir atomicamente o flag `processing` da sessão (M7.9 — idempotência).
     *
     * Caso de uso: quando o usuário toca "Confirmar", dois callbacks podem
     * chegar quase simultaneamente (duplo-clique / rede lenta). Sem proteção,
     * ambos passariam pelo check e executariam `saveTransaction + SyncSheet`
     * em duplicata — transação duplicada na planilha.
     *
     * Este método faz read-modify-write atômico (via `transaction()` do
     * gateway): lê a sessão, verifica `processing`. Se já true → retorna
     * false (alguém pegou primeiro). Se false → seta true e retorna true.
     *
     * O flag é limpo automaticamente quando a sessão é removida via
     * {@see clearSession()} (sucesso ou cancelamento). Em caso de crash
     * entre o acquire e o clear, a sessão fica "presa" em processing=true
     * — o usuário pode se recuperar via /nova (que trata qualquer sessão
     * como descartável) ou via timeout (M7.8 limpa a sessão expirada).
     *
     * **Janela de crash (W-4)**: se o processo PHP crashar entre o acquire
     * e o clearSession(), a sessão fica com `processing=true` permanentemente.
     * A recuperação automática ocorre em até `SESSION_TIMEOUT_MINUTES` (15 min)
     * quando o Router detecta a sessão como expirada. Mitigação manual:
     * comando `/cancelar` (clearSession imediato). Em produção, considerar
     * TTL explícito no flag se isso for inaceitável.
     *
     * @return bool true se o flag foi adquirido (esta chamada pode processar),
     *              false se já estava sendo processado por outra chamada.
     */
    public function tryAcquireSessionProcessingFlag(string $chatId): bool
    {
        $acquired = false;

        $this->gateway->transaction(function (FirestoreGateway $gw) use ($chatId, &$acquired): void {
            $session = $gw->getDocument(self::COLLECTION_SESSIONS, $chatId) ?? [];

            if (($session['processing'] ?? false) === true) {
                return; // já em processamento — não adquire.
            }

            $gw->mergeDocument(self::COLLECTION_SESSIONS, $chatId, [
                'processing' => true,
                'updated_at' => $this->nowIso(),
            ]);

            $acquired = true;
        });

        return $acquired;
    }

    /*
    |--------------------------------------------------------------------------
    | Categorias
    |--------------------------------------------------------------------------
    */

    /**
     * Lista todas as categorias, ordenadas por `display_name` ASC.
     *
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function getCategories(): array
    {
        return $this->gateway->query(
            collection: self::COLLECTION_CATEGORIES,
            wheres: [],
            orderBys: [['field' => 'display_name', 'direction' => 'ASC']],
            limit: null,
        );
    }

    /**
     * Devolve uma categoria pelo nome (lowercase). Null se não existe.
     *
     * @return array<string, mixed>|null
     */
    public function getCategory(string $name): ?array
    {
        return $this->gateway->getDocument(self::COLLECTION_CATEGORIES, $this->normalizeName($name));
    }

    /**
     * Verifica se a categoria existe (case-insensitive pelo nome).
     */
    public function categoryExists(string $name): bool
    {
        return $this->getCategory($name) !== null;
    }

    /**
     * Cria uma categoria. O id é o nome lowercase (slug-like).
     *
     * @param  string  $defaultType  "expense"|"income".
     * @param  bool  $isDefault  Se é uma categoria padrão (do seed).
     */
    public function createCategory(string $displayName, string $defaultType, bool $isDefault = false): void
    {
        $id = $this->normalizeName($displayName);
        $now = $this->nowIso();

        $this->gateway->setDocument(self::COLLECTION_CATEGORIES, $id, [
            'display_name' => $displayName,
            'default_type' => $defaultType,
            'use_count' => 0,
            'is_default' => $isDefault,
            'created_at' => $now,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    /**
     * Incrementa atômicamente `use_count` e atualiza `last_used_at`.
     *
     * Usa `transaction()` para garantir read-modify-write atômico (mesmo que
     * a implementação Cloud use `FieldValue::increment()` internamente, o
     * contrato via gateway mantém a semântica transacional para o fake).
     *
     * Cria a label se não existe (use_count parte de 0 → 1).
     *
     * **Ambiguidade da spec (§8.3 × §5)**: a heurística menciona "top labels
     * da categoria", mas o schema `labels/` não tem campo `category`. Aqui
     * não filtramos nem criamos esse campo — o refinamento por categoria
     * será revisitado em M7 (heurística) para não inflar o schema agora.
     */
    public function incrementLabelUse(string $name): void
    {
        // P6 — Backfill lazy: sempre grava o `name` no formato canônico capitalizado.
        $formattedName = LabelFormatter::format($name);

        $id = $this->normalizeLabelName($formattedName);
        $now = $this->nowIso();

        $this->gateway->transaction(function (FirestoreGateway $gw) use ($id, $formattedName, $now): void {
            $current = $gw->getDocument(FirestoreService::COLLECTION_LABELS, $id) ?? [];

            $gw->mergeDocument(FirestoreService::COLLECTION_LABELS, $id, [
                'name' => $formattedName,
                'use_count' => (int) ($current['use_count'] ?? 0) + 1,
                'last_used_at' => $now,
            ]);
        });
    }

    /**
     * Lista as labels mais usadas (top N por `use_count` DESC).
     *
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function getTopLabels(int $limit = 10): array
    {
        return $this->gateway->query(
            collection: self::COLLECTION_LABELS,
            wheres: [],
            orderBys: [['field' => 'use_count', 'direction' => 'DESC']],
            limit: $limit,
        );
    }

    /**
     * Define (cria ou sobrescreve) um documento em `labels/`.
     *
     * Usado pelo comando de deduplicação (F6) para criar o documento
     * canônico após consolidar duplicatas.
     */
    public function setLabelDoc(string $id, array $data): void
    {
        $this->gateway->setDocument(self::COLLECTION_LABELS, $id, $data);
    }

    /**
     * Remove um documento de `labels/`.
     *
     * Usado pelo comando de deduplicação (F6) para deletar duplicatas
     * após consolidá-las no documento canônico.
     */
    public function deleteLabelDoc(string $id): void
    {
        $this->gateway->deleteDocument(self::COLLECTION_LABELS, $id);
    }

    /**
     * Lista todos os documentos da coleção `labels/` (sem limites).
     *
     * Usado pelo comando de deduplicação (F6).
     *
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function listAllLabels(): array
    {
        return $this->gateway->query(
            collection: self::COLLECTION_LABELS,
            wheres: [],
            limit: 1000,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers internos
    |--------------------------------------------------------------------------
    */

    /**
     * Devolve "agora" como string ISO 8601 UTC com microssegundos.
     *
     * O uso explícito de `gmdate` (em vez de `now()`) garante UTC puro sem
     * depender do timezone da aplicação, e mantém o serviço testável sem
     * precisar mockar o Carbon.
     *
     * **Microssegundos (FIX-6)**: o `decode()` do gateway lê timestamps
     * via `Timestamp::FORMAT` (que inclui `.u`), e timestamps no mesmo
     * segundo ficavam idênticos — problema para ordenação/dedupe em
     * operações rápidas. Incluímos `.u` para alinhar fonte e leitura.
     */
    private function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Normaliza um nome de label para id de documento (fold: minúsculas + sem
     * acentos + trim), via {@see TextNormalizer::fold()}.
     *
     * Diferente de {@see normalizeName()} (que preserva acentos para categorias),
     * labels usam folding completo porque a heurística de dedup (SuggestLabels,
     * validateLabels) também opera acento-insensível.
     */
    private function normalizeLabelName(string $name): string
    {
        return TextNormalizer::fold($name);
    }

    /**
     * Normaliza um nome para id de documento (lowercase + trim).
     *
     * O Firestore é case-sensitive em ids; para que "Alimentação" e
     * "alimentação" apontem para o mesmo doc, normalizamos aqui.
     */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
