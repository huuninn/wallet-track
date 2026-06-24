<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SuggestCategory;
use App\Actions\SuggestLabels;
use App\Actions\SyncsSheet;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Enums\ConversationState;
use App\Exceptions\ExtractionException;
use App\Services\Google\FirestoreService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roteador central da máquina de estados conversacional (M7.3 — M7.10 + M8).
 *
 * Recebe um {@see ConversationInput} (texto/foto/callback normalizado) e
 * decide o que fazer com base no estado atual da sessão Firestore do chat.
 * Toda mutação de sessão, todo envio de mensagem e toda chamada de extração
 * passam por aqui — nenhum handler Nutgram toca Firestore ou a SDK do LLM
 * diretamente. Esta é a peça que torna o bot conversacional: ela implementa
 * o fluxo IDLE → AWAITING_DATA → AWAITING_CONFIRMATION → AWAITING_EDITION
 * descrito em docs/02-especificacao-tecnica.md §7.
 *
 * Princípios de design:
 *
 *  - **Pura lógica, fácil de testar**: depende apenas de interfaces
 *    (ExtractsText/Image, SyncsSheet, BotMessenger, FirestoreService) e do
 *    {@see StateMachine}. Pode ser instanciada em testes com stubs anonimos
 *    (ver ConversationRouterTest).
 *  - **NÃO captura \Exception em route()**: o TelegramWebhookController já
 *    captura exceções (\Exception e \Error) e responde 200 — a regra de ouro
 *    do webhook. Mas chamadas externas (extractText, extractImage) SÃO
 *    protegidas (ExtractionException) com fallback amigável, conforme o spec.
 *  - **Idempotência via `processing` flag**: a confirmação adquire o flag
 *    atomicamente (FirestoreService::tryAcquireSessionProcessingFlag); duplo
 *    clique é bloqueado (CT-018).
 *  - **CT-047 (callback de keyboard antiga)**: validado PRIMEIRO em qualquer
 *    callback quando state=AWAITING_CONFIRMATION, comparando
 *    `callbackMessageId` com `message_id_confirm` E `message_id_edit_picker`
 *    da sessão (P6 — aceita X ou Y).
 *  - **Timeout 15min (CT-043/CT-018b)**: o primeiro passo de `route()` é
 *    carregar a sessão; se `updated_at` é mais antigo que
 *    `sessionTimeoutMinutes`, trata como expirada.
 *  - **Campos pedíveis amount/type/date**: o estado AWAITING_DATA guarda
 *    qual campo está em aberto (`awaiting_field`). Cada resposta é validada
 *    por um validador dedicado antes de atualizar o draft. Acima do limite
 *    `maxDataRetries`, o bot desiste (limpa sessão + notifica) em vez de
 *    loopar.
 *  - **M8 — Heurística de labels/categoria**: antes de exibir a confirmação
 *    o Router enriquece o DTO com sugestões (labels via histórico+keywords;
 *    categoria via fuzzy match), e após a confirmação incrementa os
 *    contadores de uso (CT-022) e cria categoria nova se for o caso
 *    (CT-012). Falhas de tracking são isoladas em try/catch — não podem
 *    bloquear o confirm do usuário.
 *
 * Não faz parte do escopo desta classe:
 *
 *  - Comandos auxiliares /nova, /ultimos, /categorias, /sync (M9).
 *  - Re-tentativa automática de sync (M9 — SyncPendingTransactions command).
 */
final class ConversationRouter
{
    /**
     * Regex para validar a string já normalizada (após parsing do decimal).
     *
     * Aceita:
     *  - dígitos
     *  - separador decimal único . com 1+ dígitos após
     *
     * Rejeita: vazio, vírgula residual, sinal negativo, espaços, letras.
     * A normalização para o formato canônico (último separador com 1-2
     * dígitos como decimal) é feita em {@see validateAmount()}.
     */
    private const string AMOUNT_REGEX = '/^\d+(\.\d+)?$/';

    /** Tipos canônicos aceitos (case-insensitive). */
    private const array TYPE_MAP = [
        'despesa' => 'expense',
        'expense' => 'expense',
        'gasto' => 'expense',
        'receita' => 'income',
        'income' => 'income',
        'ganho' => 'income',
    ];

    /**
     * Campos aceitos para edição via callback `edit:<field>` (M7.6, M9.3).
     *
     * Usado nos handlers AWAITING_CONFIRMATION e AWAITING_EDITION para
     * validar o payload do botão "Editar campo". Hash set para lookup O(1)
     * com `isset()`. P7-A-2: substitui `in_array` O(n) — otimização
     * marginal mas mantém consistência com o padrão de hash maps do código.
     *
     * @var array<string, true>
     */
    private const array EDITABLE_FIELDS_MAP = [
        'amount' => true,
        'type' => true,
        'date' => true,
        'description' => true,
        'category' => true,
        'observations' => true,
    ];

    /**
     * Limite máximo de caracteres para o campo observações.
     *
     * Observações podem ser mais longas que a descrição (que é truncada em
     * 500 chars via {@see TransactionData::DESCRIPTION_MAX_LENGTH}), mas
     * ainda precisam de um teto para evitar abuso (ex.: 10 KB de texto).
     */
    private const int OBSERVATIONS_MAX_LENGTH = 1000;

    /**
     * Janela (minutos) de validade da sessão — antes disso, considera expirada.
     */
    private readonly int $sessionTimeoutMinutes;

    /**
     * Limite de retentativas de validação de campo pedível antes de desistir.
     */
    private readonly int $maxDataRetries;

    /**
     * Handler do wizard `/nova` (M9.3). Lazy — só instanciado quando
     * `route()` detecta o flag `_wizard_active` na sessão, evitando custo
     * desnecessário para o fluxo de linguagem natural (M7/M8).
     */
    private ?WizardHandler $wizardHandler = null;

    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly BotMessenger $messenger,
        private readonly TransactionSummaryFormatter $formatter,
        private readonly FirestoreService $firestore,
        private readonly ExtractsText $extractText,
        private readonly ExtractsImage $extractImage,
        private readonly SyncsSheet $syncSheet,
        private readonly SuggestCategory $suggestCategory,
        private readonly SuggestLabels $suggestLabels,
        int $sessionTimeoutMinutes,
        int $maxDataRetries,
    ) {
        $this->sessionTimeoutMinutes = $sessionTimeoutMinutes;
        $this->maxDataRetries = $maxDataRetries;
    }

    /**
     * Factory lazy do {@see WizardHandler} (M9.3 / T-016).
     *
     * Cria o handler sob demanda na primeira invocação de `route()` que
     * detecta wizard ativo. Lazy porque o wizard é uma feature opcional
     * (só `/nova` aciona) — não queremos pagar o custo de construção
     * (passa `ConversationRouter` como "friend" para reusar
     * `validateField` e `presentConfirmation`) em todo request.
     */
    private function wizardHandler(): WizardHandler
    {
        return $this->wizardHandler ??= new WizardHandler(
            router: $this,
            firestore: $this->firestore,
            messenger: $this->messenger,
            formatter: $this->formatter,
        );
    }

    /**
     * Roteia o input recebido para o handler de estado apropriado.
     *
     * Esta é a única entrada pública — todo o roteamento (texto/foto/callback
     * × IDLE/AWAITING_DATA/AWAITING_CONFIRMATION/AWAITING_EDITION) flui
     * através deste método. Exceções de programação propagam (o controller
     * HTTP captura); falhas estruturais de extração são tratadas com fallback
     * amigável.
     *
     * **M9.3 (T-016)**: se a sessão tem o flag `_wizard_active` no draft,
     * delega ao {@see WizardHandler} ANTES do dispatch principal de estado.
     * O wizard é uma sub-máquina auto-contida que herda validação e
     * enriquecimento M8 do Router.
     */
    public function route(ConversationInput $input): void
    {
        $chatId = (string) $input->chatId;
        $session = $this->firestore->getSession($chatId);
        $state = ConversationState::fromSession($session['state'] ?? null);

        // CT-018b / CT-043: sessão expirada por timeout.
        if ($session !== null && $this->isExpired($session)) {
            $this->handleExpiredSession($chatId);
            // Após expirar, recarrega e re-roteia como IDLE (a sessão agora é null).
            $session = null;
            $state = ConversationState::IDLE;
        }

        // M9.3 (T-016): detecção de wizard `/nova` ativo. Se a sessão tem o
        // flag `_wizard_active=true` no draft, delegamos para o WizardHandler
        // (sub-máquina) em vez do AWAITING_DATA genérico. O wizard é
        // transparente para o resto do Router — apenas injetamos o delegate
        // antes do match principal.
        if ($state === ConversationState::AWAITING_DATA
            && ! empty($session['draft']['_wizard_active'])
        ) {
            $this->wizardHandler()->handleStep($input, $chatId, $session);

            return;
        }

        match ($state) {
            ConversationState::IDLE => $this->handleIdle($input, $chatId),
            ConversationState::AWAITING_DATA => $this->handleAwaitingData($input, $chatId, $session),
            ConversationState::AWAITING_CONFIRMATION => $this->handleAwaitingConfirmation($input, $chatId, $session),
            ConversationState::AWAITING_EDITION => $this->handleAwaitingEdition($input, $chatId, $session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Handlers de estado
    |--------------------------------------------------------------------------
    */

    /**
     * Estado IDLE: input livre vira extração (texto ou foto).
     *
     * Callback órfão aqui significa: o usuário tocou num botão mas não há
     * sessão ativa (ex.: keyboard antiga, mensagem de confirmação já limpa).
     * Não criamos sessão a partir de callback — apenas informamos.
     *
     * S-3: parâmetro `$session` removido — nunca foi usado no corpo.
     */
    private function handleIdle(ConversationInput $input, string $chatId): void
    {
        if ($input->kind === InputKind::Callback) {
            // CT-047 parte 1: callback órfão (sem sessão em AWAITING_CONFIRMATION).
            $this->messenger->answerCallback((string) $input->callbackId, '');
            $this->messenger->notifyError(
                $chatId,
                'Esta confirmação não está mais ativa. Envie uma nova mensagem para começar.',
            );

            return;
        }

        if ($input->kind === InputKind::Photo) {
            $this->handlePhotoExtraction($chatId, (string) $input->photoFileId, 'image');

            return;
        }

        // Text.
        $this->handleTextExtraction($chatId, (string) $input->text, 'text');
    }

    /**
     * Estado AWAITING_DATA: usuário respondendo um campo pedível.
     *
     * - Texto: valida o campo respondido (`awaiting_field` da sessão), atualiza
     *   o draft, e re-avalia. Se completo → confirmation; senão → re-pergunta.
     * - Foto: extrai e mescla (campos não-null do DTO extraído sobrescrevem
     *   campos null do draft atual — uma foto bem tirada pode trazer o valor
     *   que faltava).
     * - Callback: silencioso (não faz sentido; usuário deveria estar digitando).
     *
     * @param  array<string, mixed>  $session
     */
    private function handleAwaitingData(ConversationInput $input, string $chatId, array $session): void
    {
        $awaitingField = (string) ($session['awaiting_field'] ?? '');
        $draft = TransactionData::fromDraftArray($session['draft'] ?? null);

        if ($input->kind === InputKind::Callback) {
            // Silencioso — só remove o "carregando" do cliente.
            $this->messenger->answerCallback((string) $input->callbackId, '');

            return;
        }

        if ($input->kind === InputKind::Photo) {
            // Foto pode trazer campos que faltavam — extrai e mescla.
            try {
                $extracted = $this->extractImage->handle((string) $input->photoFileId);
            } catch (ExtractionException $e) {
                $this->messenger->notifyError(
                    $chatId,
                    'Não identifiquei uma transação clara na imagem. Você pode descrever por texto ou enviar outra foto.',
                );

                return;
            }

            $merged = $this->mergeDrafts($draft, $extracted);

            if ($merged->isComplete()) {
                $this->presentConfirmation($chatId, $merged, $session['source'] ?? 'image');

                return;
            }

            // Re-pergunta o próximo campo pedível.
            $next = $this->pickNextAwaitingField($merged, $session);
            $this->assertStateTransition($session, ConversationState::AWAITING_DATA->value);
            $this->firestore->setSession(
                $chatId,
                new SessionData(
                    state: ConversationState::AWAITING_DATA->value,
                    draft: $merged->toDraftArray(),
                    awaitingField: $next,
                    source: $session['source'] ?? 'image',
                    retryCount: 0,
                ),
            );
            $this->messenger->askForField($chatId, $next, $this->formatter->askPrompt($next));

            return;
        }

        // Text: valida o campo respondido.
        $normalized = $this->validateField($awaitingField, (string) $input->text);

        if ($normalized === null) {
            $this->handleInvalidDataResponse($chatId, $session, $awaitingField);

            return;
        }

        $newDraft = $draft->withField($awaitingField, $normalized);

        if ($newDraft->isComplete()) {
            $this->presentConfirmation($chatId, $newDraft, $session['source'] ?? 'text');

            return;
        }

        // Ainda falta — pede o próximo campo pedível.
        $next = $this->pickNextAwaitingField($newDraft, $session);
        $this->assertStateTransition($session, ConversationState::AWAITING_DATA->value);
        $this->firestore->setSession(
            $chatId,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: $newDraft->toDraftArray(),
                awaitingField: $next,
                source: $session['source'] ?? 'text',
                retryCount: 0, // resposta válida zera o contador
            ),
        );
        $this->messenger->askForField($chatId, $next, $this->formatter->askPrompt($next));
    }

    /**
     * Estado AWAITING_CONFIRMATION: o usuário deve tocar Confirmar/Editar/Cancelar.
     *
     * - Callback: PRIMEIRO valida CT-047 (message_id_confirm). Depois despacha
     *   por `data`: confirm/edit/cancel/edit:<field>/desconhecido.
     * - Texto/foto: trata como "quero re-começar" — cancela sessão, re-extrai.
     *
     * @param  array<string, mixed>  $session
     */
    private function handleAwaitingConfirmation(ConversationInput $input, string $chatId, array $session): void
    {
        if ($input->kind === InputKind::Callback) {
            // CT-047: callback de keyboard antiga. P6 — aceita callbacks de
            // message_id_confirm (X) OU message_id_edit_picker (Y).
            $callbackMessageId = (int) ($input->callbackMessageId ?? 0);

            if ($callbackMessageId > 0
                && ! $this->isCallbackFromValidMessage($session, $callbackMessageId)
            ) {
                $this->messenger->answerCallback(
                    (string) $input->callbackId,
                    'Esta confirmação não está mais ativa.',
                );
                $this->messenger->notifyError(
                    $chatId,
                    'Esta confirmação não está mais ativa. Envie uma nova mensagem para começar.',
                );

                return;
            }

            $data = (string) ($input->callbackData ?? '');

            // Preservado localmente — usado adiante no handler `edit:<field>`
            // como messageIdConfirm ao persistir AWAITING_EDITION.
            $currentConfirmId = (int) ($session['message_id_confirm'] ?? 0);

            if ($data === 'confirm') {
                $this->removeConfirmationKeyboard($chatId, $session);
                $this->handleConfirm($chatId, $session, (string) $input->callbackId);

                return;
            }

            if ($data === 'edit') {
                // S1: idempotência — se já existe picker ativo, ignora o clique
                // duplicado. O picker original permanece; será deletado
                // normalmente quando o user escolher um campo (P2=B) ou
                // confirmar/cancelar (P3=B).
                $existingPickerId = (int) ($session['message_id_edit_picker'] ?? 0);
                if ($existingPickerId > 0) {
                    $this->messenger->answerCallback(
                        (string) $input->callbackId,
                        'Picker de edição já está aberto.',
                    );

                    return;
                }

                $this->removeConfirmationKeyboard($chatId, $session);
                $this->messenger->answerCallback((string) $input->callbackId, '');

                // P1: persistir o message_id do picker como segundo anchor (Y)
                // para que callbacks edit:<field> passem no CT-047.
                //
                // S6: self-transition intencional (AWAITING_CONFIRMATION →
                // AWAITING_CONFIRMATION). O estado não muda porque o picker
                // é uma mensagem adicional, não um novo passo do fluxo.
                // Permitido pela tabela do {@see StateMachine} (linha 55).
                $this->assertStateTransition($session, ConversationState::AWAITING_CONFIRMATION->value);
                $pickerMessageId = $this->messenger->sendEditFieldPicker($chatId);
                $this->firestore->setSession(
                    $chatId,
                    new SessionData(
                        state: ConversationState::AWAITING_CONFIRMATION->value,
                        draft: $session['draft'] ?? null,
                        messageIdConfirm: (int) ($session['message_id_confirm'] ?? 0),
                        messageIdEditPicker: $pickerMessageId,
                        source: $session['source'] ?? 'text',
                        retryCount: 0,
                    ),
                    clearFields: ['awaiting_field'],
                );

                return;
            }

            if ($data === 'cancel') {
                $this->messenger->answerCallback((string) $input->callbackId, 'Cancelado');
                $this->removeConfirmationKeyboard($chatId, $session);

                $this->messenger->notifyCancelled($chatId);
                $this->assertStateTransition($session, ConversationState::IDLE->value);
                $this->firestore->clearSession($chatId);

                return;
            }

            if (str_starts_with($data, 'edit:')) {
                $field = substr($data, 5);
                if (isset(self::EDITABLE_FIELDS_MAP[$field])) {
                    $this->messenger->answerCallback((string) $input->callbackId, '');

                    // Remove o keyboard do picker Y imediatamente.
                    // Defesa em camadas: annulStaleEditClickInEdition
                    // cobre race conditions (cliente offline, latência).
                    $pickerId = (int) ($session['message_id_edit_picker'] ?? 0);
                    if ($pickerId > 0) {
                        $this->messenger->editMessageReplyMarkup($chatId, $pickerId, null);
                    }

                    // R2: envia o prompt "Digite o novo ..." (Z). O prompt
                    // permanece como histórico — não é deletado após edição.
                    $this->messenger->askForEdition($chatId, $field);

                    $this->assertStateTransition($session, ConversationState::AWAITING_EDITION->value);
                    $this->firestore->setSession(
                        $chatId,
                        new SessionData(
                            state: ConversationState::AWAITING_EDITION->value,
                            draft: $session['draft'] ?? null,
                            awaitingField: $field,
                            messageIdConfirm: $currentConfirmId,
                            messageIdEditPicker: (int) ($session['message_id_edit_picker'] ?? 0), // Y preservado
                            source: $session['source'] ?? 'text',
                            retryCount: 0,
                        ),
                        // R2: messageIdAskEdition depreciado — não grava mais.
                        // Limpa campos legacy/stale que podem existir em sessões antigas.
                        clearFields: ['message_id_ask_edition'],
                    );

                    return;
                }
            }

            // Callback desconhecido: silencioso (apenas para o "carregando").
            Log::warning('ConversationRouter: callback_data não mapeado em AWAITING_CONFIRMATION', [
                'chat_id' => $chatId,
                'callback_data' => $data,
            ]);
            $this->messenger->answerCallback((string) $input->callbackId, '');

            return;
        }

        // Texto ou foto: assume "quero re-começar" — cancela e re-extrai.
        $this->messenger->notifyCancelled($chatId);
        $this->assertStateTransition($session, ConversationState::IDLE->value);
        $this->firestore->clearSession($chatId);

        if ($input->kind === InputKind::Photo) {
            $this->handlePhotoExtraction($chatId, (string) $input->photoFileId, 'image');

            return;
        }

        $this->handleTextExtraction($chatId, (string) $input->text, 'text');
    }

    /**
     * Estado AWAITING_EDITION: usuário respondendo o prompt "qual o novo valor?".
     *
     * - Texto: valida o campo (`awaiting_field`), atualiza o draft, volta
     *   para AWAITING_CONFIRMATION editando a mensagem original in-place.
     *   Reset retry_count=0 (edição bem-sucedida zera o contador).
     * - Callback `edit:*`: annullado (P7-A). O usuário deveria estar
     *   respondendo com texto; clicks em botões antigos são no-ops. Re-pick
     *   via P5 foi removido em favor de P7-A (decisão do usuário — trade-off:
     *   usuário que errar precisa cancelar e recomeçar).
     * - Callback `confirm`/`cancel`: respondemos com feedback amigável
     *   ("Esta ação não está disponível durante a edição") para que o
     *   usuário saiba por que o botão não funcionou — antes do P7-A o
     *   CT-047 dava "Esta edição não está mais ativa" nesses casos.
     * - Foto: erro amigável (não dá pra editar via foto).
     *
     * @param  array<string, mixed>  $session
     */
    private function handleAwaitingEdition(ConversationInput $input, string $chatId, array $session): void
    {
        $awaitingField = (string) ($session['awaiting_field'] ?? '');
        $draft = TransactionData::fromDraftArray($session['draft'] ?? null);
        $messageIdConfirm = (int) ($session['message_id_confirm'] ?? 0);

        if ($input->kind === InputKind::Callback) {
            $data = (string) ($input->callbackData ?? '');

            // P7-A: annullar QUALQUER click em edit:* durante AWAITING_EDITION.
            // Aplica-se tanto a clicks no picker Y (visível) quanto a clicks
            // em qualquer outra keyboard stale. Não fazemos CT-047 check aqui:
            // em AWAITING_EDITION não há keyboard válida esperando interação.
            if ($this->annulStaleEditClickInEdition($input, $data)) {
                return;
            }

            // W1 (P7-A-2): clicks em confirm/cancel precisam de feedback
            // explícito. Antes do P7-A, o CT-047 dava "Esta edição não está
            // mais ativa" nesses casos. Agora o annulStaleEditClickInEdition
            // só pega edit:*, então confirm/cancel caem aqui. Damos um toast
            // amigável para o user entender por que nada aconteceu.
            if ($data === 'confirm' || $data === 'cancel') {
                $this->messenger->answerCallback(
                    (string) $input->callbackId,
                    'Esta ação não está disponível durante a edição. '
                    .'Responda à pergunta ou use /cancelar para voltar.',
                );

                return;
            }

            // Outros callbacks (silencioso — não faz sentido neste estado).
            $this->messenger->answerCallback((string) $input->callbackId, '');

            return;
        }

        if ($input->kind === InputKind::Photo) {
            $this->messenger->notifyError(
                $chatId,
                'Para editar uma transação, envie texto. Para começar uma nova, use /cancelar primeiro.',
            );

            return;
        }

        // Text: valida e atualiza o draft.
        $normalized = $this->validateField($awaitingField, (string) $input->text);

        if ($normalized === null) {
            $this->handleInvalidEditionResponse($chatId, $session, $awaitingField);

            return;
        }

        // Captura valor antigo ANTES de withField.
        $oldRaw = $draft->getFieldValue($awaitingField);
        $newDraft = $draft->withField($awaitingField, $normalized);

        // R2: NÃO deleta Z, NÃO deleta Y, NÃO edita X in-place.
        // Em vez disso: envia feedback + nova confirmação X_new.

        // 1. Mensagem de feedback "campo alterado de X para Y".
        $this->messenger->sendText(
            $chatId,
            $this->formatter->fieldChangeMessage($awaitingField, $oldRaw, $normalized),
        );

        // 2. NOVA confirmação X_new (D1: não chama presentConfirmation para
        //    evitar re-enriquecimento M8 — o draft já tem categoria/labels).
        $newConfirmId = $this->messenger->sendConfirmationRequest($chatId, $newDraft);

        // 3. Persiste sessão com message_id_confirm sobrescrito (D3: CT-047
        //    rejeita X_old automaticamente).
        $this->assertStateTransition($session, ConversationState::AWAITING_CONFIRMATION->value);
        $this->firestore->setSession(
            $chatId,
            new SessionData(
                state: ConversationState::AWAITING_CONFIRMATION->value,
                draft: $newDraft->toDraftArray(),
                awaitingField: null,
                messageIdConfirm: $newConfirmId,
                source: $session['source'] ?? 'text',
                retryCount: 0,
            ),
            clearFields: [
                'awaiting_field',
                'message_id_ask_edition',
                'message_id_edit_picker',
                'picker_consumed',
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de sessão
    |--------------------------------------------------------------------------
    */

    /**
     * Envia a confirmação e persiste a sessão em AWAITING_CONFIRMATION.
     *
     * O `message_id_confirm` retornado pelo messenger é a âncora do CT-047:
     * callbacks de keyboards de mensagens antigas (com message_id diferente)
     * serão rejeitados.
     *
     * **M8 — Enriquecimento com sugestões** (CT-011, CT-019, CT-020, CT-021):
     * antes de enviar o keyboard, o DTO é passado por
     * {@see enrichDtoWithSuggestions()}, que adiciona:
     *  - categoria sugerida (fuzzy match ou default "Outros");
     *  - labels sugeridas (até 5, histórico → keywords).
     * O resultado enriquecido é o que o usuário vê e edita, e o que será
     * persistido se ele confirmar.
     *
     * **M9.3 (T-016)**: tornado público para reuso pelo
     * {@see WizardHandler}, que o invoca quando a 5ª etapa
     * (labels) é validada com sucesso e o draft está completo. Centralizar
     * a finalização no Router garante que o wizard herda o enriquecimento
     * M8 (sugestões de categoria e labels) sem duplicar lógica.
     */
    public function presentConfirmation(string $chatId, TransactionData $dto, string $source): void
    {
        $enriched = $this->enrichDtoWithSuggestions($dto);

        $messageId = $this->messenger->sendConfirmationRequest($chatId, $enriched);

        $this->assertStateTransition(
            $this->firestore->getSession($chatId),
            ConversationState::AWAITING_CONFIRMATION->value,
        );
        $this->firestore->setSession(
            $chatId,
            new SessionData(
                state: ConversationState::AWAITING_CONFIRMATION->value,
                draft: $enriched->toDraftArray(),
                awaitingField: null,
                messageIdConfirm: $messageId,
                source: $source,
                retryCount: 0,
            ),
            // M9.3 (T-016): limpa campos stale do wizard. Garante que
            // `_wizard_step` e `_wizard_active` não persistam em
            // AWAITING_CONFIRMATION — no-op se não existirem (caso do
            // fluxo de linguagem natural).
            clearFields: ['awaiting_field', '_wizard_step', '_wizard_active'], // W-3: limpa campo stale
        );
    }

    /**
     * Enriquece o DTO com sugestões de categoria e labels (M8).
     *
     * Pipeline (CT-011, CT-019, CT-020, CT-021):
     *
     *  1. {@see SuggestCategory::suggest()} resolve a categoria final
     *     (fuzzy match → existente; abaixo do threshold → nova; sem
     *     entrada → default). O `display` retornado é aplicado ao DTO.
     *  2. {@see SuggestLabels::suggest()} recebe a categoria final
     *     (apenas o `name` canônico quando NÃO for nova) e a descrição,
     *     e devolve até 5 labels. O resultado é mergeado com
     *     `$dto->labels` (deduplicado, ordem preservada) — o usuário pode
     *     sempre editar antes de confirmar (CT-021), então apenas somar
     *     sugestões ao que já existe é o comportamento conservador certo.
     *  3. Imutável: devolve um novo {@see TransactionData} (helpers
     *     `withCategory`/`withLabels`).
     *
     * Falhas de qualquer uma das heurísticas (ex.: Firestore indisponível
     * para buscar histórico) são capturadas localmente — uma exceção aqui
     * não pode impedir o usuário de confirmar uma transação. O log
     * warning fica disponível para diagnóstico.
     */
    private function enrichDtoWithSuggestions(TransactionData $dto): TransactionData
    {
        try {
            $category = $this->suggestCategory->suggest($dto->category, $dto->description);
        } catch (Throwable $e) {
            Log::warning('ConversationRouter: SuggestCategory falhou — seguindo sem categoria sugerida', [
                // W-2: hash do description em vez do texto cru — evita vazar
                // PII financeira nos logs em caso de falha.
                'dto_hash' => hash('xxh3', $dto->description ?? ''),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            // Fallback: categoria default já existente ou nula.
            $category = ['name' => 'outros', 'display' => SuggestCategory::DEFAULT_CATEGORY, 'isNew' => false];
        }

        $dtoWithCategory = $dto->withCategory($category['display']);

        try {
            // Passa a categoria apenas se for EXISTENTE (isNew=false): labels
            // devem ser sugeridas com base em histórico já populado, não em
            // uma categoria que acabou de nascer. Quando é nova, passamos
            // null para que o histórico global entre em jogo.
            $suggestedLabels = $this->suggestLabels->suggest(
                $dto->description,
                $category['isNew'] ? null : $category['name'],
                $dto->labels,
            );
        } catch (Throwable $e) {
            Log::warning('ConversationRouter: SuggestLabels falhou — seguindo sem labels sugeridas', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $suggestedLabels = [];
        }

        if ($suggestedLabels === []) {
            return $dtoWithCategory;
        }

        $merged = array_values(array_unique(array_merge($dto->labels, $suggestedLabels)));

        return $dtoWithCategory->withLabels($merged);
    }

    /**
     * Entra em AWAITING_DATA pedindo o próximo campo pedível.
     *
     * Se não há campo pedível mas o DTO está incompleto, é bug de programação
     * (campos extras além de amount/type/date/description sem cobertura
     * explícita). Logamos e silenciosamente voltamos para confirmação —
     * comportamento conservador: não trava o usuário.
     */
    private function enterAwaitingData(string $chatId, TransactionData $dto, string $source): void
    {
        $next = $this->pickNextAwaitingField($dto);

        if ($next === null) {
            Log::error('ConversationRouter: DTO incompleto sem campo pedível — bug', [
                'chat_id' => $chatId,
                'draft' => $dto->toDraftArray(),
            ]);
            // Fallback conservador: se tiver description+amount+type, vai para
            // confirmação (date é defaultável).
            if ($dto->isComplete() || ($dto->amount !== null && $dto->type !== null && $dto->description !== null)) {
                $this->presentConfirmation($chatId, $dto, $source);
            }

            return;
        }

        $this->assertStateTransition(
            $this->firestore->getSession($chatId),
            ConversationState::AWAITING_DATA->value,
        );
        $this->firestore->setSession(
            $chatId,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: $dto->toDraftArray(),
                awaitingField: $next,
                source: $source,
                retryCount: 0,
            ),
        );
        $this->messenger->askForField($chatId, $next, $this->formatter->askPrompt($next));
    }

    /**
     * Devolve o primeiro campo pedível null em ordem: amount → type → date → description.
     *
     * `description` raramente é null após extração (LLM sempre preenche), mas
     * mantemos a verificação como defesa em profundidade.
     *
     * **M9.3 — Suporte a wizard `/nova` (T-015)**: quando o array `$session`
     * carrega o flag `_wizard_active=true` no `draft`, o método desvia para
     * a sequência FIXA do wizard (type → amount → description → category →
     * labels) em vez da ordem amount-first do modo linguagem natural. O
     * parâmetro é OPCIONAL com default `[]` para preservar todas as
     * chamadas existentes (M7/M8) — callers que não passam `$session`
     * continuam no caminho original, sem nenhuma mudança de comportamento.
     *
     * @param  array<string, mixed>  $session  Sessão Firestore (opcional — default vazio).
     */
    private function pickNextAwaitingField(TransactionData $dto, array $session = []): ?string
    {
        // M9.3 (T-015): branch wizard. Verifica flag `_wizard_active` no draft
        // (preservado entre etapas) — quando presente, segue a ordem fixa do
        // wizard. O step 5 (labels) é o último; o 6 (confirmation) é sinalizado
        // por `isComplete()` no caller, não aqui.
        $wizardStep = (int) ($session['draft']['_wizard_step'] ?? 0);
        if ($wizardStep > 0 && ! empty($session['draft']['_wizard_active'])) {
            return match ($wizardStep) {
                1 => 'type',
                2 => 'amount',
                3 => 'description',
                4 => 'category',
                5 => 'labels',
                default => null,
            };
        }

        if ($dto->amount === null) {
            return 'amount';
        }
        if ($dto->type === null) {
            return 'type';
        }
        if ($dto->date === null) {
            return 'date';
        }
        if ($dto->description === null) {
            return 'description';
        }

        return null;
    }

    /**
     * Trata sessão expirada por timeout: notifica, limpa, e o caller continua
     * como IDLE (sessão = null).
     */
    private function handleExpiredSession(string $chatId): void
    {
        $this->messenger->notifyError(
            $chatId,
            "⏰ Sua sessão expirou ({$this->sessionTimeoutMinutes} min sem interação). Envie uma nova mensagem para começar.",
        );
        $currentSession = $this->firestore->getSession($chatId);
        $this->assertStateTransition($currentSession, ConversationState::IDLE->value);
        $this->firestore->clearSession($chatId);
    }

    /**
     * Indica se a sessão está expirada (`updated_at` mais antigo que timeout).
     *
     * Sessões sem `updated_at` (corrompidas) são tratadas como expiradas
     * (defensivo — não conseguimos calcular idade, então forçamos reset).
     *
     * @param  array<string, mixed>  $session
     */
    private function isExpired(array $session): bool
    {
        $updatedAt = $session['updated_at'] ?? null;

        if (! is_string($updatedAt) || $updatedAt === '') {
            return true;
        }

        try {
            $timestamp = new DateTimeImmutable($updatedAt);
        } catch (Throwable) {
            return true;
        }

        $ageSeconds = time() - $timestamp->getTimestamp();

        return $ageSeconds > ($this->sessionTimeoutMinutes * 60);
    }

    /**
     * Asserte que a transição de estado é legal antes de gravar (W-2 da revisão).
     *
     * Chamado antes de cada `firestore->setSession(state: $newState)` e
     * `firestore->clearSession()` (cuja transição implícita é `* → IDLE`).
     * Lança `LogicException` se a transição for ilegal — bug de programação,
     * nunca input de usuário.
     *
     * Sessão nula (sem doc no Firestore) é tratada como IDLE — entrada
     * legítima em qualquer estado (já é legal per a tabela do
     * {@see StateMachine} para `IDLE → AWAITING_DATA` e
     * `IDLE → AWAITING_CONFIRMATION`).
     *
     * @param  array<string, mixed>|null  $session
     */
    private function assertStateTransition(?array $session, string $newStateValue): void
    {
        $currentStateValue = is_array($session)
            ? (string) ($session['state'] ?? 'idle')
            : 'idle';

        $this->stateMachine->assertCanTransition(
            ConversationState::fromSession($currentStateValue),
            ConversationState::fromSession($newStateValue),
        );
    }

    /**
     * Verifica se um callback veio de uma mensagem com keyboard válido (CT-047).
     *
     * Um callback é considerado válido se o message_id da mensagem que carregava
     * o keyboard ($callbackMessageId) for igual a PELO MENOS UM dos IDs de
     * mensagens ativas registrados na sessão:
     *   - message_id_confirm (X): mensagem de resumo com [Confirmar][Editar][Cancelar]
     *   - message_id_edit_picker (Y): mensagem do picker "Qual campo você quer editar?"
     *
     * Decisão P6: aceita callbacks de X ou Y.
     * Decisão P4=B: se a sessão não tem NENHUM ID válido (sessão legacy em produção),
     * o callback é ACEITO (comportamento conservador — deixa o fluxo normal decidir).
     *
     * @param  array<string, mixed>  $session
     */
    private function isCallbackFromValidMessage(array $session, int $callbackMessageId): bool
    {
        $validIds = [];

        $confirmId = (int) ($session['message_id_confirm'] ?? 0);
        if ($confirmId > 0) {
            $validIds[] = $confirmId;
        }

        $pickerId = (int) ($session['message_id_edit_picker'] ?? 0);
        if ($pickerId > 0) {
            $validIds[] = $pickerId;
        }

        // P4=B: sessão sem IDs válidos → aceita (não rejeita)
        if ($validIds === []) {
            return true;
        }

        return in_array($callbackMessageId, $validIds, true);
    }

    /**
     * Helper DRY: annulla um callback (sem texto) e retorna true. Usado
     * pelo helper de annul abaixo para centralizar a chamada
     * `answerCallback('')` + sinal de "processado" para o caller.
     */
    private function annulCallbackSilently(ConversationInput $input): true
    {
        $this->messenger->answerCallback((string) $input->callbackId, '');

        return true;
    }

    /**
     * Annulla qualquer click em edit:* durante AWAITING_EDITION.
     *
     * Cobre botões edit:<field> do picker Y e o botão "Editar" do keyboard X.
     * Defesa em camadas complementar à remoção imediata do keyboard via
     * editMessageReplyMarkup — cobre race conditions (cliente offline).
     */
    private function annulStaleEditClickInEdition(ConversationInput $input, string $data): bool
    {
        if (! str_starts_with($data, 'edit')) {
            return false;
        }

        $this->annulCallbackSilently($input);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Handlers de extração (IDLE)
    |--------------------------------------------------------------------------
    */

    /**
     * Extrai transação de texto e segue para confirmation ou awaiting_data.
     */
    private function handleTextExtraction(string $chatId, string $text, string $source): void
    {
        try {
            $dto = $this->extractText->handle($text);
        } catch (ExtractionException $e) {
            $this->messenger->notifyError(
                $chatId,
                'Não consegui entender — tente reformular ou use /nova para cadastrar passo a passo.',
            );

            return;
        }

        $this->routeAfterExtraction($chatId, $dto, $source);
    }

    /**
     * Extrai transação de foto e segue para confirmation ou awaiting_data.
     */
    private function handlePhotoExtraction(string $chatId, string $fileId, string $source): void
    {
        try {
            $dto = $this->extractImage->handle($fileId);
        } catch (ExtractionException $e) {
            $reason = $e->reason;
            $message = $reason === ExtractionException::NOT_A_TRANSACTION
                ? 'Não identifiquei uma transação clara na imagem. Você pode descrever por texto ou enviar outra foto.'
                : 'Tive um problema ao analisar a imagem. Você pode tentar de novo ou descrever por texto.';
            $this->messenger->notifyError($chatId, $message);

            return;
        }

        $this->routeAfterExtraction($chatId, $dto, $source);
    }

    /**
     * Despacha o DTO extraído: completo → confirmation; incompleto → awaiting_data.
     */
    private function routeAfterExtraction(string $chatId, TransactionData $dto, string $source): void
    {
        if ($dto->isComplete()) {
            $this->presentConfirmation($chatId, $dto, $source);

            return;
        }

        $this->enterAwaitingData($chatId, $dto, $source);
    }

    /*
    |--------------------------------------------------------------------------
    | Handlers de confirmação / cancelamento
    |--------------------------------------------------------------------------
    */

    /**
     * Confirma a transação: persiste no Firestore e tenta sync com Sheets.
     *
     * Idempotência (CT-018): adquire o flag `processing` atomicamente. Se
     * outra chamada concorrente já pegou, apenas silenciamos e respondemos
     * um toast — nenhuma transação duplicada é criada.
     *
     * Falha de sync: NÃO falha o confirm. A transação fica `sync_status=pending`
     * e o cron M9 (`/cron/sync-pending`) recupera. Logamos warning para
     * diagnóstico.
     *
     * **M8 — Tracking pós-confirm** (CT-012, CT-022): após `saveTransaction`
     * (mas antes do `clearSession`), incrementamos o `use_count` de cada
     * label e criamos a categoria se ainda não existir. Essas operações
     * são **best-effort** (try/catch) — se a rede/Firestore falhar, o
     * usuário já recebeu o toast "Salvo!" e a transação está persistida.
     * A próxima sugestão vai simplesmente "começar do zero" nesse label
     * (até o próximo confirm).
     *
     * **Race condition** (dois confirms simultâneos na MESMA categoria):
     * o `createCategory` é idempotente via `setDocument` (overwrite não
     * destrutivo — recria o doc com o mesmo `use_count` se já existia;
     * o `incrementLabelUse` é atômico via transaction. Nenhuma proteção
     * adicional é necessária.
     *
     * @param  array<string, mixed>  $session
     */
    private function handleConfirm(string $chatId, array $session, string $callbackId): void
    {
        if (! $this->firestore->tryAcquireSessionProcessingFlag($chatId)) {
            // Duplo clique / callback concorrente — outro já está processando.
            $this->messenger->answerCallback($callbackId, 'Já estou processando...');

            return;
        }

        $dto = TransactionData::fromDraftArray($session['draft'] ?? null);

        if (! $dto->isComplete()) {
            // Bug: o state machine garante que só chegamos aqui com DTO completo,
            // mas defendemos contra corrupção da sessão. Limpa e informa.
            Log::error('ConversationRouter: confirm com DTO incompleto', [
                'chat_id' => $chatId,
                'draft' => $dto->toDraftArray(),
            ]);
            $this->messenger->answerCallback($callbackId, '');
            $this->messenger->notifyError(
                $chatId,
                'Sua transação está com dados incompletos. Envie uma nova mensagem para começar.',
            );
            $this->assertStateTransition($session, ConversationState::IDLE->value);
            $this->firestore->clearSession($chatId);

            return;
        }

        $source = (string) ($session['source'] ?? 'text');

        // 1. Persiste no Firestore. saveTransaction exige amount+type (já
        // garantido por isComplete), mas defensivamente capturamos erros
        // inesperados — o webhook controller também capturaria, mas
        // queremos responder com toast amigável antes.
        try {
            $firestoreId = $this->firestore->saveTransaction($chatId, $dto, $source);
        } catch (Throwable $e) {
            Log::error('ConversationRouter: saveTransaction falhou', [
                'chat_id' => $chatId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->messenger->answerCallback($callbackId, 'Erro ao salvar — tente de novo');
            $this->messenger->notifyError(
                $chatId,
                'Tive um problema técnico ao salvar. Tente de novo em alguns instantes.',
            );
            $this->assertStateTransition($session, ConversationState::IDLE->value);
            $this->firestore->clearSession($chatId);

            return;
        }

        // 2. M8 — Tracking de uso de labels (CT-022) e persistência de
        // categoria nova (CT-012). Best-effort: falhas NÃO bloqueiam o
        // confirm — o toast "Salvo!" já foi computado e a transação está
        // persistida. Logging de warning para diagnóstico.
        $this->trackUsageAfterConfirm($dto);

        // 3. Tenta espelhar na planilha (best-effort).
        try {
            $synced = $this->syncSheet->handle($dto, $firestoreId, $source);
        } catch (Throwable $e) {
            // Bug de programação (ex.: DTO incompleto) — logamos mas NÃO
            // falhamos o confirm: a transação está persistida, o cron M9
            // recuperará o sync.
            Log::error('ConversationRouter: SyncSheet lançou exceção inesperada', [
                'chat_id' => $chatId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $synced = false;
        }

        if (! $synced) {
            Log::warning('ConversationRouter: sync com Sheets falhou (cron M9 recuperará)', [
                'chat_id' => $chatId,
                'firestore_id' => $firestoreId,
            ]);
        }

        // 4. Notifica o usuário (sucesso) e limpa a sessão.
        $this->messenger->notifySuccess($chatId, $dto);
        $this->messenger->answerCallback($callbackId, '✅ Salvo!');
        $this->assertStateTransition($session, ConversationState::IDLE->value);
        $this->firestore->clearSession($chatId);
    }

    /**
     * Tracking de uso de labels e persistência de categoria nova (M8).
     *
     * - Para cada label do DTO: `firestore.incrementLabelUse($label)`
     *   (cria com use_count=1 se não existia; idempotente).
     * - Se a categoria não existe: `firestore.createCategory(...)` com
     *   `defaultType` derivado do `type` da transação.
     *
     * Ambos isolados em try/catch — uma falha não bloqueia a outra, e
     * nenhuma das duas impede o confirm do usuário.
     */
    private function trackUsageAfterConfirm(TransactionData $dto): void
    {
        // Labels — incrementa o contador de cada uma.
        foreach ($dto->labels as $label) {
            $trimmed = trim($label);
            if ($trimmed === '') {
                continue;
            }

            try {
                $this->firestore->incrementLabelUse($trimmed);
            } catch (Throwable $e) {
                Log::warning('ConversationRouter: incrementLabelUse falhou (não bloqueia confirm)', [
                    'label' => $trimmed,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Categoria — cria se ainda não existe. Usa o `defaultType` alinhado
        // com o `type` da transação atual (income → income; outros → expense).
        $category = $dto->category;
        if ($category === null || trim($category) === '') {
            return;
        }

        $categoryTrim = trim($category);

        try {
            if (! $this->firestore->categoryExists($categoryTrim)) {
                $defaultType = $dto->type === 'income' ? 'income' : 'expense';
                $this->firestore->createCategory($categoryTrim, $defaultType);
            }
        } catch (Throwable $e) {
            Log::warning('ConversationRouter: createCategory falhou (não bloqueia confirm)', [
                'category' => $categoryTrim,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tratamento de input inválido
    |--------------------------------------------------------------------------
    */

    /**
     * Trata resposta inválida do usuário: incrementa retry_count, e se
     * excedeu o limite, desiste; senão re-pergunta o campo.
     *
     * S-2: extraído de {@see handleInvalidDataResponse()} e
     * {@see handleInvalidEditionResponse()} — a estrutura era idêntica,
     * diferindo apenas na mensagem de desistência e no método de re-pergunta.
     *
     * @param  array<string, mixed>  $session
     */
    private function handleInvalidResponse(
        string $chatId,
        array $session,
        string $field,
        bool $isEdition,
    ): void {
        $newCount = $this->firestore->incrementSessionRetry($chatId);

        if ($newCount > $this->maxDataRetries) {
            $this->messenger->notifyError(
                $chatId,
                $isEdition
                    ? 'Não consegui entender suas respostas. A edição foi cancelada — use /cancelar para voltar ao início.'
                    : 'Não consegui entender suas respostas. Envie uma nova mensagem para recomeçar — ou use /cancelar para limpar.',
            );
            $this->assertStateTransition($session, ConversationState::IDLE->value);
            $this->firestore->clearSession($chatId);

            return;
        }

        if ($isEdition) {
            // R2: o retry envia um NOVO `askForEdition()` (com novo
            // message_id). NÃO persiste messageIdAskEdition (depreciado após R2);
            // limpa campos legacy/stale que possam existir em sessões antigas.
            // O prompt Z permanece como histórico no chat — não é deletado.
            // `retryCount: null` preserva o valor recém-incrementado por
            // `incrementSessionRetry()` acima.
            $this->messenger->askForEdition($chatId, $field);

            $this->firestore->setSession(
                $chatId,
                new SessionData(
                    state: ConversationState::AWAITING_EDITION->value,
                    draft: $session['draft'] ?? null,
                    awaitingField: $field,
                    messageIdConfirm: isset($session['message_id_confirm'])
                        ? (int) $session['message_id_confirm']
                        : null,
                    messageIdEditPicker: isset($session['message_id_edit_picker'])
                        ? (int) $session['message_id_edit_picker']
                        : null,
                    source: $session['source'] ?? 'text',
                    // null = preserva retry_count recém-incrementado por
                    // incrementSessionRetry().
                    retryCount: null,
                ),
                // R2: messageIdAskEdition depreciado — não grava mais.
                // Limpa campos legacy/stale que podem existir em sessões antigas.
                clearFields: ['message_id_ask_edition'],
            );
        } else {
            $this->messenger->askForField(
                $chatId,
                $field,
                $this->invalidFieldMessage($field, $newCount, $this->maxDataRetries),
            );
        }
    }

    /**
     * Incrementa retry_count e decide entre re-perguntar ou desistir.
     *
     * Se o novo count exceder `maxDataRetries`, desiste: limpa sessão e
     * notifica. Caso contrário, re-pergunta o mesmo campo.
     *
     * @param  array<string, mixed>  $session
     */
    private function handleInvalidDataResponse(string $chatId, array $session, string $field): void
    {
        $this->handleInvalidResponse($chatId, $session, $field, false);
    }

    /**
     * Idem para AWAITING_EDITION: re-pergunta o mesmo campo ou desiste.
     *
     * @param  array<string, mixed>  $session
     */
    private function handleInvalidEditionResponse(string $chatId, array $session, string $field): void
    {
        $this->handleInvalidResponse($chatId, $session, $field, true);
    }

    /**
     * Mensagem de re-pergunta amigável com dica específica do campo.
     */
    private function invalidFieldMessage(string $field, int $attempt, int $max): string
    {
        $hint = match ($field) {
            'amount' => 'Use o formato <code>50,00</code> (valor precisa ser maior que zero).',
            'type' => 'Responda com <b>despesa</b> ou <b>receita</b>.',
            'date' => 'Use o formato <code>15/06/2026</code> ou <code>ontem</code>.',
            'description' => 'Descreva brevemente a transação (mín. 2 caracteres).',
            'category' => 'Informe o nome da categoria (ex.: <b>Alimentação</b>).',
            default => 'Verifique o valor informado e tente de novo.',
        };

        $remaining = max(0, $max - $attempt + 1);

        return "⚠️ Valor inválido. {$hint}\n\n(Tentativa {$attempt} de {$max}.)";
    }

    /*
    |--------------------------------------------------------------------------
    | Validadores de campo
    |--------------------------------------------------------------------------
    */

    /**
     * Despacha para o validador específico do campo. Devolve o valor normalizado
     * (float para amount, string para type/date/description/category, array para
     * labels) ou null se inválido.
     *
     * **M9.3 (T-016)**: tornado público para reuso pelo
     * {@see WizardHandler}, que precisa aplicar a mesma
     * validação a cada etapa do wizard `/nova` (campos type/amount/description/
     * category/labels). Centralizar a validação no Router garante que a
     * wizard não invente regras próprias divergentes.
     */
    public function validateField(string $field, string $raw): float|string|array|null
    {
        return match ($field) {
            'amount' => $this->validateAmount($raw),
            'type' => $this->validateType($raw),
            'date' => $this->validateDate($raw),
            'description' => $this->validateDescription($raw),
            'category' => $this->validateCategory($raw),
            'observations' => $this->validateObservations($raw),
            'labels' => $this->validateLabels($raw),
            default => null,
        };
    }

    /**
     * Valida e normaliza um valor monetário.
     *
     * Aceita formatos PT-BR e US/EN com 1-2 casas decimais e separador de
     * milhar opcional:
     *  - "50" / "50,00" / "50.00" (sem milhar)
     *  - "1.234,56" / "1,234.56" (com milhar)
     *  - "R$ 1.234,56" (com prefixo de moeda)
     *  - "1.234.567,89" / "1,234,567.89" (PT-BR / US completos)
     *
     * Estratégia: o ÚLTIMO separador (vírgula ou ponto) com 1-2 dígitos
     * depois é interpretado como decimal. Qualquer outro separador
     * encontrado ANTES desse é tratado como separador de milhar e removido.
     * Esta heurística cobre tanto o caso comum ("50,00" PT-BR) quanto a
     * entrada ambígua de usuários não-técnicos ("1.234,56" ou "1,234.56")
     * sem precisar distinguir locale.
     *
     * Rejeições explícitas:
     *  - 3+ casas decimais ("50,123" → inválido) — sem isto, "50,123" cairia
     *    no branch "sem decimal" e seria silenciosamente tratado como 50123.
     *  - letras, sinais, vazio
     *  - ≤ 0 (CT-013, clarificações #2)
     *
     * @return float|null Valor float positivo, ou null se inválido.
     */
    private function validateAmount(string $raw): ?float
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^R\$/i', '', $cleaned);
        $cleaned = trim((string) $cleaned);

        // Rejeita 3+ dígitos após qualquer separador — input não pode ter
        // essa quantidade de casas decimais e cairia em "valor inteiro" se
        // não fosse bloqueado.
        if (preg_match('/[,.](\d{3,})$/', $cleaned)) {
            return null;
        }

        // Detecta o ÚLTIMO separador (vírgula ou ponto) como decimal
        // se houver 1 ou 2 dígitos depois dele.
        if (preg_match('/^(.*)[,.](\d{1,2})$/', $cleaned, $m)) {
            $intPart = str_replace(['.', ','], '', $m[1]);
            $cleaned = $intPart.'.'.$m[2];
        } else {
            // Sem decimal — remove qualquer separador de milhar.
            $cleaned = str_replace(['.', ','], '', $cleaned);
        }

        if (! preg_match(self::AMOUNT_REGEX, $cleaned)) {
            return null;
        }

        $value = (float) $cleaned;

        if ($value <= 0) {
            return null;
        }

        return $value;
    }

    /**
     * Valida e normaliza o tipo ("despesa"|"receita" → "expense"|"income").
     */
    private function validateType(string $raw): ?string
    {
        $normalized = mb_strtolower(trim($raw));

        return self::TYPE_MAP[$normalized] ?? null;
    }

    /**
     * Valida e normaliza uma data para ISO YYYY-MM-DD.
     *
     * Aceita:
     *  - ISO: "2026-06-15"
     *  - BR: "15/06/2026"
     *  - "hoje", "ontem", "anteontem" (palavras-chave relativas a now)
     *
     * Devolve a string ISO ou null se inválido.
     */
    private function validateDate(string $raw): ?string
    {
        $cleaned = trim($raw);

        // Palavras-chave em PT-BR.
        $keyword = mb_strtolower($cleaned);
        if (in_array($keyword, ['hoje', 'today'], true)) {
            return (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (in_array($keyword, ['ontem', 'yesterday'], true)) {
            return (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        }
        if (in_array($keyword, ['anteontem'], true)) {
            return (new DateTimeImmutable('-2 days'))->format('Y-m-d');
        }

        // ISO YYYY-MM-DD.
        $iso = DateTimeImmutable::createFromFormat('!Y-m-d', $cleaned);
        if ($iso !== false) {
            return $iso->format('Y-m-d');
        }

        // BR DD/MM/YYYY.
        $br = DateTimeImmutable::createFromFormat('!d/m/Y', $cleaned);
        if ($br !== false) {
            return $br->format('Y-m-d');
        }

        return null;
    }

    /**
     * Valida descrição: trim, não-vazia, ≤500 chars (trunca).
     */
    private function validateDescription(string $raw): ?string
    {
        $cleaned = trim($raw);

        if (mb_strlen($cleaned) < 2) {
            return null;
        }

        if (mb_strlen($cleaned) > TransactionData::DESCRIPTION_MAX_LENGTH) {
            return mb_substr($cleaned, 0, TransactionData::DESCRIPTION_MAX_LENGTH - 3).'...';
        }

        return $cleaned;
    }

    /**
     * Valida categoria: trim, não-vazia.
     */
    private function validateCategory(string $raw): ?string
    {
        $cleaned = trim($raw);

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * Valida observações: trim; string vazia vira string vazia.
     *
     * Diferente dos demais validadores, este SEMPRE devolve string (nunca null)
     * porque observações é um campo opcional no schema e qualquer texto —
     * inclusive vazio — é válido. O caller (ex.: {@see withField()}) trata
     * string vazia como "campo preenchido com vazio", distinguindo de null
     * (campo nunca preenchido).
     */
    private function validateObservations(string $raw): string
    {
        $cleaned = trim($raw);

        if (mb_strlen($cleaned) > self::OBSERVATIONS_MAX_LENGTH) {
            return mb_substr($cleaned, 0, self::OBSERVATIONS_MAX_LENGTH - 3).'...';
        }

        return $cleaned;
    }

    /**
     * Valida labels do wizard `/nova` (M9.3 / T-016).
     *
     * Aceita string com labels separadas por vírgula (ex.: `almoço, trabalho,
     * #fds`). Regras:
     *  - trim geral da string inteira;
     *  - "pular" / "skip" / "-" / string vazia → array vazio (rótulo sem labels);
     *  - cada token: trim, remove prefixo `#`, filtra < 2 caracteres (mínimo
     *    útil para uma label);
     *  - deduplica (case-insensitive) preservando a primeira ocorrência;
     *  - reindexa com `array_values` para manter a invariante `list<string>`.
     *
     * Retorna SEMPRE um array (nunca null) porque labels são opcionais — o
     * "pular" é uma resposta válida. O caller (WizardHandler) distingue
     * `[]` (sem labels) de `null` apenas para inputs realmente malformados
     * (que aqui nunca acontecem — qualquer string produz um array filtrado).
     *
     * @return list<string>
     */
    private function validateLabels(string $raw): array
    {
        $cleaned = trim($raw);
        $keyword = mb_strtolower($cleaned);

        // Atalhos PT-BR para "não quero labels" — sempre array vazio.
        if ($keyword === '' || in_array($keyword, ['pular', 'skip', 'nenhuma', 'nenhum', '-'], true)) {
            return [];
        }

        $tokens = explode(',', $cleaned);
        $labels = [];
        $seen = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            $token = ltrim($token, '#');
            $token = trim($token);

            if (mb_strlen($token) < 2) {
                continue;
            }

            $key = mb_strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $labels[] = $token;
        }

        return array_values($labels);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Mescla dois DTOs: campos não-null do `$extracted` sobrescrevem o `$base`.
     *
     * Útil em AWAITING_DATA quando o usuário envia uma foto (que pode trazer
     * os campos que faltavam).
     */
    private function mergeDrafts(TransactionData $base, TransactionData $extracted): TransactionData
    {
        return new TransactionData(
            description: $extracted->description ?? $base->description,
            amount: $extracted->amount ?? $base->amount,
            type: $extracted->type ?? $base->type,
            category: $extracted->category ?? $base->category,
            labels: $extracted->labels !== [] ? $extracted->labels : $base->labels,
            date: $extracted->date ?? $base->date,
            observations: $extracted->observations ?? $base->observations,
            confidence: $extracted->confidence ?? $base->confidence,
        );
    }

    /**
     * Remove o teclado inline da mensagem de confirmação X (best-effort).
     *
     * Usado nos branches confirm/edit/cancel do AWAITING_CONFIRMATION
     * para consumir o keyboard [Confirmar][Editar][Cancelar] imediatamente
     * após o clique — sem isto o usuário veria botões de uma ação já
     * concluída.
     *
     * @param  array<string, mixed>  $session
     */
    private function removeConfirmationKeyboard(string $chatId, array $session): void
    {
        $confirmId = (int) ($session['message_id_confirm'] ?? 0);
        if ($confirmId > 0) {
            $this->messenger->editMessageReplyMarkup($chatId, $confirmId, null);
        }
    }
}
