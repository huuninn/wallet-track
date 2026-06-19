<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Bot\Handlers\CancelarHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Enums\ConversationState;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\TestCase;

/**
 * Testes do {@see CancelarHandler} (M9.4 / T-003 / GAP-02).
 *
 * Cobre CT-026a (IDLE) até CT-026e (durante wizard).
 * Regressão preservada: o handler continua chamando `clearSession` + `notifyCancelled`
 * quando há sessão ativa (comportamento já testado em
 * {@see \Tests\Feature\Conversation\ConversationRouterTest::test_cancelar_handler_clears_session_and_notifies}).
 */
#[CoversClass(CancelarHandler::class)]
class CancelarHandlerTest extends TestCase
{
    private const string CHAT_ID = '12345';

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
     * Mocka o Nutgram com `message()` retornando um Message com chat->id.
     */
    private function makeBotMock(): Nutgram
    {
        $message = new Message(null);
        $message->chat = new Chat(null);
        $message->chat->id = (int) self::CHAT_ID;

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('message')->andReturn($message);

        return $bot;
    }

    /**
     * Pré-popula a sessão do chat em um estado arbitrário.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedSession(string $state, array $overrides = []): void
    {
        $this->gateway->setDocument(
            FirestoreService::COLLECTION_SESSIONS,
            self::CHAT_ID,
            array_merge([
                'state' => $state,
                'updated_at' => gmdate('Y-m-d\TH:i:s.u\Z'),
            ], $overrides),
        );
    }

    public function test_cancelar_in_idle_shows_nothing_to_cancel(): void
    {
        // CT-026a: /cancelar em IDLE (sem sessão) → mensagem amigável,
        // SEM chamar notifyCancelled (que enviaria "🚫 Transação cancelada...").
        $this->assertNull($this->firestore->getSession(self::CHAT_ID), 'precondição: sem sessão');

        $bot = $this->makeBotMock();
        (new CancelarHandler)($bot);

        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
        $this->assertStringContainsString('Nenhuma operação em andamento', $this->messenger->sentTexts[self::CHAT_ID][0]['text']);
        $this->assertSame(0, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }

    public function test_cancelar_in_idle_state_field_shows_nothing_to_cancel(): void
    {
        // CT-026a (variante): sessão existe com state=idle → mesmo comportamento.
        $this->seedSession(ConversationState::IDLE->value);

        $bot = $this->makeBotMock();
        (new CancelarHandler)($bot);

        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
        $this->assertStringContainsString('Nenhuma operação em andamento', $this->messenger->sentTexts[self::CHAT_ID][0]['text']);
        $this->assertSame(0, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }

    public function test_cancelar_in_awaiting_data_clears_session(): void
    {
        // CT-026b: AWAITING_DATA → clearSession + notifyCancelled (comportamento legado).
        $this->seedSession(ConversationState::AWAITING_DATA->value, [
            'awaiting_field' => 'amount',
            'draft' => ['description' => 'Almoço'],
        ]);

        $bot = $this->makeBotMock();
        (new CancelarHandler)($bot);

        $this->assertNull(
            $this->firestore->getSession(self::CHAT_ID),
            'Sessão AWAITING_DATA deve ser limpa após /cancelar (CT-026b)',
        );
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }

    public function test_cancelar_in_awaiting_confirmation_clears_session(): void
    {
        // CT-026c: AWAITING_CONFIRMATION → clearSession + notifyCancelled.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, [
            'message_id_confirm' => 5001,
            'source' => 'text',
            'draft' => [
                'description' => 'Cinema',
                'amount' => 35.0,
                'type' => 'expense',
            ],
        ]);

        $bot = $this->makeBotMock();
        (new CancelarHandler)($bot);

        $this->assertNull(
            $this->firestore->getSession(self::CHAT_ID),
            'Sessão AWAITING_CONFIRMATION deve ser limpa após /cancelar (CT-026c)',
        );
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }

    public function test_cancelar_in_awaiting_edition_clears_session(): void
    {
        // CT-026d: AWAITING_EDITION → clearSession + notifyCancelled.
        $this->seedSession(ConversationState::AWAITING_EDITION->value, [
            'awaiting_field' => 'amount',
            'draft' => [
                'description' => 'Farmácia',
                'amount' => 100.0,
                'type' => 'expense',
            ],
        ]);

        $bot = $this->makeBotMock();
        (new CancelarHandler)($bot);

        $this->assertNull(
            $this->firestore->getSession(self::CHAT_ID),
            'Sessão AWAITING_EDITION deve ser limpa após /cancelar (CT-026d)',
        );
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }

    public function test_cancelar_during_wizard_clears_session(): void
    {
        // CT-026e: durante wizard /nova (state=AWAITING_DATA, draft com
        // _wizard_step) → clearSession + notifyCancelled.
        $this->seedSession(ConversationState::AWAITING_DATA->value, [
            'awaiting_field' => 'amount',
            'source' => 'wizard',
            'draft' => [
                '_wizard_step' => 2,
                '_wizard_active' => true,
                'type' => 'expense',
            ],
        ]);

        $bot = $this->makeBotMock();
        (new CancelarHandler)($bot);

        $this->assertNull(
            $this->firestore->getSession(self::CHAT_ID),
            'Sessão de wizard deve ser limpa após /cancelar (CT-026e)',
        );
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }
}
