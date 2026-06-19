<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Conversation\ConversationInput;
use App\Conversation\ConversationRouter;
use SergiX44\Nutgram\Nutgram;

/**
 * Adapter de Nutgram → {@see ConversationRouter} para callback queries (botões).
 *
 * Chamado quando o usuário toca em qualquer botão do inline keyboard do bot
 * (Confirmar, Editar, Cancelar, edit:amount, etc.). Constrói um
 * {@see ConversationInput::callback()} com:
 *
 *  - `callbackId`         → id da callback_query (Telegram), usado em answerCallback
 *  - `callbackData`       → payload do botão (ex.: "confirm", "edit:amount")
 *  - `callbackMessageId`  → id da mensagem que carregava o keyboard. Essencial
 *                           para CT-047 (rejeitar callback de keyboard antiga):
 *                           comparamos com `sessions/{chat_id}.message_id_confirm`.
 *
 * Edge cases tratados:
 *
 *  - Sem callback_query no update (defensivo) → ignora.
 *  - callback_data null (cliente enviou vazio) → passa null adiante, o
 *    Router trata como "desconhecido" silenciosamente.
 *  - inline_message_id em vez de message (modo inline) → improvável em bot de
 *    uso pessoal mas se ocorrer, `message` será null; o Router recebe
 *    `callbackMessageId=null` e o check de CT-047 fica no-op seguro.
 */
class CallbackQueryRouterHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $query = $bot->callbackQuery();
        if ($query === null) {
            return;
        }

        $message = $query->message;
        $chatId = $message !== null ? (int) $message->chat->id : 0;
        $callbackMessageId = $message !== null ? (int) $message->message_id : null;

        $router = app(ConversationRouter::class);
        $router->route(ConversationInput::callback(
            chatId: $chatId,
            callbackId: $query->id,
            callbackData: $query->data,
            callbackMessageId: $callbackMessageId,
        ));
    }
}
