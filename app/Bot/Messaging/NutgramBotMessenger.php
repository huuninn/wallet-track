<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Bot\Handlers\StartHandler;
use App\Dto\TransactionData;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

/**
 * Implementação concreta de {@see BotMessenger} usando o Nutgram (M7.4).
 *
 * Toda a I/O real do Telegram fica encapsulada aqui. O Router nunca toca
 * o singleton {@see Nutgram} diretamente — apenas chama os métodos desta
 * classe, o que permite substituir por {@see InMemoryBotMessenger} em testes.
 *
 * Convenções:
 *
 *  - Mensagens de usuário são enviadas em HTML parse_mode (permite <b>, <code>
 *    e emojis) — alinhado com {@see StartHandler}.
 *  - callback_data dos botões: `confirm`, `cancel`, `edit`, `edit:amount`,
 *    `edit:type`, `edit:date` — curtos (<= 64 bytes, limite da API).
 *  - Em caso de falha de rede/API, propagamos o que o Nutgram lança
 *    (TelegramException) — o Router/Logger trata (não é responsabilidade
 *    do messenger decidir se deve retentar).
 *
 * Nota de testes: esta classe NÃO é coberta por unit/feature testes (exigiria
 * mockar o HTTP do Telegram). O contrato é exercitado via InMemoryBotMessenger
 * nos testes do ConversationRouter; um teste de integração ponta-a-ponta
 * (que dispara update real simulado contra o Nutgram::run) cobre o mínimo
 * de smoke — eventualmente em M11.
 */
final class NutgramBotMessenger implements BotMessenger
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly TransactionSummaryFormatter $formatter = new TransactionSummaryFormatter,
    ) {}

    public function sendText(int|string $chatId, string $text): int
    {
        return $this->messageId(
            $this->bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            ),
        );
    }

    public function sendConfirmationRequest(int|string $chatId, TransactionData $draft, ?string $firestoreId = null): int
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ Confirmar', callback_data: 'confirm', style: ButtonStyle::SUCCESS),
                InlineKeyboardButton::make('✏️ Editar', callback_data: 'edit'),
            )
            ->addRow(
                InlineKeyboardButton::make('❌ Cancelar', callback_data: 'cancel', style: ButtonStyle::DANGER),
            );

        return $this->messageId(
            $this->bot->sendMessage(
                text: $this->formatter->summary($draft),
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $keyboard,
            ),
        );
    }

    public function askForField(int|string $chatId, string $field, string $prompt): int
    {
        return $this->messageId(
            $this->bot->sendMessage(
                text: $prompt,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            ),
        );
    }

    public function askForEdition(int|string $chatId, string $field): int
    {
        return $this->messageId(
            $this->bot->sendMessage(
                text: $this->formatter->editPrompt($field),
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            ),
        );
    }

    public function sendEditFieldPicker(int|string $chatId): int
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('💵 Valor', callback_data: 'edit:amount'),
                InlineKeyboardButton::make('🔖 Tipo', callback_data: 'edit:type'),
            )
            ->addRow(
                InlineKeyboardButton::make('📅 Data', callback_data: 'edit:date'),
                InlineKeyboardButton::make('💸 Descrição', callback_data: 'edit:description'),
            )
            ->addRow(
                InlineKeyboardButton::make('🏷 Categoria', callback_data: 'edit:category'),
            );

        return $this->messageId(
            $this->bot->sendMessage(
                text: '✏️ Qual campo você quer editar?',
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $keyboard,
            ),
        );
    }

    public function answerCallback(string $callbackId, string $text): void
    {
        $this->bot->answerCallbackQuery(
            callback_query_id: $callbackId,
            text: $text,
        );
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text): void
    {
        $this->bot->editMessageText(
            text: $text,
            chat_id: $chatId,
            message_id: $messageId,
            parse_mode: ParseMode::HTML,
        );
    }

    public function deleteMessage(int|string $chatId, int $messageId): void
    {
        // Best-effort: se a mensagem já foi deletada (pelo usuário ou por
        // outra chamada) ou se a rede/API falhar, NÃO propagamos — o
        // contrato é silencioso para que o caller (Router) possa chamar
        // deleteMessage sem se preocupar com cleanup. Logamos para
        // diagnóstico em produção.
        //
        // W1 (reviewer): capturamos `Throwable` em vez de só `TelegramException`
        // para honrar o contrato best-effort mesmo em erros não-encapsulados
        // pela SDK (e.g., `GuzzleException`, `RuntimeException` de rede).
        try {
            $this->bot->deleteMessage(chat_id: $chatId, message_id: $messageId);
        } catch (Throwable $e) {
            Log::warning('NutgramBotMessenger: deleteMessage falhou (não-bloqueante)', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function notifySuccess(int|string $chatId, TransactionData $dto): void
    {
        $this->bot->sendMessage(
            text: $this->successMessage($dto),
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
        );
    }

    public function notifyCancelled(int|string $chatId): void
    {
        $this->bot->sendMessage(
            text: '🚫 Transação cancelada. Você pode começar de novo quando quiser — é só me mandar uma mensagem.',
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
        );
    }

    public function notifyError(int|string $chatId, string $message): void
    {
        $this->bot->sendMessage(
            text: "⚠️ {$message}",
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Extrai o message_id de uma resposta de sendMessage — defensivo contra
     * null (pode ocorrer em chats muito restritos ou erros silenciosos).
     *
     * Se null, devolve 0 — o Router valida que o message_id_confirm é > 0
     * antes de salvá-lo (um callback sobre mensagem "0" sempre falha a
     * verificação de CT-047, o que é o comportamento correto/seguro).
     */
    private function messageId(?Message $message): int
    {
        return $message?->message_id ?? 0;
    }

    /**
     * Mensagem de sucesso em PT-BR, com resumo curto da transação persistida.
     */
    private function successMessage(TransactionData $dto): string
    {
        return '✅ <b>Transação registrada!</b>'
            ."\n\n".$this->formatter->summary($dto);
    }
}
