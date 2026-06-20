<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Actions\SyncsSheet;
use App\Bot\Handlers\SyncHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Services\Google\FirestoreGateway;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\Feature\Console\StubSyncsSheet;
use Tests\TestCase;

/**
 * Testes do {@see SyncHandler} (M9.7 / T-012).
 *
 * Cobertura (Decisão Portão 2 #7 + #8 + spec §2.4):
 *  - CT-048  → 0 pendentes → "Nenhuma transação pendente".
 *  - CT-049  → 1 pendente, sync ok → "1 sincronizada com sucesso".
 *  - CT-050  → 3 pendentes, todas ok → "3 sincronizadas".
 *  - CT-051  → pendente com attempts=2 (já falhou 2x) → reseta e sincroniza (não vai para failed).
 *  - CT-052  → lock: durante sync em andamento, /sync avisa e não duplica (best-effort).
 *  - CT-053  → em estado não-IDLE → handler é stateless, não mexe na sessão.
 *  - markSyncFailed idempotente de notified_at.
 *  - BotMessenger::notifyError chamado 1x por transação com 3 falhas.
 *
 * O comando Artisan é invocado in-process via `Artisan::call()` — a
 * interação é end-to-end. O SyncSheet é stubado (reusa o
 * {@see StubSyncsSheet} do teste do command).
 */
#[CoversClass(SyncHandler::class)]
class SyncHandlerTest extends TestCase
{
    private const string CHAT_ID = '12345';

    private const int CHAT_ID_INT = 12345;

    private InMemoryFirestoreGateway $gateway;

    private FirestoreService $firestore;

    private InMemoryBotMessenger $messenger;

    private StubSyncsSheet $syncSheet;

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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Constrói um mock leve do Nutgram com message() retornando um Message.
     */
    private function makeBotMock(): Nutgram
    {
        $message = new Message(null);
        $message->chat = new Chat(null);
        $message->chat->id = self::CHAT_ID_INT;

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('message')->andReturn($message);

        return $bot;
    }

    /**
     * Popula uma transação pendente com a contagem de tentativas dada.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedPendingTx(string $id, int $attempts = 0, array $overrides = []): void
    {
        $this->gateway->setDocument(FirestoreService::COLLECTION_TRANSACTIONS, $id, array_merge([
            'chat_id' => self::CHAT_ID,
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

    /**
     * Retorna o último texto enviado ao chat (helper de asserção).
     */
    private function lastSentText(): string
    {
        $list = $this->messenger->sentTexts[self::CHAT_ID] ?? [];

        return (string) ($list[count($list) - 1]['text'] ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | CT-048: 0 pendentes → "Nenhuma transação pendente"
    |--------------------------------------------------------------------------
    */

    public function test_sync_with_zero_pendents_sends_friendly_message(): void
    {
        // CT-048: nenhum doc com sync_status=pending.

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        $text = $this->lastSentText();
        $this->assertStringContainsString('Nenhuma transação pendente', $text);
        $this->assertStringContainsString('planilha', $text);
        // Não chama o SyncSheet nem emite "iniciando sync".
        $this->assertSame(0, $this->syncSheet->callCount);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-049: 1 pendente, sync ok
    |--------------------------------------------------------------------------
    */

    #[Group('smoke')]
    public function test_sync_with_one_pendent_resets_and_succeeds(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1', attempts: 0);

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        // 1ª mensagem: "iniciando sync"; 2ª: "resultado".
        $messages = array_column($this->messenger->sentTexts[self::CHAT_ID] ?? [], 'text');
        $this->assertGreaterThanOrEqual(2, count($messages));
        $this->assertStringContainsString('Sincronizando 1 transação(ões)', $messages[0]);
        $this->assertStringContainsString('1 sincronizada(s) com sucesso', end($messages));

        // Doc marcado como synced.
        $this->assertSame(FirestoreService::SYNC_SYNCED, $this->firestore->getTransaction('tx1')['sync_status']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-050: 3 pendentes, todas ok
    |--------------------------------------------------------------------------
    */

    public function test_sync_with_multiple_pendents_processes_all(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');
        $this->seedPendingTx('tx2');
        $this->seedPendingTx('tx3');

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        $text = $this->lastSentText();
        $this->assertStringContainsString('3 sincronizada(s) com sucesso', $text);
        $this->assertSame(3, $this->syncSheet->callCount);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-051: reset de contador (Decisão Portão 2 #7)
    |--------------------------------------------------------------------------
    */

    public function test_sync_resets_sync_attempts_to_zero_before_processing(): void
    {
        // tx1 já falhou 2 vezes. /sync deve resetar para 0 antes de tentar.
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1', attempts: 2, overrides: [
            'sync_error_message' => 'previous error',
        ]);

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        // Sucesso — não foi para failed porque o reset zerou os attempts.
        $doc = $this->firestore->getTransaction('tx1');
        $this->assertSame(FirestoreService::SYNC_SYNCED, $doc['sync_status']);
        $this->assertNull($doc['sync_error_message'], 'mensagem de erro anterior foi limpa pelo reset');
        $this->assertSame(0, $doc['sync_attempts'], 'attempts zerado pelo reset (sucesso não incrementa)');
    }

    public function test_sync_without_reset_would_have_marked_failed(): void
    {
        // Regressão: prova que sem o reset, tx1 com attempts=2 que falhasse
        // iria para failed (3ª falha). Com reset, evita-se a falha.
        // Aqui validamos que o reset foi aplicado ANTES do sync.
        $this->syncSheet->defaultReturn = false;
        $this->syncSheet->defaultError = 'sheets 500';
        $this->seedPendingTx('tx1', attempts: 2);

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        $doc = $this->firestore->getTransaction('tx1');
        // SyncSheet incrementou para 3. Como o reset ZEROU antes, e o
        // increment durante a falha trouxe para 1 (não 3), o status fica
        // pending (re-enfileirado), não failed.
        $this->assertSame(FirestoreService::SYNC_PENDING, $doc['sync_status']);
        $this->assertSame(1, $doc['sync_attempts'], 'reset zerou, falha incrementou para 1');
    }

    /*
    |--------------------------------------------------------------------------
    | CT-052: lock atômico — concorrência com cron
    |--------------------------------------------------------------------------
    */

    public function test_sync_skips_transaction_already_being_processed(): void
    {
        // Simula outra execução já com o lock.
        $this->seedPendingTx('tx1', overrides: ['processing' => true]);

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        // SyncSheet NÃO foi chamado para tx1 (lock ativo).
        $this->assertSame(0, $this->syncSheet->callCount);
        // Doc preservado intocado (sem mudanca de status/attempts).
        $doc = $this->firestore->getTransaction('tx1');
        $this->assertTrue((bool) $doc['processing'], 'lock preservado');
        $this->assertSame(FirestoreService::SYNC_PENDING, $doc['sync_status']);
        $this->assertSame(0, $doc['sync_attempts'], 'attempts não tocado');
    }

    /*
    |--------------------------------------------------------------------------
    | CT-053: handler é stateless (não mexe na sessão)
    |--------------------------------------------------------------------------
    */

    public function test_sync_preserves_non_idle_session_state(): void
    {
        // Sessão em AWAITING_CONFIRMATION.
        $this->gateway->setDocument(FirestoreService::COLLECTION_SESSIONS, self::CHAT_ID, [
            'state' => 'awaiting_confirmation',
            'message_id_confirm' => 5001,
            'draft' => ['description' => 'Cinema', 'amount' => 35.0, 'type' => 'expense'],
            'updated_at' => '2026-06-15T00:00:00.000000Z',
        ]);

        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        // Sessão preservada intacta.
        $session = $this->firestore->getSession(self::CHAT_ID);
        $this->assertNotNull($session);
        $this->assertSame('awaiting_confirmation', $session['state']);
        $this->assertSame(5001, $session['message_id_confirm']);
    }

    /*
    |--------------------------------------------------------------------------
    | Notificação: 3 falhas → notifyError 1x (cenário de integração)
    |--------------------------------------------------------------------------
    |
    | IMPORTANTE: o /sync sempre reseta o contador de tentativas (Decisão
    | Portão 2 #7) — por isso a notificação de 3 falhas NÃO é alcançável
    | dentro de UMA única invocação do handler. A validação completa da
    | notificação está em {@see \Tests\Feature\Console\SyncPendingTransactionsCommandTest::test_command_three_failures_marks_failed_and_notifies_user}.
    |
    | Aqui validamos apenas que o handler DELEGA corretamente ao command
    | e que a integração funciona (command é invocado, messenger injetado).
    */

    public function test_sync_with_mixed_results_lists_failures_in_response(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx-ok-1');
        $this->seedPendingTx('tx-ok-2');
        $this->seedPendingTx('tx-fail');
        $this->syncSheet->overrides['tx-fail'] = ['return' => false, 'error' => 'HTTP 500'];

        $bot = $this->makeBotMock();
        (new SyncHandler)($bot);

        $text = $this->lastSentText();
        $this->assertStringContainsString('2 sincronizada(s) com sucesso', $text);
        $this->assertStringContainsString('1 com falha', $text);
        $this->assertStringContainsString('tx-fail', $text);
        $this->assertStringContainsString('HTTP 500', $text);
    }

    /*
    |--------------------------------------------------------------------------
    | markSyncFailed idempotência de notified_at (regressão)
    |--------------------------------------------------------------------------
    */

    public function test_mark_sync_failed_does_not_overwrite_existing_notified_at(): void
    {
        $stamp = '2026-06-15T10:00:00.000000Z';
        $id = 'tx1';
        $this->seedPendingTx($id, attempts: 3, overrides: [
            'sync_status' => 'failed', // já estava failed
            'notified_at' => $stamp,
        ]);

        // Tenta marcar failed de novo (caller pode ser o command re-rodando).
        $this->firestore->markSyncFailed($id, 'boom again');

        // notified_at preservado (rastreabilidade).
        $this->assertSame($stamp, $this->firestore->getTransaction($id)['notified_at']);
    }
}
