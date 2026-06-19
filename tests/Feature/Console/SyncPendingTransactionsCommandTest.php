<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\SyncSheet;
use App\Actions\SyncsSheet;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Console\Commands\SyncPendingTransactions;
use App\Dto\TransactionData;
use App\Services\Google\FirestoreGateway;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do command `transactions:sync-pending` (M9.8 / T-011).
 *
 * Cobertura (Decisão Portão 2 #5 + #7 + #10 + spec §2.9):
 *  - CT-033a  → 0 pendentes, exit 0, mensagem informativa.
 *  - CT-033b  → 1 pendente, sync ok, status=synced.
 *  - CT-033c  → attempts=2 + Sheets ok → 3ª tentativa sucede, status=synced.
 *  - CT-033d  → 3 falhas consecutivas → status=failed + notifyError 1x.
 *  - CT-033e  → attempts>=3 + status=failed → pulado (não conta como processado).
 *  - CT-033f  → Sheets indisponível → status fica pending (re-enfileirado).
 *  - CT-033g  → 25 pendentes, limite 20 → apenas 20 processadas.
 *  - Idempotência de notify: 2ª execução não re-notifica (notified_at).
 *  - Dry-run: lista sem side-effects.
 *  - --format=json: output JSON válido e parseável.
 *  - --chat-id: filtra por chat.
 *
 * O SyncSheet é mockado por stub anônimo (não usa InMemorySheetsGateway
 * porque o SyncSheet já encapsula a chamada Sheets + update Firestore —
 * queremos testar a ORQUESTRAÇÃO do command, não o SyncSheet em si).
 *
 * O BotMessenger é o InMemoryBotMessenger (captura `notifyError` etc).
 *
 * O Firestore é o InMemoryFirestoreGateway, bindado no container para
 * que o FirestoreService (singleton) e o command compartilhem a mesma
 * instância dentro de cada teste.
 */
#[CoversClass(SyncPendingTransactions::class)]
class SyncPendingTransactionsCommandTest extends TestCase
{
    private const string CHAT_ID = '12345';

    private const string CHAT_ID_OTHER = '67890';

    private InMemoryFirestoreGateway $gateway;

    private FirestoreService $firestore;

    private InMemoryBotMessenger $messenger;

    /**
     * Stub configurável do SyncsSheet — substitui SyncSheet::handle().
     *
     * @var StubSyncsSheet
     */
    private object $syncSheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemoryFirestoreGateway;
        $this->firestore = new FirestoreService($this->gateway);
        $this->messenger = new InMemoryBotMessenger;
        $this->syncSheet = new StubSyncsSheet($this->firestore);

        $this->app->instance(FirestoreGateway::class, $this->gateway);
        $this->app->instance(FirestoreService::class, $this->firestore);
        $this->app->instance(BotMessenger::class, $this->messenger);
        $this->app->instance(SyncsSheet::class, $this->syncSheet);
    }

    /**
     * Cria uma transação pendente com a contagem de tentativas dada.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedPendingTx(string $id, int $attempts = 0, string $chatId = self::CHAT_ID, array $overrides = []): void
    {
        $this->gateway->setDocument(FirestoreService::COLLECTION_TRANSACTIONS, $id, array_merge([
            'chat_id' => $chatId,
            'description' => "Tx {$id}",
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Outros',
            'date' => '2026-06-15',
            'labels' => [],
            'source' => 'text',
            'sync_status' => FirestoreService::SYNC_PENDING,
            'sync_attempts' => $attempts,
            'sync_last_attempt_at' => null,
            'sync_error_message' => null,
            'spreadsheet_row_id' => null,
            'processing' => false,
            'notified_at' => null,
            'created_at' => '2026-06-15T00:00:00.000000Z',
            'updated_at' => '2026-06-15T00:00:00.000000Z',
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033a: 0 pendentes, sem erro
    |--------------------------------------------------------------------------
    */

    public function test_command_with_zero_pendents_exits_zero_and_reports_noop(): void
    {
        $this->syncSheet->defaultReturn = true;

        $this->artisan('transactions:sync-pending')
            ->assertSuccessful()
            ->expectsOutputToContain('Sincronizando 0 transação(ões) pendente(s)');
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033b: 1 pendente, sync ok
    |--------------------------------------------------------------------------
    */

    public function test_command_with_one_pendent_syncs_and_reports_one_synced(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');

        $this->artisan('transactions:sync-pending')
            ->assertSuccessful()
            ->expectsOutputToContain('1 sincronizadas');

        $doc = $this->firestore->getTransaction('tx1');
        $this->assertSame(FirestoreService::SYNC_SYNCED, $doc['sync_status']);
        $this->assertSame('tx1', $doc['spreadsheet_row_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033c: attempts=2 + Sheets ok → synced na 3ª tentativa
    |--------------------------------------------------------------------------
    */

    public function test_command_with_attempts_two_and_sheets_ok_succeeds(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1', attempts: 2);

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        $doc = $this->firestore->getTransaction('tx1');
        $this->assertSame(FirestoreService::SYNC_SYNCED, $doc['sync_status']);
        // Sucesso NÃO incrementa attempts (comportamento do M6 SyncSheet:
        // `updateSyncStatus(SYNC_SYNCED)` sem errorMessage → sem increment).
        $this->assertSame(2, $doc['sync_attempts']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033d: 3 falhas → failed + notifyError 1x
    |--------------------------------------------------------------------------
    */

    public function test_command_three_failures_marks_failed_and_notifies_user(): void
    {
        $this->syncSheet->defaultReturn = false;
        $this->syncSheet->defaultError = 'HTTP 500 from Sheets API';
        $this->seedPendingTx('tx1', attempts: 2);

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        $doc = $this->firestore->getTransaction('tx1');
        $this->assertSame(FirestoreService::SYNC_FAILED, $doc['sync_status']);
        $this->assertSame(3, $doc['sync_attempts']);
        $this->assertNotNull($doc['notified_at'], 'notified_at deve ser carimbado na 1ª failed definitiva');

        // NotifyError chamado exatamente 1x.
        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertCount(1, $errors, 'Deve notificar exatamente 1x (não-spam)');
        $this->assertStringContainsString('Sincronização falhou', $errors[0]['message']);
    }

    public function test_command_notification_is_idempotent_second_run_does_not_renotify(): void
    {
        // Doc JÁ tem notified_at setado (simula 1ª notificação já enviada
        // em execução anterior). Nova execução não deve re-notificar.
        $this->syncSheet->defaultReturn = false;
        $this->seedPendingTx('tx1', attempts: 3, overrides: [
            'notified_at' => '2026-06-15T10:00:00.000000Z',
            // SyncSheet::handle sempre marca failed (e nosso stub também) — mas
            // a próxima iteração do command precisa pular este doc (attempts>=3
            // + listPendingSync filtra). Aqui simulamos um cenário "edge": o
            // doc escapa do filtro (attempts=2 pré-fail) e já foi notificado.
            'sync_attempts' => 2,
        ]);

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        // Mesmo com notify já tendo sido enviado antes, o attempts sobe para 3
        // e notificação NÃO é re-enviada.
        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertCount(0, $errors, 'Não deve re-notificar quando notified_at já existe');
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033e: attempts>=3 → pulado pela listPendingSync
    |--------------------------------------------------------------------------
    */

    public function test_command_skips_transactions_with_attempts_gte_three(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1', attempts: 3, overrides: [
            'sync_status' => FirestoreService::SYNC_PENDING, // ainda pending (rare edge)
        ]);

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        // SyncSheet NÃO deve ter sido chamado para esta tx.
        $this->assertSame(0, $this->syncSheet->callCount);
        // Doc permanece inalterado.
        $this->assertSame(3, $this->firestore->getTransaction('tx1')['sync_attempts']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033f: Sheets indisponível → re-enfileira
    |--------------------------------------------------------------------------
    */

    public function test_command_sheets_unavailable_re_enqueues_with_attempts_incremented(): void
    {
        $this->syncSheet->defaultReturn = false;
        $this->syncSheet->defaultError = 'Sheets offline';
        $this->seedPendingTx('tx1', attempts: 0);

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        $doc = $this->firestore->getTransaction('tx1');
        $this->assertSame(FirestoreService::SYNC_PENDING, $doc['sync_status'], 'deve voltar a pending para próxima rodada');
        $this->assertSame(1, $doc['sync_attempts'], 'tentativa contabilizada (sync_attempts++)');
        $this->assertNull($doc['notified_at'], 'NÃO deve notificar (ainda não atingiu 3)');
    }

    /*
    |--------------------------------------------------------------------------
    | CT-033g: batch de 20 (limite default)
    |--------------------------------------------------------------------------
    */

    public function test_command_processes_max_twenty_pendents_per_run(): void
    {
        $this->syncSheet->defaultReturn = true;
        for ($i = 0; $i < 25; $i++) {
            $this->seedPendingTx(sprintf('tx-%02d', $i));
        }

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        // 20 processadas (5 restantes ficam pending para próxima rodada).
        $this->assertSame(20, $this->syncSheet->callCount);

        // Verifica que as 5 últimas (tx-20..tx-24) NÃO foram processadas.
        $this->assertSame(FirestoreService::SYNC_PENDING, $this->firestore->getTransaction('tx-20')['sync_status']);
        $this->assertSame(FirestoreService::SYNC_PENDING, $this->firestore->getTransaction('tx-24')['sync_status']);
    }

    /*
    |--------------------------------------------------------------------------
    | --format=json
    |--------------------------------------------------------------------------
    */

    public function test_command_format_json_emits_parseable_json(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');
        $this->seedPendingTx('tx2');

        // Usar Artisan::call() direto (não $this->artisan()) — o helper de
        // teste do Laravel consome o output via MockOutputStyle, dificultando
        // a captura integral. Aqui queremos o output bruto.
        $exit = Artisan::call('transactions:sync-pending', ['--format' => 'json']);
        $this->assertSame(0, $exit, 'command deve exit com 0');

        $output = trim(Artisan::output());

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Output deve ser JSON válido. Recebido: '{$output}'");
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(2, $decoded['processed']);
        $this->assertSame(2, $decoded['synced']);
        $this->assertSame(0, $decoded['failed']);
        $this->assertSame([], $decoded['errors']);
    }

    /*
    |--------------------------------------------------------------------------
    | --chat-id filtra por chat
    |--------------------------------------------------------------------------
    */

    public function test_command_chat_id_option_filters_by_chat(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx-c1', chatId: self::CHAT_ID);
        $this->seedPendingTx('tx-c2', chatId: self::CHAT_ID_OTHER);

        $this->artisan('transactions:sync-pending', ['--chat-id' => self::CHAT_ID])
            ->assertSuccessful()
            ->expectsOutputToContain('1 sincronizadas');

        // Apenas tx-c1 foi processada.
        $this->assertSame(FirestoreService::SYNC_SYNCED, $this->firestore->getTransaction('tx-c1')['sync_status']);
        $this->assertSame(FirestoreService::SYNC_PENDING, $this->firestore->getTransaction('tx-c2')['sync_status']);
    }

    /*
    |--------------------------------------------------------------------------
    | --dry-run não toca Firestore/Sheets
    |--------------------------------------------------------------------------
    */

    public function test_command_dry_run_lists_without_side_effects(): void
    {
        $this->seedPendingTx('tx1');
        $this->seedPendingTx('tx2');

        $this->artisan('transactions:sync-pending', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN');

        // SyncSheet NUNCA chamado.
        $this->assertSame(0, $this->syncSheet->callCount);
        // Firestore inalterado.
        $this->assertSame(FirestoreService::SYNC_PENDING, $this->firestore->getTransaction('tx1')['sync_status']);
        $this->assertSame(0, $this->firestore->getTransaction('tx1')['sync_attempts']);
    }

    /*
    |--------------------------------------------------------------------------
    | Integração: 1 sync ok + 1 falhar + 1 re-enfileirar (mixed)
    |--------------------------------------------------------------------------
    */

    public function test_command_mixed_outcomes_counts_synced_and_failed_correctly(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx-ok-1');
        $this->seedPendingTx('tx-ok-2');
        $this->seedPendingTx('tx-fail', overrides: ['sync_attempts' => 2]);

        // tx-fail falha.
        $this->syncSheet->overrides['tx-fail'] = ['return' => false, 'error' => 'HTTP 500'];

        $this->artisan('transactions:sync-pending')
            ->assertSuccessful()
            ->expectsOutputToContain('3 processadas');

        // Verifica contadores nos Firestore docs.
        $this->assertSame(FirestoreService::SYNC_SYNCED, $this->firestore->getTransaction('tx-ok-1')['sync_status']);
        $this->assertSame(FirestoreService::SYNC_SYNCED, $this->firestore->getTransaction('tx-ok-2')['sync_status']);
        $this->assertSame(FirestoreService::SYNC_FAILED, $this->firestore->getTransaction('tx-fail')['sync_status']);
        $this->assertSame(3, $this->firestore->getTransaction('tx-fail')['sync_attempts']);

        // 1 notificação (apenas para tx-fail, que atingiu 3 tentativas).
        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);
    }
}

/**
 * Stub do {@see SyncsSheet} — simula fielmente o efeito colateral de
 * {@see SyncSheet} no Firestore.
 *
 * Em produção, o `SyncSheet` real:
 *  - Sucesso → chama `updateSyncStatus(id, SYNC_SYNCED)` (sem increment attempts).
 *  - Falha   → chama `updateSyncStatus(id, SYNC_FAILED, $error)` (com increment).
 *
 * O command então decide a próxima ação (re-enfileirar pending ou marcar
 * definitivo failed). Para que os testes de orquestração sejam fiéis à
 * produção, o stub replica EXATAMENTE este side effect no Firestore de
 * teste. Sem isso, o command "enxergaria" o doc como se ainda tivesse o
 * attempts original e tomaria a decisão errada.
 */
class StubSyncsSheet implements SyncsSheet
{
    public bool $defaultReturn = true;

    public string $defaultError = 'unknown';

    /**
     * Mapa de overrides por id de transação.
     *
     * @var array<string, array{return: bool, error?: string}>
     */
    public array $overrides = [];

    public int $callCount = 0;

    public function __construct(
        private readonly ?FirestoreService $firestore = null,
    ) {}

    public function handle(TransactionData $dto, string $firestoreId, string $source): bool
    {
        $this->callCount++;

        $override = $this->overrides[$firestoreId] ?? null;
        $shouldReturn = $override['return'] ?? $this->defaultReturn;
        $error = $override['error'] ?? $this->defaultError;

        if (! $shouldReturn) {
            // Replica side effect do SyncSheet real em falha: marca failed
            // (com increment) e retorna false.
            if ($this->firestore !== null) {
                $this->firestore->updateSyncStatus(
                    $firestoreId,
                    FirestoreService::SYNC_FAILED,
                    $error,
                );
            }

            return false;
        }

        // Sucesso: replica side effect (status=synced, sem increment).
        if ($this->firestore !== null) {
            $this->firestore->updateSyncStatus(
                $firestoreId,
                FirestoreService::SYNC_SYNCED,
            );
        }

        return true;
    }
}
