<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Conversation\WizardHandler;
use App\Enums\ConversationState;
use App\Enums\WizardStep;
use App\Services\Google\FirestoreService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler do comando `/nova` (M9.3 / T-018).
 *
 * Inicia o wizard passo-a-passo para criar uma transação manualmente.
 * É a alternativa à linguagem natural para usuários que preferem ser
 * guiados campo a campo (CT-025 happy path, CT-025l sobrescreve sessão
 * pendente).
 *
 * **Sequência do wizard (Portão 2 / Decisão #5)**: TYPE → AMOUNT →
 * DESCRIPTION → CATEGORY → LABELS → CONFIRMATION. Definida em
 * {@see WizardStep}.
 *
 * **Comportamento ao ser invocado com sessão ativa** (Decisão #2 do
 * Portão 2): o handler LIMPA a sessão existente incondicionalmente
 * (mesma estratégia do `StartHandler` e `CancelarHandler`) e inicia
 * o wizard do zero. Atende CT-025l — `/nova` durante AWAITING_CONFIRMATION
 * descarta a transação pendente e começa nova. UX consistente: o
 * usuário não precisa digitar `/cancelar` antes.
 *
 * **O que este handler NÃO faz**:
 *
 *  - Não toca o ConversationRouter — quem gerencia a sequência das
 *    etapas é o {@see WizardHandler}, que o Router
 *    invoca lazy quando detecta `_wizard_active` na sessão.
 *  - Não renderiza keyboards inline para a etapa 4 (categoria) — a
 *    Decisão #13 do Portão 2 fixou Opção A (texto livre com sugestão).
 *    O refinamento para inline keyboard fica para iteração futura.
 *  - Não envia a pergunta do campo "data" — a Decisão do spec §3.3
 *    define que o wizard usa HOJE como default e o Router preenche
 *    automaticamente antes da confirmação.
 *
 * **Edge cases**:
 *
 *  - Sem mensagem no update: defensivo, retorna silenciosamente (o
 *    Nutgram sempre popula message em comandos, mas é robusto).
 *  - Falha em `clearSession` ou `setSession`: loga e segue — best-effort.
 *    Em produção o webhook controller captura exceções; aqui apenas
 *    registramos o warning para diagnóstico.
 *
 * **Ref.:** `docs/specs/m9-spec-fase-2.md` §2.1, §3.1, `docs/planos/
 * m9-plano-tecnico.md` §2.Fase-D (T-018) e `docs/testes/m9-plano-testes.
 * md` CT-025/025l/025m/025n.
 */
final class NovaHandler
{
    /**
     * Mensagem da etapa 1 (Tipo) — exposta como constante para permitir
     * validação isolada em testes de messaging.
     */
    public const string STEP_1_PROMPT = '📝 <b>Nova transação — Etapa 1/6: Tipo</b>'
        ."\n\nQual o tipo da transação?\n\n"
        .'💸 <b>Despesa</b> &nbsp; 💰 <b>Receita</b>';

    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        $chatId = (string) (int) $message->chat->id;
        $services = app();
        $firestore = $services->make(FirestoreService::class);
        $messenger = $services->make(BotMessenger::class);

        // Decisão #2: /nova durante AWAITING_CONFIRMATION → limpar sessão
        // anterior e iniciar wizard (descartar pendente). CT-025l.
        $existingSession = $firestore->getSession($chatId);
        if ($existingSession !== null
            && ($existingSession['state'] ?? null) === ConversationState::AWAITING_CONFIRMATION->value
        ) {
            $this->notifyDiscarded($messenger, $chatId);
        }

        try {
            $firestore->clearSession($chatId);
        } catch (\Throwable $e) {
            // Best-effort: a limpeza pode falhar se já não existir; seguimos
            // mesmo assim. Log apenas para diagnóstico.
            Log::warning('NovaHandler: clearSession falhou (seguindo)', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }

        // Configura a sessão wizard. Draft começa vazio + flags `_wizard_*`
        // que o ConversationRouter::route() detecta para delegar ao WizardHandler.
        // `source='wizard'` é usado no confirm para o SyncSheet.
        try {
            $firestore->setSession(
                chatId: $chatId,
                state: ConversationState::AWAITING_DATA->value,
                draft: [
                    '_wizard_step' => WizardStep::TYPE->value,
                    '_wizard_active' => true,
                ],
                awaitingField: WizardStep::TYPE->fieldName(),
                source: 'wizard',
                retryCount: 0,
            );
        } catch (\Throwable $e) {
            Log::error('NovaHandler: setSession falhou', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $messenger->notifyError(
                $chatId,
                '⚠️ Não consegui iniciar o cadastro. Tente /nova novamente em alguns instantes.',
            );

            return;
        }

        // Envia a primeira pergunta.
        $messenger->askForField($chatId, WizardStep::TYPE->fieldName(), self::STEP_1_PROMPT);
    }

    /**
     * Avisa o usuário que a transação pendente foi descartada (Decisão #2).
     *
     * Mensagem curta antes da etapa 1 do wizard — não é bloqueante, é
     * informativa. O usuário pode ignorar e responder à etapa 1 normalmente.
     */
    private function notifyDiscarded(BotMessenger $messenger, string $chatId): void
    {
        $messenger->sendText(
            $chatId,
            '⚠️ <b>Você tem uma transação pendente de confirmação.</b>'
            ."\n\nA transação anterior foi descartada. Vamos começar uma nova:"
        );
    }
}
