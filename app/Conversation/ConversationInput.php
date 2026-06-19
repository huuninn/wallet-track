<?php

declare(strict_types=1);

namespace App\Conversation;

/**
 * DTO de input do Telegram normalizado (M7.3).
 *
 * Abstrai os diferentes formatos de update do Telegram (mensagem de texto,
 * mensagem com foto, callback query) em uma única estrutura consumida pelo
 * {@see ConversationRouter}. Isto desacopla o Router da SDK do Nutgram —
 * o {@see \App\Bot\BotLoader} converte os updates brutos em instâncias
 * deste DTO, e o Router nunca toca `Nutgram`, `Message` ou `CallbackQuery`
 * diretamente.
 *
 * A disciplina de preenchimento é responsabilidade do BotLoader:
 *  - `kind = Text`   → `text` obrigatório.
 *  - `kind = Photo`  → `photoFileId` obrigatório.
 *  - `kind = Callback` → `callbackId`, `callbackData`, `callbackMessageId`
 *                       obrigatórios (`callbackMessageId` é o id da mensagem
 *                       que carregava o keyboard — usado para CT-047).
 *
 * Readonly + promoted: structs imutáveis comparáveis estruturalmente.
 */

/**
 * Tipo do input recebido do Telegram.
 */
enum InputKind: string
{
    /** Mensagem de texto livre do usuário. */
    case Text = 'text';

    /** Mensagem com foto (nota fiscal/cupom) para OCR. */
    case Photo = 'photo';

    /** Toque em botão do inline keyboard (Confirmar/Editar/Cancelar/...). */
    case Callback = 'callback';
}

/**
 * @see InputKind
 */
final readonly class ConversationInput
{
    public function __construct(
        /**
         * ID do chat Telegram (de onde veio o update).
         * Aceita int|string para compatibilidade com o gateway (que usa
         * string como id de documento Firestore).
         */
        public int|string $chatId,
        public InputKind $kind,
        /**
         * Texto da mensagem (kind=Text) OU resposta do usuário em
         * AWAITING_DATA/AWAITING_EDITION (kind=Text).
         */
        public ?string $text = null,
        /** Identificador Telegram da foto em maior resolução (kind=Photo). */
        public ?string $photoFileId = null,
        /** ID do callback_query para ack via answerCallback (kind=Callback). */
        public ?string $callbackId = null,
        /** Payload do botão (ex.: "confirm", "edit:amount") — kind=Callback. */
        public ?string $callbackData = null,
        /**
         * message_id da mensagem com o keyboard que originou o callback
         * (kind=Callback). Usado para CT-047: rejeitar callbacks de
         * keyboards antigos já cancelados/processados.
         */
        public ?int $callbackMessageId = null,
    ) {}

    /**
     * Factory para mensagens de texto livre ou respostas a campo pedível.
     */
    public static function text(int|string $chatId, string $text): self
    {
        return new self(chatId: $chatId, kind: InputKind::Text, text: $text);
    }

    /**
     * Factory para mensagens com foto (nota fiscal/cupom).
     */
    public static function photo(int|string $chatId, string $photoFileId): self
    {
        return new self(chatId: $chatId, kind: InputKind::Photo, photoFileId: $photoFileId);
    }

    /**
     * Factory para toques em inline keyboard (botões Confirmar/Editar/Cancelar/...).
     *
     * @param  string|null  $callbackMessageId  ID da mensagem que carregava o keyboard
     *                                          (essencial para CT-047). Pode ser null
     *                                          em modo inline (improvável no bot de uso pessoal).
     */
    public static function callback(
        int|string $chatId,
        string $callbackId,
        ?string $callbackData = null,
        ?int $callbackMessageId = null,
    ): self {
        return new self(
            chatId: $chatId,
            kind: InputKind::Callback,
            callbackId: $callbackId,
            callbackData: $callbackData,
            callbackMessageId: $callbackMessageId,
        );
    }
}
