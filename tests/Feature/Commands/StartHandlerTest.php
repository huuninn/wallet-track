<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Bot\Handlers\StartHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Dto\SessionData;
use App\Enums\ConversationState;
use App\Services\Store\WalletStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes do {@see StartHandler} (M9.1 / T-001 / GAP-01).
 *
 * Garante que `/start` reseta a sessão em qualquer estado (CT-023a, CT-023b,
 * CT-023c, CT-023f) e mantém a idempotência em IDLE (CT-023e).
 *
 * Padrão de teste: `WalletStore` bindado no container + `InMemoryBotMessenger`
 * como `BotMessenger` (captura `sendMessage` indireto) + mock leve do
 * `Nutgram` que devolve um `Message` pré-configurado.
 */
#[CoversClass(StartHandler::class)]
class StartHandlerTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private const string CHAT_ID = '12345';

    private WalletStore $store;

    private InMemoryBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->messenger = new InMemoryBotMessenger;

        $this->bindStoreToContainer();
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
        $bot->shouldReceive('sendMessage')->once()->andReturnUsing(
            function (string $text, ?string $parse_mode = null) {
                $this->messenger->sendText(self::CHAT_ID, $text);

                return null;
            }
        );

        return $bot;
    }

    /**
     * Pré-popula a sessão do chat em um estado arbitrário.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedSession(string $state, array $overrides = []): void
    {
        $draft = $overrides['draft'] ?? null;
        $messageIdConfirm = $overrides['message_id_confirm'] ?? null;
        $messageIdEditPicker = $overrides['message_id_edit_picker'] ?? null;
        $messageIdAskEdition = $overrides['message_id_ask_edition'] ?? null;
        $source = $overrides['source'] ?? null;
        $awaitingField = $overrides['awaiting_field'] ?? null;

        $this->store->setSession(self::CHAT_ID, new SessionData(
            state: $state,
            draft: $draft,
            awaitingField: $awaitingField,
            messageIdConfirm: $messageIdConfirm,
            messageIdEditPicker: $messageIdEditPicker,
            messageIdAskEdition: $messageIdAskEdition,
            source: $source,
        ));
    }

    #[Group('smoke')]
    public function test_start_in_idle_sends_welcome_and_keeps_no_session(): void
    {
        // CT-023: /start em IDLE — nenhuma sessão criada, boas-vindas enviadas.
        $bot = $this->makeBotMock();

        (new StartHandler)($bot);

        $this->assertNull($this->store->getSession(self::CHAT_ID));
        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
        $this->assertStringContainsString('Olá! Sou o Wallet Track', $this->messenger->sentTexts[self::CHAT_ID][0]['text']);
    }

    public function test_start_in_awaiting_data_clears_session_and_sends_welcome(): void
    {
        // CT-023a: /start em AWAITING_DATA limpa sessão e envia boas-vindas.
        $this->seedSession(ConversationState::AWAITING_DATA->value, [
            'awaiting_field' => 'amount',
            'draft' => ['description' => 'Almoço'],
        ]);

        $bot = $this->makeBotMock();
        (new StartHandler)($bot);

        $this->assertNull(
            $this->store->getSession(self::CHAT_ID),
            'Sessão em AWAITING_DATA deve ser limpa após /start (CT-023a)',
        );
        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
    }

    public function test_start_in_awaiting_confirmation_clears_session(): void
    {
        // CT-023b: /start em AWAITING_CONFIRMATION descarta draft pendente.
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
        (new StartHandler)($bot);

        $this->assertNull(
            $this->store->getSession(self::CHAT_ID),
            'Sessão em AWAITING_CONFIRMATION deve ser limpa após /start (CT-023b)',
        );
    }

    public function test_start_in_awaiting_edition_clears_session(): void
    {
        // CT-023c: /start em AWAITING_EDITION descarta edição em andamento.
        $this->seedSession(ConversationState::AWAITING_EDITION->value, [
            'awaiting_field' => 'amount',
            'draft' => ['description' => 'Farmácia', 'amount' => 100.0, 'type' => 'expense'],
        ]);

        $bot = $this->makeBotMock();
        (new StartHandler)($bot);

        $this->assertNull(
            $this->store->getSession(self::CHAT_ID),
            'Sessão em AWAITING_EDITION deve ser limpa após /start (CT-023c)',
        );
    }

    public function test_start_idempotent_two_invocations(): void
    {
        // CT-023e: /start idempotente — duas invocações = mesmo resultado, sem erro.
        $bot1 = $this->makeBotMock();
        (new StartHandler)($bot1);

        $bot2 = $this->makeBotMock();
        (new StartHandler)($bot2);

        $this->assertNull($this->store->getSession(self::CHAT_ID));
    }

    public function test_start_persisted_session_is_removed(): void
    {
        // CT-023f: /start com sessão ativa remove o documento `sessions/{chat_id}`.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, [
            'draft' => ['description' => 'Mercado', 'amount' => 50.0, 'type' => 'expense'],
            'message_id_confirm' => 5001,
        ]);

        // Confirma persistência ANTES do /start.
        $this->assertNotNull($this->store->getSession(self::CHAT_ID));

        $bot = $this->makeBotMock();
        (new StartHandler)($bot);

        // Após /start, o doc foi removido.
        $this->assertNull(
            $this->store->getSession(self::CHAT_ID),
            'Documento da sessão deve ser removido após /start (CT-023f)',
        );
    }

    public function test_start_removes_keyboards_from_x_and_y(): void
    {
        // Sessão com X (confirm), Y (picker), Z (ask_edition) → /start remove
        // teclados inline de X e Y via SessionMessageCleaner, NÃO deleta nada.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001,
            'source' => 'text',
            'draft' => [
                'description' => 'Cinema',
                'amount' => 35.0,
                'type' => 'expense',
            ],
        ]);

        $bot = $this->makeBotMock();
        (new StartHandler)($bot);

        // NENHUMA mensagem é deletada (R2).
        $deleted = $this->messenger->deletedMessages[self::CHAT_ID] ?? [];
        $this->assertEmpty($deleted, 'Nenhuma mensagem deve ser deletada pelo /start (R2)');

        // Teclados removidos de X e Y (R1).
        $markups = $this->messenger->editedMarkups[self::CHAT_ID] ?? [];
        $editedIds = array_column($markups, 'message_id');
        $this->assertContains(5001, $editedIds, 'X (confirm) deve ter keyboard removido');
        $this->assertContains(6001, $editedIds, 'Y (picker) deve ter keyboard removido');
        $this->assertNotContains(7001, $editedIds, 'Z (ask_edition) NÃO deve ter keyboard removido');
        $this->assertCount(2, $markups, 'Apenas X e Y devem ter keyboard removido');
    }
}
