<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Bot\Handlers\UltimosHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\TestCase;

/**
 * Testes do {@see UltimosHandler} (M9.5 / T-006).
 *
 * Cobertura:
 *  - CT-027      → default 5 transações
 *  - CT-027a     → 0 transações (mensagem amigável)
 *  - CT-027b     → só receitas
 *  - CT-027c     → só despesas
 *  - CT-028      → /ultimos 10 com 12 disponíveis
 *  - CT-028a     → /ultimos 0 → fallback 5
 *  - CT-028b     → /ultimos -3 → fallback 5
 *  - CT-028c     → /ultimos abc → fallback 5
 *  - CT-028d     → /ultimos 999999 → cap 50 (Portão 2 #1)
 *  - CT-028e     → n > total (pede 10, tem 3)
 *  - CT-028f     → preserva estado não-IDLE (stateless)
 */
#[CoversClass(UltimosHandler::class)]
class UltimosHandlerTest extends TestCase
{
    private const string CHAT_ID = '12345';

    private const int CHAT_ID_INT = 12345;

    private InMemoryFirestoreGateway $gateway;

    private FirestoreService $firestore;

    private InMemoryBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemoryFirestoreGateway;
        $this->firestore = new FirestoreService($this->gateway);
        $this->messenger = new InMemoryBotMessenger;

        $this->app->instance(FirestoreService::class, $this->firestore);
        $this->app->instance(BotMessenger::class, $this->messenger);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Constrói um bot mockado que devolve um Message com o texto dado e o
     * chat_id constante. Registra cada `sendMessage` no InMemoryBotMessenger
     * para asserção determinística.
     */
    private function makeBotMock(string $text): Nutgram
    {
        $message = new Message(null);
        $message->chat = new Chat(null);
        $message->chat->id = self::CHAT_ID_INT;
        $message->text = $text;

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('message')->andReturn($message);

        return $bot;
    }

    /**
     * Popula N transações para o chat_id.
     *
     * @param  list<array<string, mixed>>  $dataList
     */
    private function seedTransactions(array $dataList): void
    {
        $baseDate = new \DateTimeImmutable('2026-06-15');
        foreach ($dataList as $i => $data) {
            $date = isset($data['date'])
                ? (string) $data['date']
                : $baseDate->modify("-{$i} days")->format('Y-m-d');

            $this->gateway->setDocument(FirestoreService::COLLECTION_TRANSACTIONS, "tx-{$i}", array_merge([
                'chat_id' => self::CHAT_ID,
                'description' => "Tx {$i}",
                'amount' => 10.0,
                'type' => 'expense',
                'category' => 'Outros',
                'date' => $date,
                'labels' => [],
                'source' => 'text',
                'sync_status' => 'synced',
            ], $data));
        }
    }

    #[Group('smoke')]
    public function test_ultimos_default_5_lists_five_transactions(): void
    {
        // CT-027: /ultimos sem parâmetro → 5 transações (12 disponíveis).
        $this->seedTransactions(array_fill(0, 12, []));

        $bot = $this->makeBotMock('/ultimos');
        (new UltimosHandler)($bot);

        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 5 transações</b>', $text);
        $this->assertStringContainsString('1.', $text);
        $this->assertStringContainsString('5.', $text);
        $this->assertStringNotContainsString('6.', $text);
    }

    public function test_ultimos_with_zero_transactions_shows_friendly_message(): void
    {
        // CT-027a: 0 transações → mensagem amigável.
        $bot = $this->makeBotMock('/ultimos');
        (new UltimosHandler)($bot);

        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('📭', $text);
        $this->assertStringContainsString('Nenhuma transação registrada', $text);
    }

    public function test_ultimos_with_only_income(): void
    {
        // CT-027b: só receitas.
        $this->seedTransactions(array_fill(0, 3, ['type' => 'income', 'description' => 'Salário']));

        $bot = $this->makeBotMock('/ultimos');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('Receita', $text);
        $this->assertStringNotContainsString('Despesa', $text);
    }

    public function test_ultimos_with_only_expense(): void
    {
        // CT-027c: só despesas.
        $this->seedTransactions(array_fill(0, 3, ['type' => 'expense', 'description' => 'Mercado']));

        $bot = $this->makeBotMock('/ultimos');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('Despesa', $text);
        $this->assertStringNotContainsString('Receita', $text);
    }

    public function test_ultimos_with_param_10(): void
    {
        // CT-028: /ultimos 10 com 12 disponíveis.
        $this->seedTransactions(array_fill(0, 12, []));

        $bot = $this->makeBotMock('/ultimos 10');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 10 transações</b>', $text);
        $this->assertStringContainsString('1.', $text);
        $this->assertStringContainsString('10.', $text);
    }

    public function test_ultimos_with_param_zero_falls_back_to_5(): void
    {
        // CT-028a: /ultimos 0 → fallback 5.
        $this->seedTransactions(array_fill(0, 10, []));

        $bot = $this->makeBotMock('/ultimos 0');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 5 transações</b>', $text);
    }

    public function test_ultimos_with_param_negative_falls_back_to_5(): void
    {
        // CT-028b: /ultimos -3 → fallback 5 (não numérico, ctype_digit=false).
        $this->seedTransactions(array_fill(0, 10, []));

        $bot = $this->makeBotMock('/ultimos -3');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 5 transações</b>', $text);
    }

    public function test_ultimos_with_param_abc_falls_back_to_5(): void
    {
        // CT-028c: /ultimos abc → fallback 5.
        $this->seedTransactions(array_fill(0, 10, []));

        $bot = $this->makeBotMock('/ultimos abc');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 5 transações</b>', $text);
    }

    public function test_ultimos_with_param_999999_caps_at_50(): void
    {
        // CT-028d: /ultimos 999999 → cap em 50 (Portão 2 #1).
        $this->seedTransactions(array_fill(0, 60, []));

        $bot = $this->makeBotMock('/ultimos 999999');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 50 transações</b>', $text);
        $this->assertStringContainsString('50.', $text);
    }

    public function test_ultimos_with_n_greater_than_available_returns_all(): void
    {
        // CT-028e: /ultimos 10 com 3 disponíveis → lista as 3.
        $this->seedTransactions(array_fill(0, 3, []));

        $bot = $this->makeBotMock('/ultimos 10');
        (new UltimosHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('<b>Últimas 3 transações</b>', $text);
        $this->assertStringContainsString('1.', $text);
        $this->assertStringContainsString('3.', $text);
    }

    public function test_ultimos_preserves_non_idle_state(): void
    {
        // CT-028f: /ultimos em AWAITING_DATA não altera o estado da sessão.
        $this->seedTransactions(array_fill(0, 2, []));

        // Simula sessão em AWAITING_DATA.
        $this->gateway->setDocument(
            FirestoreService::COLLECTION_SESSIONS,
            self::CHAT_ID,
            [
                'state' => 'awaiting_data',
                'awaiting_field' => 'amount',
                'draft' => ['description' => 'Almoço'],
                'updated_at' => gmdate('Y-m-d\TH:i:s.u\Z'),
            ],
        );

        $bot = $this->makeBotMock('/ultimos 3');
        (new UltimosHandler)($bot);

        // Sessão preservada intacta.
        $session = $this->firestore->getSession(self::CHAT_ID);
        $this->assertNotNull($session, 'Sessão deve permanecer após /ultimos (CT-028f)');
        $this->assertSame('awaiting_data', $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);
        $this->assertSame(['description' => 'Almoço'], $session['draft']);

        // E o handler enviou a listagem normalmente.
        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
    }
}
