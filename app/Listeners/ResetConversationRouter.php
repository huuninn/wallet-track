<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Conversation\ConversationRouter;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestReceived;
use Throwable;

/**
 * Listener Octane que reseta o estado mutável por-request do
 * {@see ConversationRouter}.
 *
 * A cada evento {@see RequestReceived} (antes do controller processar
 * o update do Telegram), este listener chama
 * {@see ConversationRouter::resetState()}, que zera:
 *
 *  - {@see ConversationRouter::$wizardHandler} — handler lazy do wizard
 *    `/nova`, que carrega referência ao próprio Router e poderia manter
 *    estado de wizard entre requests de chat_ids diferentes.
 *  - {@see ConversationRouter::$cachedLabelCatalog} — cache em memória
 *    do catálogo de labels do usuário, que é específico por `chat_id`
 *    e deve ser invalidado a cada request.
 *
 * As dependências injetadas (StateMachine, WalletStore, BotMessenger,
 * ExtractsText/Image, SyncsSheet, SuggestCategory, SuggestsLabels) NÃO
 * são afetadas — são stateless ou mantêm estado próprio isolado por
 * `chat_id` via Redis/banco de dados.
 *
 * ## Robustez
 *
 * O reset é envolto em try/catch defensivo, consistente com o padrão
 * do {@see ResetNutgramState}. Uma falha aqui não pode derrubar o
 * request — estado stale no Router é preferível a 500 em TODAS as
 * requests. O listener roda pré-controller, antes de qualquer lógica
 * de negócio.
 *
 * @see ConversationRouter::resetState()
 */
final class ResetConversationRouter
{
    public function __construct(
        private readonly ConversationRouter $router,
    ) {}

    public function handle(RequestReceived $event): void
    {
        try {
            $this->router->resetState();
        } catch (Throwable $e) {
            Log::warning('ResetConversationRouter: falha ao resetar estado do ConversationRouter', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
