<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Dto\TransactionData;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes da camada de persistência Firestore (M5.1/M5.4/M5.5/M5.6/M5.7).
 *
 * Estes testes **não tocam o Firestore real** — constroem o
 * {@see FirestoreService} diretamente com um {@see InMemoryFirestoreGateway}
 * novo em cada teste, o que torna tudo síncrono, rápido e determinístico.
 *
 * Cobertura:
 *   - Transações: saveTransaction, getTransaction, listRecent (com filtro
 *     por type e ordenação DESC), updateSyncStatus (synced/failed/pending).
 *   - Sessões: getSession/setSession/clearSession + edge cases.
 *   - Categorias: getCategories/createCategory/categoryExists/getCategory.
 *   - Labels: incrementLabelUse (idempotente + transacional) e getTopLabels.
 *   - Edge cases: ids inexistentes devolvem null sem lançar.
 *
 * Roda isolado: vendor/bin/phpunit --filter FirestoreServiceTest
 */
#[CoversClass(FirestoreService::class)]
class FirestoreServiceTest extends TestCase
{
    private FirestoreService $service;

    private InMemoryFirestoreGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemoryFirestoreGateway;
        $this->service = new FirestoreService($this->gateway);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de montagem
    |--------------------------------------------------------------------------
    */

    /**
     * Monta um DTO completo para uso nos testes de saveTransaction.
     */
    private function dto(array $override = []): TransactionData
    {
        return TransactionData::fromArray(array_merge([
            'description' => 'Almoço no restaurante',
            'amount' => 47.50,
            'type' => 'expense',
            'category' => 'Alimentação',
            'labels' => ['almoço', 'restaurante'],
            'date' => '2026-06-15',
            'observations' => null,
        ], $override));
    }

    /*
    |--------------------------------------------------------------------------
    | saveTransaction / getTransaction
    |--------------------------------------------------------------------------
    */

    public function test_save_transaction_creates_document_with_pending_sync_and_returns_id(): void
    {
        $id = $this->service->saveTransaction('12345', $this->dto(), 'text');

        $this->assertNotEmpty($id);

        $stored = $this->service->getTransaction($id);

        $this->assertNotNull($stored);
        $this->assertSame('12345', $stored['chat_id']);
        $this->assertSame('2026-06-15', $stored['date']);
        $this->assertSame('Almoço no restaurante', $stored['description']);
        $this->assertSame(47.50, $stored['amount']);
        $this->assertSame('expense', $stored['type']);
        $this->assertSame('Alimentação', $stored['category']);
        $this->assertSame(['almoço', 'restaurante'], $stored['labels']);
        $this->assertSame('text', $stored['source']);
        $this->assertNull($stored['observations']);

        // Campos de sync inicializados.
        $this->assertSame(FirestoreService::SYNC_PENDING, $stored['sync_status']);
        $this->assertSame(0, $stored['sync_attempts']);
        $this->assertNull($stored['sync_last_attempt_at']);
        $this->assertNull($stored['sync_error_message']);

        // Timestamps preenchidos com strings ISO (com microssegundos — FIX-6).
        $this->assertNotEmpty($stored['created_at']);
        $this->assertNotEmpty($stored['updated_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $stored['created_at'],
        );
    }

    public function test_get_transaction_returns_null_when_id_does_not_exist(): void
    {
        $this->assertNull($this->service->getTransaction('non-existent-id'));
    }

    public function test_save_transaction_with_image_source_round_trips(): void
    {
        $id = $this->service->saveTransaction(
            '99999',
            $this->dto(['observations' => 'Foto ruim']),
            'image',
        );

        $stored = $this->service->getTransaction($id);

        $this->assertNotNull($stored);
        $this->assertSame('image', $stored['source']);
        $this->assertSame('Foto ruim', $stored['observations']);
    }

    /*
    |--------------------------------------------------------------------------
    | saveTransaction — guarda de DTO incompleto (FIX-3)
    |--------------------------------------------------------------------------
    */

    public function test_save_transaction_throws_when_amount_is_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TransactionData incompleto');

        // DTO com amount null (mas type válido): fluxo conversacional
        // do M3/M4 pede o valor antes de persistir — chamar saveTransaction
        // aqui seria corrupção silenciosa (schema exige amount NOT NULL).
        $this->service->saveTransaction(
            'C1',
            $this->dto(['amount' => null]),
            'text',
        );
    }

    public function test_save_transaction_throws_when_type_is_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TransactionData incompleto');

        $this->service->saveTransaction(
            'C1',
            $this->dto(['type' => null]),
            'text',
        );
    }

    public function test_save_transaction_accepts_complete_dto_with_no_exception(): void
    {
        // DTO completo (amount + type + description + date não-null):
        // deve persistir sem lançar e devolver um id válido.
        $id = $this->service->saveTransaction('C1', $this->dto(), 'text');

        $this->assertNotEmpty($id);

        $stored = $this->service->getTransaction($id);
        $this->assertNotNull($stored);
        $this->assertSame(47.50, $stored['amount']);
        $this->assertSame('expense', $stored['type']);
    }

    public function test_transaction_data_is_complete_returns_true_only_when_required_fields_present(): void
    {
        // Completo.
        $this->assertTrue($this->dto()->isComplete());

        // Faltando amount.
        $this->assertFalse($this->dto(['amount' => null])->isComplete());

        // Faltando type.
        $this->assertFalse($this->dto(['type' => null])->isComplete());

        // Faltando description.
        $this->assertFalse($this->dto(['description' => null])->isComplete());

        // Faltando date.
        $this->assertFalse($this->dto(['date' => null])->isComplete());

        // Campos opcionais (category, labels) ausentes não afetam isComplete.
        $optionalMissing = TransactionData::fromArray([
            'description' => 'X',
            'amount' => 1.0,
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->assertTrue($optionalMissing->isComplete());
    }

    /*
    |--------------------------------------------------------------------------
    | listRecent
    |--------------------------------------------------------------------------
    */

    public function test_list_recent_orders_by_date_desc_and_applies_limit(): void
    {
        $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-10']), 'text');
        $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-15']), 'text');
        $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-12']), 'text');

        $list = $this->service->listRecent('C1');

        $this->assertCount(3, $list);
        $this->assertSame('2026-06-15', $list[0]['data']['date']);
        $this->assertSame('2026-06-12', $list[1]['data']['date']);
        $this->assertSame('2026-06-10', $list[2]['data']['date']);
    }

    public function test_list_recent_filters_out_other_chats(): void
    {
        $this->service->saveTransaction('C1', $this->dto(), 'text');
        $this->service->saveTransaction('C2', $this->dto(), 'text');

        $list = $this->service->listRecent('C1');

        $this->assertCount(1, $list);
        $this->assertSame('C1', $list[0]['data']['chat_id']);
    }

    public function test_list_recent_filters_by_type_when_provided(): void
    {
        $this->service->saveTransaction('C1', $this->dto(['type' => 'expense', 'amount' => 10]), 'text');
        $this->service->saveTransaction('C1', $this->dto(['type' => 'income', 'amount' => 100, 'description' => 'Salário']), 'text');
        $this->service->saveTransaction('C1', $this->dto(['type' => 'expense', 'amount' => 20]), 'text');

        $list = $this->service->listRecent('C1', type: 'income');

        $this->assertCount(1, $list);
        $this->assertSame('income', $list[0]['data']['type']);
        $this->assertSame('Salário', $list[0]['data']['description']);
    }

    public function test_list_recent_applies_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]), 'text');
        }

        $list = $this->service->listRecent('C1', limit: 2);

        $this->assertCount(2, $list);
        // 2 mais recentes: dia 05 e dia 04.
        $this->assertSame('2026-06-05', $list[0]['data']['date']);
        $this->assertSame('2026-06-04', $list[1]['data']['date']);
    }

    /*
    |--------------------------------------------------------------------------
    | updateSyncStatus
    |--------------------------------------------------------------------------
    */

    public function test_update_sync_status_to_synced(): void
    {
        $id = $this->service->saveTransaction('C1', $this->dto(), 'text');

        $this->service->updateSyncStatus($id, FirestoreService::SYNC_SYNCED);

        $stored = $this->service->getTransaction($id);
        $this->assertSame(FirestoreService::SYNC_SYNCED, $stored['sync_status']);
        $this->assertSame(0, $stored['sync_attempts']); // não incrementa sem erro
        $this->assertNull($stored['sync_error_message']);
    }

    public function test_update_sync_status_to_failed_increments_attempts_and_sets_error(): void
    {
        $id = $this->service->saveTransaction('C1', $this->dto(), 'text');

        // Primeira falha.
        $this->service->updateSyncStatus($id, FirestoreService::SYNC_FAILED, 'timeout');

        $stored = $this->service->getTransaction($id);
        $this->assertSame(FirestoreService::SYNC_FAILED, $stored['sync_status']);
        $this->assertSame('timeout', $stored['sync_error_message']);
        $this->assertSame(1, $stored['sync_attempts']);
        $this->assertNotNull($stored['sync_last_attempt_at']);

        // Segunda falha — incrementa de novo.
        $this->service->updateSyncStatus($id, FirestoreService::SYNC_FAILED, 'sheet offline');

        $stored = $this->service->getTransaction($id);
        $this->assertSame(2, $stored['sync_attempts']);
        $this->assertSame('sheet offline', $stored['sync_error_message']);
    }

    public function test_update_sync_status_to_pending_after_failure(): void
    {
        $id = $this->service->saveTransaction('C1', $this->dto(), 'text');

        // Falhou uma vez.
        $this->service->updateSyncStatus($id, FirestoreService::SYNC_FAILED, 'timeout');
        $this->assertSame(1, $this->service->getTransaction($id)['sync_attempts']);

        // Recolocada na fila como pending — sem incrementar (sem erro).
        $this->service->updateSyncStatus($id, FirestoreService::SYNC_PENDING);

        $stored = $this->service->getTransaction($id);
        $this->assertSame(FirestoreService::SYNC_PENDING, $stored['sync_status']);
        $this->assertSame(1, $stored['sync_attempts']); // mantém o contador
    }

    /*
    |--------------------------------------------------------------------------
    | Sessões
    |--------------------------------------------------------------------------
    */

    public function test_get_session_returns_null_when_absent(): void
    {
        $this->assertNull($this->service->getSession('12345'));
    }

    public function test_set_session_creates_and_merge_updates(): void
    {
        $this->service->setSession(
            chatId: '12345',
            state: 'awaiting_data',
            draft: ['amount' => 50.0],
            awaitingField: 'amount',
            messageIdConfirm: 1001,
        );

        $session = $this->service->getSession('12345');
        $this->assertNotNull($session);
        $this->assertSame('awaiting_data', $session['state']);
        $this->assertSame(['amount' => 50.0], $session['draft']);
        $this->assertSame('amount', $session['awaiting_field']);
        $this->assertSame(1001, $session['message_id_confirm']);
        $this->assertArrayNotHasKey('retry_count', $session); // FIX-4: setSession não reseta
        $this->assertNotEmpty($session['updated_at']);
    }

    public function test_set_session_does_not_reset_retry_count_when_already_set(): void
    {
        // Pré-configura uma sessão com retry_count=3 (ex.: após 3 pedidos).
        $this->gateway->mergeDocument(FirestoreService::COLLECTION_SESSIONS, 'C1', [
            'state' => 'awaiting_data',
            'retry_count' => 3,
            'updated_at' => '2026-06-15T12:00:00Z',
        ]);

        // Mudança de estado NÃO deve zerar o contador (FIX-4).
        $this->service->setSession('C1', state: 'awaiting_confirmation');

        $session = $this->service->getSession('C1');
        $this->assertSame('awaiting_confirmation', $session['state']);
        $this->assertSame(3, $session['retry_count']); // preservado
    }

    public function test_increment_session_retry_starts_from_one_when_no_session(): void
    {
        // Sem sessão prévia, parte de 0 e incrementa para 1.
        $newCount = $this->service->incrementSessionRetry('C1');

        $this->assertSame(1, $newCount);

        $session = $this->service->getSession('C1');
        $this->assertSame(1, $session['retry_count']);
    }

    public function test_increment_session_retry_accumulates_from_existing_value(): void
    {
        // Sessão já existe com retry_count=2.
        $this->gateway->mergeDocument(FirestoreService::COLLECTION_SESSIONS, 'C1', [
            'state' => 'awaiting_data',
            'retry_count' => 2,
            'updated_at' => '2026-06-15T12:00:00Z',
        ]);

        $newCount = $this->service->incrementSessionRetry('C1');

        $this->assertSame(3, $newCount);

        $session = $this->service->getSession('C1');
        $this->assertSame(3, $session['retry_count']);
    }

    public function test_increment_session_retry_accumulates_multiple_times(): void
    {
        // Três incrementos seguidos devem produzir 1, 2, 3.
        $this->assertSame(1, $this->service->incrementSessionRetry('C1'));
        $this->assertSame(2, $this->service->incrementSessionRetry('C1'));
        $this->assertSame(3, $this->service->incrementSessionRetry('C1'));

        $session = $this->service->getSession('C1');
        $this->assertSame(3, $session['retry_count']);
    }

    public function test_set_session_merges_without_overwriting_other_fields(): void
    {
        // Sessão inicial com state e draft.
        $this->service->setSession('C1', state: 'awaiting_data', draft: ['amount' => 10]);
        // Segunda chamada muda só o state — draft deve persistir.
        $this->service->setSession('C1', state: 'awaiting_confirmation');

        $session = $this->service->getSession('C1');
        $this->assertSame('awaiting_confirmation', $session['state']);
        $this->assertSame(['amount' => 10], $session['draft']);
    }

    public function test_set_session_clear_fields_removes_stale_field(): void
    {
        // W-3 da revisão: setSession com clearFields deve APAGAR o campo,
        // não apenas omiti-lo do merge. O `awaiting_field` de um estado
        // anterior não pode persistir ao mudar de estado.
        $this->service->setSession(
            'C1',
            state: 'awaiting_data',
            draft: ['amount' => 10],
            awaitingField: 'amount',
        );

        $session = $this->service->getSession('C1');
        $this->assertSame('amount', $session['awaiting_field']);

        // Transição para AWAITING_CONFIRMATION com clearFields.
        $this->service->setSession(
            'C1',
            state: 'awaiting_confirmation',
            awaitingField: null,
            clearFields: ['awaiting_field'],
        );

        $session = $this->service->getSession('C1');
        $this->assertArrayNotHasKey('awaiting_field', $session);
        $this->assertSame(['amount' => 10], $session['draft']); // outros campos preservados
    }

    public function test_set_session_without_clear_fields_keeps_stale_field(): void
    {
        // Documenta o BUG que o W-3 corrige: sem clearFields, o merge
        // com null apenas OMITE — não remove — o campo existente.
        $this->service->setSession(
            'C1',
            state: 'awaiting_data',
            awaitingField: 'amount',
        );

        // setSession com awaitingField=null SEM clearFields.
        $this->service->setSession(
            'C1',
            state: 'awaiting_confirmation',
            awaitingField: null,
        );

        $session = $this->service->getSession('C1');
        // O campo AINDA existe (merge não apaga).
        $this->assertSame('amount', $session['awaiting_field']);
    }

    public function test_set_session_clear_fields_can_remove_multiple(): void
    {
        $this->service->setSession(
            'C1',
            state: 'awaiting_data',
            draft: ['x' => 1],
            awaitingField: 'amount',
            messageIdConfirm: 100,
        );

        $this->service->setSession(
            'C1',
            state: 'awaiting_confirmation',
            clearFields: ['awaiting_field', 'message_id_confirm'],
        );

        $session = $this->service->getSession('C1');
        $this->assertArrayNotHasKey('awaiting_field', $session);
        $this->assertArrayNotHasKey('message_id_confirm', $session);
        $this->assertSame(['x' => 1], $session['draft']); // preservado
    }

    public function test_clear_session_removes_session(): void
    {
        $this->service->setSession('C1', state: 'idle');

        $this->assertNotNull($this->service->getSession('C1'));

        $this->service->clearSession('C1');

        $this->assertNull($this->service->getSession('C1'));
    }

    public function test_clear_session_is_idempotent(): void
    {
        // Limpar sessão que nunca existiu não lança.
        $this->service->clearSession('never-existed');

        $this->assertNull($this->service->getSession('never-existed'));
    }

    /*
    |--------------------------------------------------------------------------
    | Categorias
    |--------------------------------------------------------------------------
    */

    public function test_create_category_stores_with_lowercase_id(): void
    {
        $this->service->createCategory('Alimentação', 'expense', isDefault: true);

        // Por baixo: id é lowercase. Acessamos via raw() do in-memory.
        $raw = $this->gateway->raw()['categories'] ?? [];
        $this->assertArrayHasKey('alimentação', $raw);

        $data = $raw['alimentação'];
        $this->assertSame('Alimentação', $data['display_name']);
        $this->assertSame('expense', $data['default_type']);
        $this->assertTrue($data['is_default']);
        $this->assertSame(0, $data['use_count']);
        $this->assertNotEmpty($data['created_at']);
    }

    public function test_category_exists_is_case_insensitive(): void
    {
        $this->service->createCategory('Transporte', 'expense');

        $this->assertTrue($this->service->categoryExists('Transporte'));
        $this->assertTrue($this->service->categoryExists('TRANSPORTE'));
        $this->assertTrue($this->service->categoryExists('transporte'));
        $this->assertFalse($this->service->categoryExists('Lazer'));
    }

    public function test_get_category_returns_data_or_null(): void
    {
        $this->service->createCategory('Saúde', 'expense');

        $data = $this->service->getCategory('saúde');
        $this->assertNotNull($data);
        $this->assertSame('Saúde', $data['display_name']);

        $this->assertNull($this->service->getCategory('inexistente'));
    }

    public function test_get_categories_returns_sorted_by_display_name(): void
    {
        $this->service->createCategory('Lazer', 'expense');
        $this->service->createCategory('Alimentação', 'expense');
        $this->service->createCategory('Educação', 'expense');

        $list = $this->service->getCategories();

        $names = array_column(array_map(fn ($row) => $row['data'], $list), 'display_name');
        $this->assertSame(['Alimentação', 'Educação', 'Lazer'], $names);
    }

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    public function test_increment_label_use_starts_from_one(): void
    {
        $this->service->incrementLabelUse('almoço');

        $raw = $this->gateway->raw()['labels']['almoço'];
        $this->assertSame('almoço', $raw['name']);
        $this->assertSame(1, $raw['use_count']);
        $this->assertNotEmpty($raw['last_used_at']);
    }

    public function test_increment_label_use_accumulates(): void
    {
        $this->service->incrementLabelUse('almoço');
        $this->service->incrementLabelUse('almoço');

        $raw = $this->gateway->raw()['labels']['almoço'];
        $this->assertSame(2, $raw['use_count']);
    }

    public function test_get_top_labels_orders_by_use_count_desc(): void
    {
        // Cria três labels com contagens diferentes.
        for ($i = 0; $i < 3; $i++) {
            $this->service->incrementLabelUse('mercado');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->service->incrementLabelUse('almoço');
        }
        $this->service->incrementLabelUse('transporte');

        $top = $this->service->getTopLabels();

        // Mais usado primeiro.
        $this->assertCount(3, $top);
        $this->assertSame('almoço', $top[0]['id']);
        $this->assertSame(5, $top[0]['data']['use_count']);
        $this->assertSame('mercado', $top[1]['id']);
        $this->assertSame(3, $top[1]['data']['use_count']);
        $this->assertSame('transporte', $top[2]['id']);
        $this->assertSame(1, $top[2]['data']['use_count']);
    }

    public function test_get_top_labels_applies_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->incrementLabelUse("label-{$i}");
        }

        $top = $this->service->getTopLabels(limit: 2);
        $this->assertCount(2, $top);
    }

    public function test_get_top_labels_returns_empty_when_collection_empty(): void
    {
        $this->assertSame([], $this->service->getTopLabels());
    }
}
