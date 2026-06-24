<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\SessionMessageCleaner;
use App\Conversation\ConversationRouter;
use App\Enums\ConversationState;
use App\Services\Google\FirestoreService;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler do comando /cancelar (M9.4 / CT-026 — T-003).
 *
 * Força o cancelamento da sessão atual do chat em qualquer estado **ativo**
 * — inclusive sessão expirada (em que o Router já teria limpado) ou sessão em
 * processamento (transação "presa" — ex.: crash entre
 * `tryAcquireSessionProcessingFlag` e `clearSession`).
 *
 * **GAP-02 (M9 / Portão 2)**: o handler AGORA distingue IDLE × sessão ativa.
 * Quando o chat está em IDLE (sem sessão ou com `state='idle'`), responde
 * com mensagem amigável "Nenhuma operação em andamento para cancelar" e
 * NÃO chama `notifyCancelled` (que sempre envia "🚫 Transação cancelada..."
 * — mensagem enganosa em IDLE). Atende CT-026a.
 *
 * Por que NÃO chamar o {@see ConversationRouter}?
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
    /**
     * Invoca o handler: detecta se há sessão ativa. Em IDLE responde
     * mensagem amigável (CT-026a); em qualquer outro estado limpa a
     * sessão + notifica o cancelamento (CT-026b a CT-026e).
     *
     * @param  Nutgram  $bot  Instância do bot injetada pelo BotLoader.
     */
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        // FirestoreService exige `string` (strict_types=1 impede coerção
        // automática), então convertemos o ID numérico do Telegram.
        $chatId = (int) $message->chat->id;
        $chatIdStr = (string) $chatId;

        $services = app();
        $firestore = $services->make(FirestoreService::class);
        $messenger = $services->make(BotMessenger::class);

        // T-003: detecta IDLE. Se não há sessão OU a sessão tem state='idle',
        // emite mensagem amigável e NÃO chama notifyCancelled (CT-026a).
        $session = $firestore->getSession($chatIdStr);
        $isIdle = $session === null || ($session['state'] ?? null) === ConversationState::IDLE->value;

        if ($isIdle) {
            $messenger->sendText(
                $chatIdStr,
                "🤷 <b>Nenhuma operação em andamento</b> para cancelar.\n\n"
                .'Você está no início — é só me mandar uma mensagem para começar.',
            );

            return;
        }

        // Caso normal (CT-026b, CT-026c, CT-026d, CT-026e): clearSession + notifyCancelled.

        // R1 — remove teclados inline de X e Y via SessionMessageCleaner.
        $services->make(SessionMessageCleaner::class)->cleanup($chatIdStr, $session);

        $firestore->clearSession($chatIdStr);
        $messenger->notifyCancelled($chatIdStr);
    }
}
