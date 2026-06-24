<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Dto\SessionData;
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
        $id = $this->service->saveTransaction('12345', $this->dto());

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
        );

        $stored = $this->service->getTransaction($id);

        $this->assertNotNull($stored);
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
        $id = $this->service->saveTransaction('C1', $this->dto());

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
        $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-10']));
        $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-15']));
        $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-12']));

        $list = $this->service->listRecent('C1');

        $this->assertCount(3, $list);
        $this->assertSame('2026-06-15', $list[0]['data']['date']);
        $this->assertSame('2026-06-12', $list[1]['data']['date']);
        $this->assertSame('2026-06-10', $list[2]['data']['date']);
    }

    public function test_list_recent_filters_out_other_chats(): void
    {
        $this->service->saveTransaction('C1', $this->dto());
        $this->service->saveTransaction('C2', $this->dto());

        $list = $this->service->listRecent('C1');

        $this->assertCount(1, $list);
        $this->assertSame('C1', $list[0]['data']['chat_id']);
    }

    public function test_list_recent_filters_by_type_when_provided(): void
    {
        $this->service->saveTransaction('C1', $this->dto(['type' => 'expense', 'amount' => 10]));
        $this->service->saveTransaction('C1', $this->dto(['type' => 'income', 'amount' => 100, 'description' => 'Salário']));
        $this->service->saveTransaction('C1', $this->dto(['type' => 'expense', 'amount' => 20]));

        $list = $this->service->listRecent('C1', type: 'income');

        $this->assertCount(1, $list);
        $this->assertSame('income', $list[0]['data']['type']);
        $this->assertSame('Salário', $list[0]['data']['description']);
    }

    public function test_list_recent_applies_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->saveTransaction('C1', $this->dto(['date' => '2026-06-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]));
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
        $id = $this->service->saveTransaction('C1', $this->dto());

        $this->service->updateSyncStatus($id, FirestoreService::SYNC_SYNCED);

        $stored = $this->service->getTransaction($id);
        $this->assertSame(FirestoreService::SYNC_SYNCED, $stored['sync_status']);
        $this->assertSame(0, $stored['sync_attempts']); // não incrementa sem erro
        $this->assertNull($stored['sync_error_message']);
    }

    public function test_update_sync_status_to_failed_increments_attempts_and_sets_error(): void
    {
        $id = $this->service->saveTransaction('C1', $this->dto());

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
        $id = $this->service->saveTransaction('C1', $this->dto());

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
            '12345',
            new SessionData(
                state: 'awaiting_data',
                draft: ['amount' => 50.0],
                awaitingField: 'amount',
                messageIdConfirm: 1001,
            ),
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
        $this->service->setSession('C1', new SessionData(state: 'awaiting_confirmation'));

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
        $this->service->setSession('C1', new SessionData(state: 'awaiting_data', draft: ['amount' => 10]));
        // Segunda chamada muda só o state — draft deve persistir.
        $this->service->setSession('C1', new SessionData(state: 'awaiting_confirmation'));

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
            new SessionData(
                state: 'awaiting_data',
                draft: ['amount' => 10],
                awaitingField: 'amount',
            ),
        );

        $session = $this->service->getSession('C1');
        $this->assertSame('amount', $session['awaiting_field']);

        // Transição para AWAITING_CONFIRMATION com clearFields.
        $this->service->setSession(
            'C1',
            new SessionData(
                state: 'awaiting_confirmation',
                awaitingField: null,
            ),
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
            new SessionData(
                state: 'awaiting_data',
                awaitingField: 'amount',
            ),
        );

        // setSession com awaitingField=null SEM clearFields.
        $this->service->setSession(
            'C1',
            new SessionData(
                state: 'awaiting_confirmation',
                awaitingField: null,
            ),
        );

        $session = $this->service->getSession('C1');
        // O campo AINDA existe (merge não apaga).
        $this->assertSame('amount', $session['awaiting_field']);
    }

    public function test_set_session_clear_fields_can_remove_multiple(): void
    {
        $this->service->setSession(
            'C1',
            new SessionData(
                state: 'awaiting_data',
                draft: ['x' => 1],
                awaitingField: 'amount',
                messageIdConfirm: 100,
            ),
        );

        $this->service->setSession(
            'C1',
            new SessionData(state: 'awaiting_confirmation'),
            clearFields: ['awaiting_field', 'message_id_confirm'],
        );

        $session = $this->service->getSession('C1');
        $this->assertArrayNotHasKey('awaiting_field', $session);
        $this->assertArrayNotHasKey('message_id_confirm', $session);
        $this->assertSame(['x' => 1], $session['draft']); // preservado
    }

    /*
    |--------------------------------------------------------------------------
    | Sessão — message_id_edit_picker (CT-047 fix)
    |--------------------------------------------------------------------------
    |
    | Cobertura do novo parâmetro messageIdEditPicker em setSession
    | (P1 — segunda âncora do CT-047 para callbacks vindos do picker Y).
    */

    public function test_set_session_persists_message_id_edit_picker(): void
    {
        // N11: setSession com messageIdEditPicker não-nulo grava o campo.
        $this->service->setSession(
            'C1',
            new SessionData(
                state: 'awaiting_confirmation',
                messageIdEditPicker: 6001,
            ),
        );

        $session = $this->service->getSession('C1');
        $this->assertSame(6001, $session['message_id_edit_picker']);
    }

    public function test_set_session_with_null_message_id_edit_picker_does_not_pollute_document(): void
    {
        // N12: setSession sem messageIdEditPicker (default null) não cria
        // o campo — array_filter remove o null do merge (consistente com
        // os outros campos opcionais).
        $this->service->setSession(
            'C1',
            new SessionData(
                state: 'awaiting_confirmation',
            ),
        );

        $session = $this->service->getSession('C1');
        $this->assertArrayNotHasKey('message_id_edit_picker', $session);
    }

    public function test_clear_fields_removes_message_id_edit_picker(): void
    {
        // N13: clearFields pode remover o message_id_edit_picker. Usado em
        // confirm/cancel (P3=B — o picker é deletado e o ID removido da
        // sessão). P7-A: durante edição normal, o campo NÃO é mais limpo
        // (o picker Y permanece no chat). Ver
        // ConversationRouterTest::test_p7a_edit_then_valid_response_keeps_message_id_edit_picker.
        $this->service->setSession(
            'C1',
            new SessionData(
                state: 'awaiting_edition',
                messageIdEditPicker: 6001,
            ),
        );

        $session = $this->service->getSession('C1');
        $this->assertSame(6001, $session['message_id_edit_picker']);

        $this->service->setSession(
            'C1',
            new SessionData(state: 'awaiting_confirmation'),
            clearFields: ['message_id_edit_picker'],
        );

        $session = $this->service->getSession('C1');
        $this->assertArrayNotHasKey('message_id_edit_picker', $session);
    }

    public function test_clear_session_removes_session(): void
    {
        $this->service->setSession('C1', new SessionData(state: 'idle'));

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

        $raw = $this->gateway->raw()['labels']['almoco'];
        $this->assertSame('almoço', $raw['name']);
        $this->assertSame(1, $raw['use_count']);
        $this->assertNotEmpty($raw['last_used_at']);
    }

    public function test_increment_label_use_accumulates(): void
    {
        $this->service->incrementLabelUse('almoço');
        $this->service->incrementLabelUse('almoço');

        $raw = $this->gateway->raw()['labels']['almoco'];
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
        $this->assertSame('almoco', $top[0]['id']);
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

    public function test_increment_label_use_accent_insensitive_same_document(): void
    {
        // 2× increment com acentos diferentes ("Almoço"/"almoco") → mesmo doc,
        // use_count=2.
        $this->service->incrementLabelUse('Almoço');
        $this->service->incrementLabelUse('almoco');

        $raw = $this->gateway->raw()['labels']['almoco'];
        $this->assertSame(2, $raw['use_count']);
    }

    public function test_create_category_preserves_accent_in_id(): void
    {
        // Categorias continuam com normalizeName (mb_strtolower, SEM fold).
        // Acentos são preservados no id do documento.
        $this->service->createCategory('Alimentação', 'expense', isDefault: true);

        $raw = $this->gateway->raw()['categories'] ?? [];
        $this->assertArrayHasKey('alimentação', $raw);
        $this->assertArrayNotHasKey('alimentacao', $raw);

        $data = $raw['alimentação'];
        $this->assertSame('Alimentação', $data['display_name']);
    }

    /*
    |--------------------------------------------------------------------------
    | Sincronização em lote (M9.8) — resetPendingSyncAttempts, listPendingSync,
    | markSyncStarted, markSyncSuccess, markSyncFailed.
    |--------------------------------------------------------------------------
    */

    /**
     * Cria uma transação persistida (não via saveTransaction para permitir
     * customizar sync_status/sync_attempts antes do "caminho normal").
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedPendingTransaction(string $id, string $chatId, array $overrides = []): string
    {
        $base = array_merge([
            'chat_id' => $chatId,
            'description' => 'Tx '.uniqid(),
            'amount' => 10.0,
            'type' => 'expense',
            'category' => 'Outros',
            'date' => '2026-06-15',
            'labels' => [],
            'source' => 'text',
            'sync_status' => FirestoreService::SYNC_PENDING,
            'sync_attempts' => 0,
            'sync_last_attempt_at' => null,
            'sync_error_message' => null,
            'spreadsheet_row_id' => null,
            'processing' => false,
            'notified_at' => null,
            'created_at' => '2026-06-15T00:00:00.000000Z',
            'updated_at' => '2026-06-15T00:00:00.000000Z',
        ], $overrides);

        $this->gateway->setDocument(FirestoreService::COLLECTION_TRANSACTIONS, $id, $base);

        return $id;
    }

    /*
    |--------------------------------------------------------------------------
    | listPendingSync
    |--------------------------------------------------------------------------
    */

    public function test_list_pending_sync_filters_by_attempts_less_than_three(): void
    {
        // 2 com attempts<3, 1 com attempts=3 (deve ser pulado), 1 com status=synced.
        $this->seedPendingTransaction('tx1', 'C1', ['sync_attempts' => 0]);
        $this->seedPendingTransaction('tx2', 'C1', ['sync_attempts' => 2]);
        $this->seedPendingTransaction('tx3', 'C1', ['sync_attempts' => 3, 'sync_status' => 'pending']);
        $this->seedPendingTransaction('tx4', 'C1', ['sync_status' => 'synced']);

        $list = $this->service->listPendingSync('C1');

        $ids = array_column($list, 'id');
        $this->assertCount(2, $list);
        $this->assertContains('tx1', $ids);
        $this->assertContains('tx2', $ids);
        $this->assertNotContains('tx3', $ids); // attempts >= 3 → pulado
        $this->assertNotContains('tx4', $ids); // status != pending → pulado
    }

    public function test_list_pending_sync_orders_by_created_at_asc(): void
    {
        // FIFO: mais antiga primeiro.
        $this->seedPendingTransaction('tx-old', 'C1', ['created_at' => '2026-06-01T00:00:00.000000Z']);
        $this->seedPendingTransaction('tx-new', 'C1', ['created_at' => '2026-06-15T00:00:00.000000Z']);
        $this->seedPendingTransaction('tx-mid', 'C1', ['created_at' => '2026-06-10T00:00:00.000000Z']);

        $list = $this->service->listPendingSync('C1');

        $this->assertSame(['tx-old', 'tx-mid', 'tx-new'], array_column($list, 'id'));
    }

    public function test_list_pending_sync_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedPendingTransaction("tx-{$i}", 'C1', [
                'created_at' => sprintf('2026-06-01T00:00:%02d.000000Z', $i),
            ]);
        }

        $list = $this->service->listPendingSync('C1', limit: 3);

        $this->assertCount(3, $list);
    }

    public function test_list_pending_sync_with_null_chat_id_returns_all_chats(): void
    {
        $this->seedPendingTransaction('tx-c1', 'C1');
        $this->seedPendingTransaction('tx-c2', 'C2');

        $list = $this->service->listPendingSync(chatId: null);

        $ids = array_column($list, 'id');
        $this->assertCount(2, $list);
        $this->assertContains('tx-c1', $ids);
        $this->assertContains('tx-c2', $ids);
    }

    /*
    |--------------------------------------------------------------------------
    | resetPendingSyncAttempts
    |--------------------------------------------------------------------------
    */

    public function test_reset_pending_sync_attempts_zeros_all_for_chat(): void
    {
        $this->seedPendingTransaction('tx1', 'C1', ['sync_attempts' => 2, 'sync_error_message' => 'old error']);
        $this->seedPendingTransaction('tx2', 'C1', ['sync_attempts' => 1]);
        $this->seedPendingTransaction('tx3', 'C2', ['sync_attempts' => 2]); // outro chat — não deve mexer

        $count = $this->service->resetPendingSyncAttempts('C1');

        $this->assertSame(2, $count);
        $this->assertSame(0, $this->service->getTransaction('tx1')['sync_attempts']);
        $this->assertSame(FirestoreService::SYNC_PENDING, $this->service->getTransaction('tx1')['sync_status']);
        $this->assertNull($this->service->getTransaction('tx1')['sync_error_message']);
        $this->assertSame(0, $this->service->getTransaction('tx2')['sync_attempts']);
        $this->assertSame(2, $this->service->getTransaction('tx3')['sync_attempts']); // preservado
    }

    public function test_reset_pending_sync_attempts_returns_zero_when_no_pendents(): void
    {
        // Nenhuma transação pendente.
        $count = $this->service->resetPendingSyncAttempts('C1');

        $this->assertSame(0, $count);
    }

    public function test_reset_pending_sync_attempts_with_null_chat_id_resets_globally(): void
    {
        $this->seedPendingTransaction('tx1', 'C1', ['sync_attempts' => 2]);
        $this->seedPendingTransaction('tx2', 'C2', ['sync_attempts' => 1]);

        $count = $this->service->resetPendingSyncAttempts(chatId: null);

        $this->assertSame(2, $count);
        $this->assertSame(0, $this->service->getTransaction('tx1')['sync_attempts']);
        $this->assertSame(0, $this->service->getTransaction('tx2')['sync_attempts']);
    }

    /*
    |--------------------------------------------------------------------------
    | markSyncStarted (lock atômico)
    |--------------------------------------------------------------------------
    */

    public function test_mark_sync_started_acquires_lock_and_returns_true(): void
    {
        $id = $this->seedPendingTransaction('tx1', 'C1');

        $acquired = $this->service->markSyncStarted($id);

        $this->assertTrue($acquired);
        $this->assertTrue($this->service->getTransaction($id)['processing']);
    }

    public function test_mark_sync_started_is_idempotent_second_call_returns_false(): void
    {
        $id = $this->seedPendingTransaction('tx1', 'C1');

        $first = $this->service->markSyncStarted($id);
        $second = $this->service->markSyncStarted($id);

        $this->assertTrue($first, 'Primeira chamada deve adquirir o lock');
        $this->assertFalse($second, 'Segunda chamada concorrente deve ser bloqueada');
    }

    public function test_mark_sync_started_releases_lock_after_success_or_failed(): void
    {
        $id = $this->seedPendingTransaction('tx1', 'C1');

        $this->service->markSyncStarted($id);
        $this->assertTrue($this->service->getTransaction($id)['processing']);

        $this->service->markSyncSuccess($id, 'row-42');
        $this->assertFalse($this->service->getTransaction($id)['processing']);

        // Outra chamada agora deve passar.
        $this->assertTrue($this->service->markSyncStarted($id));
    }

    /*
    |--------------------------------------------------------------------------
    | markSyncSuccess
    |--------------------------------------------------------------------------
    */

    public function test_mark_sync_success_sets_status_and_row_id_and_releases_lock(): void
    {
        $id = $this->seedPendingTransaction('tx1', 'C1', ['processing' => true]);

        $this->service->markSyncSuccess($id, 'row-42');

        $doc = $this->service->getTransaction($id);
        $this->assertSame(FirestoreService::SYNC_SYNCED, $doc['sync_status']);
        $this->assertSame('row-42', $doc['spreadsheet_row_id']);
        $this->assertFalse($doc['processing']);
    }

    public function test_mark_sync_success_is_idempotent(): void
    {
        $id = $this->seedPendingTransaction('tx1', 'C1');

        $this->service->markSyncSuccess($id, 'row-1');
        $this->service->markSyncSuccess($id, 'row-2'); // sobrescreve

        $this->assertSame('row-2', $this->service->getTransaction($id)['spreadsheet_row_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | markSyncFailed
    |--------------------------------------------------------------------------
    */

    public function test_mark_sync_failed_sets_status_error_and_releases_lock(): void
    {
        $id = $this->seedPendingTransaction('tx1', 'C1', ['processing' => true, 'sync_attempts' => 3]);

        $this->service->markSyncFailed($id, 'HTTP 500');

        $doc = $this->service->getTransaction($id);
        $this->assertSame(FirestoreService::SYNC_FAILED, $doc['sync_status']);
        $this->assertSame('HTTP 500', $doc['sync_error_message']);
        // markSyncFailed NÃO incrementa attempts (SyncSheet já o fez no caminho
        // de erro); documenta a contagem que veio do caller.
        $this->assertSame(3, $doc['sync_attempts']);
        $this->assertNotNull($doc['sync_last_attempt_at']);
        $this->assertFalse($doc['processing']);
    }

    public function test_mark_sync_failed_sets_notified_at_on_first_failure(): void
    {
        // Doc novo: notified_at = null. Após 1ª falha, deve ser carimbado.
        $id = $this->seedPendingTransaction('tx1', 'C1', ['notified_at' => null]);

        $this->service->markSyncFailed($id, 'boom');

        $this->assertNotNull($this->service->getTransaction($id)['notified_at']);
    }

    public function test_mark_sync_failed_does_not_overwrite_existing_notified_at(): void
    {
        // Doc já notificado (idempotência): 2ª/3ª falha NÃO sobrescreve o carimbo.
        $originalStamp = '2026-06-15T10:00:00.000000Z';
        $id = $this->seedPendingTransaction('tx1', 'C1', [
            'notified_at' => $originalStamp,
            'sync_attempts' => 3,
        ]);

        $this->service->markSyncFailed($id, 'boom again');

        $this->assertSame($originalStamp, $this->service->getTransaction($id)['notified_at']);
    }

    public function test_update_sync_status_clears_processing_flag(): void
    {
        // Regressão M9: o updateSyncStatus legado (M6/M7/M8) deve limpar
        // o flag processing quando transiciona o sync_status — caso
        // contrário, o lock ficaria preso entre tentativas.
        $id = $this->seedPendingTransaction('tx1', 'C1', ['processing' => true]);

        $this->service->updateSyncStatus($id, FirestoreService::SYNC_PENDING, 'transient error');

        $this->assertFalse($this->service->getTransaction($id)['processing']);
    }

    public function test_save_transaction_initializes_sync_metadata_fields(): void
    {
        // Regressão M9.8: notified_at, processing e spreadsheet_row_id
        // devem ser inicializados em saveTransaction para que o command
        // (que depende de notified_at=null na 1ª falha) funcione sem
        // precisar de merge explícito.
        $id = $this->service->saveTransaction('C1', $this->dto());

        $doc = $this->service->getTransaction($id);
        $this->assertNull($doc['notified_at']);
        $this->assertFalse($doc['processing']);
        $this->assertNull($doc['spreadsheet_row_id']);
    }
}
