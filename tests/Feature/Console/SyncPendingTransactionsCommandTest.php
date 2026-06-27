<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\SyncsSheet;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Console\Commands\SyncPendingTransactions;
use App\Models\Transaction;
use App\Services\Store\WalletStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\WithWalletStore;
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
 * porque o SyncSheet já encapsula a chamada Sheets + update WalletStore —
 * queremos testar a ORQUESTRAÇÃO do command, não o SyncSheet em si).
 *
 * O BotMessenger é o InMemoryBotMessenger (captura `notifyError` etc).
 *
 * O WalletStore é resolvido via container e usa RefreshDatabase (SQLite
 * in-memory) para que o command compartilhe a mesma instância do banco.
 */
#[CoversClass(SyncPendingTransactions::class)]
class SyncPendingTransactionsCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private const string CHAT_ID = '12345';

    private const string CHAT_ID_OTHER = '67890';

    private WalletStore $store;

    private InMemoryBotMessenger $messenger;

    /**
     * Stub configurável do SyncsSheet — substitui SyncSheet::handle().
     */
    private StubSyncsSheet $syncSheet;

    /**
     * Mapa label → ID inteiro para rastrear transações criadas.
     *
     * @var array<string, int>
     */
    private array $txIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->messenger = new InMemoryBotMessenger;
        $this->syncSheet = new StubSyncsSheet($this->store);

        $this->bindStoreToContainer();
        $this->app->instance(BotMessenger::class, $this->messenger);
        $this->app->instance(SyncsSheet::class, $this->syncSheet);
    }

    /**
     * Cria uma transação pendente com a contagem de tentativas dada.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedPendingTx(string $label, int $attempts = 0, string $chatId = self::CHAT_ID, array $overrides = []): int
    {
        $tx = Transaction::factory()->create(array_merge([
            'chat_id' => $chatId,
            'description' => "Tx {$label}",
            'sync_status' => WalletStore::SYNC_PENDING,
            'sync_attempts' => $attempts,
            'processing' => false,
        ], $overrides));
        $this->txIds[$label] = $tx->id;

        return $tx->id;
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

        $tx = Transaction::find($this->txIds['tx1']);
        $this->assertSame(WalletStore::SYNC_SYNCED, $tx->sync_status);
        $this->assertSame((string) $this->txIds['tx1'], $tx->spreadsheet_row_id);
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

        $tx = Transaction::find($this->txIds['tx1']);
        $this->assertSame(WalletStore::SYNC_SYNCED, $tx->sync_status);
        // Sucesso NÃO incrementa attempts (comportamento do M6 SyncSheet:
        // `updateSyncStatus(SYNC_SYNCED)` sem errorMessage → sem increment).
        $this->assertSame(2, $tx->sync_attempts);
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

        $tx = Transaction::find($this->txIds['tx1']);
        $this->assertSame(WalletStore::SYNC_FAILED, $tx->sync_status);
        $this->assertSame(3, $tx->sync_attempts);
        $this->assertNotNull($tx->notified_at, 'notified_at deve ser carimbado na 1ª failed definitiva');

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
            'notified_at' => '2026-06-15 10:00:00',
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
            'sync_status' => WalletStore::SYNC_PENDING, // still pending (rare edge)
        ]);

        $this->artisan('transactions:sync-pending')->assertSuccessful();

        // SyncSheet NÃO deve ter sido chamado para esta tx.
        $this->assertSame(0, $this->syncSheet->callCount);
        // Doc permanece inalterado.
        $this->assertSame(3, Transaction::find($this->txIds['tx1'])->sync_attempts);
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

        $tx = Transaction::find($this->txIds['tx1']);
        $this->assertSame(WalletStore::SYNC_PENDING, $tx->sync_status, 'deve voltar a pending para próxima rodada');
        $this->assertSame(1, $tx->sync_attempts, 'tentativa contabilizada (sync_attempts++)');
        $this->assertNull($tx->notified_at, 'NÃO deve notificar (ainda não atingiu 3)');
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
        $this->assertSame(WalletStore::SYNC_PENDING, Transaction::find($this->txIds['tx-20'])->sync_status);
        $this->assertSame(WalletStore::SYNC_PENDING, Transaction::find($this->txIds['tx-24'])->sync_status);
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
        $this->assertSame(WalletStore::SYNC_SYNCED, Transaction::find($this->txIds['tx-c1'])->sync_status);
        $this->assertSame(WalletStore::SYNC_PENDING, Transaction::find($this->txIds['tx-c2'])->sync_status);
    }

    /*
    |--------------------------------------------------------------------------
    | --dry-run não toca WalletStore/Sheets
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
        // Banco inalterado.
        $this->assertSame(WalletStore::SYNC_PENDING, Transaction::find($this->txIds['tx1'])->sync_status);
        $this->assertSame(0, Transaction::find($this->txIds['tx1'])->sync_attempts);
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
        $this->syncSheet->overrides[$this->txIds['tx-fail']] = ['return' => false, 'error' => 'HTTP 500'];

        $this->artisan('transactions:sync-pending')
            ->assertSuccessful()
            ->expectsOutputToContain('3 processadas');

        // Verifica contadores nos registros do banco de dados.
        $this->assertSame(WalletStore::SYNC_SYNCED, Transaction::find($this->txIds['tx-ok-1'])->sync_status);
        $this->assertSame(WalletStore::SYNC_SYNCED, Transaction::find($this->txIds['tx-ok-2'])->sync_status);
        $this->assertSame(WalletStore::SYNC_FAILED, Transaction::find($this->txIds['tx-fail'])->sync_status);
        $this->assertSame(3, Transaction::find($this->txIds['tx-fail'])->sync_attempts);

        // 1 notificação (apenas para tx-fail, que atingiu 3 tentativas).
        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | --time-budget (orçamento de tempo)
    |--------------------------------------------------------------------------
    */

    public function test_time_budget_low_interrupts_processing(): void
    {
        // Cenário: 3 documentos pendentes, --time-budget=1.
        // Com budget de 1s e margem de 5s, a condição `elapsed + 5 > budget`
        // é verdadeira na primeira iteração (0 + 5 > 1).
        // Esperado: processed=0, time_budget_exhausted=true.
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');
        $this->seedPendingTx('tx2');
        $this->seedPendingTx('tx3');

        $exit = Artisan::call('transactions:sync-pending', [
            '--format' => 'json',
            '--time-budget' => '1',
        ]);
        $this->assertSame(0, $exit);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, "Output deve ser JSON válido. Recebido: '{$output}'");
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(0, $decoded['processed'], 'Com budget=1, nenhuma transação deve ser processada');
        $this->assertTrue($decoded['time_budget_exhausted'], 'time_budget_exhausted deve ser true');
        $this->assertSame(0, $decoded['synced']);
        $this->assertSame(0, $decoded['failed']);
    }

    public function test_time_budget_high_allows_normal_processing(): void
    {
        // Cenário: 3 documentos pendentes, --time-budget=3600.
        // Com budget generoso (1h), todas as transações devem ser processadas.
        // Esperado: processed=3, time_budget_exhausted=false.
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');
        $this->seedPendingTx('tx2');
        $this->seedPendingTx('tx3');

        $exit = Artisan::call('transactions:sync-pending', [
            '--format' => 'json',
            '--time-budget' => '3600',
        ]);
        $this->assertSame(0, $exit);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, "Output deve ser JSON válido. Recebido: '{$output}'");
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(3, $decoded['processed'], 'Com budget=3600, todas as 3 transações devem ser processadas');
        $this->assertFalse($decoded['time_budget_exhausted'], 'time_budget_exhausted deve ser false');
        $this->assertSame(3, $decoded['synced']);
        $this->assertSame(0, $decoded['failed']);
    }
}

