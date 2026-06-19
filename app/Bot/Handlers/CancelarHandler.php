<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Services\Google\FirestoreService;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler do comando /cancelar (CT-026 — M9.4, mas implementado aqui como
 * peça de segurança do M7).
 *
 * Força o cancelamento da sessão atual do chat em qualquer estado — inclusive
 * sessão expirada (em que o Router já teria limpado) ou sessão em processamento
 * (transação "presa" — ex.: crash entre tryAcquireSessionProcessingFlag e
 * clearSession).
 *
 * Por que NÃO chamar o {@see \App\Conversation\ConversationRouter}?
 *
 *  - Independência de estado: este handler deve funcionar mesmo quando não
 *    há sessão ou quando a sessão está em estado inconsistente (processing=true
 *    preso). O Router exige uma sessão válida para dispatch.
 *  - Simplicidade: o trabalho aqui é apenas `clearSession + notifyCancelled`
 *    — encapsular no Router adicionaria ramos especiais que não agregam valor.
 *
 * Edge case — sem mensagem no update: isso seria impossível se o Nutgram já
 * roteou o comando /cancelar (que é uma mensagem), mas defendemos retornando
 * silenciosamente para o caso de bug do framework.
 */
class CancelarHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        // S-5: padronizado para (int) na origem — consistente com
        // MessageRouterHandler e CallbackQueryRouterHandler. O casting final
        // para string é feito inline porque FirestoreService exige `string`
        // (strict_types=1 impede coerção automática).
        $chatId = (int) $message->chat->id;
        $chatIdStr = (string) $chatId;

        // S-8: resolve o container uma única vez em vez de duas chamadas app().
        $services = app();
        $services->make(FirestoreService::class)->clearSession($chatIdStr);
        $services->make(BotMessenger::class)->notifyCancelled($chatIdStr);
    }
}
