<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Dto\TransactionData;
use App\Services\Google\InMemoryFirestoreGateway;
use App\Services\Google\InMemorySheetsGateway;

/**
 * Implementação fake de {@see BotMessenger} para uso em testes (M7).
 *
 * Não envia nada ao Telegram — registra todas as chamadas em arrays públicos
 * para asserção determinística, e devolve `message_id`s incrementais (1, 2, 3…)
 * para que o Router possa simular o "save/lookup" de `message_id_confirm`.
 *
 * Replica o padrão dos fakes já usados no projeto
 * ({@see InMemoryFirestoreGateway},
 * {@see InMemorySheetsGateway}): regra de negócio testada
 * sem rede, em milissegundos, com asserções ricas sobre o que foi enviado.
 *
 * Estrutura dos registros:
 *
 *  - $sentTexts[$chatId][]        = ['message_id' => int, 'text' => string]
 *  - $confirmations[$chatId][]    = ['message_id' => int, 'draft' => TransactionData, 'firestore_id' => ?string]
 *  - $fieldAsks[$chatId][]        = ['message_id' => int, 'field' => string, 'prompt' => string]
 *  - $editionAsks[$chatId][]      = ['message_id' => int, 'field' => string]
 *  - $fieldPickers[$chatId][]     = ['message_id' => int]
 *  - $editedMessages[$chatId][]   = ['message_id' => int, 'text' => string]
 *  - $editedMarkups[$chatId][]    = ['message_id' => int, 'markup' => ?array]
 *  - $deletedMessages[$chatId][]  = int
 *  - $restoredKeyboards[$chatId][] = int (message_id que teve keyboard de confirmação restaurado)
 *  - $successes[$chatId][]        = ['dto' => TransactionData]
 *  - $cancelled[$chatId]          = int (count)
 *  - $errors[$chatId][]           = ['message' => string]
 *  - $callbackAnswers[]           = ['callback_id' => string, 'text' => string]
 */
final class InMemoryBotMessenger implements BotMessenger
{
    /** @var array<int|string, list<array{message_id: int, text: string}>> */
    public array $sentTexts = [];

    /** @var array<int|string, list<array{message_id: int, draft: TransactionData, firestore_id: ?string}>> */
    public array $confirmations = [];

    /** @var array<int|string, list<array{message_id: int, field: string, prompt: string}>> */
    public array $fieldAsks = [];

    /** @var array<int|string, list<array{message_id: int, field: string}>> */
    public array $editionAsks = [];

    /** @var array<int|string, list<array{message_id: int}>> */
    public array $fieldPickers = [];

    /**
     * Callback_data dos botões do último picker enviado por chat.
     * Espelha a estrutura do NutgramBotMessenger para asserção em testes (CT-124).
     *
     * @var array<int|string, list<string>>
     */
    public array $fieldPickerCallbacks = [];

    /** @var array<int|string, list<array{message_id: int, text: string}>> */
    public array $editedMessages = [];

    /**
     * Registro de chamadas a `editMessageReplyMarkup()` — útil para
     * asserções determinísticas em testes (P7-A → P7-B: "botões do picker
     * somem após seleção de campo").
     *
     * @var array<int|string, list<array{message_id: int, markup: ?array}>>
     */
    public array $editedMarkups = [];

    /** @var array<int|string, list<int>> */
    public array $deletedMessages = [];

    /**
     * Registro de chamadas a `restoreConfirmationKeyboard()` — útil para
     * asserções em testes que verificam se o teclado de confirmação foi
     * restaurado após uma edição bem-sucedida.
     *
     * @var array<int|string, list<int>>
     */
    public array $restoredKeyboards = [];

    /** @var array<int|string, list<array{dto: TransactionData}>> */
    public array $successes = [];

    /** @var array<int|string, int> */
    public array $cancelled = [];

    /** @var array<int|string, list<array{message: string}>> */
    public array $errors = [];

    /** @var list<array{callback_id: string, text: string}> */
    public array $callbackAnswers = [];

    private int $nextMessageId = 1000;

    public function __construct(
        private readonly TransactionSummaryFormatter $formatter = new TransactionSummaryFormatter,
    ) {}

    public function sendText(int|string $chatId, string $text): int
    {
        $id = $this->nextMessageId++;
        $this->sentTexts[$chatId][] = ['message_id' => $id, 'text' => $text];

        return $id;
    }

    public function sendConfirmationRequest(int|string $chatId, TransactionData $draft, ?string $firestoreId = null): int
    {
        $id = $this->nextMessageId++;
        $this->confirmations[$chatId][] = [
            'message_id' => $id,
            'draft' => $draft,
            'firestore_id' => $firestoreId,
        ];

        // Também registra como texto enviado (para asserção de conteúdo do resumo).
        $this->sentTexts[$chatId][] = ['message_id' => $id, 'text' => $this->formatter->summary($draft)];

        return $id;
    }

    public function askForField(int|string $chatId, string $field, string $prompt): int
    {
        $id = $this->nextMessageId++;
        $this->fieldAsks[$chatId][] = [
            'message_id' => $id,
            'field' => $field,
            'prompt' => $prompt,
        ];
        $this->sentTexts[$chatId][] = ['message_id' => $id, 'text' => $prompt];

        return $id;
    }

    public function askForEdition(int|string $chatId, string $field): int
    {
        $prompt = $this->formatter->editPrompt($field);
        $id = $this->nextMessageId++;
        $this->editionAsks[$chatId][] = ['message_id' => $id, 'field' => $field];
        $this->sentTexts[$chatId][] = ['message_id' => $id, 'text' => $prompt];

        return $id;
    }

    public function sendEditFieldPicker(int|string $chatId): int
    {
        $id = $this->nextMessageId++;
        $this->fieldPickers[$chatId][] = ['message_id' => $id];
        $this->sentTexts[$chatId][] = ['message_id' => $id, 'text' => '✏️ Qual campo você quer editar?'];

        // Espelha os callbacks do NutgramBotMessenger para asserção em testes.
        $this->fieldPickerCallbacks[$chatId] = [
            'edit:amount',
            'edit:type',
            'edit:date',
            'edit:description',
            'edit:category',
            'edit:observations',
            'edit:items',
        ];

        return $id;
    }

    public function answerCallback(string $callbackId, string $text): void
    {
        $this->callbackAnswers[] = ['callback_id' => $callbackId, 'text' => $text];
    }

    /**
     * @deprecated R2: nenhuma mensagem é editada in-place. O Router envia
     *             nova mensagem via sendText/sendConfirmationRequest. Mantido
     *             para retrocompatibilidade de testes.
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text): void
    {
        $this->editedMessages[$chatId][] = ['message_id' => $messageId, 'text' => $text];
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $markup): void
    {
        $this->editedMarkups[$chatId][] = ['message_id' => $messageId, 'markup' => $markup];
    }

    /**
     * @deprecated R2: nenhuma mensagem é editada in-place. O Router envia
     *             nova mensagem via sendText/sendConfirmationRequest. Mantido
     *             para retrocompatibilidade de testes.
     */
    public function restoreConfirmationKeyboard(int|string $chatId, int $messageId): void
    {
        $this->restoredKeyboards[$chatId][] = $messageId;
    }

    public function deleteMessage(int|string $chatId, int $messageId): void
    {
        $this->deletedMessages[$chatId][] = $messageId;
    }

    public function notifySuccess(int|string $chatId, TransactionData $dto): void
    {
        $this->successes[$chatId][] = ['dto' => $dto];
    }

    public function notifyCancelled(int|string $chatId): void
    {
        $this->cancelled[$chatId] = ($this->cancelled[$chatId] ?? 0) + 1;
    }

    public function notifyError(int|string $chatId, string $message): void
    {
        $this->errors[$chatId][] = ['message' => $message];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de asserção (encapsulam acessos a arrays aninhados)
    |--------------------------------------------------------------------------
    */

    /**
     * Último message_id emitido para qualquer operação no chat (ou null).
     *
     * Útil para testes que precisam simular um callback com messageId "atual".
     */
    public function lastMessageId(int|string $chatId): ?int
    {
        $candidates = array_filter([
            $this->sentTexts[$chatId] ?? null,
            $this->confirmations[$chatId] ?? null,
            $this->fieldAsks[$chatId] ?? null,
            $this->editionAsks[$chatId] ?? null,
        ]);

        $max = null;
        foreach ($candidates as $list) {
            foreach ($list as $entry) {
                $max = max($max ?? 0, $entry['message_id']);
            }
        }

        return $max;
    }
}
