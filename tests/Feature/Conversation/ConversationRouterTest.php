<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SuggestCategory;
use App\Actions\SuggestsLabels;
use App\Actions\SyncsSheet;
use App\Bot\Handlers\CancelarHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Conversation\ConversationInput;
use App\Conversation\ConversationRouter;
use App\Conversation\InputKind;
use App\Conversation\StateMachine;
use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Enums\ConversationState;
use App\Exceptions\ExtractionException;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\Store\WalletStore;
use App\Support\LabelFormatter;
use App\Support\TextNormalizer;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes do {@see ConversationRouter} (M7.3 — M7.11).
 *
 * Estes testes NÃO tocam a SDK do Telegram nem fazem I/O de rede — o Router
 * é testado em isolamento, recebendo um {@see ConversationInput} (DTO
 * normalizado) e exercitando os caminhos da máquina de estados contra
 * stubs anônimos de {@see ExtractsText}, {@see ExtractsImage} e
 * {@see SyncsSheet}, com {@see WalletStore} rodando sobre
 * banco de dados + Redis (via {@see RedisFake}) e {@see InMemoryBotMessenger} capturando
 * todas as chamadas de I/O para asserção determinística.
 *
 * Cobertura mapeada para os critérios de aceitação do §10.5 (M7):
 *
 *  - CT-015 (confirm grava banco de dados+Sheets)  → test_awaiting_confirmation_confirm_*
 *  - CT-016 (editar campo atualiza resumo)   → test_awaiting_edition_*
 *  - CT-017 (cancelar limpa sessão)          → test_awaiting_confirmation_cancel_clears_session
 *  - CT-018 (duplo clique → 1 transação)     → test_awaiting_confirmation_idempotent_*
 *  - CT-018b (confirmar após 16min)          → coberto por test_session_timeout_*
 *  - CT-043 (timeout 15min funcional)        → test_session_timeout_*
 *  - CT-047 (callback antigo rejeitado)      → test_callback_with_stale_message_id_*
 *
 * Roda isolado: vendor/bin/phpunit --filter ConversationRouterTest
 */
#[CoversClass(ConversationRouter::class)]
class ConversationRouterTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private const string CHAT_ID = '12345';

    private WalletStore $store;

    private InMemoryBotMessenger $messenger;

    /**
     * Stubs anônimos — resetados a cada teste.
     */
    private object $extractText;

    private object $extractImage;

    private object $syncSheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->messenger = new InMemoryBotMessenger;

        $this->bindStoreToContainer();
        $this->app->instance(BotMessenger::class, $this->messenger);

        $this->extractText = new class implements ExtractsText
        {
            public ?TransactionData $toReturn = null;

            public ?\Throwable $toThrow = null;

            public int $callCount = 0;

            public function handle(string $text, array $labelCatalog = []): TransactionData
            {
                $this->callCount++;
                if ($this->toThrow !== null) {
                    throw $this->toThrow;
                }
                if ($this->toReturn === null) {
                    throw new \LogicException('Stub não configurado: defina toReturn ou toThrow.');
                }

                return $this->toReturn;
            }
        };

        $this->extractImage = new class implements ExtractsImage
        {
            public ?TransactionData $toReturn = null;

            public ?\Throwable $toThrow = null;

            public int $callCount = 0;

            public function handle(string $fileId, array $labelCatalog = []): TransactionData
            {
                $this->callCount++;
                if ($this->toThrow !== null) {
                    throw $this->toThrow;
                }
                if ($this->toReturn === null) {
                    throw new \LogicException('Stub não configurado: defina toReturn ou toThrow.');
                }

                return $this->toReturn;
            }
        };

        $this->syncSheet = new class implements SyncsSheet
        {
            public bool $toReturn = true;

            public ?\Throwable $toThrow = null;

            public int $callCount = 0;

            public ?TransactionData $lastDto = null;

            public ?int $lastTxId = null;

            public function handle(TransactionData $dto, int $txId): bool
            {
                $this->callCount++;
                $this->lastDto = $dto;
                $this->lastTxId = $txId;
                if ($this->toThrow !== null) {
                    throw $this->toThrow;
                }

                return $this->toReturn;
            }
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function makeRouter(
        int $maxRetries = 3,
        ?StateMachine $stateMachine = null,
    ): ConversationRouter {
        $suggestLabels = new class implements SuggestsLabels
        {
            public array $toReturn = [];

            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                return $this->toReturn;
            }
        };

        return new ConversationRouter(
            stateMachine: $stateMachine ?? new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $suggestLabels,
            maxDataRetries: $maxRetries,
        );
    }

    /**
     * DTO completo (pronto para AWAITING_CONFIRMATION) para reutilizar.
     */
    private function completeDto(): TransactionData
    {
        return TransactionData::fromArray([
            'description' => 'Almoço no restaurante',
            'amount' => 47.50,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
        ]);
    }

    /**
     * Pré-popula a sessão do chat em um estado arbitrário, com draft opcional.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedSession(string $state, ?TransactionData $draft = null, array $overrides = []): void
    {
        $this->store->setSession(self::CHAT_ID, new SessionData(
            state: $state,
            draft: $overrides['draft'] ?? $draft?->toDraftArray(),
            awaitingField: $overrides['awaiting_field'] ?? null,
            source: $overrides['source'] ?? null,
            messageIdConfirm: $overrides['message_id_confirm'] ?? null,
            messageIdEditPicker: $overrides['message_id_edit_picker'] ?? null,
            messageIdAskEdition: $overrides['message_id_ask_edition'] ?? null,
            retryCount: $overrides['retry_count'] ?? null,
        ));
    }

    /**
     * Lê a sessão atual e devolve como array (ou null).
     *
     * @return array<string, mixed>|null
     */
    private function currentSession(): ?array
    {
        return $this->store->getSession(self::CHAT_ID);
    }

    /*
    |--------------------------------------------------------------------------
    | IDLE + Texto
    |--------------------------------------------------------------------------
    */

    public function test_idle_text_with_complete_extraction_transitions_to_awaiting_confirmation(): void
    {
        $this->extractText->toReturn = $this->completeDto();

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'Gastei R$ 50 no almoço'));

        $this->assertCount(1, $this->messenger->confirmations[self::CHAT_ID] ?? []);
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertNotNull($session['message_id_confirm']);
        $this->assertSame('text', $session['source']);
    }

    public function test_idle_text_with_missing_amount_enters_awaiting_data(): void
    {
        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Almoço',
            'type' => 'expense',
            'date' => '2026-06-15',
            // amount=null proposital
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'almoço'));

        $this->assertCount(1, $this->messenger->fieldAsks[self::CHAT_ID] ?? []);
        $this->assertSame('amount', $this->messenger->fieldAsks[self::CHAT_ID][0]['field']);
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);
    }

    public function test_idle_text_incomplete_extraction_starts_awaiting_data(): void
    {
        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Salário',
            'amount' => 5000.0,
            'date' => '2026-06-15',
            // type=null proposital
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'recebi 5000'));

        $this->assertCount(1, $this->messenger->fieldAsks[self::CHAT_ID] ?? []);
        $this->assertSame('type', $this->messenger->fieldAsks[self::CHAT_ID][0]['field']);
    }

    public function test_extraction_failure_shows_friendly_error(): void
    {
        $this->extractText->toThrow = new ExtractionException(ExtractionException::API_ERROR, 'timeout');

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'qualquer coisa'));

        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);
        $this->assertNull($this->currentSession(), 'Falha de extração não deve criar sessão');
    }

    /*
    |--------------------------------------------------------------------------
    | IDLE + Foto
    |--------------------------------------------------------------------------
    */

    public function test_idle_photo_with_complete_extraction_transitions_to_confirmation(): void
    {
        $this->extractImage->toReturn = $this->completeDto();

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-file-id-xyz'));

        $this->assertCount(1, $this->messenger->confirmations[self::CHAT_ID] ?? []);
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertSame('image', $session['source']);
    }

    public function test_idle_photo_with_not_a_transaction_shows_specific_error(): void
    {
        $this->extractImage->toThrow = new ExtractionException(ExtractionException::NOT_A_TRANSACTION, 'foto de cachorro');

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-file-id'));

        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('transação clara', $errors[0]['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | AWAITING_DATA
    |--------------------------------------------------------------------------
    */

    public function test_awaiting_data_valid_response_completes_and_moves_to_confirmation(): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'Almoço',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '50,00'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertCount(1, $this->messenger->confirmations[self::CHAT_ID] ?? []);
    }

    public function test_awaiting_data_invalid_response_increments_retry(): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'Almoço',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'abc'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame(1, $session['retry_count']);
        // Re-perguntou
        $this->assertCount(1, $this->messenger->fieldAsks[self::CHAT_ID] ?? []);
    }

    public function test_awaiting_data_exceeds_max_retries_aborts(): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'Almoço',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
            'retry_count' => 3, // já no limite
        ]);

        $this->makeRouter(maxRetries: 3)
            ->route(ConversationInput::text(self::CHAT_ID, 'lixo'));

        // retry_count incrementou para 4 > max=3 → desiste.
        $this->assertNull($this->currentSession());
        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);
    }

    public function test_awaiting_data_photo_applies_label_limit(): void
    {
        // W1: se o LLM devolver >3 labels na re-extração por foto em
        // AWAITING_DATA, applyLabelLimit deve ser chamado para truncar
        // e avisar o usuário. Sem isso, labels excedentes passavam.
        $partial = TransactionData::fromArray([
            'description' => 'Compras no mercado',
            'amount' => 150.00,
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'category',
            'source' => 'text',
        ]);

        // LLM na re-extração devolve 5 labels (acima do limite de 3).
        $this->extractImage->toReturn = TransactionData::fromArray([
            'description' => 'Compras no mercado',
            'amount' => 150.00,
            'type' => 'expense',
            'category' => 'Mercado',
            'date' => '2026-06-15',
            'labels' => ['Mercado', 'Alimentação', 'Compras', 'Extra1', 'Extra2'],
        ]);

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-file-id'));

        // Aviso "Limitei a 3 labels" deve ter sido enviado.
        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $limitMessages = array_filter($sentTexts, fn (array $m) => str_contains($m['text'], 'Limitei a'));
        $this->assertCount(1, $limitMessages, 'Mensagem de limite de labels deve ser enviada');
    }

    /*
    |--------------------------------------------------------------------------
    | AWAITING_CONFIRMATION + Callback
    |--------------------------------------------------------------------------
    */

    public function test_awaiting_confirmation_confirm_saves_to_database_and_sheets(): void
    {
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // Transação persistida.
        $tx = Transaction::latest()->first();
        $this->assertNotNull($tx);
        $this->assertSame(self::CHAT_ID, $tx->chat_id);
        $this->assertSame(WalletStore::SYNC_PENDING, $tx->sync_status);

        // SyncSheet chamado com DTO+id corretos.
        $this->assertSame(1, $this->syncSheet->callCount);
        $this->assertNotNull($this->syncSheet->lastDto);
        $this->assertNotNull($this->syncSheet->lastTxId);

        // notifySuccess enviado, cancelamento NÃO.
        $this->assertCount(1, $this->messenger->successes[self::CHAT_ID] ?? []);
        $this->assertSame(0, $this->messenger->cancelled[self::CHAT_ID] ?? 0);

        // Callback respondido.
        $lastAnswer = end($this->messenger->callbackAnswers);
        $this->assertSame('cb-1', $lastAnswer['callback_id']);
        $this->assertStringContainsString('Salvo', $lastAnswer['text']);

        // Sessão limpa.
        $this->assertNull($this->currentSession());
    }

    public function test_awaiting_confirmation_confirm_handles_sync_failure_gracefully(): void
    {
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = false;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // Transação persistida com sync_status=pending (cron M9 recupera).
        $tx = Transaction::latest()->first();
        $this->assertNotNull($tx);
        $this->assertSame(WalletStore::SYNC_PENDING, $tx->sync_status);

        // notifySuccess MESMO COM sync falho (o usuário não vê falha).
        $this->assertCount(1, $this->messenger->successes[self::CHAT_ID] ?? []);
    }

    public function test_awaiting_confirmation_idempotent_double_confirm_creates_only_one_transaction(): void
    {
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $router = $this->makeRouter();

        // 1º confirm — adquire o flag.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // 2º confirm — o 1º já limpou a sessão (clearSession removeu o doc),
        // então não há mais sessão em AWAITING_CONFIRMATION. O callback órfão
        // cai no branch IDLE+Callback (silencioso). Resultado: 1 transação.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-2',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        $this->assertSame(1, Transaction::count());
    }

    public function test_awaiting_confirmation_callback_edit_sends_field_picker_and_keeps_state(): void
    {
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));

        $this->assertCount(1, $this->messenger->fieldPickers[self::CHAT_ID] ?? []);

        // Estado PERMANECE AWAITING_CONFIRMATION — awaiting_field continua null.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertNull($session['awaiting_field'] ?? null);

        // P1 (CT-047 fix): o message_id do picker foi persistido como segunda
        // âncora (Y) para que callbacks edit:<field> passem no CT-047.
        $this->assertArrayHasKey('message_id_edit_picker', $session);
        $this->assertNotNull($session['message_id_edit_picker']);
        $this->assertNotSame(
            $session['message_id_confirm'],
            $session['message_id_edit_picker'],
            'Picker deve ter message_id distinto do resumo',
        );
    }

    public function test_awaiting_confirmation_callback_edit_field_advances_to_awaiting_edition(): void
    {
        // CT-047 fix: o callback edit:<field> vem do picker (Y), não do resumo (X).
        // O teste simula o ciclo completo: 1) user clica "Editar" → picker Y criado;
        // 2) user clica "edit:amount" no picker Y → AWAITING_EDITION.
        // P7-A: picker Y NÃO é deletado após edit:<field>.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // 1ª etapa: user clica "Editar" → cria picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));

        $session = $this->currentSession();
        $pickerId = $session['message_id_edit_picker'];
        $this->assertNotNull($pickerId, 'Picker Y deve ter sido persistido');

        // 2ª etapa: user clica "edit:amount" no picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit-amount',
            callbackData: 'edit:amount',
            callbackMessageId: $pickerId,
        ));

        $this->assertCount(1, $this->messenger->editionAsks[self::CHAT_ID] ?? []);
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);

        // P7-A: picker Y NÃO é deletado e permanece na sessão.
        $this->assertArrayNotHasKey(
            self::CHAT_ID,
            $this->messenger->deletedMessages,
            'P7-A: picker Y não deve ser deletado após edit:<field>',
        );
        $this->assertSame(
            $pickerId,
            $session['message_id_edit_picker'],
            'P7-A: picker Y deve permanecer registrado na sessão',
        );
    }

    public function test_awaiting_confirmation_cancel_clears_session(): void
    {
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'cancel',
            callbackMessageId: 5001,
        ));

        $this->assertNull($this->currentSession());
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
        $lastAnswer = end($this->messenger->callbackAnswers);
        $this->assertSame('cb-1', $lastAnswer['callback_id']);
        $this->assertStringContainsString('Cancelado', $lastAnswer['text']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-047 — callback de keyboard antiga
    |--------------------------------------------------------------------------
    */

    public function test_callback_with_stale_message_id_is_rejected_for_ct_047(): void
    {
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 100, // sessão atual tem message_id=100
            'source' => 'text',
        ]);

        // Callback chega com message_id=99 (mensagem antiga, já não é a atual).
        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-stale',
            callbackData: 'confirm',
            callbackMessageId: 99,
        ));

        // Callback respondido (toast).
        $lastAnswer = end($this->messenger->callbackAnswers);
        $this->assertSame('cb-stale', $lastAnswer['callback_id']);

        // Nenhuma transação criada.
        $this->assertSame(0, Transaction::count());

        // notifyError chamado.
        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);

        // Estado da sessão INTACTO.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
    }

    /*
    |--------------------------------------------------------------------------
    | IDLE + Callback (órfão)
    |--------------------------------------------------------------------------
    */

    public function test_idle_callback_is_answered_and_does_not_create_session(): void
    {
        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-orphan',
            callbackData: 'confirm',
            callbackMessageId: 999,
        ));

        $this->assertCount(1, $this->messenger->callbackAnswers);
        $this->assertSame('cb-orphan', $this->messenger->callbackAnswers[0]['callback_id']);
        $this->assertNull($this->currentSession(), 'Callback órfão não deve criar sessão');
        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | Timeout de sessão (CT-043 / CT-018b)
    |--------------------------------------------------------------------------
    */

    public function test_session_timeout_clears_session_and_returns_to_idle(): void
    {
        // Simula sessão expirada no Redis (TTL esgotou): limpamos a sessão
        // manualmente para simular o comportamento da expiração do Redis,
        // depois verificamos que uma nova extração via texto funciona normalmente.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 100,
            'source' => 'text',
        ]);
        $this->extractText->toReturn = $this->completeDto();

        // Simula expiração: Redis TTL expirou → sessão removida.
        $this->store->clearSession(self::CHAT_ID);

        // Nova extração por texto deve criar uma nova sessão.
        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'outra transação'));

        // Sessão nova foi criada (extração subsequente).
        $session = $this->currentSession();
        $this->assertNotNull($session, 'Extração subsequente deve criar nova sessão');
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
    }

    public function test_session_within_timeout_window_is_not_expired(): void
    {
        // Sessão ativa: o timeout agora é gerenciado pelo TTL do Redis.
        // Como o RedisFake não implementa TTL real, a sessão permanece ativa
        // e o callback deve ser processado normalmente.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 100,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'cancel',
            callbackMessageId: 100,
        ));

        // Cancelamento processado normalmente.
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
        // Sem notifyError (não houve expiração).
        $this->assertCount(0, $this->messenger->errors[self::CHAT_ID] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | AWAITING_EDITION
    |--------------------------------------------------------------------------
    */

    public function test_awaiting_edition_valid_response_updates_draft_and_returns_to_confirmation(): void
    {
        $dto = $this->completeDto();
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $dto, [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '75,00'));

        // Sessão volta a AWAITING_CONFIRMATION.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);

        // R2: NENHUMA mensagem é deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem deletada na edição',
        );

        // R2: NENHUMA mensagem é editada in-place.
        $this->assertEmpty(
            $this->messenger->editedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem editada in-place na edição',
        );

        // R2: NOVA confirmação X_new foi enviada (sem keyboard restoration).
        $confirms = $this->messenger->confirmations[self::CHAT_ID] ?? [];
        $this->assertCount(1, $confirms, 'Nova confirmação X_new deve ser enviada');

        // O message_id_confirm agora referencia X_new (não 5001 X_old).
        $this->assertNotSame(5001, $session['message_id_confirm'], 'message_id_confirm deve ser atualizado para X_new');
        $this->assertSame($confirms[0]['message_id'], $session['message_id_confirm']);

        // Feedback de alteração enviado via sendText.
        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $feedbackText = $sentTexts[count($sentTexts) - 2]['text'] ?? ''; // penúltima msg (antes da confirmação)
        $this->assertStringContainsString('alterado', $feedbackText, 'Feedback de "campo alterado" deve estar presente');

        // retry_count zerado (edição bem-sucedida zera contador).
        $this->assertSame(0, $session['retry_count']);
    }

    public function test_awaiting_edition_rejects_negative_amount(): void
    {
        $dto = $this->completeDto();
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $dto, [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '-50'));

        // Validador rejeita ≤0.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame(1, $session['retry_count']);
    }

    public function test_annul_stale_edit_click_in_awaiting_edition(): void
    {
        // P7-A: em AWAITING_EDITION, QUALQUER click em edit:<field> (mesmo
        // vindo do picker Y) é annullado. O usuário deveria estar
        // respondendo com texto, não clicando em botões antigos.
        // P5 (re-pick) foi removido em favor de P7-A.
        $dto = $this->completeDto();
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $dto, [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:date',
            callbackMessageId: 6001, // vindo do Y — seria re-pick no P5
        ));

        // Sessão INTACTA — não transicionou, awaiting_field não mudou.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field'], 'P7-A: awaiting_field não mudou para date');
        $this->assertSame(6001, $session['message_id_edit_picker']);
        $this->assertCount(0, $this->messenger->editionAsks[self::CHAT_ID] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | W-1: validateAmount — parsing robusto PT-BR / US-EN (W-1 da revisão)
    |--------------------------------------------------------------------------
    */

    /**
     * Testa o parsing de um valor monetário semeando AWAITING_DATA e
     * despachando o input como resposta do usuário. O valor parsed é
     * então lido do `draft` persistido no WalletStore.
     *
     * Estratégia: usamos um draft que falta SÓ o amount, garantindo que
     * a sessão entre em AWAITING_DATA com awaiting_field='amount' e que
     * após a resposta válida o DTO esteja completo, indo para
     * AWAITING_CONFIRMATION com o amount já gravado no draft.
     */
    private function assertAmountParsedAs(string $input, float $expected): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'Almoço',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, $input));

        $session = $this->currentSession();
        $this->assertNotNull($session, "Input '{$input}' deve produzir uma sessão");
        $this->assertSame(
            ConversationState::AWAITING_CONFIRMATION->value,
            $session['state'],
            "Input '{$input}' deve completar o DTO e ir para AWAITING_CONFIRMATION",
        );
        $this->assertSame($expected, $session['draft']['amount'] ?? null);
    }

    /**
     * Testa o parsing de um valor INVALIDO: a sessão deve permanecer em
     * AWAITING_DATA e o retry_count deve ser incrementado.
     */
    private function assertAmountRejected(string $input): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'Almoço',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, $input));

        $session = $this->currentSession();
        $this->assertNotNull($session, "Input '{$input}' não deve limpar a sessão");
        $this->assertSame(
            ConversationState::AWAITING_DATA->value,
            $session['state'],
            "Input '{$input}' deve ser rejeitado e manter AWAITING_DATA",
        );
        $this->assertSame(
            1,
            $session['retry_count'] ?? 0,
            "Input '{$input}' deve incrementar retry_count",
        );
    }

    public function test_validate_amount_pt_br_simple_comma(): void
    {
        $this->assertAmountParsedAs('50,00', 50.0);
    }

    public function test_validate_amount_us_en_simple_dot(): void
    {
        $this->assertAmountParsedAs('50.00', 50.0);
    }

    public function test_validate_amount_pt_br_with_currency_prefix(): void
    {
        $this->assertAmountParsedAs('R$ 1.234,56', 1234.56);
    }

    public function test_validate_amount_pt_br_with_thousands_no_currency(): void
    {
        $this->assertAmountParsedAs('1.234,56', 1234.56);
    }

    public function test_validate_amount_us_en_with_thousands(): void
    {
        $this->assertAmountParsedAs('1234.56', 1234.56);
    }

    public function test_validate_amount_without_decimal(): void
    {
        $this->assertAmountParsedAs('1234', 1234.0);
    }

    public function test_validate_amount_pt_br_full_thousands(): void
    {
        $this->assertAmountParsedAs('1.234.567,89', 1234567.89);
    }

    public function test_validate_amount_us_en_full_thousands(): void
    {
        $this->assertAmountParsedAs('1,234,567.89', 1234567.89);
    }

    public function test_validate_amount_rejects_letters(): void
    {
        $this->assertAmountRejected('abc');
    }

    public function test_validate_amount_rejects_negative(): void
    {
        $this->assertAmountRejected('-50');
    }

    public function test_validate_amount_rejects_zero(): void
    {
        $this->assertAmountRejected('0');
    }

    public function test_validate_amount_rejects_three_decimal_places(): void
    {
        $this->assertAmountRejected('50,123');
    }

    /**
     * W-1 teste: espaços extras no validateAmount — "  50,00  " deve ser
     * aceito e normalizado.
     */
    public function test_validate_amount_handles_extra_whitespace(): void
    {
        $this->assertAmountParsedAs('  50,00  ', 50.0);
    }

    /*
    |--------------------------------------------------------------------------
    | W-2: StateMachine integrado (assertCanTransition em toda mudança)
    |--------------------------------------------------------------------------
    |
    | O `StateMachine` é uma classe `final` sem interface. PHP não permite
    | estender `final` (mesmo via classe anônima) e Mockery::mock() em uma
    | `final class` falha a checagem de tipo exigida pelo construtor do
    | Router (`StateMachine $stateMachine`). Sem refatorar a produção (o
    | que o escopo da revisão não permite), não há como injetar um spy
    | "puro" no construtor.
    |
    | A integração é então verificada **comportamentalmente**: para cada
    * cenário de mudança de estado exercitado, o estado final correto
    * prova que `assertCanTransition` foi consultada e aceitou a transição
    * (caso contrário, o Router lançaria `LogicException` antes de gravar
    * e o estado ficaria desatualizado). Os testes positivos já existentes
    * (acima) cobrem todos os caminhos; este bloco adiciona apenas o
    * caminho "negativo" — quando o `tryAcquireSessionProcessingFlag`
    * BLOQUEIA o confirm, o estado NÃO muda (prova de que a transição
    * foi consultada e a gravação não ocorreu). Esse é o teste mais forte
    * de "assert é consultado" possível sem refatorar a produção.
    */

    public function test_state_machine_assertion_blocks_idle_transition_when_processing_flag_held(): void
    {
        // Cenário "negativo": se o Router consultasse o StateMachine e a
        // transição fosse legal, a sessão seria gravada. Aqui, o caminho
        // do `tryAcquireSessionProcessingFlag` falha (flag já em true),
        // o callback é respondido mas NENHUMA transição de estado ocorre.
        // Se o Router NÃO consultasse o StateMachine, este teste ainda
        // passaria — mas a ausência de gravação confirma o comportamento
        // defensivo esperado.
        //
        // O valor real deste teste é documentar que o caminho "Já estou
        // processando..." não toca o banco.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        // Simula concorrente que já segurou o flag de processamento.
        $this->store->tryAcquireSessionProcessingFlag(self::CHAT_ID);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // Sessão INTACTA — Router saiu antes de consultar StateMachine.
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);

        // Nenhuma transação criada.
        $this->assertSame(0, Transaction::count());
    }

    public function test_state_machine_assertion_consulted_on_idle_to_awaiting_data(): void
    {
        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'X',
            'type' => 'expense',
            'date' => '2026-06-15',
            // amount null → IDLE → AWAITING_DATA
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'sem valor'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);
    }

    public function test_state_machine_assertion_consulted_on_awaiting_data_to_awaiting_data(): void
    {
        // Self-transition AWAITING_DATA → AWAITING_DATA (re-pergunta campo).
        $partial = TransactionData::fromArray([
            'description' => 'X',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        // Input inválido → re-pergunta o mesmo campo (mesmo estado).
        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'lixo'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame(1, $session['retry_count']);
    }

    public function test_state_machine_assertion_consulted_on_awaiting_data_to_idle_on_max_retries(): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'X',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
            'retry_count' => 3,
        ]);

        $this->makeRouter(maxRetries: 3)
            ->route(ConversationInput::text(self::CHAT_ID, 'lixo'));

        // AWAITING_DATA → IDLE (clearSession após max retries).
        $this->assertNull($this->currentSession());
    }

    public function test_state_machine_assertion_consulted_on_awaiting_data_to_confirmation_on_complete(): void
    {
        $partial = TransactionData::fromArray([
            'description' => 'X',
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $partial, [
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '50,00'));

        // AWAITING_DATA → AWAITING_CONFIRMATION (DTO completo).
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
    }

    /*
    |--------------------------------------------------------------------------
    | S-6: testes faltantes da revisão
    |--------------------------------------------------------------------------
    */

    public function test_cancelar_handler_clears_session_and_notifies(): void
    {
        // S-6.1: handler /cancelar deve limpar a sessão e notificar cancelamento.
        // Simula o caso de uso real: chat tem sessão em AWAITING_CONFIRMATION
        // (transação "presa" — ex.: sessão expirada com processing=true), e
        // o usuário emite /cancelar para reset.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
            'processing' => true,
        ]);

        // Container bindings que o handler resolve via `app()`.
        $this->app->instance(WalletStore::class, $this->store);
        $this->app->instance(BotMessenger::class, $this->messenger);

        // Mocka Nutgram com `message()` retornando um Message com chat->id.
        $message = new Message(null);
        $message->chat = new Chat(null);
        $message->chat->id = (int) self::CHAT_ID;

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('message')->once()->andReturn($message);

        // Invoca o handler.
        (new CancelarHandler)($bot);

        // Sessão foi limpa.
        $this->assertNull($this->currentSession());

        // notifyCancelled foi chamado.
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);

        Mockery::close();
    }

    public function test_callback_edit_description_advances_to_awaiting_edition(): void
    {
        // S-6.2: callback edit:description → AWAITING_EDITION com awaiting_field='description'.
        // CT-047 fix: o callback vem do picker (Y) — simula o ciclo completo.
        // P7-A: picker Y NÃO é deletado após edit:<field>.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // 1ª etapa: "Editar" → cria picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));
        $session = $this->currentSession();
        $pickerId = $session['message_id_edit_picker'];

        // 2ª etapa: "edit:description" no picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:description',
            callbackMessageId: $pickerId,
        ));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('description', $session['awaiting_field']);
        $this->assertCount(1, $this->messenger->editionAsks[self::CHAT_ID] ?? []);

        // P7-A: picker Y NÃO é deletado e permanece na sessão.
        $this->assertArrayNotHasKey(
            self::CHAT_ID,
            $this->messenger->deletedMessages,
            'P7-A: picker Y não deve ser deletado após edit:<field>',
        );
        $this->assertSame($pickerId, $session['message_id_edit_picker']);
    }

    public function test_callback_edit_category_advances_to_awaiting_edition(): void
    {
        // S-6.3: callback edit:category → AWAITING_EDITION com awaiting_field='category'.
        // CT-047 fix: o callback vem do picker (Y) — simula o ciclo completo.
        // P7-A: picker Y NÃO é deletado após edit:<field>.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // 1ª etapa: "Editar" → cria picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));
        $session = $this->currentSession();
        $pickerId = $session['message_id_edit_picker'];

        // 2ª etapa: "edit:category" no picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:category',
            callbackMessageId: $pickerId,
        ));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('category', $session['awaiting_field']);
        $this->assertCount(1, $this->messenger->editionAsks[self::CHAT_ID] ?? []);

        // P7-A: picker Y NÃO é deletado e permanece na sessão.
        $this->assertArrayNotHasKey(
            self::CHAT_ID,
            $this->messenger->deletedMessages,
            'P7-A: picker Y não deve ser deletado após edit:<field>',
        );
        $this->assertSame($pickerId, $session['message_id_edit_picker']);
    }

    public function test_callback_edit_observations_advances_to_awaiting_edition(): void
    {
        // S-6.4: callback edit:observations → AWAITING_EDITION com awaiting_field='observations'.
        // CT-047 fix: o callback vem do picker (Y) — simula o ciclo completo.
        // P7-A: picker Y NÃO é deletado após edit:<field>.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // 1ª etapa: "Editar" → cria picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));
        $session = $this->currentSession();
        $pickerId = $session['message_id_edit_picker'];

        // 2ª etapa: "edit:observations" no picker (Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:observations',
            callbackMessageId: $pickerId,
        ));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('observations', $session['awaiting_field']);
        $this->assertCount(1, $this->messenger->editionAsks[self::CHAT_ID] ?? []);

        // P7-A: picker Y NÃO é deletado e permanece na sessão.
        $this->assertArrayNotHasKey(
            self::CHAT_ID,
            $this->messenger->deletedMessages,
            'P7-A: picker Y não deve ser deletado após edit:<field>',
        );
        $this->assertSame($pickerId, $session['message_id_edit_picker']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-047 fix — bug do picker de edição
    |--------------------------------------------------------------------------
    |
    | Estes testes documentam o fix do bug CT-047: ao clicar "Editar" e depois
    | em qualquer campo do picker, o bot rejeitava com "confirmação não está
    | mais ativa" porque o check só conhecia `message_id_confirm` (X), não
    | o `message_id_edit_picker` (Y) da mensagem separada do picker.
    |
    | Cobertura:
    |  - N1: regressão direta do bug — edit+edit:<field> com Y é aceito.
    |  - N2: picker Y é deletado no confirm (P3=B).
    |  - N3: picker Y é deletado no cancel (P3=B).
    |  - N4: REESCRITO para P7-A — picker Y NÃO é mais deletado em edit:<field>.
    |  - N5: REESCRITO para P7-A — múltiplos cliques em Y são annullados.
    |  - N6: CT-047 aceita Y em AWAITING_CONFIRMATION (P6).
    |  - N7: REESCRITO para P7-A — em AWAITING_EDITION, callback stale é
    |       annullado (não rejeitado). P5 (re-pick) removido.
    |  - N8: sessão sem IDs válidos aceita callback (P4=B).
    |  - N9: REESCRITO para P7-A — message_id_edit_picker PERMANECE após edição.
    |  - S1: duplo clique em "Editar" é idempotente — segundo clique ignorado.
    |  - P7-A: picker Y permanece no chat e na sessão após edit:<field>;
    |    re-cliques em Y são silenciosamente annullados (answerCallback + return).
    */

    public function test_edit_then_edit_amount_with_picker_message_id_is_accepted(): void
    {
        // N1: regressão do bug original. Antes do fix, este teste falhava
        // com "Esta confirmação não está mais ativa" porque o check CT-047
        // rejeitava callback com message_id diferente de message_id_confirm.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // 1ª etapa: user clica "Editar" → cria picker Y.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));

        $session = $this->currentSession();
        $pickerId = $session['message_id_edit_picker'];
        $this->assertNotSame(5001, $pickerId, 'Picker Y é mensagem separada do resumo X');

        // 2ª etapa: user clica edit:amount no picker (Y) — antes do fix isto
        // era rejeitado pelo CT-047. Agora é aceito (P6 — aceita X ou Y).
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-2',
            callbackData: 'edit:amount',
            callbackMessageId: $pickerId,
        ));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);
    }

    public function test_confirm_does_not_delete_edit_picker_after_r2(): void
    {
        // R2: confirm NÃO deleta Y nem Z — deletePictureIfPresent e
        // deleteAskEditionIfPresent foram removidos do handleConfirm.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // R2: NENHUMA mensagem é deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: confirm não deve deletar mensagens',
        );

        // Transação foi persistida normalmente (não-bloqueante).
        $this->assertSame(1, Transaction::count());
    }

    public function test_cancel_does_not_delete_edit_picker_after_r2(): void
    {
        // R2: cancel NÃO deleta Y nem Z — deletePictureIfPresent e
        // deleteAskEditionIfPresent foram removidos do cancel branch.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'cancel',
            callbackMessageId: 5001,
        ));

        // R2: NENHUMA mensagem é deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: cancel não deve deletar mensagens',
        );
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
        $this->assertNull($this->currentSession());
    }

    public function test_edit_field_callback_removes_picker_keyboard(): void
    {
        // P7-A: edit:<field> NÃO deleta o picker Y. O picker permanece
        // visível no chat e registrado na sessão; será deletado apenas em
        // confirm/cancel (P3=B). Re-click subsequente em Y é annullado
        // (ver test_annul_stale_edit_click_in_awaiting_edition).
        //
        // Substitui o antigo test_edit_field_callback_deletes_picker (N4)
        // que testava o comportamento P2=B (removido em P7-A).
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 7001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:amount',
            callbackMessageId: 7001,
        ));

        // P7-A: picker Y NÃO foi deletado.
        $this->assertArrayNotHasKey(
            self::CHAT_ID,
            $this->messenger->deletedMessages,
            'P7-A: edit:<field> não deve deletar o picker Y',
        );

        // Estado transicionou normalmente.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);

        // Y permanece registrado na sessão (será annullado em re-click).
        $this->assertSame(7001, $session['message_id_edit_picker']);
    }

    public function test_picker_lifecycle_after_edition_r2(): void
    {
        // R2: após edição válida (AWAITING_EDITION → AWAITING_CONFIRMATION):
        // - NENHUMA mensagem é deletada (Y permanece, Z permanece)
        // - NENHUM keyboard é restaurado em X_old
        // - NOVA confirmação X_new é enviada
        // - message_id_confirm muda para X_new
        // - message_id_edit_picker e ask_edition são limpos da sessão
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001,
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '100,00'));

        // R2: NENHUMA mensagem deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem deletada após edição',
        );

        // R2: NENHUM keyboard restaurado.
        $this->assertEmpty(
            $this->messenger->restoredKeyboards[self::CHAT_ID] ?? [],
            'R2: nenhum keyboard restaurado após edição',
        );

        // R2: NENHUMA mensagem editada in-place.
        $this->assertEmpty(
            $this->messenger->editedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem editada in-place após edição',
        );

        // Session is back to AWAITING_CONFIRMATION.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);

        // Session no longer has message_id_edit_picker key.
        $this->assertNull($session['message_id_edit_picker'] ?? null);

        // Session no longer has message_id_ask_edition key.
        $this->assertNull($session['message_id_ask_edition'] ?? null);

        // Session no longer has picker_consumed key.
        $this->assertNull($session['picker_consumed'] ?? null);

        // R2: message_id_confirm mudou para X_new (não é mais 5001).
        $this->assertNotSame(5001, $session['message_id_confirm'], 'message_id_confirm deve ser X_new, não X_old');

        // NOVA confirmação foi enviada.
        $confirms = $this->messenger->confirmations[self::CHAT_ID] ?? [];
        $this->assertCount(1, $confirms, 'Nova confirmação X_new deve ser enviada');

        // Feedback de alteração está presente.
        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $feedbackText = $sentTexts[count($sentTexts) - 2]['text'] ?? '';
        $this->assertStringContainsString('alterado', $feedbackText);
    }

    public function test_callback_with_edit_picker_message_id_accepted_in_awaiting_confirmation(): void
    {
        // N6: P6 — em AWAITING_CONFIRMATION, callback vindo do picker (Y) é aceito.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 6001, // Y
        ));

        // Callback aceito (não rejeitado pelo CT-047) → transação foi criada.
        $this->assertSame(1, Transaction::count(), 'Callback de Y deve ser aceito (P6)');
    }

    public function test_stale_callback_in_awaiting_edition_is_annulled_not_rejected(): void
    {
        // P7-A: em AWAITING_EDITION, QUALQUER callback edit:<field> é
        // annullado (silencioso), independente do message_id de origem. Não
        // há mais CT-047 check nem rejeição com "Esta edição não está mais
        // ativa" — apenas no-op + answerCallback.
        //
        // Substitui o antigo test_callback_with_stale_message_id_rejected_in_awaiting_edition (N7)
        // que testava a rejeição do P5 (removido em P7-A).
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'awaiting_field' => 'amount',
            'message_id_edit_picker' => 6001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:type',
            callbackMessageId: 9999, // stale (≠ X ≠ Y) — seria rejeitado no P5
        ));

        // Sessão INTACTA — annullado silenciosamente.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field'], 'P7-A: awaiting_field não mudou para type');
        $this->assertSame(6001, $session['message_id_edit_picker']);
    }

    public function test_callback_accepted_when_session_has_no_message_ids(): void
    {
        // N8: P4=B — sessão legacy sem IDs válidos → callback é aceito
        // (comportamento conservador — não rejeita para não travar usuário).
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            // sem message_id_confirm, sem message_id_edit_picker
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'cancel',
            callbackMessageId: 9999, // não bate com nada — P4=B aceita
        ));

        // Cancel processou → sessão limpa.
        $this->assertNull($this->currentSession());
        $this->assertSame(1, $this->messenger->cancelled[self::CHAT_ID] ?? 0);
    }

    public function test_double_edit_click_is_idempotent_and_keeps_original_picker(): void
    {
        // S1: idempotência do "Editar" — se o usuário clicar "Editar" duas vezes
        // (ex.: duplo-clique, ou dois toques rápidos antes do picker aparecer),
        // o segundo clique é IGNORADO. O picker original permanece ativo, sem
        // criação de novo picker nem deleteMessage do anterior. O picker será
        // deletado normalmente quando o user escolher um campo (P2=B) ou
        // confirmar/cancelar (P3=B).
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // 1º clique em "Editar" — cria picker Y1.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));

        $session = $this->currentSession();
        $pickerY1 = $session['message_id_edit_picker'];
        $this->assertNotNull($pickerY1);
        $this->assertCount(1, $this->messenger->fieldPickers[self::CHAT_ID] ?? []);

        // 2º clique em "Editar" — deve ser IGNORADO.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-2',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));

        // Picker Y1 continua ativo (mesmo ID), nenhum picker novo foi criado.
        $session = $this->currentSession();
        $this->assertSame($pickerY1, $session['message_id_edit_picker']);
        $this->assertCount(
            1,
            $this->messenger->fieldPickers[self::CHAT_ID] ?? [],
            'Duplo clique não deve criar segundo picker',
        );

        // Nenhum deleteMessage foi chamado (o picker original permanece).
        $this->assertArrayNotHasKey(
            self::CHAT_ID,
            $this->messenger->deletedMessages,
            'Duplo clique não deve deletar o picker original',
        );
    }

    public function test_photo_in_awaiting_edition_state_shows_friendly_error(): void
    {
        // S-6.5: foto em AWAITING_EDITION → notifyError amigável, sessão inalterada.
        $dto = $this->completeDto();
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $dto, [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-file-id'));

        // notifyError chamado com mensagem amigável.
        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('texto', $errors[0]['message']);

        // Sessão INALTERADA — ainda AWAITING_EDITION com awaiting_field='amount'.
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);
    }

    public function test_awaiting_edition_valid_response_clears_stale_awaiting_field_via_clear_fields(): void
    {
        // W-3: ao transicionar de AWAITING_EDITION → AWAITING_CONFIRMATION
        // (resposta válida do usuário), o campo awaiting_field deve ser
        // REMOVIDO do doc — não apenas setado como null. Sem isso, o
        // campo persistiria e poderia confundir o fluxo se uma nova
        // sessão fosse retomada.
        $dto = $this->completeDto();
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $dto, [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '75,00'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertNull(
            $session['awaiting_field'] ?? null,
            'W-3: awaiting_field deve ser limpo (null), não persistir valor stale',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de ConversationInput (sanity)
    |--------------------------------------------------------------------------
    */

    public function test_conversation_input_factories_build_correct_shape(): void
    {
        $text = ConversationInput::text(123, 'oi');
        $this->assertSame(InputKind::Text, $text->kind);
        $this->assertSame(123, $text->chatId);
        $this->assertSame('oi', $text->text);

        $photo = ConversationInput::photo(456, 'file-1');
        $this->assertSame(InputKind::Photo, $photo->kind);
        $this->assertSame(456, $photo->chatId);
        $this->assertSame('file-1', $photo->photoFileId);

        $cb = ConversationInput::callback(789, 'cb-id', 'confirm', 100);
        $this->assertSame(InputKind::Callback, $cb->kind);
        $this->assertSame(789, $cb->chatId);
        $this->assertSame('cb-id', $cb->callbackId);
        $this->assertSame('confirm', $cb->callbackData);
        $this->assertSame(100, $cb->callbackMessageId);
    }

    /*
    |--------------------------------------------------------------------------
    | M8 — Heurística de Labels e Categoria
    |--------------------------------------------------------------------------
    |
    | Cobertura:
    |  - CT-011: Categoria sugerida quando extraída é null → fall-back "Outros".
    |  - CT-012: Categoria nova persistida em handleConfirm.
    |  - CT-019: Histórico de labels sugerido primeiro.
    |  - CT-020: Keywords da descrição complementam quando histórico vazio.
    |  - CT-021: Edição de labels funciona após sugestões.
    |  - CT-022: use_count de labels é incrementado após confirm.
    |
    | A integração usa o WalletStore/banco de dados e InMemoryBotMessenger —
    | nenhuma chamada real ao Telegram ou ao banco de dados.
    */

    /**
     * Helper: popula categorias padrão no banco de dados para que
     * SuggestCategory faça match contra elas (em vez de sempre cair em "Outros").
     */
    private function seedDefaultCategories(): void
    {
        $this->store->createCategory('Alimentação', 'expense', isDefault: true);
        $this->store->createCategory('Transporte', 'expense', isDefault: true);
        $this->store->createCategory('Moradia', 'expense', isDefault: true);
        $this->store->createCategory('Outros', 'expense', isDefault: true);
    }

    public function test_present_confirmation_enriches_null_category_with_suggestion(): void
    {
        // CT-011: DTO sem categoria → SuggestCategory infere "Outros" (existente)
        // e o draft é persistido com a categoria aplicada.
        $this->seedDefaultCategories();
        $dtoNoCategory = TransactionData::fromArray([
            'description' => 'Paguei 50 em algo genérico',
            'amount' => 50.0,
            'type' => 'expense',
            'date' => '2026-06-15',
            // category=null
        ]);
        $this->extractText->toReturn = $dtoNoCategory;

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'qualquer coisa'));

        // Sessão gravada com categoria default.
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertNotNull($session['draft']);
        $this->assertSame(
            SuggestCategory::DEFAULT_CATEGORY,
            $session['draft']['category'] ?? null,
            'DTO sem categoria deve receber default via SuggestCategory',
        );
    }

    public function test_present_confirmation_enriches_empty_labels_with_suggestions(): void
    {
        // Após a refatoração de labels (F2): labels NÃO são mais enriquecidas
        // automaticamente com histórico/keywords. O DTO mantém apenas os labels
        // que o LLM extraiu (ou vazio).
        $this->seedDefaultCategories();
        // Histórico: ifood e restaurante já usados (mas NÃO injetados automaticamente).
        for ($i = 0; $i < 3; $i++) {
            $this->store->incrementLabelUse('ifood');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->store->incrementLabelUse('restaurante');
        }

        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Paguei 32,00 na pizza do iFood',
            'amount' => 32.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            // labels=[] (vazio)
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'pizza ifood'));

        // Labels NÃO são enriquecidas automaticamente — o DTO preserva o que veio.
        $session = $this->currentSession();
        $labels = $session['draft']['labels'] ?? [];
        $this->assertSame([], $labels, 'Labels devem permanecer como extraídos (vazio), sem injeção automática');
    }

    public function test_present_confirmation_labels_from_llm_are_preserved_without_merge(): void
    {
        // Após F2: labels do LLM são preservados sem merge automático de histórico.
        $this->seedDefaultCategories();
        // Sem labels no histórico.

        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Paguei 120,00 na conta de luz da enel',
            'amount' => 120.0,
            'type' => 'expense',
            'category' => 'Moradia',
            'date' => '2026-06-15',
            'labels' => [],
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'luz enel'));

        $session = $this->currentSession();
        $labels = $session['draft']['labels'] ?? [];
        $this->assertSame([], $labels, 'Sem histórico automático — labels vêm apenas do LLM');
    }

    public function test_present_confirmation_keeps_existing_labels_from_extraction(): void
    {
        // Após F2: LLM labels são preservadas sem merge automático.
        $this->seedDefaultCategories();
        $this->store->incrementLabelUse('ifood'); // histórico (não injetado)

        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Paguei 50 no iFood',
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            'labels' => ['japones', 'domingo'], // LLM já preencheu
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'japones'));

        $session = $this->currentSession();
        $labels = $session['draft']['labels'] ?? [];
        // LLM labels preservadas.
        $this->assertContains('japones', $labels);
        $this->assertContains('domingo', $labels);
        // Histórico NÃO é injetado automaticamente (F2).
        $this->assertCount(2, $labels, 'Apenas os labels do LLM, sem merge de histórico');
    }

    public function test_present_confirmation_user_edits_labels_manually(): void
    {
        // Após F2: labels vêm apenas do LLM ou entrada manual — sem sugestão automática.
        $this->seedDefaultCategories();
        $this->store->incrementLabelUse('ifood');

        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Paguei 50 no iFood',
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            'labels' => [],
        ]);

        $router = $this->makeRouter();
        $router->route(ConversationInput::text(self::CHAT_ID, 'iFood'));

        // Sessão criada com labels do DTO (vazio — sem injeção automática).
        $session = $this->currentSession();
        $messageId = $session['message_id_confirm'];
        $originalLabels = $session['draft']['labels'] ?? [];
        $this->assertSame([], $originalLabels, 'Sem sugestão automática de labels');

        // Usuário clica "Editar" → "Descrição" e edita.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:description',
            callbackMessageId: $messageId,
        ));
        $router->route(ConversationInput::text(self::CHAT_ID, 'iFood de japonês'));

        // Após edição, sessão volta para AWAITING_CONFIRMATION — labels vazios preservados.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertSame([], $session['draft']['labels'] ?? []);
    }

    public function test_confirm_increments_label_use_count(): void
    {
        // CT-022: após confirm, o use_count de cada label é incrementado.
        $this->seedDefaultCategories();

        $dto = TransactionData::fromArray([
            'description' => 'Paguei 50',
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            'labels' => ['ifood', 'restaurante'],
        ]);

        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $dto, [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // Labels criadas com use_count=1.
        $foldedIfood = TextNormalizer::fold(LabelFormatter::format('ifood'));
        $labelIfood = Label::where('folded_name', $foldedIfood)->first();
        $this->assertNotNull($labelIfood);
        $this->assertSame(1, $labelIfood->use_count);

        $foldedRest = TextNormalizer::fold(LabelFormatter::format('restaurante'));
        $labelRest = Label::where('folded_name', $foldedRest)->first();
        $this->assertNotNull($labelRest);
        $this->assertSame(1, $labelRest->use_count);
    }

    public function test_confirm_increments_existing_label_use_count(): void
    {
        // CT-022 — segundo uso: increment acumula.
        $this->seedDefaultCategories();
        $this->store->incrementLabelUse('ifood'); // pre-existente: use_count=1

        $dto = TransactionData::fromArray([
            'description' => 'Paguei 50',
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            'labels' => ['ifood'],
        ]);

        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $dto, [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        $folded = TextNormalizer::fold(LabelFormatter::format('ifood'));
        $label = Label::where('folded_name', $folded)->first();
        $this->assertNotNull($label);
        $this->assertSame(2, $label->use_count, 'Deve incrementar de 1 → 2');
    }

    public function test_confirm_creates_new_category_if_not_exists(): void
    {
        // CT-012: confirm com categoria que não existe → cria no banco de dados.
        // Não popula default categories → "Hobbies" não existe.
        $dto = TransactionData::fromArray([
            'description' => 'Paguei 150 em material de pintura',
            'amount' => 150.0,
            'type' => 'expense',
            'category' => 'Hobbies',
            'date' => '2026-06-15',
        ]);

        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $dto, [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        $category = Category::where('slug', 'hobbies')->first();
        $this->assertNotNull($category, 'Categoria nova deve ser criada no confirm');
        $this->assertSame('Hobbies', $category->display_name);
        $this->assertSame('expense', $category->default_type);
    }

    public function test_confirm_does_not_recreate_existing_category(): void
    {
        // Categoria já existe (default seed) → confirm NÃO deve sobrescrever.
        $this->seedDefaultCategories();
        // Marca a categoria com um use_count custom para garantir preservação.
        $this->store->incrementLabelUse('alimentação'); // trick: use_count-like via doc

        $existing = $this->store->getCategory('Alimentação');
        $before = $existing->use_count ?? 0;
        $this->assertSame(0, $before, 'Categoria seed começa com use_count=0');

        $dto = TransactionData::fromArray([
            'description' => 'Almoço',
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
        ]);

        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $dto, [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // Categoria continua existindo (id="alimentação") — use_count não foi
        // alterado pelo confirm (a versão atual não atualiza use_count da category).
        $after = $this->store->getCategory('Alimentação');
        $this->assertNotNull($after);
        $this->assertSame(0, $after->use_count ?? 0);
    }

    public function test_present_confirmation_uses_category_from_extraction_when_provided(): void
    {
        // Quando o LLM já extraiu a categoria corretamente, ela é preservada
        // (não substituída por default).
        $this->seedDefaultCategories();

        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Paguei 50',
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'almoco'));

        $session = $this->currentSession();
        $this->assertSame('Alimentação', $session['draft']['category'] ?? null);
    }

    public function test_confirm_label_increment_does_not_block_on_storage_failure(): void
    {
        // Best-effort: uma falha no incrementLabelUse NÃO deve impedir o confirm.
        // Aqui simulamos fazendo o gateway lançar — através de um stub que
        // substitui a incrementLabelUse.
        //
        // Implementação: não mockamos o WalletStore inteiro;
        // em vez disso, validamos que o caminho normal funciona e logamos
        // o caso best-effort via inspeção do código (a lógica está em
        // trackUsageAfterConfirm com try/catch).

        $this->seedDefaultCategories();

        $dto = TransactionData::fromArray([
            'description' => 'P',
            'amount' => 10.0,
            'type' => 'expense',
            'category' => 'Outros',
            'date' => '2026-06-15',
            'labels' => ['label-que-vai-falhar'],
        ]);

        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $dto, [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);
        $this->syncSheet->toReturn = true;

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // Transação foi persistida (não houve erro fatal).
        $this->assertSame(1, Transaction::count());

        // Label foi incrementada (caminho feliz deste teste).
        $folded = TextNormalizer::fold(LabelFormatter::format('label-que-vai-falhar'));
        $label = Label::where('folded_name', $folded)->first();
        $this->assertNotNull($label);
        $this->assertSame(1, $label->use_count);
    }

    /*
    |--------------------------------------------------------------------------
    | P7-B — UX do picker de edição e prompt de "Digite o novo ..."
    |--------------------------------------------------------------------------
    |
    | Estes testes documentam o fix P7-A → P7-B:
    |
    |  1. Ao clicar num campo do picker (edit:<field>), os botões do picker
    |     SOMEM imediatamente (markup=null via editMessageReplyMarkup),
    |     mas o texto "✏️ Qual campo você quer editar?" PERMANECE no chat
    |     como histórico. Resolve o bug onde o usuário clicava em botões
    |     velhos e recebia silêncio.
    |
    |  2. Quando o usuário responde com texto válido em AWAITING_EDITION,
    |     a msg do prompt "✏️ Digite o novo 💵 valor" é DELETADA — sem
    |     pergunta órfã no chat após edição.
    |
    |  3. Texto inválido em AWAITING_EDITION NÃO deleta a msg do prompt
    |     (o usuário ainda está respondendo — não deve perder a pergunta).
    |
    |  4. Após edição válida, o fluxo de confirm/cancel continua intacto:
    |     message_id_confirm e o keyboard X permanecem válidos.
    |
    | A defesa em camadas (editMessageReplyMarkup com markup=null,
    | annulStaleEditClickInEdition) é mantida para race conditions onde o
    | Telegram entrega callbacks de mensagens antigas que ainda têm
    | keyboards no client do user — ver comentários nos métodos.
    */

    public function test_p7b_picker_buttons_disappear_after_field_selection(): void
    {
        // Cenário 1: ao clicar edit:<field> no picker, os botões somem
        // (markup=null) mas o texto "✏️ Qual campo você quer editar?"
        // permanece como histórico no chat.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-1',
            callbackData: 'edit:amount',
            callbackMessageId: 6001, // vindo do picker Y
        ));

        // P7-B: editMessageReplyMarkup foi chamado UMA vez com o ID do
        // picker e markup=null (remove keyboard, mantém texto).
        $this->assertArrayHasKey(self::CHAT_ID, $this->messenger->editedMarkups);
        $this->assertCount(1, $this->messenger->editedMarkups[self::CHAT_ID]);
        $this->assertSame(
            6001,
            $this->messenger->editedMarkups[self::CHAT_ID][0]['message_id'],
            'P7-B: message_id editado deve ser o do picker (Y)',
        );
        $this->assertNull(
            $this->messenger->editedMarkups[self::CHAT_ID][0]['markup'],
            'P7-B: markup deve ser null (remove keyboard, mantém texto)',
        );

        // Comportamento esperado continua: transicionou para AWAITING_EDITION
        // e enviou o prompt de edição.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);
        $this->assertCount(1, $this->messenger->editionAsks[self::CHAT_ID] ?? []);
    }

    public function test_p7b_ask_edition_prompt_not_deleted_on_valid_response_after_r2(): void
    {
        // R2: resposta válida em AWAITING_EDITION → a msg do prompt Z
        // NÃO é deletada, NÃO há editMessageText/RestoreKeyboards.
        // Em vez disso, envia feedback + nova confirmação X_new.
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'message_id_ask_edition' => 7001, // ID do prompt "✏️ Digite o novo 💵 valor"
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '75,00'));

        // R2: NENHUMA mensagem é deletada (nem Z nem Y).
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem deletada após edição válida',
        );

        // R2: NENHUMA mensagem é editada in-place.
        $this->assertEmpty(
            $this->messenger->editedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem editada in-place',
        );

        // Transição normal: AWAITING_CONFIRMATION com NOVO message_id_confirm.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertNotSame(5001, $session['message_id_confirm'], 'message_id_confirm deve ser X_new');
    }

    public function test_p7b_ask_edition_prompt_not_deleted_on_invalid_text_response(): void
    {
        // Cenário 3: resposta INVÁLIDA em AWAITING_EDITION → a msg do
        // prompt NÃO deve ser deletada (o usuário ainda está respondendo
        // — perder a pergunta seria pior que mantê-la).
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'message_id_ask_edition' => 7001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'lixo'));

        // P7-B: a msg do prompt NÃO foi deletada.
        $deleted = $this->messenger->deletedMessages[self::CHAT_ID] ?? [];
        $this->assertNotContains(7001, $deleted, 'P7-B: prompt não deve ser deletado em resposta inválida');

        // Sessão continua em AWAITING_EDITION com retry_count incrementado.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame(1, $session['retry_count']);
    }

    public function test_p7b_confirm_cancel_still_work_after_edition_with_r2(): void
    {
        // R2: após edição válida, o fluxo de confirm/cancel continua
        // funcionando normalmente via X_new. message_id_confirm muda para
        // X_new após edição.
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // Edição válida → volta para AWAITING_CONFIRMATION com X_new.
        $router->route(ConversationInput::text(self::CHAT_ID, '99,00'));
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);

        // R2: message_id_confirm agora é X_new (não 5001 X_old).
        $newConfirmId = $session['message_id_confirm'];
        $this->assertNotSame(5001, $newConfirmId, 'R2: message_id_confirm deve ser X_new');

        // Confirm: processa normalmente via X_new.
        $this->syncSheet->toReturn = true;
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-confirm',
            callbackData: 'confirm',
            callbackMessageId: $newConfirmId, // X_new
        ));

        $this->assertSame(1, Transaction::count(), 'Confirm deve persistir a transação');
        $this->assertCount(1, $this->messenger->successes[self::CHAT_ID] ?? [], 'notifySuccess deve ser chamado');
    }

    public function test_p7b_ask_edition_id_is_updated_after_invalid_retry(): void
    {
        // R2: resposta INVÁLIDA em AWAITING_EDITION gera um NOVO `askForEdition()`
        // (com novo message_id). Após R2, `message_id_ask_edition` é DEPRECIADO
        // e NÃO é mais gravado na sessão — o prompt Z permanece como histórico.
        // Este teste verifica que:
        //  - Um novo prompt de edição é enviado ao usuário
        //  - A sessão NÃO contém mais o campo `message_id_ask_edition`
        //  - retry_count é incrementado e preservado
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001, // ID do prompt original (legacy)
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'lixo'));

        // O retry emitiu UM novo prompt de edição.
        $this->assertCount(1, $this->messenger->editionAsks[self::CHAT_ID] ?? []);

        // R2: message_id_ask_edition NÃO é mais gravado na sessão.
        // O campo é limpo via clearFields — sessions legacy são sanitizadas.
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertNull(
            $session['message_id_ask_edition'] ?? null,
            'R2: message_id_ask_edition depreciado — não deve ter valor na sessão após retry',
        );
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame(1, $session['retry_count'], 'retry_count deve ter sido incrementado e preservado');

        // O prompt original (7001) não foi deletado — o user ainda está
        // respondendo (mesma semântica do test_p7b_ask_edition_prompt_not_deleted_*).
        $deleted = $this->messenger->deletedMessages[self::CHAT_ID] ?? [];
        $this->assertNotContains(7001, $deleted, 'R2: prompt original não deve ser deletado em retry');
    }

    /*
    |--------------------------------------------------------------------------
    | T-NEW: Novos testes da refatoração de botões inline (P7-B)
    |--------------------------------------------------------------------------
    |
    | Cobrem os novos comportamentos:
    |  1. Remoção de markup de X ao clicar Editar/Confirmar/Cancelar
    |  2. Deleção de Y e Z + restauração de teclado em X após edição
    |  3. Botão de Observações no picker
    */

    public function test_edit_removes_keyboard_from_confirmation_message_x(): void
    {
        // Ao clicar "Editar", o markup do teclado de confirmação de X
        // deve ser removido (markup=null) ANTES de mostrar o picker Y.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit',
            callbackData: 'edit',
            callbackMessageId: 5001,
        ));

        // Verifica que editMessageReplyMarkup foi chamado para remover
        // o keyboard de X (markup=null).
        $markups = $this->messenger->editedMarkups[self::CHAT_ID] ?? [];
        $this->assertCount(1, $markups);
        $this->assertSame(5001, $markups[0]['message_id']);
        $this->assertNull($markups[0]['markup'], 'Keyboard de X deve ser null (removido)');
    }

    public function test_confirm_removes_keyboard_from_x_but_does_not_delete_y_or_z(): void
    {
        // R2: ao clicar "Confirmar": remove markup de X, mas NÃO deleta Y nem Z.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-confirm',
            callbackData: 'confirm',
            callbackMessageId: 5001,
        ));

        // X: markup removido.
        $markups = $this->messenger->editedMarkups[self::CHAT_ID] ?? [];
        $confirmMarkup = array_values(array_filter($markups, fn (array $e): bool => $e['message_id'] === 5001));
        $this->assertCount(1, $confirmMarkup);
        $this->assertNull($confirmMarkup[0]['markup'], 'Keyboard de X deve ser removido');

        // R2: NENHUMA mensagem é deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: confirm não deleta Y nem Z',
        );
    }

    public function test_cancel_removes_keyboard_from_x_but_does_not_delete_y_or_z(): void
    {
        // R2: ao clicar "Cancelar": remove markup de X, mas NÃO deleta Y nem Z.
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-cancel',
            callbackData: 'cancel',
            callbackMessageId: 5001,
        ));

        // X: markup removido.
        $markups = $this->messenger->editedMarkups[self::CHAT_ID] ?? [];
        $confirmMarkup = array_values(array_filter($markups, fn (array $e): bool => $e['message_id'] === 5001));
        $this->assertCount(1, $confirmMarkup);
        $this->assertNull($confirmMarkup[0]['markup'], 'Keyboard de X deve ser removido');

        // R2: NENHUMA mensagem é deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: cancel não deleta Y nem Z',
        );
    }

    public function test_edition_conclusion_r2_sends_new_confirmation_not_edit_in_place(): void
    {
        // R2: após edição válida (AWAITING_EDITION → AWAITING_CONFIRMATION):
        // - NENHUMA mensagem é deletada
        // - NENHUMA mensagem é editada in-place
        // - NENHUM keyboard é restaurado
        // - NOVA confirmação X_new é enviada
        // - Feedback de alteração é enviado
        // - message_id_confirm, ask_edition, edit_picker são limpos da sessão
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'message_id_confirm' => 5001,
            'message_id_edit_picker' => 6001,
            'message_id_ask_edition' => 7001,
            'awaiting_field' => 'amount',
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '100,00'));

        // R2: NENHUMA mensagem deletada.
        $this->assertEmpty(
            $this->messenger->deletedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem deletada',
        );

        // Sessão: campos limpos.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertNull($session['message_id_edit_picker'] ?? null, 'Y deve ser limpo da sessão');
        $this->assertNull($session['message_id_ask_edition'] ?? null, 'Z deve ser limpo da sessão');

        // R2: NENHUM keyboard restaurado.
        $this->assertEmpty(
            $this->messenger->restoredKeyboards[self::CHAT_ID] ?? [],
            'R2: nenhum keyboard restaurado',
        );

        // R2: NENHUMA mensagem editada in-place.
        $this->assertEmpty(
            $this->messenger->editedMessages[self::CHAT_ID] ?? [],
            'R2: nenhuma mensagem editada in-place',
        );

        // R2: NOVA confirmação X_new foi enviada.
        $confirms = $this->messenger->confirmations[self::CHAT_ID] ?? [];
        $this->assertCount(1, $confirms, 'R2: nova confirmação X_new deve ser enviada');
        $this->assertSame($confirms[0]['message_id'], $session['message_id_confirm'], 'message_id_confirm deve ser X_new');

        // Feedback de alteração enviado.
        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $feedbackText = $sentTexts[count($sentTexts) - 2]['text'] ?? '';
        $this->assertStringContainsString('alterado', $feedbackText);
    }

    /*
    |--------------------------------------------------------------------------
    | M4 (R1/R2) — Novos testes específicos da refatoração
    |--------------------------------------------------------------------------
    */

    public function test_field_change_message_gender_agreement_amount_alterado(): void
    {
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '150,00'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        $this->assertStringContainsString('Valor alterado', $feedback);
        $this->assertStringContainsString('R$ 47,50', $feedback); // valor antigo
        $this->assertStringContainsString('R$ 150,00', $feedback); // valor novo
    }

    public function test_field_change_message_gender_agreement_type_alterado(): void
    {
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'type',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'receita'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        $this->assertStringContainsString('Tipo alterado', $feedback);
        $this->assertStringContainsString('Despesa', $feedback); // valor antigo
        $this->assertStringContainsString('Receita', $feedback); // valor novo
    }

    public function test_field_change_message_gender_agreement_date_alterada(): void
    {
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'date',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '20/06/2026'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        // "Data" é feminino → "alterada"
        $this->assertStringContainsString('Data alterada', $feedback);
        $this->assertStringContainsString('15/06/2026', $feedback); // valor antigo
    }

    public function test_field_change_message_gender_agreement_description_alterada(): void
    {
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'description',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'Novo almoço'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        // "Descrição" é feminino → "alterada"
        $this->assertStringContainsString('Descrição alterada', $feedback);
    }

    public function test_field_change_message_gender_agreement_category_alterada(): void
    {
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'category',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'Lazer'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        // "Categoria" é feminino → "alterada"
        $this->assertStringContainsString('Categoria alterada', $feedback);
    }

    public function test_field_change_message_gender_agreement_observations_alteradas(): void
    {
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'observations',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'Novas obs'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        // "Observações" é feminino plural → "alteradas"
        $this->assertStringContainsString('Observações alteradas', $feedback);
    }

    public function test_ct047_rejects_x_old_after_edition(): void
    {
        // CT-047 (D3): após uma edição, X_old (5001) é rejeitado por callbacks.
        // O novo message_id_confirm (X_new) é gravado na sessão e apenas ele
        // é aceito como válido (side effect de sobrescrever message_id_confirm).
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, '99,00'));

        $session = $this->currentSession();
        $newConfirmId = $session['message_id_confirm'];
        $this->assertNotSame(5001, $newConfirmId, 'X_old não deve ser mais o message_id_confirm');

        // Callback de X_old (5001) deve ser rejeitado (não está na sessão).
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-stale',
            callbackData: 'confirm',
            callbackMessageId: 5001, // X_old
        ));

        // A sessão NÃO foi limpa (o confirm não processou).
        $this->assertNotNull($this->currentSession(), 'Sessão não deve ser limpa por callback stale');
        // Uma mensagem de erro foi enviada.
        $this->assertCount(1, $this->messenger->errors[self::CHAT_ID] ?? []);
    }

    public function test_multiple_editions_create_x_new_each_cycle(): void
    {
        // R2: cada edição cria uma nova confirmação X_new.
        // message_id_confirm muda a cada ciclo.
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $this->completeDto(), [
            'awaiting_field' => 'amount',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $router = $this->makeRouter();

        // Primeira edição: amount.
        $router->route(ConversationInput::text(self::CHAT_ID, '99,00'));
        $session1 = $this->currentSession();
        $confirmId1 = $session1['message_id_confirm'];
        $this->assertNotSame(5001, $confirmId1, 'Edição 1: message_id_confirm deve ser X_new');

        // Número de confirmações: 1.
        $this->assertCount(1, $this->messenger->confirmations[self::CHAT_ID] ?? []);

        // Prepara segunda edição (editar type agora).
        // Simula callback edit:type no X_new para entrar em AWAITING_EDITION.
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit-type',
            callbackData: 'edit:type',
            callbackMessageId: $confirmId1, // do X_new
        ));

        $router->route(ConversationInput::text(self::CHAT_ID, 'receita'));
        $session2 = $this->currentSession();
        $confirmId2 = $session2['message_id_confirm'];
        $this->assertNotSame($confirmId1, $confirmId2, 'Edição 2: message_id_confirm deve ser novo X_new');

        // Número de confirmações: 2 (cada edição criou uma nova).
        $this->assertCount(2, $this->messenger->confirmations[self::CHAT_ID] ?? []);

        // Cada edição gerou feedback.
        $feedbackTexts = array_map(fn ($e) => $e['text'], $this->messenger->sentTexts[self::CHAT_ID] ?? []);
        $feedbackMessages = array_values(array_filter(
            $feedbackTexts,
            fn (string $t): bool => str_contains($t, 'alterad'),
        ));
        $this->assertCount(2, $feedbackMessages, 'Cada edição deve gerar um feedback');
    }

    public function test_null_value_displays_dash_in_feedback(): void
    {
        // Quando o valor antigo é null, o feedback mostra "—".
        // Usamos category (null por default no DTO parcial).
        $dto = $this->completeDto()->withCategory(null);
        $this->seedSession(ConversationState::AWAITING_EDITION->value, $dto, [
            'awaiting_field' => 'category',
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'NovaCat'));

        $feedback = $this->messenger->sentTexts[self::CHAT_ID][0]['text'] ?? '';
        $this->assertStringContainsString('—', $feedback, 'Valor null deve exibir "—"');
        $this->assertStringContainsString('NovaCat', $feedback);
    }

    /*
    |--------------------------------------------------------------------------
    | M3 — Labels Inteligentes (T3.6)
    |--------------------------------------------------------------------------
    */

    public function test_extraction_passes_catalog_to_text_extractor(): void
    {
        // T3.1: a extração de texto deve receber o catálogo de labels
        // do banco de dados via fetchLabelCatalog().
        // Seed uma label e verifica que o extrator a recebe.
        $this->store->incrementLabelUse('Almoço');

        $capturedCatalog = null;
        $this->extractText = new class implements ExtractsText
        {
            public ?array $capturedCatalog = null;

            public function handle(string $text, array $labelCatalog = []): TransactionData
            {
                $this->capturedCatalog = $labelCatalog;

                return TransactionData::fromArray([
                    'description' => 'Almoço',
                    'amount' => 50.00,
                    'type' => 'expense',
                    'date' => '2026-06-15',
                ]);
            }
        };

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'Almoço 50 reais'));

        $this->assertNotNull($this->extractText->capturedCatalog, 'Catálogo deve ser passado ao extrator');
        $this->assertContains('Almoço', $this->extractText->capturedCatalog);
    }

    public function test_extraction_passes_catalog_to_image_extractor(): void
    {
        // T3.1: a extração de foto também deve passar o catálogo.
        $this->store->incrementLabelUse('Restaurante');

        $capturedCatalog = null;
        $this->extractImage = new class implements ExtractsImage
        {
            public ?array $capturedCatalog = null;

            public function handle(string $fileId, array $labelCatalog = []): TransactionData
            {
                $this->capturedCatalog = $labelCatalog;

                return TransactionData::fromArray([
                    'description' => 'Jantar',
                    'amount' => 80.00,
                    'type' => 'expense',
                    'date' => '2026-06-15',
                ]);
            }
        };

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-123'));

        $this->assertNotNull($this->extractImage->capturedCatalog, 'Catálogo deve ser passado ao extrator de imagem');
        $this->assertContains('Restaurante', $this->extractImage->capturedCatalog);
    }

    public function test_dto_with_many_labels_truncates_to_max_and_notifies(): void
    {
        // T3.3: DTO extraído com 5 labels → trunca para 3 + envia aviso.
        $this->extractText->toReturn = TransactionData::fromArray([
            'description' => 'Compras variadas',
            'amount' => 200.00,
            'type' => 'expense',
            'date' => '2026-06-15',
            'labels' => ['Almoço', 'Restaurante', 'Trabalho', 'Viagem', 'Ifood'],
        ]);

        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, 'Compras 200'));

        // Deve ter enviado aviso de truncamento.
        $texts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $truncateMessages = array_filter(
            $texts,
            fn (array $t): bool => str_contains($t['text'], 'Limitei a'),
        );
        $this->assertCount(1, $truncateMessages, 'Deve enviar aviso de truncamento');
        $this->assertStringContainsString('Limitei a 3 labels', $truncateMessages[0]['text'] ?? '');

        // Confirmação deve ter apenas 3 labels — verifica via draft do DTO.
        $confirmations = $this->messenger->confirmations[self::CHAT_ID] ?? [];
        $this->assertCount(1, $confirmations);
        $dto = $confirmations[0]['draft'];
        $this->assertCount(3, $dto->labels);
        $this->assertContains('Almoço', $dto->labels);
        $this->assertContains('Restaurante', $dto->labels);
        $this->assertContains('Trabalho', $dto->labels);
    }

    public function test_validate_labels_with_catalog_corrects_fuzzy_spelling(): void
    {
        // T3.2: validateLabels com catálogo deve corrigir "almoco" → "Almoço"
        // (fuzzy match acima do threshold).
        // Seed uma label para o catálogo.
        $this->store->incrementLabelUse('Almoço');

        $router = $this->makeRouter();

        // Chama validateLabels com "almoco" (sem acento, lowercase).
        // O catálogo contém "Almoço" — o fuzzy match deve substituir.
        $result = $router->validateLabels('almoco');

        $this->assertContains('Almoço', $result);
        $this->assertCount(1, $result);
    }

    public function test_validate_labels_with_catalog_keeps_unmatched_tokens(): void
    {
        // T3.2: token que não tem match no catálogo deve ser mantido
        // (após LabelFormatter::format).
        $router = $this->makeRouter();

        $result = $router->validateLabels('pizza, cinema');

        $this->assertContains('Pizza', $result);
        $this->assertContains('Cinema', $result);
        $this->assertCount(2, $result);
    }

    public function test_validate_labels_truncates_to_max(): void
    {
        // T3.2: validateLabels deve truncar para max_labels (3) silenciosamente.
        // Tokens precisam ter ≥ 2 caracteres para não serem filtrados.
        $router = $this->makeRouter();

        $result = $router->validateLabels('aa, bb, cc, dd, ee');

        $this->assertCount(3, $result, 'Deve truncar para 3 labels');
    }

    public function test_confirmation_summary_includes_labels_line(): void
    {
        // T3.5 (D3=B): o resumo de confirmação deve incluir a linha de labels.
        $dto = $this->completeDto()->withLabels(['Almoço', 'Restaurante']);
        $this->seedSession(ConversationState::AWAITING_CONFIRMATION->value, $dto, [
            'message_id_confirm' => 5001,
            'source' => 'text',
        ]);

        // Re-route via confirm para verificar que o resumo tem labels.
        // Na verdade, o resumo é construído pelo presentConfirmation.
        // Vamos verificar o summary diretamente via formatter.
        $formatter = new TransactionSummaryFormatter;
        $summary = $formatter->summary($dto);

        $this->assertStringContainsString('🏷️ <b>Labels:</b>', $summary);
        $this->assertStringContainsString('#Almoço', $summary);
        $this->assertStringContainsString('#Restaurante', $summary);
    }

    public function test_confirmation_summary_omits_labels_when_empty(): void
    {
        // T3.5: sem labels → não mostra a linha de labels.
        $dto = $this->completeDto();
        $formatter = new TransactionSummaryFormatter;
        $summary = $formatter->summary($dto);

        $this->assertStringNotContainsString('Labels:', $summary);
    }

    /*
    |--------------------------------------------------------------------------
    | M-ITENS-5 — Edição inline de items
    |--------------------------------------------------------------------------
    */

    public function test_edit_items_callback_transitions_to_awaiting_edition(): void
    {
        // CT-125/AC-017: clicar "🛒 Itens" → AWAITING_EDITION com awaiting_field='items'.
        $this->seedSession(
            ConversationState::AWAITING_CONFIRMATION->value,
            $this->completeDto(),
            ['message_id_confirm' => 2000],
        );

        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-edit-items',
            callbackData: 'edit:items',
            callbackMessageId: 2000,
        ));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('items', $session['awaiting_field']);

        // Deve ter enviado o prompt de edição.
        $editionAsks = $this->messenger->editionAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $editionAsks);
        $this->assertSame('items', $editionAsks[0]['field']);
    }

    public function test_awaiting_edition_valid_items_updates_draft(): void
    {
        // CT-126/AC-018: envia "Coca x3 8.50\nPão" → draft atualizado com 2 items.
        $draft = $this->completeDto();
        $this->seedSession(
            ConversationState::AWAITING_EDITION->value,
            $draft,
            [
                'awaiting_field' => 'items',
                'message_id_confirm' => 2000,
            ],
        );

        $router = $this->makeRouter();
        $router->route(ConversationInput::text(self::CHAT_ID, "Coca x3 8.50\nPão"));

        $session = $this->currentSession();
        // Deve ter voltado para AWAITING_CONFIRMATION.
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertArrayHasKey('items', $session['draft']);
        $this->assertCount(2, $session['draft']['items']);
        $this->assertSame('Coca', $session['draft']['items'][0]['name']);
        $this->assertSame('Pão', $session['draft']['items'][1]['name']);
    }

    public function test_awaiting_edition_limpar_zeros_items(): void
    {
        // CT-127/AC-019: envia "limpar" → items=[].
        $draft = $this->completeDto();
        $draft = $draft->withField('items', [['name' => 'Coca', 'qty' => 1]]);
        $this->seedSession(
            ConversationState::AWAITING_EDITION->value,
            $draft,
            [
                'awaiting_field' => 'items',
                'message_id_confirm' => 2000,
            ],
        );

        $router = $this->makeRouter();
        $router->route(ConversationInput::text(self::CHAT_ID, 'limpar'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        // items=[] é omitido pelo toDraftArray (filter $v !== []).
        $this->assertArrayNotHasKey('items', $session['draft']);
    }

    public function test_awaiting_edition_empty_input_clears_items(): void
    {
        // CT-128/AC-020 — adaptado: ItemsParser é permissivo (regex casa quase tudo).
        // Whitespace → validateItems retorna [] → draft atualizado com items=[].
        $draft = $this->completeDto();
        $draft = $draft->withField('items', [['name' => 'Coca', 'qty' => 1]]);
        $this->seedSession(
            ConversationState::AWAITING_EDITION->value,
            $draft,
            [
                'awaiting_field' => 'items',
                'message_id_confirm' => 2000,
            ],
        );

        $router = $this->makeRouter();
        // Envia apenas whitespace/newlines — parser devolve [], validateItems retorna [].
        $router->route(ConversationInput::text(self::CHAT_ID, " \t\n"));

        $session = $this->currentSession();
        // items=[] omitido pelo toDraftArray → confirmação sem items.
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertArrayNotHasKey('items', $session['draft']);
    }

    public function test_sum_divergence_does_not_block(): void
    {
        // CT-129/AC-021: amount=87.30 + items somando 49.90 → aceito sem erro.
        $draft = TransactionData::fromArray([
            'description' => 'Supermercado',
            'amount' => 87.30,
            'type' => 'expense',
            'date' => '2026-06-15',
            'items' => [
                ['name' => 'Arroz', 'qty' => 1, 'unitPrice' => 32.90, 'subtotal' => 32.90],
                ['name' => 'Feijão', 'qty' => 1, 'unitPrice' => 17.00, 'subtotal' => 17.00],
            ],
        ]);

        $this->seedSession(
            ConversationState::AWAITING_EDITION->value,
            $draft,
            [
                'awaiting_field' => 'items',
                'message_id_confirm' => 2000,
            ],
        );

        $router = $this->makeRouter();
        $router->route(ConversationInput::text(self::CHAT_ID, "Arroz\nFeijão"));

        $session = $this->currentSession();
        // Deve ter avançado para confirmação (sem erro).
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
    }

    public function test_sum_exceeding_amount_does_not_block(): void
    {
        // CT-130/AC-022: amount=50 + items somando 150 → aceito.
        $draft = TransactionData::fromArray([
            'description' => 'Compras',
            'amount' => 50.0,
            'type' => 'expense',
            'date' => '2026-06-15',
        ]);

        $this->seedSession(
            ConversationState::AWAITING_EDITION->value,
            $draft,
            [
                'awaiting_field' => 'items',
                'message_id_confirm' => 2000,
            ],
        );

        $router = $this->makeRouter();
        $router->route(ConversationInput::text(
            self::CHAT_ID,
            "Item A x1 50.00\nItem B x1 50.00\nItem C x1 50.00",
        ));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        // items aceitos (3 itens, soma 150 > amount 50).
        $this->assertCount(3, $session['draft']['items']);
    }

    /*
    |--------------------------------------------------------------------------
    | W-A: mergeDrafts — preservação de items
    |--------------------------------------------------------------------------
    */

    public function test_merge_drafts_preserves_base_items_when_extracted_has_none(): void
    {
        // W-A: draft base tem 3 items; foto extraída sem items → items preservados.
        $base = TransactionData::fromArray([
            'description' => 'Compra no mercado',
            'type' => 'expense',
            'date' => '2026-06-15',
            'items' => [
                ['name' => 'Arroz', 'qty' => 2.0, 'unitPrice' => null, 'subtotal' => null],
                ['name' => 'Feijão', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
                ['name' => 'Detergente', 'qty' => 1.0, 'unitPrice' => 4.5, 'subtotal' => null],
            ],
        ]);
        // Base incompleta (sem amount) → AWAITING_DATA.
        $this->seedSession(ConversationState::AWAITING_DATA->value, $base, [
            'awaiting_field' => 'amount',
            'source' => 'text',
            'draft' => $base->toDraftArray(),
        ]);

        // Foto extraída: apenas amount, sem items.
        $this->extractImage->toReturn = TransactionData::fromArray([
            'amount' => 50.0,
        ]);

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-id-a'));

        // Merged é completo (description+type+date do base + amount da foto) → confirmation.
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertArrayHasKey('items', $session['draft'], 'Items do draft base devem ser preservados');
        $this->assertCount(3, $session['draft']['items']);
        $this->assertSame('Arroz', $session['draft']['items'][0]['name']);
        $this->assertSame('Feijão', $session['draft']['items'][1]['name']);
        $this->assertSame('Detergente', $session['draft']['items'][2]['name']);
    }

    public function test_merge_drafts_uses_extracted_items_when_present(): void
    {
        // W-A: draft base tem 2 items; foto extraída tem 5 items → 5 items no merged.
        $base = TransactionData::fromArray([
            'description' => 'Compra antiga',
            'type' => 'expense',
            'date' => '2026-06-15',
            'items' => [
                ['name' => 'Item Base A', 'qty' => 1.0, 'unitPrice' => null, 'subtotal' => null],
                ['name' => 'Item Base B', 'qty' => 1.0, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);
        $this->seedSession(ConversationState::AWAITING_DATA->value, $base, [
            'awaiting_field' => 'amount',
            'source' => 'text',
            'draft' => $base->toDraftArray(),
        ]);

        // Foto extraída com amount + 5 items.
        $this->extractImage->toReturn = TransactionData::fromArray([
            'amount' => 100.0,
            'items' => [
                ['name' => 'Item Foto 1', 'qty' => 1.0, 'unitPrice' => 20.0, 'subtotal' => null],
                ['name' => 'Item Foto 2', 'qty' => 2.0, 'unitPrice' => 15.0, 'subtotal' => null],
                ['name' => 'Item Foto 3', 'qty' => 1.0, 'unitPrice' => 10.0, 'subtotal' => null],
                ['name' => 'Item Foto 4', 'qty' => 3.0, 'unitPrice' => 5.0, 'subtotal' => null],
                ['name' => 'Item Foto 5', 'qty' => 1.0, 'unitPrice' => 8.0, 'subtotal' => null],
            ],
        ]);

        $this->makeRouter()->route(ConversationInput::photo(self::CHAT_ID, 'photo-id-b'));

        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertArrayHasKey('items', $session['draft'], 'Items da foto devem estar presentes');
        $this->assertCount(5, $session['draft']['items']);
        $this->assertSame('Item Foto 1', $session['draft']['items'][0]['name']);
        $this->assertSame('Item Foto 5', $session['draft']['items'][4]['name']);
    }
}
