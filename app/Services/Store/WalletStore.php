<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\LabelFormatter;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Camada de persistência do Wallet Track.
 *
 * Sucessora do FirestoreService (deletado em M7). Usa Eloquent (banco de dados) para dados de domínio
 * (transações, categorias, labels) e Redis Hash para sessões conversacionais.
 *
 * Transações: tabelas transactions + transaction_items + transaction_labels (N:N)
 * Categorias: tabela categories (slug UNIQUE)
 * Labels: tabela labels (folded_name UNIQUE) + transaction_labels (N:N)
 * Sessões: Redis Hash session:{chatId} com TTL 15min
 */
final class WalletStore
{
    public const string SYNC_PENDING = 'pending';

    public const string SYNC_SYNCED = 'synced';

    public const string SYNC_FAILED = 'failed';

    private const int SESSION_TTL_SECONDS = 900; // 15 minutos

    // === TRANSAÇÕES (banco de dados) ===

    /**
     * Persiste um lançamento a partir do DTO. Cria a transação + items + labels
     * em uma transação DB atômica. Retorna o ID (int) da transação criada.
     *
     * @throws \InvalidArgumentException Quando o DTO não tem amount/type.
     */
    public function saveTransaction(string $chatId, TransactionData $dto): int
    {
        // Validar completude do DTO
        if ($dto->amount === null || $dto->type === null || $dto->date === null) {
            throw new \InvalidArgumentException(
                'TransactionData incompleto: amount, type e date são obrigatórios para persistência.'
            );
        }

        return DB::transaction(function () use ($chatId, $dto): int {
            // 1. INSERT transactions
            $transaction = Transaction::create([
                'chat_id' => $chatId,
                'date' => $dto->date,
                'description' => $dto->description,
                'amount' => $dto->amount,
                'type' => $dto->type,
                'category' => $dto->category,
                'observations' => $dto->observations,
                'sync_status' => self::SYNC_PENDING,
                'sync_attempts' => 0,
                'processing' => false,
            ]);

            // 2. INSERT transaction_items (preserva ordem via position)
            foreach ($dto->items as $position => $item) {
                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'position' => $position,
                    'name' => $item['name'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unitPrice'],
                    'subtotal' => $item['subtotal'],
                ]);
            }

            // 3. INSERT transaction_labels (resolve ou cria labels)
            foreach ($dto->labels as $labelName) {
                $formatted = LabelFormatter::format($labelName);
                $folded = TextNormalizer::fold($formatted);
                if ($folded === '') {
                    continue;
                }
                $label = Label::firstOrCreate(
                    ['folded_name' => $folded],
                    ['name' => $formatted],
                );
                // Attach sem duplicar (sync sem detach)
                $transaction->labels()->syncWithoutDetaching([$label->id]);
            }

            return $transaction->id;
        });
    }

    /**
     * Recupera uma transação pelo ID com items e labels eager-loaded.
     */
    public function getTransaction(int $id): ?Transaction
    {
        return Transaction::with(['items' => fn ($q) => $q->orderBy('position'), 'labels'])
            ->find($id);
    }

    /**
     * Lista transações recentes do chat, ordenadas por date DESC.
     *
     * @return Collection<int, Transaction>
     */
    public function listRecent(string $chatId, int $limit = 10, ?string $type = null): Collection
    {
        $query = Transaction::with(['items' => fn ($q) => $q->orderBy('position'), 'labels'])
            ->where('chat_id', $chatId)
            ->orderBy('date', 'desc')
            ->limit($limit);

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * Atualiza o status de sincronização. Se houver erro, incrementa sync_attempts.
     */
    public function updateSyncStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        DB::transaction(function () use ($id, $status, $errorMessage): void {
            $fields = [
                'sync_status' => $status,
                'processing' => false,
                'processing_since' => null,
                'updated_at' => now(),
            ];

            if ($errorMessage !== null) {
                $fields['sync_error_message'] = $errorMessage;
                $fields['sync_last_attempt_at'] = now();
            }

            DB::table('transactions')->where('id', $id)->update($fields);

            if ($errorMessage !== null) {
                DB::table('transactions')->where('id', $id)->increment('sync_attempts');
            }
        });
    }

    /**
     * Reseta sync_attempts=0 para todas as transações pendentes.
     * Retorna o número de registros afetados.
     */
    public function resetPendingSyncAttempts(?string $chatId = null): int
    {
        $query = DB::table('transactions')->where('sync_status', self::SYNC_PENDING);

        if ($chatId !== null) {
            $query->where('chat_id', $chatId);
        }

        return $query->update([
            'sync_attempts' => 0,
            'sync_error_message' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Lista transações pendentes de sync (sync_status=pending AND sync_attempts<3).
     * Ordenadas por created_at ASC (FIFO). Filtro sync_attempts<3 agora no WHERE
     * (no SQL agora).
     *
     * @return Collection<int, Transaction>
     */
    public function listPendingSync(?string $chatId = null, int $limit = 100): Collection
    {
        $query = Transaction::with(['items' => fn ($q) => $q->orderBy('position'), 'labels'])
            ->where('sync_status', self::SYNC_PENDING)
            ->where('sync_attempts', '<', 3)
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        if ($chatId !== null) {
            $query->where('chat_id', $chatId);
        }

        return $query->get();
    }

    /**
     * Tenta adquirir atomicamente o lock de processamento da transação.
     *
     * UPDATE atômico: só adquire se processing=false OU se o lock é stale
     * (processing_since mais antigo que staleLockSeconds). Retorna true se
     * adquirido, false se outra execução já tem o lock fresco.
     */
    public function markSyncStarted(int $id, int $staleLockSeconds = 600): bool
    {
        $affected = DB::table('transactions')
            ->where('id', $id)
            ->where(function ($q) use ($staleLockSeconds): void {
                $q->where('processing', false)
                  ->orWhere('processing_since', '<', now()->subSeconds($staleLockSeconds));
            })
            ->update([
                'processing' => true,
                'processing_since' => now(),
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    /**
     * Marca a transação como sincronizada com sucesso.
     */
    public function markSyncSuccess(int $id, string $spreadsheetRowId): void
    {
        DB::table('transactions')->where('id', $id)->update([
            'sync_status' => self::SYNC_SYNCED,
            'spreadsheet_row_id' => $spreadsheetRowId,
            'processing' => false,
            'processing_since' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Re-enfileira a transação como pending sem incrementar sync_attempts.
     */
    public function requeuePendingSync(int $id): void
    {
        DB::table('transactions')->where('id', $id)->update([
            'sync_status' => self::SYNC_PENDING,
            'processing' => false,
            'processing_since' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Marca a transação como falha definitiva. Carimba notified_at apenas
     * na primeira transição para failed (preserva carimbo original em re-chamadas).
     *
     * Usa DB::transaction + lockForUpdate para serializar leitura e escrita,
     * evitando race condition em que duas chamadas concorrentes leem
     * notified_at=null e ambas o sobrescrevem.
     */
    public function markSyncFailed(int $id, string $error): void
    {
        DB::transaction(function () use ($id, $error): void {
            $tx = Transaction::where('id', $id)->lockForUpdate()->first();
            if ($tx === null) {
                return;
            }

            $fields = [
                'sync_status' => self::SYNC_FAILED,
                'sync_error_message' => $error,
                'sync_last_attempt_at' => now(),
                'processing' => false,
                'processing_since' => null,
                'updated_at' => now(),
            ];

            // Carimba notified_at apenas na 1ª transição para failed.
            if (empty($tx->notified_at)) {
                $fields['notified_at'] = now();
            }

            $tx->update($fields);
        });
    }

    // === SESSÕES (Redis) ===

    /**
     * Lê a sessão do chat. Retorna array com os campos da sessão ou null.
     *
     * Redis HGETALL devolve todos os valores como string. Tipos são
     * coerzidos de volta: draft (JSON→array), message_id_* (→int),
     * retry_count (→int), processing (→bool).
     *
     * @return array<string, mixed>|null
     */
    public function getSession(string $chatId): ?array
    {
        $data = Redis::hgetall("session:{$chatId}");

        if (empty($data)) {
            return null;
        }

        return $this->deserializeSession($data);
    }

    /**
     * Atualiza (merge) a sessão do chat. Cria se não existe.
     *
     * Usa Redis HMSET (skip nulls — SessionData::toMergeArray já filtra).
     * Renova o TTL após cada escrita. clearFields remove campos via HDEL.
     *
     * @param  list<string>  $clearFields  Campos a serem removidos.
     */
    public function setSession(string $chatId, SessionData $data, array $clearFields = []): void
    {
        $key = "session:{$chatId}";
        $merge = $data->toMergeArray(now()->toIso8601String());

        // Serializa draft para JSON string (Redis armazena tudo como string).
        // JSON_PRESERVE_ZERO_FRACTION garante que 50.0 seja serializado como
        // "50.0" (não "50") — sem isso, floats com valor inteiro perdem a
        // tipagem no round-trip via Redis (json_decode devolve int).
        if (isset($merge['draft']) && is_array($merge['draft'])) {
            $merge['draft'] = json_encode($merge['draft'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }

        // Converte todos os valores para string (Redis exige)
        $stringMerge = [];
        foreach ($merge as $field => $value) {
            if (is_bool($value)) {
                $stringMerge[$field] = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $stringMerge[$field] = (string) $value;
            } else {
                $stringMerge[$field] = (string) $value;
            }
        }

        if (! empty($stringMerge)) {
            Redis::hmset($key, $stringMerge);
        }

        // Renova TTL
        Redis::expire($key, self::SESSION_TTL_SECONDS);

        // Limpa campos stale
        foreach ($clearFields as $field) {
            Redis::hdel($key, $field);
        }
    }

    /**
     * Incrementa atômicamente retry_count da sessão.
     * Retorna o novo valor.
     */
    public function incrementSessionRetry(string $chatId): int
    {
        $key = "session:{$chatId}";
        $newCount = Redis::hincrby($key, 'retry_count', 1);
        Redis::hset($key, 'updated_at', now()->toIso8601String());
        Redis::expire($key, self::SESSION_TTL_SECONDS);

        return $newCount;
    }

    /**
     * Remove a sessão do chat. Idempotente.
     */
    public function clearSession(string $chatId): void
    {
        Redis::del("session:{$chatId}");
    }

    /**
     * Tenta adquirir atomicamente o flag processing da sessão.
     *
     * Usa Redis HSETNX: só seta 'processing' se o campo não existe ainda
     * (campo só existe quando um processo adquiriu o lock e ainda não limpou).
     * Retorna true se adquirido, false se já estava sendo processado.
     */
    public function tryAcquireSessionProcessingFlag(string $chatId): bool
    {
        $key = "session:{$chatId}";

        // HSETNX retorna 1 (campo criado = adquirido) ou 0 (já existe = negado)
        $acquired = Redis::hsetnx($key, 'processing', '1');

        if ($acquired) {
            Redis::hset($key, 'updated_at', now()->toIso8601String());
            Redis::expire($key, self::SESSION_TTL_SECONDS);
        }

        return (bool) $acquired;
    }

    // === CATEGORIAS (banco de dados) ===

    /**
     * Lista todas as categorias ordenadas por display_name ASC.
     *
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return Category::orderBy('display_name', 'asc')->get();
    }

    /**
     * Devolve uma categoria pelo nome (case-insensitive via slug).
     */
    public function getCategory(string $name): ?Category
    {
        $slug = mb_strtolower(trim($name));

        return Category::where('slug', $slug)->first();
    }

    /**
     * Verifica se a categoria existe (case-insensitive).
     */
    public function categoryExists(string $name): bool
    {
        return $this->getCategory($name) !== null;
    }

    /**
     * Cria uma categoria. Idempotente via firstOrCreate.
     */
    public function createCategory(string $displayName, string $defaultType, bool $isDefault = false): void
    {
        Category::firstOrCreate(
            ['slug' => mb_strtolower(trim($displayName))],
            [
                'display_name' => $displayName,
                'default_type' => $defaultType,
                'use_count' => 0,
                'is_default' => $isDefault,
            ],
        );
    }

    // === LABELS (banco de dados) ===

    /**
     * Incrementa use_count e atualiza last_used_at. Cria a label se não existe.
     */
    public function incrementLabelUse(string $name): void
    {
        $formattedName = LabelFormatter::format($name);
        $foldedName = TextNormalizer::fold($formattedName);

        if ($foldedName === '') {
            return;
        }

        // firstOrCreate + increment atômico. Sempre incrementa use_count
        // (use_count = (current ?? 0) + 1).
        // Sem o increment incondicional, labels novas ficavam com use_count=0
        // porque wasRecentlyCreated=true pulava o bloco.
        $label = Label::firstOrCreate(
            ['folded_name' => $foldedName],
            [
                'name' => $formattedName,
                'use_count' => 0,
                'last_used_at' => now(),
            ],
        );

        $label->increment('use_count');
        $label->update(['last_used_at' => now()]);
    }

    /**
     * Lista as labels mais usadas (top N por use_count DESC).
     *
     * @return Collection<int, Label>
     */
    public function getTopLabels(int $limit = 10): Collection
    {
        return Label::orderBy('use_count', 'desc')->limit($limit)->get();
    }

    /**
     * Cria ou sobrescreve uma label pelo folded_name.
     * Usado pelo comando de deduplicação.
     */
    public function upsertLabel(string $foldedName, array $data): void
    {
        Label::updateOrCreate(['folded_name' => $foldedName], $data);
    }

    /**
     * Remove uma label pelo folded_name.
     * Usado pelo comando de deduplicação.
     */
    public function deleteLabelByFoldedName(string $foldedName): void
    {
        Label::where('folded_name', $foldedName)->delete();
    }

    /**
     * Lista todas as labels.
     *
     * @return Collection<int, Label>
     */
    public function listAllLabels(): Collection
    {
        return Label::all();
    }

    // === HELPERS ===

    /**
     * Deserializa um Redis Hash (todas strings) para array tipado de sessão.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function deserializeSession(array $data): array
    {
        $result = [];

        $result['state'] = $data['state'] ?? null;

        $result['draft'] = isset($data['draft']) && $data['draft'] !== ''
            ? json_decode($data['draft'], true)
            : null;

        $result['awaiting_field'] = $data['awaiting_field'] ?? null;

        $result['message_id_confirm'] = isset($data['message_id_confirm'])
            ? (int) $data['message_id_confirm']
            : null;

        $result['message_id_edit_picker'] = isset($data['message_id_edit_picker'])
            ? (int) $data['message_id_edit_picker']
            : null;

        $result['message_id_ask_edition'] = isset($data['message_id_ask_edition'])
            ? (int) $data['message_id_ask_edition']
            : null;

        $result['source'] = $data['source'] ?? null;

        $result['retry_count'] = isset($data['retry_count'])
            ? (int) $data['retry_count']
            : null;

        $result['processing'] = isset($data['processing'])
            ? $data['processing'] === '1'
            : false;

        $result['updated_at'] = $data['updated_at'] ?? null;

        return $result;
    }
}
