<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Actions\ExtractFromImage;
use App\Conversation\ConversationInput;
use App\Conversation\ConversationRouter;
use App\Providers\TelegramServiceProvider;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

/**
 * Adapter de Nutgram → {@see ConversationRouter} para mensagens (texto ou foto).
 *
 * Captura todas as mensagens que NÃO são comandos (start/help/cancelar) e
 * converte em um {@see ConversationInput} que o Router sabe processar.
 *
 * Ordem de detecção (importante — texto é mais comum e mais barato de verificar):
 *
 *  1. Texto (MessageType::TEXT) → ConversationInput::text(...)
 *  2. Foto (MessageType::PHOTO) → pega a maior resolução (último PhotoSize) →
 *     ConversationInput::photo(...)
 *  3. Qualquer outro tipo (sticker, áudio, vídeo, etc.) → IGNORADO. O usuário
 *     recebe silêncio (não respondemos a figurinhas com "não entendi" — só
 *     geramos ruído). Sticker é o caso mais comum: deixamos o Telegram mostrar
 *     a figurinha e nada mais.
 *
 * Por que a maior resolução? O Telegram envia múltiplos PhotoSize (thumbnail,
 * medium, large, xlarge, ...). O {@see ExtractFromImage} precisa
 * do maior para a extração do Gemini ter melhor OCR.
 *
 * Referência: docs/02-especificacao-tecnica.md §7 (fluxo), docs/06 §10 (M7.4).
 */
class MessageRouterHandler
{
    /**
     * Resolve o {@see ConversationRouter} do container Laravel a cada invocação.
     *
     * O Nutgram cria o handler via seu container interno, que não tem visibility
     * sobre o container Laravel (não há delegate configurado). Optamos por
     * resolver via helper `app()` (idiomático em handlers finos de bot) — evita
     * tocar no {@see TelegramServiceProvider} só para configurar
     * delegate de container. O custo de `app()` é uma chamada de hash de classe,
     * negligível.
     */
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        $chatId = (int) $message->chat->id;
        $router = app(ConversationRouter::class);

        $text = $message->getText();
        if ($text !== null) {
            $router->route(ConversationInput::text($chatId, $text));

            return;
        }

        $photoFileId = $this->extractLargestPhotoFileId($message);
        if ($photoFileId !== null) {
            $router->route(ConversationInput::photo($chatId, $photoFileId));

            return;
        }

        // Outros tipos de mensagem (sticker, áudio, etc.) — silencioso.
    }

    /**
     * Extrai o file_id da maior resolução entre os PhotoSize do Telegram.
     *
     * O Telegram entrega `photo` como array de PhotoSize em ordem crescente
     * de resolução. O último elemento é o maior; usamos `end($array)` para
     * obtê-lo sem precisar conhecer os tipos internos do PhotoSize.
     */
    private function extractLargestPhotoFileId(Message $message): ?string
    {
        $photo = $message->photo;
        if ($photo === null || $photo === []) {
            return null;
        }

        $largest = end($photo);
        if ($largest === false) {
            return null;
        }

        return $largest->file_id;
    }
}
