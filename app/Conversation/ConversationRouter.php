<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SuggestCategory;
use App\Actions\SuggestsLabels;
use App\Actions\SyncsSheet;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Enums\ConversationState;
use App\Exceptions\ExtractionException;
use App\Services\Store\WalletStore;
use App\Services\Parsing\ItemsParser;
use App\Support\LabelFormatter;
use App\Support\TextNormalizer;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roteador central da máquina de estados conversacional (M7.3 — M7.10 + M8).
 *
 * Recebe um {@see ConversationInput} (texto/foto/callback normalizado) e
 * decide o que fazer com base no estado atual da sessão do chat.
 * Toda mutação de sessão, todo envio de mensagem e toda chamada de extração
 * passam por aqui — nenhum handler Nutgram toca a persistência ou a SDK do LLM
 * diretamente. Esta é a peça que torna o bot conversacional: ela implementa
 * o fluxo IDLE → AWAITING_DATA → AWAITING_CONFIRMATION → AWAITING_EDITION
 * descrito em docs/02-especificacao-tecnica.md §7.
 *
 * Princípios de design:
 *
 *  - **Pura lógica, fácil de testar**: depende apenas de interfaces
 *    (ExtractsText/Image, SyncsSheet, BotMessenger, WalletStore) e do
 *    {@see StateMachine}. Pode ser instanciada em testes com stubs anonimos
 *    (ver ConversationRouterTest).
 *  - **NÃO captura \Exception em route()**: o TelegramWebhookController já
 *    captura exceções (\Exception e \Error) e responde 200 — a regra de ouro
 *    do webhook. Mas chamadas externas (extractText, extractImage) SÃO
 *    protegidas (ExtractionException) com fallback amigável, conforme o spec.
 *  - **Idempotência via `processing` flag**: a confirmação adquire o flag
 *    atomicamente (WalletStore::tryAcquireSessionProcessingFlag); duplo
 *    clique é bloqueado (CT-018).
 *  - **CT-047 (callback de keyboard antiga)**: validado PRIMEIRO em qualquer
 *    callback quando state=AWAITING_CONFIRMATION, comparando
 *    `callbackMessageId` com `message_id_confirm` E `message_id_edit_picker`
 *    da sessão (P6 — aceita X ou Y).
 *  - **Timeout via Redis TTL**: a sessão expira automaticamente após o TTL
 *    no Redis — não há mais verificação manual de timeout.
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
        'items' => true,
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
     * Limite de retentativas de validação de campo pedível antes de desistir.
     */
    private readonly int $maxDataRetries;

    /**
     * Handler do wizard `/nova` (M9.3). Lazy — só instanciado quando
     * `route()` detecta o flag `_wizard_active` na sessão, evitando custo
     * desnecessário para o fluxo de linguagem natural (M7/M8).
     */
    private ?WizardHandler $wizardHandler = null;

    /**
     * Cache em memória do catálogo de labels (top-N do usuário).
     *
     * Populado na primeira chamada de {@see fetchLabelCatalog()} e
     * reutilizado em chamadas subsequentes dentro do mesmo request HTTP.
     * O catálogo não muda durante um request (labels são criadas apenas
     * no confirm, que encerra este Router).
     *
     * `null` = ainda não carregado (não tentou Fetch).
     *
     * @var list<string>|null
     */
    private ?array $cachedLabelCatalog = null;

    /**
     * Parser stateless de items (M-ITENS-5). Instanciado inline — sem
     * custo de DI adicional no construtor já grande.
     */
    private readonly ItemsParser $itemsParser;

    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly BotMessenger $messenger,
        private readonly TransactionSummaryFormatter $formatter,
        private readonly WalletStore $store,
        private readonly ExtractsText $extractText,
        private readonly ExtractsImage $extractImage,
        private readonly SyncsSheet $syncSheet,
        private readonly SuggestCategory $suggestCategory,
        private readonly SuggestsLabels $suggestLabels,
        int $maxDataRetries,
    ) {
        $this->maxDataRetries = $maxDataRetries;
        $this->itemsParser = new ItemsParser();
    }

    /**
     * Reseta o estado mutável por-request para isolar conversas entre
     * {@see chat_id}s distintos no mesmo worker Octane long-lived.
     *
     * Este método é chamado pelo listener
     * {@see \App\Listeners\ResetConversationRouter} a cada evento
     * {@see \Laravel\Octane\Events\RequestReceived}, garantindo que
     * nenhum estado de uma request "vaze" para a request seguinte
     * dentro do mesmo processo PHP.
     *
     * **O que é resetado:**
     *
     *  1. {@see ConversationRouter::$wizardHandler} — o handler do wizard
     *     `/nova` é lazy e carrega referência ao `ConversationRouter`
     *     (self), ao `WalletStore` (stateless) e ao `BotMessenger`
     *     (stateless). Instâncias de wizard do request anterior são
     *     descartadas para evitar que o wizard de um chat_id interfira
     *     no wizard de outro.
     *  2. {@see ConversationRouter::$cachedLabelCatalog} — o cache em
     *     memória do catálogo de labels (top-N do usuário) é populado
     *     por `fetchLabelCatalog()` e reutilizado dentro do mesmo
     *     request. Como o catálogo é específico por `chat_id` (dados
     *     no banco de dados/Redis são segregados por `chat_id`), o cache
     *     deve ser invalidado a cada request para evitar servir labels
     *     de um usuário para outro.
     *
     * **O que NÃO é resetado:**
     *
     *  - Dependências injetadas via constructor ({@see StateMachine},
     *    {@see WalletStore}, {@see BotMessenger}, {@see ExtractsText},
     *    {@see ExtractsImage}, {@see SyncsSheet},
     *    {@see SuggestCategory}, {@see SuggestsLabels}) — estas são
     *    stateless (apenas orquestram chamadas a serviços externos)
     *    ou têm estado próprio isolado por `chat_id` via Redis.
     *  - {@see ConversationRouter::$maxDataRetries} — config imutável
     *    injetada via constructor.
     *  - {@see ConversationRouter::$itemsParser} — parser stateless,
     *    sem estado interno acumulativo.
     *
     * **Atenção ao adicionar novas propriedades mutáveis:**
     * se uma propriedade nova for adicionada a esta classe no futuro
     * e ela mantiver estado entre requests (cache, lazy-loading,
     * acumuladores), **este método DEVE ser atualizado** para incluir
     * o reset dessa propriedade. Revise esta docblock como checklist.
     */
    public function resetState(): void
    {
        $this->wizardHandler = null;
        $this->cachedLabelCatalog = null;
    }

    /**
     * Expõe o limite de retentativas configurado (W-C — M-ITENS-7).
     *
     * Usado pelo {@see WizardHandler} que precisa compartilhar o mesmo
     * limite de retry que o Router (ex.: maxDataRetries=3 configurado
     * externamente deve ser respeitado em todos os branches de validação).
     */
    public function maxDataRetries(): int
    {
        return $this->maxDataRetries;
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
            store: $this->store,
            messenger: $this->messenger,
            formatter: $this->formatter,
            suggestLabels: $this->suggestLabels,
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
        $session = $this->store->getSession($chatId);
        // M4: $session pode ser null (Redis retorna array vazio quando chave não existe).
        // fromSession(null) já devolve IDLE, mas evitamos o warning de acesso a offset
        // em null em PHP 8.x com a guarda explícita.
        $state = ConversationState::fromSession($session !== null ? ($session['state'] ?? null) : null);

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
            $catalog = $this->fetchLabelCatalog();
            try {
                $extracted = $this->extractImage->handle((string) $input->photoFileId, $catalog);
            } catch (ExtractionException $e) {
                $this->messenger->notifyError(
                    $chatId,
                    'Não identifiquei uma transação clara na imagem. Você pode descrever por texto ou enviar outra foto.',
                );

                return;
            }

            $merged = $this->mergeDrafts($draft, $extracted);
            $merged = $this->applyLabelLimit($chatId, $merged);

            if ($merged->isComplete()) {
                $this->presentConfirmation($chatId, $merged, $session['source'] ?? 'image');

                return;
            }

            // Re-pergunta o próximo campo pedível.
            $next = $this->pickNextAwaitingField($merged, $session);
            $this->assertStateTransition($session, ConversationState::AWAITING_DATA->value);
            $this->store->setSession(
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
        $this->store->setSession(
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
                $this->store->setSession(
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
                $this->store->clearSession($chatId);

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
                    $this->store->setSession(
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
        $this->store->clearSession($chatId);

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
        $this->store->setSession(
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
            $this->store->getSession($chatId),
            ConversationState::AWAITING_CONFIRMATION->value,
        );
        $this->store->setSession(
            $chatId,
            new SessionData(
                state: ConversationState::AWAITING_CONFIRMATION->value,
                draft: $enriched->toDraftArray(),
                awaitingField: null,
                messageIdConfirm: $messageId,
                source: $source,
                retryCount: 0,
            ),
            // M9.3 (T-016): limpa campos stale. `_wizard_*` são limpos
            // implicitamente pelo overwrite do draft em toDraftArray() —
            // não precisam de deleteField explícito (W-NB-1).
            clearFields: ['awaiting_field'], // W-3: limpa campos stale do wizard
        );
    }

    /**
     * Enriquece o DTO com sugestão de categoria (M8).
     *
     * Pipeline (CT-011):
     *
     *  1. {@see SuggestCategory::suggest()} resolve a categoria final
     *     (fuzzy match → existente; abaixo do threshold → nova; sem
     *     entrada → default). O `display` retornado é aplicado ao DTO.
     *  2. Imutável: devolve um novo {@see TransactionData} (helper
     *     `withCategory`).
     *
     * Falhas da heurística (ex.: banco de dados indisponível para buscar
     * categorias) são capturadas localmente — uma exceção aqui
     * não pode impedir o usuário de confirmar uma transação. O log
     * warning fica disponível para diagnóstico.
     *
     * A sugestão de labels foi removida do fluxo principal na refatoração
     * de labels (F2) — o histórico global não é mais injetado
     * automaticamente. O usuário define labels manualmente ou via LLM.
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

        return $dto->withCategory($category['display']);
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
            $this->store->getSession($chatId),
            ConversationState::AWAITING_DATA->value,
        );
        $this->store->setSession(
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
     * @param  array<string, mixed>  $session  Sessão (opcional — default vazio).
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
     * Asserte que a transição de estado é legal antes de gravar (W-2 da revisão).
     *
     * Chamado antes de cada `repositório->setSession(state: $newState)` e
     * `repositório->clearSession()` (cuja transição implícita é `* → IDLE`).
     * Lança `LogicException` se a transição for ilegal — bug de programação,
     * nunca input de usuário.
     *
     * Sessão nula (sem doc na store) é tratada como IDLE — entrada
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
        $catalog = $this->fetchLabelCatalog();

        try {
            $dto = $this->extractText->handle($text, $catalog);
        } catch (ExtractionException $e) {
            $this->messenger->notifyError(
                $chatId,
                'Não consegui entender — tente reformular ou use /nova para cadastrar passo a passo.',
            );

            return;
        }

        $dto = $this->applyLabelLimit($chatId, $dto);

        $this->routeAfterExtraction($chatId, $dto, $source);
    }

    /**
     * Extrai transação de foto e segue para confirmation ou awaiting_data.
     */
    private function handlePhotoExtraction(string $chatId, string $fileId, string $source): void
    {
        $catalog = $this->fetchLabelCatalog();

        try {
            $dto = $this->extractImage->handle($fileId, $catalog);
        } catch (ExtractionException $e) {
            $reason = $e->reason;
            $message = $reason === ExtractionException::NOT_A_TRANSACTION
                ? 'Não identifiquei uma transação clara na imagem. Você pode descrever por texto ou enviar outra foto.'
                : 'Tive um problema ao analisar a imagem. Você pode tentar de novo ou descrever por texto.';
            $this->messenger->notifyError($chatId, $message);

            return;
        }

        $dto = $this->applyLabelLimit($chatId, $dto);

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

    /**
     * Se o DTO tem mais de max_labels labels, trunca e avisa o usuário.
     *
     * Chamado logo após a extração (antes de routeAfterExtraction) para
     * garantir que o fluxo de confirmação nunca receba mais labels do que
     * o limite configurado. Labels extras são descartadas e o usuário é
     * avisado com uma mensagem amigável.
     */
    private function applyLabelLimit(string $chatId, TransactionData $dto): TransactionData
    {
        $max = (int) config('labels.max_labels', 3);
        if (count($dto->labels) <= $max) {
            return $dto;
        }

        $kept = array_slice($dto->labels, 0, $max);
        $dto = $dto->withLabels($kept);

        $escaped = array_map(
            fn (string $l): string => htmlspecialchars($l, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $kept,
        );
        $keptStr = implode(', ', $escaped);
        $this->messenger->sendText(
            $chatId,
            "ℹ️ Limitei a {$max} labels: {$keptStr}",
        );

        return $dto;
    }

    /*
    |--------------------------------------------------------------------------
    | Handlers de confirmação / cancelamento
    |--------------------------------------------------------------------------
    */

    /**
     * Confirma a transação: persiste no banco e tenta sync com Sheets.
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
     * são **best-effort** (try/catch) — se a rede/banco falhar, o
     * usuário já recebeu o toast "Salvo!" e a transação está persistida.
     * A próxima sugestão vai simplesmente "começar do zero" nesse label
     * (até o próximo confirm).
     *
     * **Race condition** (dois confirms simultâneos na MESMA categoria):
     * o `createCategory` é idempotente via `firstOrCreate`;
     * o `incrementLabelUse` é atômico. Nenhuma proteção
     * adicional é necessária.
     *
     * @param  array<string, mixed>  $session
     */
    private function handleConfirm(string $chatId, array $session, string $callbackId): void
    {
        if (! $this->store->tryAcquireSessionProcessingFlag($chatId)) {
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
            $this->store->clearSession($chatId);

            return;
        }

        // 1. Persiste no banco de dados. saveTransaction exige amount+type (já
        // garantido por isComplete), mas defensivamente capturamos erros
        // inesperados — o webhook controller também capturaria, mas
        // queremos responder com toast amigável antes.
        try {
            $txId = $this->store->saveTransaction($chatId, $dto);
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
            $this->store->clearSession($chatId);

            return;
        }

        // 2. M8 — Tracking de uso de labels (CT-022) e persistência de
        // categoria nova (CT-012). Best-effort: falhas NÃO bloqueiam o
        // confirm — o toast "Salvo!" já foi computado e a transação está
        // persistida. Logging de warning para diagnóstico.
        $this->trackUsageAfterConfirm($dto);

        // 3. Tenta espelhar na planilha (best-effort).
        try {
            $synced = $this->syncSheet->handle($dto, $txId);
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
                'tx_id' => $txId,
            ]);
        }

        // 4. Notifica o usuário (sucesso) e limpa a sessão.
        $this->messenger->notifySuccess($chatId, $dto);
        $this->messenger->answerCallback($callbackId, '✅ Salvo!');
        $this->assertStateTransition($session, ConversationState::IDLE->value);
        $this->store->clearSession($chatId);
    }

    /**
     * Tracking de uso de labels e persistência de categoria nova (M8).
     *
     * - Para cada label do DTO: `store.incrementLabelUse($label)`
     *   (cria com use_count=1 se não existia; idempotente).
     * - Se a categoria não existe: `store.createCategory(...)` com
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
                $this->store->incrementLabelUse($trimmed);
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
            if (! $this->store->categoryExists($categoryTrim)) {
                $defaultType = $dto->type === 'income' ? 'income' : 'expense';
                $this->store->createCategory($categoryTrim, $defaultType);
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
        $newCount = $this->store->incrementSessionRetry($chatId);

        if ($newCount > $this->maxDataRetries) {
            $this->messenger->notifyError(
                $chatId,
                $isEdition
                    ? 'Não consegui entender suas respostas. A edição foi cancelada — use /cancelar para voltar ao início.'
                    : 'Não consegui entender suas respostas. Envie uma nova mensagem para recomeçar — ou use /cancelar para limpar.',
            );
            $this->assertStateTransition($session, ConversationState::IDLE->value);
            $this->store->clearSession($chatId);

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

            $this->store->setSession(
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
            'items' => 'Envie cada item em uma linha (ex.: <code>Arroz x2 32.90</code>) ou <code>pular</code> para limpar.',
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
            'items' => $this->validateItems($raw),
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
     * Valida labels do wizard `/nova` (M9.3 / T-016) e do fluxo conversacional (M3).
     *
     * Aceita string com labels separadas por vírgula (ex.: `almoço, trabalho,
     * #fds`). Regras:
     *  - trim geral da string inteira;
     *  - "pular" / "skip" / "-" / string vazia → array vazio (rótulo sem labels);
     *  - cada token: trim, remove prefixo `#`, filtra < 2 caracteres (mínimo
     *    útil para uma label);
     *  - fuzzy match contra catálogo (se fornecido): substitui tokens por versões
     *    canônicas do catálogo quando a similaridade é >= threshold;
     *  - aplica {@see LabelFormatter::format()} (P1 + P7) em todas as labels;
     *  - deduplica (case-insensitive) preservando a primeira ocorrência;
     *  - trunca para o máximo configurado (ex.: 3) silenciosamente;
     *  - reindexa com `array_values` para manter a invariante `list<string>`.
     *
     * Retorna SEMPRE um array (nunca null) porque labels são opcionais — o
     * "pular" é uma resposta válida. O caller (WizardHandler) distingue
     * `[]` (sem labels) de erro.
     *
     * @param  list<string>  $labelCatalog  Catálogo top-N do usuário (opcional).
     *                                      Se vazio, busca internamente via fetchLabelCatalog().
     * @return list<string>
     */
    public function validateLabels(string $raw, array $labelCatalog = []): array
    {
        $cleaned = trim($raw);
        $keyword = mb_strtolower($cleaned);

        // Atalhos PT-BR para "não quero labels" — sempre array vazio.
        if ($keyword === '' || in_array($keyword, ['pular', 'skip', 'nenhuma', 'nenhum', '-'], true)) {
            return [];
        }

        // Se não recebeu catálogo, busca internamente.
        if ($labelCatalog === []) {
            $labelCatalog = $this->fetchLabelCatalog();
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

            // Fuzzy match contra catálogo: se o token tem similaridade
            // >= threshold com uma label do catálogo, usa a versão canônica.
            $matched = $this->matchCatalog($token, $labelCatalog);
            $token = $matched ?? $token;

            // Aplica formatação canônica (P1 + P7).
            $token = LabelFormatter::format($token);

            if ($token === '') {
                continue;
            }

            $key = TextNormalizer::fold($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $labels[] = $token;
        }

        // Trunca para o máximo configurado (P8/D1=B).
        $max = (int) config('labels.max_labels', 3);
        if (count($labels) > $max) {
            $labels = array_slice($labels, 0, $max);
        }

        return array_values($labels);
    }

    /**
     * Valida e normaliza items (campo editável D-P10=a / M-ITENS-5).
     *
     * Atalhos 'pular'/'skip'/'limpar'/'nenhum'/'-'/vazio → retorna [] (zera items).
     * Senão, delega para ItemsParser. Se parser retorna [] (nenhum item válido),
     * retorna null (inválido — caller re-pergunta).
     *
     * NÃO valida sum(subtotal)==amount (D-P3=a — items são descritivos).
     *
     * @return list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>|null
     */
    private function validateItems(string $raw): ?array
    {
        $cleaned = trim($raw);
        $keyword = mb_strtolower($cleaned);

        // Atalhos para "zerar items" (consistente com validateLabels).
        if ($keyword === '' || in_array($keyword, ['pular', 'skip', 'limpar', 'nenhum', '-'], true)) {
            return [];
        }

        $items = $this->itemsParser->parse($cleaned);

        return $items === [] ? null : $items;
    }

    /**
     * Busca o catálogo top-N de labels do usuário via WalletStore.
     *
     * O resultado é cacheado em memória (propriedade `$cachedLabelCatalog`)
     * para evitar múltiplos queries dentro do mesmo request HTTP.
     * O catálogo é imutável durante a vida de um request (labels são criadas
     * apenas no confirm, que encerra este Router).
     *
     * **Tornado público (M4)** para reuso pelo {@see WizardHandler}, que
     * também precisa do catálogo para sugestão LLM quando o usuário pula a
     * etapa de labels. Evita duplicar o método em duas classes.
     *
     * Best-effort: se a store estiver indisponível, loga o warning
     * e retorna array vazio — a extração e validação continuam sem
     * catálogo (apenas sem benefício do fuzzy match).
     *
     * @return list<string> Display names das top-N labels do usuário.
     */
    public function fetchLabelCatalog(): array
    {
        if ($this->cachedLabelCatalog !== null) {
            return $this->cachedLabelCatalog;
        }

        try {
            $n = (int) config('labels.catalog_top_n', 15);
            $labels = $this->store->getTopLabels($n);
        } catch (Throwable $e) {
            Log::warning('ConversationRouter: getTopLabels falhou — extraindo sem catálogo', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->cachedLabelCatalog = [];

            return [];
        }

        $catalog = [];
        foreach ($labels as $label) {
            $name = $label->name;
            if ($name !== '') {
                $catalog[] = $name;
            }
        }

        $this->cachedLabelCatalog = $catalog;

        return $catalog;
    }

    /**
     * Tenta encontrar a label do catálogo mais similar ao token (fuzzy match).
     *
     * Usa similaridade de Levenshtein normalizada (idêntica à do
     * {@see SuggestCategory}) com threshold configurável. Se a melhor
     * similaridade for >= threshold, devolve o nome canônico do catálogo;
     * caso contrário, devolve null (o token é tratado como label nova).
     *
     * @param  string  $token  Token digitado pelo usuário.
     * @param  list<string>  $catalog  Catálogo de labels existentes.
     * @return string|null Nome canônico do catálogo, ou null se nenhum match.
     */
    private function matchCatalog(string $token, array $catalog): ?string
    {
        if ($catalog === []) {
            return null;
        }

        $threshold = (float) config('labels.fuzzy_threshold', 0.85);
        $tokenFold = TextNormalizer::fold($token);

        $best = null;
        $bestScore = 0.0;

        foreach ($catalog as $candidate) {
            $candidateFold = TextNormalizer::fold($candidate);
            $score = TextNormalizer::similarity($tokenFold, $candidateFold);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return ($best !== null && $bestScore >= $threshold) ? $best : null;
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
            items: $extracted->items !== [] ? $extracted->items : $base->items,
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
