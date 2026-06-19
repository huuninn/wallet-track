<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Dto\TransactionData;

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
     * @param  string  $source  "text" (DeepSeek) ou "image" (Gemini).
     * @return string ID do documento criado em `transactions/`.
     *
     * @throws \InvalidArgumentException Quando o DTO não tem `amount`/`type`.
     */
    public function saveTransaction(string $chatId, TransactionData $dto, string $source): string
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
            'source' => $source,
            'observations' => $dto->observations,

            // Campos de sincronização com Sheets (consumidos pelo M6).
            'sync_status' => self::SYNC_PENDING,
            'sync_attempts' => 0,
            'sync_last_attempt_at' => null,
            'sync_error_message' => null,

            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $this->gateway->createDocument(self::COLLECTION_TRANSACTIONS, $data);
    }

    /**
     * Recupera um lançamento pelo id. Devolve `data` ou null se não existe.
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
     * Campos null não sobrescrevem valores existentes (são omitidos do merge).
     * O campo `updated_at` sempre é setado para "agora".
     *
     * **Não reseta `retry_count`** (FIX-4): o contador de retentativas
     * deve persistir entre mudanças de estado. Se for necessário resetá-lo
     * (ex.: transição para estado de sucesso), use
     * {@see incrementSessionRetry()} ou faça merge explícito de
     * `retry_count=0`. Sem isso, uma lógica de limite de retentativas
     * (M7/M8) seria burlada — toda mudança de estado zeraria o contador,
     * permitindo retries infinitos.
     *
     * M7 adiciona `source` (text|image — para encaminhar ao SyncSheet no
     * confirm) e permite resetar explicitamente `retry_count` passando 0
     * (null = não mexer). `processing` é gerenciado por
     * {@see tryAcquireSessionProcessingFlag()}.
     *
     * **Limpeza explícita de campos (W-3 da revisão)**: o parâmetro
     * `$clearFields` aceita uma lista de chaves a serem **removidas** do
     * documento via `FieldValue::delete()`. Necessário porque o merge com
     * `null` apenas omite — não apaga — campos existentes. Use ao sair de
     * AWAITING_DATA/EDITION (campo `awaiting_field` deve desaparecer).
     *
     * @param  array<string, mixed>|null  $draft  Draft serializado do TransactionData.
     * @param  string|null  $awaitingField  Campo pedível em aberto (amount/type/date...).
     * @param  int|null  $messageIdConfirm  message_id da mensagem de confirmação (CT-047).
     * @param  string|null  $source  Origem da extração ("text"|"image") para o SyncSheet.
     * @param  int|null  $retryCount  Reset explícito do contador (use 0; null = não mexer).
     * @param  list<string>  $clearFields  Campos a serem removidos do doc (ex.: ["awaiting_field"]).
     */
    public function setSession(
        string $chatId,
        string $state,
        ?array $draft = null,
        ?string $awaitingField = null,
        ?int $messageIdConfirm = null,
        ?string $source = null,
        ?int $retryCount = null,
        array $clearFields = [],
    ): void {
        $data = array_filter([
            'state' => $state,
            'draft' => $draft,
            'awaiting_field' => $awaitingField,
            'message_id_confirm' => $messageIdConfirm,
            'source' => $source,
            'retry_count' => $retryCount,
            'updated_at' => $this->nowIso(),
        ], fn ($value): bool => $value !== null);

        $this->gateway->mergeDocument(self::COLLECTION_SESSIONS, $chatId, $data);

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
        $id = $this->normalizeName($name);
        $now = $this->nowIso();

        $this->gateway->transaction(function (FirestoreGateway $gw) use ($id, $name, $now): void {
            $current = $gw->getDocument(FirestoreService::COLLECTION_LABELS, $id) ?? [];

            $gw->mergeDocument(FirestoreService::COLLECTION_LABELS, $id, [
                'name' => $name,
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
