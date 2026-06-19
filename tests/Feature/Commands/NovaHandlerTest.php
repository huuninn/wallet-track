<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Bot\Handlers\NovaHandler;
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
 * Testes do {@see NovaHandler} (M9.3 / T-018 / T-019).
 *
 * Cobre:
 *  - CT-025 (happy path): /nova em IDLE → configura wizard + pergunta tipo
 *  - CT-025l: /nova durante AWAITING_CONFIRMATION descarta pendente
 *  - CT-025m: /nova com /cancelar no meio (reset wizard)
 *  - Invariantes de sessão: state, awaiting_field, draft._wizard_*, source
 *  - Edge cases: sessão pré-existente em outros estados (limpa + reinicia)
 *  - Compatibilidade com BotLoader (registro do comando)
 *
 * Padrão de teste consistente com {@see StartHandlerTest}
 * e {@see CancelarHandlerTest}: `InMemoryFirestoreGateway`
 * bindado no container + `InMemoryBotMessenger` para captura + mock leve
 * do `Nutgram` com `message()` pré-configurado.
 */
#[CoversClass(NovaHandler::class)]
class NovaHandlerTest extends TestCase
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

    public function test_nova_in_idle_configures_wizard_session(): void
    {
        // CT-025 (parte da inicialização): /nova em IDLE → sessão wizard
        // configurada com state=AWAITING_DATA, awaiting_field='type', e
        // draft com _wizard_step=1 + _wizard_active=true.
        $this->assertNull($this->firestore->getSession(self::CHAT_ID), 'precondição: IDLE');

        $bot = $this->makeBotMock();
        (new NovaHandler)($bot);

        $session = $this->firestore->getSession(self::CHAT_ID);
        $this->assertNotNull($session, 'Sessão wizard deve ser criada');
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame('type', $session['awaiting_field']);
        $this->assertSame(1, $session['draft']['_wizard_step']);
        $this->assertTrue($session['draft']['_wizard_active']);
        $this->assertSame('wizard', $session['source']);
        $this->assertSame(0, $session['retry_count']);
    }

    public function test_nova_sends_first_question_for_type(): void
    {
        // A primeira pergunta do wizard é o tipo — com botões visuais
        // (apenas texto, mas com formatação rica PT-BR).
        $bot = $this->makeBotMock();
        (new NovaHandler)($bot);

        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $fieldAsks);
        $this->assertSame('type', $fieldAsks[0]['field']);
        $this->assertStringContainsString('Etapa 1/6', $fieldAsks[0]['prompt']);
        $this->assertStringContainsString('Tipo', $fieldAsks[0]['prompt']);
        $this->assertStringContainsString('Despesa', $fieldAsks[0]['prompt']);
        $this->assertStringContainsString('Receita', $fieldAsks[0]['prompt']);
    }

    public function test_nova_in_awaiting_confirmation_clears_previous_session(): void
    {
        // CT-025l: /nova durante AWAITING_CONFIRMATION → descarta
        // transação pendente e inicia wizard do zero.
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
        (new NovaHandler)($bot);

        $session = $this->firestore->getSession(self::CHAT_ID);
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame(1, $session['draft']['_wizard_step']);
        $this->assertTrue($session['draft']['_wizard_active']);
        // Draft da transação anterior foi descartado.
        $this->assertArrayNotHasKey('amount', $session['draft']);
        $this->assertArrayNotHasKey('type', $session['draft']);
        // message_id_confirm antigo foi sobrescrito.
        $this->assertNull($session['message_id_confirm'] ?? null);
    }

    public function test_nova_in_awaiting_confirmation_notifies_discarded(): void
    {
        // CT-025l: notificação de "transação descartada" enviada antes da
        // etapa 1 do wizard.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, [
            'draft' => ['description' => 'Cinema', 'amount' => 35.0, 'type' => 'expense'],
        ]);

        $bot = $this->makeBotMock();
        (new NovaHandler)($bot);

        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $this->assertGreaterThanOrEqual(1, count($sentTexts));
        // A PRIMEIRA mensagem é o aviso de descarte.
        $this->assertStringContainsString('descartada', $sentTexts[0]['text']);
        $this->assertStringContainsString('pendente', $sentTexts[0]['text']);
    }

    public function test_nova_in_awaiting_data_resets_wizard(): void
    {
        // CT-025m: /nova com wizard em andamento (state=AWAITING_DATA,
        // source=wizard) → reseta para o início (step 1).
        $this->seedSession(ConversationState::AWAITING_DATA->value, [
            'awaiting_field' => 'amount',
            'source' => 'wizard',
            'draft' => [
                '_wizard_step' => 3,
                '_wizard_active' => true,
                'type' => 'expense',
            ],
        ]);

        $bot = $this->makeBotMock();
        (new NovaHandler)($bot);

        $session = $this->firestore->getSession(self::CHAT_ID);
        $this->assertSame(1, $session['draft']['_wizard_step'], 'wizard deve voltar para step 1');
        $this->assertTrue($session['draft']['_wizard_active']);
        $this->assertSame('type', $session['awaiting_field']);
    }

    public function test_nova_in_awaiting_edition_resets_session(): void
    {
        // Edge: /nova durante AWAITING_EDITION → limpa sessão, inicia wizard.
        $this->seedSession(ConversationState::AWAITING_EDITION->value, [
            'awaiting_field' => 'amount',
            'draft' => ['amount' => 100.0, 'type' => 'expense', 'description' => 'X'],
        ]);

        $bot = $this->makeBotMock();
        (new NovaHandler)($bot);

        $session = $this->firestore->getSession(self::CHAT_ID);
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame(1, $session['draft']['_wizard_step']);
    }

    public function test_nova_step1_prompt_constant_has_required_elements(): void
    {
        // Constante exposta para validação isolada em outros testes / smoke.
        $prompt = NovaHandler::STEP_1_PROMPT;
        $this->assertStringContainsString('Etapa 1/6', $prompt);
        $this->assertStringContainsString('Tipo', $prompt);
        $this->assertStringContainsString('Despesa', $prompt);
        $this->assertStringContainsString('Receita', $prompt);
    }

    public function test_nova_in_idle_does_not_notify_discard(): void
    {
        // Em IDLE, NÃO deve enviar aviso de descarte (não há nada para
        // descartar). Apenas inicia o wizard.
        $this->assertNull($this->firestore->getSession(self::CHAT_ID));

        $bot = $this->makeBotMock();
        (new NovaHandler)($bot);

        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        // Apenas 1 mensagem (a pergunta do wizard), não 2.
        $this->assertCount(1, $sentTexts);
        $this->assertStringNotContainsString('descartada', $sentTexts[0]['text']);
    }

    public function test_nova_can_be_invoked_twice_idempotently(): void
    {
        // /nova duas vezes → mesmo estado final (wizard reinicia).
        $bot1 = $this->makeBotMock();
        (new NovaHandler)($bot1);
        $session1 = $this->firestore->getSession(self::CHAT_ID);
        $this->assertSame(1, $session1['draft']['_wizard_step']);

        $bot2 = $this->makeBotMock();
        (new NovaHandler)($bot2);
        $session2 = $this->firestore->getSession(self::CHAT_ID);
        $this->assertSame(1, $session2['draft']['_wizard_step']);
        $this->assertSame('type', $session2['awaiting_field']);
    }
}
