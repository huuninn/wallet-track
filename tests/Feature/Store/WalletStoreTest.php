<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\Store\WalletStore;
use App\Support\LabelFormatter;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RedisFake;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes de integração do {@see WalletStore} (banco de dados + Redis).
 *
 * Substitui o antigo {@see \Tests\Unit\Services\Google\FirestoreServiceTest}.
 *
 * Banco de dados (transactions, categories, labels): usa RefreshDatabase + SQLite in-memory.
 * Redis (sessions): usa um fake in-memory que implementa os comandos de hash
 * usados pelo WalletStore e é injetado via Redis::swap().
 */
final class WalletStoreTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private WalletStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSAÇÕES (banco de dados)
    |--------------------------------------------------------------------------
    */

    /**
     * Cria um TransactionData DTO válido com os campos mínimos.
     *
     * Importante: usa array_key_exists (via helper $get) em vez de `??`
     * para distinguir "chave ausente" de "explicitamente null". Sem isso,
     * testes como test_save_transaction_throws_when_amount_is_null passam
     * `['amount' => null]` mas o `??` substitui pelo default — o teste
     * nunca exercita o caminho de erro e falha silenciosamente.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeDto(array $overrides = []): TransactionData
    {
        $get = static fn (string $key, mixed $default): mixed =>
            array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new TransactionData(
            description: $get('description', 'Compra no mercado'),
            amount: $get('amount', 150.75),
            type: $get('type', 'expense'),
            category: $get('category', 'Alimentação'),
            labels: $get('labels', ['mercado', 'semana']),
            date: $get('date', '2026-06-15'),
            observations: $get('observations', null),
            items: $get('items', []),
        );
    }

    public function test_save_transaction_creates_transaction_with_items_and_labels(): void
    {
        $dto = $this->makeDto([
            'items' => [
                ['name' => 'Arroz', 'qty' => 2.0, 'unitPrice' => 5.50, 'subtotal' => 11.00],
                ['name' => 'Feijão', 'qty' => 1.0, 'unitPrice' => 8.00, 'subtotal' => 8.00],
            ],
        ]);

        $id = $this->store->saveTransaction('123456', $dto);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $tx = Transaction::with(['items', 'labels', 'category'])->find($id);
        $this->assertNotNull($tx);
        $this->assertSame('123456', $tx->chat_id);
        $this->assertSame('2026-06-15', $tx->date->toDateString());
        $this->assertSame('Compra no mercado', $tx->description);
        $this->assertSame(150.75, (float) $tx->amount);
        $this->assertSame('expense', $tx->type);
        $this->assertNotNull($tx->category_id, 'category_id deve ser setado (FK)');
        $this->assertNotNull($tx->category, 'relação category deve ser carregada');
        $this->assertSame('Alimentação', $tx->category->display_name);
        $this->assertSame(WalletStore::SYNC_PENDING, $tx->sync_status);
        $this->assertSame(0, $tx->sync_attempts);
        $this->assertFalse($tx->processing);

        // Items em ordem de position.
        $this->assertCount(2, $tx->items);
        $this->assertSame('Arroz', $tx->items[0]->name);
        $this->assertSame(0, $tx->items[0]->position);
        $this->assertSame('Feijão', $tx->items[1]->name);
        $this->assertSame(1, $tx->items[1]->position);

        // Labels associadas.
        $this->assertCount(2, $tx->labels);
    }

    public function test_save_transaction_throws_when_amount_is_null(): void
    {
        $dto = $this->makeDto(['amount' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount, type e date são obrigatórios');

        $this->store->saveTransaction('123456', $dto);
    }

    public function test_save_transaction_throws_when_type_is_null(): void
    {
        $dto = $this->makeDto(['type' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount, type e date são obrigatórios');

        $this->store->saveTransaction('123456', $dto);
    }

    public function test_save_transaction_throws_when_date_is_null(): void
    {
        $dto = $this->makeDto(['date' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount, type e date são obrigatórios');

        $this->store->saveTransaction('123456', $dto);
    }

    public function test_save_transaction_with_multiple_items_preserves_position(): void
    {
        $dto = $this->makeDto([
            'items' => [
                ['name' => 'Item 0', 'qty' => 1, 'unitPrice' => 10.0, 'subtotal' => 10.0],
                ['name' => 'Item 1', 'qty' => 2, 'unitPrice' => 20.0, 'subtotal' => 40.0],
                ['name' => 'Item 2', 'qty' => 3, 'unitPrice' => 30.0, 'subtotal' => 90.0],
            ],
        ]);

        $id = $this->store->saveTransaction('123456', $dto);
        $tx = Transaction::with('items')->find($id);

        $this->assertCount(3, $tx->items);
        $this->assertSame('Item 0', $tx->items[0]->name);
        $this->assertSame(0, $tx->items[0]->position);
        $this->assertSame('Item 1', $tx->items[1]->name);
        $this->assertSame(1, $tx->items[1]->position);
        $this->assertSame('Item 2', $tx->items[2]->name);
        $this->assertSame(2, $tx->items[2]->position);
    }

    public function test_save_transaction_creates_labels_with_folded_name(): void
    {
        $dto = $this->makeDto([
            'labels' => ['ALMOÇO'],
        ]);

        $this->store->saveTransaction('123456', $dto);

        $folded = TextNormalizer::fold(LabelFormatter::format('ALMOÇO'));
        $label = Label::where('folded_name', $folded)->first();
        $this->assertNotNull($label);
        $this->assertSame('Almoço', $label->name);
    }

    public function test_save_transaction_does_not_duplicate_labels_on_folded_match(): void
    {
        // Primeira transação cria o label "Almoço".
        $dto1 = $this->makeDto(['labels' => ['Almoço']]);
        $this->store->saveTransaction('123456', $dto1);

        // Segunda transação com variante "ALMOÇO" deve reusar o mesmo label.
        $dto2 = $this->makeDto(['labels' => ['ALMOÇO']]);
        $id2 = $this->store->saveTransaction('123456', $dto2);

        // Só deve existir 1 label no banco.
        $folded = TextNormalizer::fold(LabelFormatter::format('ALMOÇO'));
        $this->assertSame(1, Label::where('folded_name', $folded)->count());

        // Ambas as transações associadas ao mesmo label.
        $tx2 = Transaction::with('labels')->find($id2);
        $this->assertCount(1, $tx2->labels);
        $this->assertSame($folded, $tx2->labels[0]->folded_name);
    }

    public function test_save_transaction_creates_category_when_it_does_not_exist(): void
    {
        $initialCount = Category::count();

        $dto = $this->makeDto(['category' => 'NovaCategoria']);
        $id = $this->store->saveTransaction('123456', $dto);

        // Categoria foi criada.
        $this->assertSame($initialCount + 1, Category::count());

        $tx = Transaction::with('category')->find($id);
        $this->assertNotNull($tx);
        $this->assertNotNull($tx->category_id);
        $this->assertNotNull($tx->category);
        $this->assertSame('NovaCategoria', $tx->category->display_name);
        $this->assertSame('novacategoria', $tx->category->slug);
        $this->assertSame('expense', $tx->category->default_type);
    }

    public function test_save_transaction_with_null_category_saves_null_category_id(): void
    {
        $dto = $this->makeDto(['category' => null]);
        $id = $this->store->saveTransaction('123456', $dto);

        $tx = Transaction::with('category')->find($id);
        $this->assertNotNull($tx);
        $this->assertNull($tx->category_id);
        $this->assertNull($tx->category);
    }

    public function test_save_transaction_with_empty_category_saves_null_category_id(): void
    {
        $dto = $this->makeDto(['category' => '']);
        $id = $this->store->saveTransaction('123456', $dto);

        $tx = Transaction::with('category')->find($id);
        $this->assertNotNull($tx);
        $this->assertNull($tx->category_id);
        $this->assertNull($tx->category);
    }

    public function test_get_transaction_returns_model_with_relations(): void
    {
        $dto = $this->makeDto([
            'items' => [
                ['name' => 'Pão', 'qty' => 1.0, 'unitPrice' => 2.0, 'subtotal' => 2.0],
            ],
            'labels' => ['padaria'],
        ]);
        $id = $this->store->saveTransaction('123456', $dto);

        $tx = $this->store->getTransaction($id);

        $this->assertNotNull($tx);
        $this->assertInstanceOf(Transaction::class, $tx);
        $this->assertCount(1, $tx->items);
        $this->assertCount(1, $tx->labels);
        $this->assertSame('Pão', $tx->items[0]->name);
    }

    public function test_get_transaction_returns_null_when_not_found(): void
    {
        $tx = $this->store->getTransaction(99999);

        $this->assertNull($tx);
    }

    public function test_list_recent_orders_by_date_desc(): void
    {
        Transaction::factory()->create([
            'chat_id' => '123456',
            'date' => '2026-06-10',
            'sync_status' => WalletStore::SYNC_PENDING,
        ]);
        Transaction::factory()->create([
            'chat_id' => '123456',
            'date' => '2026-06-15',
            'sync_status' => WalletStore::SYNC_PENDING,
        ]);
        Transaction::factory()->create([
            'chat_id' => '123456',
            'date' => '2026-06-05',
            'sync_status' => WalletStore::SYNC_PENDING,
        ]);

        $result = $this->store->listRecent('123456', limit: 10);

        $this->assertCount(3, $result);
        $this->assertSame('2026-06-15', $result[0]->date->toDateString());
        $this->assertSame('2026-06-10', $result[1]->date->toDateString());
        $this->assertSame('2026-06-05', $result[2]->date->toDateString());
    }

    public function test_list_recent_filters_by_type(): void
    {
        Transaction::factory()->create([
            'chat_id' => '123456',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        Transaction::factory()->create([
            'chat_id' => '123456',
            'type' => 'income',
            'date' => '2026-06-14',
        ]);
        Transaction::factory()->create([
            'chat_id' => '123456',
            'type' => 'expense',
            'date' => '2026-06-13',
        ]);

        $result = $this->store->listRecent('123456', limit: 10, type: 'expense');

        $this->assertCount(2, $result);
        $this->assertSame('expense', $result[0]->type);
        $this->assertSame('expense', $result[1]->type);
    }

    public function test_list_recent_respects_limit(): void
    {
        Transaction::factory()->count(5)->create(['chat_id' => '123456']);

        $result = $this->store->listRecent('123456', limit: 3);

        $this->assertCount(3, $result);
    }

    public function test_list_recent_eager_loads_items_and_labels(): void
    {
        $tx = Transaction::factory()->withItems(2)->create(['chat_id' => '123456']);
        $label = Label::factory()->create();
        $tx->labels()->attach($label);

        $result = $this->store->listRecent('123456', limit: 10);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->relationLoaded('items'));
        $this->assertTrue($result[0]->relationLoaded('labels'));
    }

    public function test_update_sync_status_to_synced(): void
    {
        $tx = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 2,
            'processing' => true,
            'processing_since' => now(),
        ]);

        $this->store->updateSyncStatus($tx->id, WalletStore::SYNC_SYNCED);

        $tx->refresh();
        $this->assertSame(WalletStore::SYNC_SYNCED, $tx->sync_status);
        $this->assertFalse((bool) $tx->processing);
        $this->assertNull($tx->processing_since);
    }

    public function test_update_sync_status_updates_updated_at_timestamp(): void
    {
        $id = $this->store->saveTransaction('chat-ts', $this->makeDto([
            'description' => 'Transação timestamp',
        ]));

        $tx = Transaction::find($id);
        $this->assertNotNull($tx, 'Transação deve ser persistida');

        $before = $tx->updated_at;

        // Garante ao menos 1 segundo de diferença.
        usleep(1_100_000);

        $this->store->updateSyncStatus($id, WalletStore::SYNC_SYNCED);

        $after = $tx->fresh()->updated_at;

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertTrue($after->greaterThan($before), 'updated_at deve ser atualizado após updateSyncStatus');
    }

    public function test_update_sync_status_to_failed_increments_attempts(): void
    {
        $tx = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 1,
            'processing' => true,
        ]);

        $this->store->updateSyncStatus($tx->id, WalletStore::SYNC_FAILED, 'Sheets error');

        $tx->refresh();
        $this->assertSame(WalletStore::SYNC_FAILED, $tx->sync_status);
        $this->assertSame(2, $tx->sync_attempts);
        $this->assertSame('Sheets error', $tx->sync_error_message);
        $this->assertFalse((bool) $tx->processing);
    }

    public function test_reset_pending_sync_attempts_resets_for_chat(): void
    {
        Transaction::factory()->create([
            'chat_id' => 'chat-A',
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 5,
            'sync_error_message' => 'err',
        ]);
        Transaction::factory()->create([
            'chat_id' => 'chat-A',
            'sync_status' => WalletStore::SYNC_SYNCED,
            'sync_attempts' => 1,
        ]);
        Transaction::factory()->create([
            'chat_id' => 'chat-B',
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 5,
            'sync_error_message' => 'err',
        ]);

        $affected = $this->store->resetPendingSyncAttempts('chat-A');

        $this->assertSame(1, $affected);

        $txA = Transaction::where('chat_id', 'chat-A')
            ->where('sync_status', WalletStore::SYNC_PENDING)
            ->first();
        $this->assertSame(0, $txA->sync_attempts);
        $this->assertNull($txA->sync_error_message);

        $txB = Transaction::where('chat_id', 'chat-B')->first();
        $this->assertSame(5, $txB->sync_attempts);
    }

    public function test_reset_pending_sync_attempts_global(): void
    {
        Transaction::factory()->create([
            'chat_id' => 'chat-A',
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 3,
        ]);
        Transaction::factory()->create([
            'chat_id' => 'chat-B',
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 4,
        ]);
        Transaction::factory()->create([
            'chat_id' => 'chat-C',
            'sync_status' => WalletStore::SYNC_SYNCED,
            'sync_attempts' => 1,
        ]);

        $affected = $this->store->resetPendingSyncAttempts();

        $this->assertSame(2, $affected);

        $this->assertSame(0, Transaction::where('chat_id', 'chat-A')->first()->sync_attempts);
        $this->assertSame(0, Transaction::where('chat_id', 'chat-B')->first()->sync_attempts);
        $this->assertSame(1, Transaction::where('chat_id', 'chat-C')->first()->sync_attempts);
    }

    /**
     * Regressão: transações com sync_attempts=0 e sync_error_message=null
     * DEVEM ser contadas (não ignoradas). MySQL retorna "0 rows affected"
     * quando o UPDATE não altera valores — o método usa count() antes do
     * update() para evitar esse falso-zero.
     */
    public function test_reset_pending_sync_attempts_counts_even_when_already_zero(): void
    {
        Transaction::factory()->create([
            'chat_id' => 'chat-A',
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 0,
            'sync_error_message' => null,
        ]);
        Transaction::factory()->create([
            'chat_id' => 'chat-A',
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 0,
            'sync_error_message' => null,
        ]);
        // Não-pendente (deve ser ignorada).
        Transaction::factory()->create([
            'chat_id' => 'chat-A',
            'sync_status' => WalletStore::SYNC_SYNCED,
            'sync_attempts' => 0,
        ]);

        $affected = $this->store->resetPendingSyncAttempts('chat-A');

        // Mesmo com attempts já zerados, o count deve retornar 2 (não 0).
        $this->assertSame(2, $affected, 'Deve contar transações mesmo com sync_attempts=0');
    }

    public function test_list_pending_sync_filters_attempts_below_3(): void
    {
        Transaction::factory()->create(['sync_status' => WalletStore::SYNC_PENDING, 'sync_attempts' => 0]);
        Transaction::factory()->create(['sync_status' => WalletStore::SYNC_PENDING, 'sync_attempts' => 2]);
        Transaction::factory()->create(['sync_status' => WalletStore::SYNC_PENDING, 'sync_attempts' => 3]);
        Transaction::factory()->create(['sync_status' => WalletStore::SYNC_SYNCED, 'sync_attempts' => 0]);

        $result = $this->store->listPendingSync();

        $this->assertCount(2, $result);
        $this->assertLessThan(3, $result[0]->sync_attempts);
        $this->assertLessThan(3, $result[1]->sync_attempts);
    }

    public function test_list_pending_sync_orders_by_created_at_asc(): void
    {
        $tx1 = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 0,
        ]);
        $tx2 = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 0,
        ]);
        $tx3 = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => 0,
        ]);

        $result = $this->store->listPendingSync();

        $this->assertCount(3, $result);
        $this->assertSame($tx1->id, $result[0]->id);
        $this->assertSame($tx2->id, $result[1]->id);
        $this->assertSame($tx3->id, $result[2]->id);
    }

    public function test_mark_sync_started_acquires_lock(): void
    {
        $tx = Transaction::factory()->create([
            'processing' => false,
            'processing_since' => null,
        ]);

        $acquired = $this->store->markSyncStarted($tx->id);

        $this->assertTrue($acquired);
        $tx->refresh();
        $this->assertTrue((bool) $tx->processing);
        $this->assertNotNull($tx->processing_since);
    }

    public function test_mark_sync_started_returns_false_when_already_processing(): void
    {
        $tx = Transaction::factory()->create([
            'processing' => true,
            'processing_since' => now(),
        ]);

        $acquired = $this->store->markSyncStarted($tx->id);

        $this->assertFalse($acquired);
    }

    public function test_mark_sync_started_reclaims_stale_lock(): void
    {
        $tx = Transaction::factory()->create([
            'processing' => true,
            'processing_since' => now()->subMinutes(15),
        ]);

        $acquired = $this->store->markSyncStarted($tx->id, staleLockSeconds: 600);

        $this->assertTrue($acquired);
        $tx->refresh();
        $this->assertTrue((bool) $tx->processing);
        $this->assertNotNull($tx->processing_since);
    }

    public function test_mark_sync_success_sets_spreadsheet_row_id(): void
    {
        $tx = Transaction::factory()->create([
            'processing' => true,
            'processing_since' => now(),
        ]);

        $this->store->markSyncSuccess($tx->id, 'row-42');

        $tx->refresh();
        $this->assertSame(WalletStore::SYNC_SYNCED, $tx->sync_status);
        $this->assertSame('row-42', $tx->spreadsheet_row_id);
        $this->assertFalse((bool) $tx->processing);
        $this->assertNull($tx->processing_since);
    }

    public function test_mark_sync_failed_stamps_notified_at_on_first_failure(): void
    {
        $tx = Transaction::factory()->create([
            'notified_at' => null,
            'sync_status' => WalletStore::SYNC_PENDING,
        ]);

        $this->store->markSyncFailed($tx->id, 'Fatal error');

        $tx->refresh();
        $this->assertSame(WalletStore::SYNC_FAILED, $tx->sync_status);
        $this->assertSame('Fatal error', $tx->sync_error_message);
        $this->assertNotNull($tx->notified_at, 'notified_at deve ser carimbado na 1ª falha');
        $this->assertFalse((bool) $tx->processing);
    }

    public function test_mark_sync_failed_does_not_overwrite_notified_at(): void
    {
        $originalStamp = '2026-06-15 10:00:00';
        $tx = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_FAILED,
            'notified_at' => $originalStamp,
            'sync_error_message' => 'first error',
        ]);

        $this->store->markSyncFailed($tx->id, 'second error');

        $tx->refresh();
        $this->assertSame($originalStamp, $tx->notified_at->toDateTimeString());
        $this->assertSame('second error', $tx->sync_error_message);
    }

    public function test_requeue_pending_sync_resets_status(): void
    {
        $tx = Transaction::factory()->create([
            'sync_status' => WalletStore::SYNC_FAILED,
            'processing' => true,
            'processing_since' => now(),
        ]);

        $this->store->requeuePendingSync($tx->id);

        $tx->refresh();
        $this->assertSame(WalletStore::SYNC_PENDING, $tx->sync_status);
        $this->assertFalse((bool) $tx->processing);
        $this->assertNull($tx->processing_since);
    }

    /*
    |--------------------------------------------------------------------------
    | CATEGORIAS (banco de dados)
    |--------------------------------------------------------------------------
    */

    public function test_get_categories_returns_ordered_by_display_name(): void
    {
        Category::factory()->create(['display_name' => 'Zoológico']);
        Category::factory()->create(['display_name' => 'Alimentação']);
        Category::factory()->create(['display_name' => 'Moradia']);

        $categories = $this->store->getCategories();

        $this->assertCount(3, $categories);
        $this->assertSame('Alimentação', $categories[0]->display_name);
        $this->assertSame('Moradia', $categories[1]->display_name);
        $this->assertSame('Zoológico', $categories[2]->display_name);
    }

    public function test_get_category_finds_by_slug_case_insensitive(): void
    {
        Category::factory()->create([
            'slug' => 'alimentacao',
            'display_name' => 'Alimentação',
        ]);

        $category = $this->store->getCategory('Alimentacao');

        $this->assertNotNull($category);
        $this->assertSame('alimentacao', $category->slug);
        $this->assertSame('Alimentação', $category->display_name);
    }

    public function test_category_exists_returns_bool(): void
    {
        Category::factory()->create([
            'slug' => 'transporte',
            'display_name' => 'Transporte',
        ]);

        $this->assertTrue($this->store->categoryExists('Transporte'));
        $this->assertTrue($this->store->categoryExists('TRANSPORTE'));
        $this->assertFalse($this->store->categoryExists('Inexistente'));
    }

    public function test_create_category_is_idempotent(): void
    {
        $this->store->createCategory('Teste', 'expense');
        $this->assertSame(1, Category::where('slug', 'teste')->count());

        $this->store->createCategory('Teste', 'income');
        $this->assertSame(1, Category::where('slug', 'teste')->count());

        $category = Category::where('slug', 'teste')->first();
        $this->assertSame('expense', $category->default_type);
    }

    /*
    |--------------------------------------------------------------------------
    | LABELS (banco de dados)
    |--------------------------------------------------------------------------
    */

    public function test_increment_label_use_creates_new_label(): void
    {
        $this->store->incrementLabelUse('NOVALABEL');

        $folded = TextNormalizer::fold(LabelFormatter::format('NOVALABEL'));
        $label = Label::where('folded_name', $folded)->first();
        $this->assertNotNull($label);
        $this->assertSame('Novalabel', $label->name);
        $this->assertSame(1, $label->use_count);
    }

    public function test_increment_label_use_increments_existing(): void
    {
        $folded = TextNormalizer::fold(LabelFormatter::format('Recorrente'));
        Label::factory()->create([
            'folded_name' => $folded,
            'name' => LabelFormatter::format('Recorrente'),
            'use_count' => 5,
        ]);

        $this->store->incrementLabelUse('Recorrente');

        $label = Label::where('folded_name', $folded)->first();
        $this->assertSame(6, $label->use_count);
    }

    public function test_increment_label_use_updates_last_used_at(): void
    {
        $folded = TextNormalizer::fold(LabelFormatter::format('Antiga'));
        $oldDate = '2026-01-01 00:00:00';
        Label::factory()->create([
            'folded_name' => $folded,
            'name' => LabelFormatter::format('Antiga'),
            'use_count' => 1,
            'last_used_at' => $oldDate,
        ]);

        $this->store->incrementLabelUse('Antiga');

        $label = Label::where('folded_name', $folded)->first();
        $this->assertNotSame($oldDate, $label->last_used_at->toDateTimeString());
    }

    public function test_increment_label_use_normalizes_name(): void
    {
        $this->store->incrementLabelUse('  ALMOÇO  ');

        $folded = TextNormalizer::fold(LabelFormatter::format('ALMOÇO'));
        $label = Label::where('folded_name', $folded)->first();
        $this->assertNotNull($label);
        $this->assertSame('Almoço', $label->name);
        $this->assertSame(1, $label->use_count);
    }

    public function test_get_top_labels_orders_by_use_count_desc(): void
    {
        Label::factory()->create(['name' => 'Raro', 'use_count' => 1]);
        Label::factory()->create(['name' => 'Médio', 'use_count' => 5]);
        Label::factory()->create(['name' => 'Popular', 'use_count' => 10]);

        $top = $this->store->getTopLabels(limit: 10);

        $this->assertCount(3, $top);
        $this->assertSame(10, $top[0]->use_count);
        $this->assertSame(5, $top[1]->use_count);
        $this->assertSame(1, $top[2]->use_count);
    }

    public function test_upsert_label_creates_or_updates(): void
    {
        $this->store->upsertLabel('teste-folded', ['name' => 'Teste', 'use_count' => 3]);
        $label = Label::where('folded_name', 'teste-folded')->first();
        $this->assertNotNull($label);
        $this->assertSame('Teste', $label->name);
        $this->assertSame(3, $label->use_count);

        $this->store->upsertLabel('teste-folded', ['name' => 'Teste Atualizado', 'use_count' => 7]);
        $label->refresh();
        $this->assertSame('Teste Atualizado', $label->name);
        $this->assertSame(7, $label->use_count);
    }

    public function test_delete_label_by_folded_name(): void
    {
        Label::factory()->create(['folded_name' => 'para-deletar', 'name' => 'Para Deletar']);
        Label::factory()->create(['folded_name' => 'manter', 'name' => 'Manter']);

        $this->store->deleteLabelByFoldedName('para-deletar');

        $this->assertNull(Label::where('folded_name', 'para-deletar')->first());
        $this->assertNotNull(Label::where('folded_name', 'manter')->first());
    }

    /*
    |--------------------------------------------------------------------------
    | SESSÕES (Redis In-Memory Fake)
    |--------------------------------------------------------------------------
    */

    public function test_get_session_returns_null_when_not_exists(): void
    {
        $session = $this->store->getSession('chat-nao-existe');

        $this->assertNull($session);
    }

    public function test_set_session_and_get_round_trip(): void
    {
        $chatId = 'chat-roundtrip';
        $data = new SessionData(
            state: 'idle',
            source: 'text',
        );

        $this->store->setSession($chatId, $data);

        $session = $this->store->getSession($chatId);
        $this->assertNotNull($session);
        $this->assertSame('idle', $session['state']);
        $this->assertSame('text', $session['source']);
        $this->assertNotNull($session['updated_at']);
    }

    public function test_set_session_merges_fields(): void
    {
        $chatId = 'chat-merge';

        // Estado 1: state=idle, source=text.
        $this->store->setSession($chatId, new SessionData(state: 'idle', source: 'text'));

        // Estado 2: adiciona draft sem mexer nos outros campos.
        $draft = ['description' => 'Cinema', 'amount' => 35.5, 'type' => 'expense'];
        $this->store->setSession($chatId, new SessionData(draft: $draft, retryCount: 0));

        $session = $this->store->getSession($chatId);
        $this->assertNotNull($session);
        $this->assertSame('idle', $session['state']);
        $this->assertSame('text', $session['source']);
        $this->assertSame($draft, $session['draft']);
        $this->assertSame(0, $session['retry_count']);
    }

    public function test_set_session_clears_specified_fields(): void
    {
        $chatId = 'chat-clear';

        $this->store->setSession($chatId, new SessionData(
            state: 'idle',
            source: 'text',
            messageIdConfirm: 5001,
        ));

        $this->store->setSession(
            $chatId,
            new SessionData(state: 'awaiting_confirmation'),
            clearFields: ['message_id_confirm'],
        );

        $session = $this->store->getSession($chatId);
        $this->assertNotNull($session);
        $this->assertSame('awaiting_confirmation', $session['state']);
        $this->assertNull($session['message_id_confirm']);
        $this->assertSame('text', $session['source']);
    }

    public function test_increment_session_retry_returns_new_count(): void
    {
        $chatId = 'chat-retry';

        $count = $this->store->incrementSessionRetry($chatId);
        $this->assertSame(1, $count);

        $count = $this->store->incrementSessionRetry($chatId);
        $this->assertSame(2, $count);

        $session = $this->store->getSession($chatId);
        $this->assertNotNull($session);
        $this->assertSame(2, $session['retry_count']);
    }

    public function test_clear_session_is_idempotent(): void
    {
        $chatId = 'chat-limpar';

        $this->store->setSession($chatId, new SessionData(state: 'idle'));

        $this->store->clearSession($chatId);
        $this->assertNull($this->store->getSession($chatId));

        $this->store->clearSession($chatId);
        $this->assertNull($this->store->getSession($chatId));
    }

    public function test_try_acquire_session_processing_flag_succeeds_first_time(): void
    {
        $chatId = 'chat-acquire';

        $acquired = $this->store->tryAcquireSessionProcessingFlag($chatId);
        $this->assertTrue($acquired);

        $session = $this->store->getSession($chatId);
        $this->assertTrue($session['processing']);
    }

    public function test_try_acquire_session_processing_flag_fails_second_time(): void
    {
        $chatId = 'chat-acquire-2';

        $this->assertTrue($this->store->tryAcquireSessionProcessingFlag($chatId));
        $this->assertFalse($this->store->tryAcquireSessionProcessingFlag($chatId));
    }

    public function test_session_has_ttl(): void
    {
        $chatId = 'chat-ttl';

        $this->store->setSession($chatId, new SessionData(state: 'idle'));

        $key = "session:{$chatId}";
        $this->assertArrayHasKey($key, RedisFake::$expiry, 'expire() deve ser chamado');
        $this->assertSame(900, RedisFake::$expiry[$key], 'TTL deve ser 900 segundos (15 min)');
    }
}
