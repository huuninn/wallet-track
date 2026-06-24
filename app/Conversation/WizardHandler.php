<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Actions\SuggestsLabels;
use App\Bot\Handlers\NovaHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Enums\ConversationState;
use App\Enums\WizardStep;
use App\Services\Google\FirestoreService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestrador do wizard `/nova` (M9.3 / T-017).
 *
 * O wizard é uma sequência FIXA de 5 campos pedíveis (mais a tela de
 * confirmação) — diferente do fluxo conversacional de linguagem natural
 * (M7), que testa os campos na ordem amount → type → date → description.
 *
 * **Por que uma classe separada?**
 *
 *  - Mantém o {@see ConversationRouter} focado na máquina de estados
 *    (M7/M8); a sequência do wizard é uma "sub-máquina" auto-contida.
 *  - Encapsula as 5 mensagens de pergunta + a transição final para
 *    `presentConfirmation()` — testável isoladamente com mocks.
 *  - A reutilização de `validateField()` e `presentConfirmation()` do
 *    Router (tornados `public` em M9.3) garante que o wizard herda toda
 *    a validação e enriquecimento (sugestões de categoria/labels M8) sem
 *    duplicar código.
 *
 * **Detecção de wizard ativo** (Decisão #7 / M9.1): o Router detecta
 * wizard no `route()` lendo `session['draft']['_wizard_active'] === true`
 * e delega para esta classe. Ao chegar ao fim (step 5 validado), o
 * Router é invocado novamente em `presentConfirmation()` e o wizard
 * termina — `_wizard_*` é removido do draft no `clearFields`.
 *
 * **Estado da sessão durante o wizard**:
 *
 *  - `state` = `AWAITING_DATA` (reusado do fluxo natural — não há
 *    `ConversationState::WIZARD` porque seria um estado novo, e a
 *    Decisão do Portão 2 é justamente REUSAR `AWAITING_DATA` com flag).
 *  - `awaiting_field` = nome do campo da etapa atual (type, amount…).
 *  - `draft._wizard_step` (int 1..5) = etapa atual.
 *  - `draft._wizard_active` (bool) = flag de detecção.
 *  - `draft.<campos>` = dados já preenchidos (description, amount, …).
 *  - `source` = `'wizard'` (rastreabilidade do SyncSheet).
 *
 * **Ref.:** `docs/specs/m9-spec-fase-2.md` §3 (fluxo detalhado) e
 * `docs/planos/m9-plano-tecnico.md` §2.Fase-D (plano de mitigação
 * Risco #1 — refactor MÍNIMO do Router).
 */
final class WizardHandler
{
    /**
     * Mensagem da etapa 1 (Tipo) — exposta como constante PÚBLICA porque o
     * {@see NovaHandler} precisa dela para enviar a
     * primeira pergunta ANTES de qualquer estado do wizard existir (a
     * sessão wizard ainda não tem `wizard_step` válido no momento em que
     * o /nova responde). Single source of truth entre NovaHandler e este
     * WizardHandler (review M9 W-2).
     */
    public const string STEP_1_TYPE_PROMPT = '📝 <b>Nova transação — Etapa 1/6: Tipo</b>'
        ."\n\nQual o tipo da transação?\n\n"
        .'💸 <b>Despesa</b> &nbsp; 💰 <b>Receita</b>';

    /**
     * Mensagens de pergunta para cada etapa do wizard.
     *
     * Cada entrada é um template que recebe o DTO parcial (campos já
     * preenchidos) e devolve o texto humanizado em PT-BR. Mantemos as
     * mensagens aqui — não no Formatter — porque elas são específicas
     * do wizard e podem evoluir independentemente do formato de listagem.
     *
     * O índice 1 (Tipo) reusa {@see self::STEP_1_TYPE_PROMPT} para
     * garantir uma única fonte da verdade (review M9 W-2).
     *
     * @return array<int, string> índice = WizardStep->value
     */
    private const array STEP_PROMPTS = [
        1 => self::STEP_1_TYPE_PROMPT,
        2 => '💵 <b>Nova transação — Etapa 2/6: Valor</b>'
            ."\n\nTipo: %type%\n\n"
            .'Qual o valor? (ex: <code>47.50</code> ou <code>R$ 47,50</code>)',
        3 => '📝 <b>Nova transação — Etapa 3/6: Descrição</b>'
            ."\n\nTipo: %type%\nValor: %amount%\n\n"
            .'Descreva a transação em poucas palavras:',
        4 => '🏷 <b>Nova transação — Etapa 4/6: Categoria</b>'
            ."\n\nTipo: %type%\nValor: %amount%\nDescrição: %description%\n\n"
            .'Qual a categoria? (ex: <b>Alimentação</b>, <b>Transporte</b>, <b>Moradia</b>)'
            ."\n\n💡 <i>Sugestão: <b>%suggested_category%</b> (baseado na descrição)</i>",
        5 => '🏷 <b>Nova transação — Etapa 5/6: Labels</b>'
            ."\n\nTipo: %type%\nValor: %amount%\nDescrição: %description%\nCategoria: %category%\n\n"
            .'Adicione <b>labels separadas por vírgula</b> (opcional):'
            ."\nex: <i>almoco, trabalho, viagem</i>\n\n"
            .'Envie <code>-</code> ou <code>pular</code> e eu sugiro labels automaticamente.',
    ];

    public function __construct(
        private readonly ConversationRouter $router,
        private readonly FirestoreService $firestore,
        private readonly BotMessenger $messenger,
        private readonly TransactionSummaryFormatter $formatter,
        private readonly SuggestsLabels $suggestLabels,
    ) {}

    /**
     * Processa a próxima resposta do usuário durante o wizard.
     *
     * Recebe um {@see ConversationInput} (texto ou callback), lê a sessão
     * atual, valida o campo da etapa em curso, e ou avança o wizard ou
     * re-pergunta em caso de input inválido.
     *
     * Idempotente em relação a callbacks: toques de botão durante o
     * wizard são silenciosos (a confirmação é apenas por texto — o
     * keyboard de categoria ficou para iteração futura, ver Decisão #13).
     *
     * @param  array<string, mixed>  $session  Sessão atual (com draft._wizard_*).
     */
    public function handleStep(ConversationInput $input, string $chatId, array $session): void
    {
        $wizardStep = (int) ($session['draft']['_wizard_step'] ?? 0);

        if ($wizardStep < 1 || $wizardStep > 5) {
            // Estado inconsistente — loga e desiste (limpa sessão).
            Log::error('WizardHandler: _wizard_step inválido', [
                'chat_id' => $chatId,
                'wizard_step' => $wizardStep,
            ]);
            $this->firestore->clearSession($chatId);
            $this->messenger->notifyError(
                $chatId,
                '⚠️ Ocorreu um problema no cadastro. Use /nova para começar de novo.',
            );

            return;
        }

        $step = WizardStep::from($wizardStep);
        $field = $step->fieldName();
        $draft = TransactionData::fromDraftArray($session['draft'] ?? null);

        // Callbacks durante wizard são silenciosos (a confirmação é por texto).
        if ($input->kind === InputKind::Callback) {
            $this->messenger->answerCallback((string) $input->callbackId, '');

            return;
        }

        // Fotos no wizard: extraímos e mesclamos, mas mantendo a sequência
        // do wizard (se a foto trouxer campos que faltavam, eles são
        // incorporados). Para simplicidade do M9.1, ignoramos fotos e
        // pedimos que o usuário siga o wizard em texto.
        if ($input->kind === InputKind::Photo) {
            $this->messenger->notifyError(
                $chatId,
                '📷 Para o cadastro passo a passo, responda em texto. '
                .'Use a foto de nota fiscal apenas no fluxo normal (envie a foto sem /nova).',
            );
            $this->reAskCurrentStep($chatId, $draft, $step);

            return;
        }

        // Validação do campo da etapa atual.
        $raw = (string) $input->text;
        $normalized = $this->router->validateField($field, $raw);

        if ($normalized === null) {
            $this->handleInvalidWizardResponse($chatId, $session, $step, $draft, $field, $raw);

            return;
        }

        // M4 — LABELS com skip → sugere via LLM.
        // Se o usuário pulou a etapa de labels (input foi "pular"/"-"/etc.),
        // validateLabels retorna []. Nesse caso, chamamos o LLM para sugerir
        // labels automaticamente baseadas no contexto da transação.
        if ($step === WizardStep::LABELS && $normalized === [] && $this->isSkipIntent($raw)) {
            $catalog = $this->router->fetchLabelCatalog();
            $this->messenger->sendText($chatId, (string) config('labels.wizard_loading_message'));

            try {
                $suggested = $this->suggestLabels->suggest($draft, $catalog);
            } catch (Throwable $e) {
                Log::warning('WizardHandler: suggestLabels falhou — seguindo sem labels', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $suggested = [];
            }

            if ($suggested !== []) {
                $normalized = $suggested;
            }
            // Se $suggested é vazio, mantemos $normalized = [] (sem labels).
        }

        // Aplica ao draft (DTO).
        $newDraft = $draft->withField($field, $normalized);

        // Avança a etapa.
        $nextStep = $step->next();

        // CONFIRMATION é a SAÍDA do wizard (não tem prompt). Quando
        // chegamos aqui após validar LABELS, next() retorna CONFIRMATION
        // e devemos finalizar o wizard — não pedir prompt.
        if ($nextStep === null || $nextStep === WizardStep::CONFIRMATION) {
            // Última etapa (LABELS) validada — wizard completo.
            // Para o campo "amount" pode ser float; o withField já tratou.
            // O DTO precisa de `date` para `isComplete()` — se ausente,
            // preenchemos com hoje (decisão §3.3 do spec).
            if ($newDraft->date === null) {
                $newDraft = $newDraft->withField('date', (new DateTimeImmutable('today'))->format('Y-m-d'));
            }

            $this->completeWizard($chatId, $session, $newDraft);

            return;
        }

        // Persiste o draft + avança o wizard.
        $this->advanceToStep($chatId, $session, $newDraft, $nextStep);
    }

    /**
     * Avança o wizard para o próximo step: persiste draft, incrementa
     * `_wizard_step`, e envia a pergunta da próxima etapa.
     *
     * @param  WizardStep  $nextStep  Etapa de destino (1..5).
     */
    private function advanceToStep(
        string $chatId,
        array $session,
        TransactionData $newDraft,
        WizardStep $nextStep,
    ): void {
        $draftArray = $newDraft->toDraftArray();
        $draftArray['_wizard_step'] = $nextStep->value;
        $draftArray['_wizard_active'] = true;

        $this->firestore->setSession(
            $chatId,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: $draftArray,
                awaitingField: $nextStep->fieldName(),
                source: $session['source'] ?? 'wizard',
                retryCount: 0,
            ),
        );

        $this->messenger->askForField(
            $chatId,
            $nextStep->fieldName(),
            $this->buildPrompt($nextStep, $newDraft),
        );
    }

    /**
     * Finaliza o wizard: chama `presentConfirmation` do Router (que aplica
     * enriquecimento M8 e transiciona para `AWAITING_CONFIRMATION`).
     *
     * O Router cuida de remover os campos `_wizard_*` no `clearFields`
     * antes de gravar a sessão em `AWAITING_CONFIRMATION` (ver
     * {@see ConversationRouter::presentConfirmation()}).
     */
    private function completeWizard(string $chatId, array $session, TransactionData $dto): void
    {
        $source = $session['source'] ?? 'wizard';
        $this->router->presentConfirmation($chatId, $dto, $source);
    }

    /**
     * Trata resposta inválida: re-pergunta o mesmo campo, incrementando
     * `retry_count` da sessão. Acima do limite, desiste (limpa sessão).
     *
     * Estratégia: usa {@see FirestoreService::incrementSessionRetry()}
     * (já existente) e compara com o limite padrão do Router. A constante
     * `maxDataRetries` não está exposta no Router, mas o limite razoável
     * é 3 (consistente com M7).
     */
    private function handleInvalidWizardResponse(
        string $chatId,
        array $session,
        WizardStep $step,
        TransactionData $draft,
        string $field,
        string $raw,
    ): void {
        $newCount = $this->firestore->incrementSessionRetry($chatId);
        $maxRetries = 3;

        if ($newCount > $maxRetries) {
            $this->firestore->clearSession($chatId);
            $this->messenger->notifyError(
                $chatId,
                '⚠️ Não consegui entender suas respostas. O cadastro foi cancelado — use /nova para tentar de novo.',
            );

            return;
        }

        $this->messenger->askForField(
            $chatId,
            $field,
            $this->invalidWizardMessage($field, $newCount, $maxRetries)
                ."\n\n".$this->buildPrompt($step, $draft),
        );
    }

    /**
     * Re-pergunta a etapa atual sem incrementar retry (usado para fotos
     * e outros casos especiais onde a entrada não conta como "tentativa").
     */
    private function reAskCurrentStep(string $chatId, TransactionData $draft, WizardStep $step): void
    {
        $this->messenger->askForField(
            $chatId,
            $step->fieldName(),
            $this->buildPrompt($step, $draft),
        );
    }

    /**
     * Constrói o texto da pergunta para uma etapa, com os placeholders
     * preenchidos pelos valores já acumulados.
     *
     * Não deve ser chamado para {@see WizardStep::CONFIRMATION} — esse
     * step é a saída do wizard (tela de revisão), não tem pergunta.
     */
    private function buildPrompt(WizardStep $step, TransactionData $draft): string
    {
        if ($step === WizardStep::CONFIRMATION) {
            // Defensivo: o caller não deve pedir prompt da etapa de
            // confirmação. Log e devolve string vazia (não deve acontecer).
            Log::warning('WizardHandler: buildPrompt chamado para CONFIRMATION (no-op)');

            return '';
        }

        $template = self::STEP_PROMPTS[$step->value];

        $replacements = [
            '%type%' => $this->formatType($draft->type),
            '%amount%' => $draft->amount !== null
                ? 'R$ '.number_format((float) $draft->amount, 2, ',', '.')
                : '—',
            '%description%' => $draft->description ?? '—',
            '%category%' => $draft->category ?? '—',
            '%suggested_category%' => $this->suggestCategoryFromDescription($draft->description ?? ''),
        ];

        return strtr($template, $replacements);
    }

    /**
     * Formata o type para exibição PT-BR na linha de contexto.
     */
    private function formatType(?string $type): string
    {
        if ($type === null) {
            return '—';
        }

        return match ($type) {
            'expense' => '💸 Despesa',
            'income' => '💰 Receita',
            default => $type,
        };
    }

    /**
     * Mapa de palavras-chave → sugestão de categoria (M9.1).
     *
     * Cada entrada mapeia o nome canônico da categoria (lowercase) para
     * uma lista de palavras-chave associadas (também lowercase, sem
     * acentos). A busca é O(categorias × keywords) mas o mapa é pequeno
     * (~80 itens) — mantido como constante estática para evitar
     * realocação a cada chamada de {@see suggestCategoryFromDescription()}.
     *
     * @var array<string, list<string>>
     */
    private const array CATEGORY_KEYWORDS = [
        'alimentação' => ['almoço', 'almoco', 'jantar', 'café', 'cafe', 'lanche', 'restaurante', 'ifood', 'comida', 'pizza', 'hambúrguer', 'hamburguer', 'marmita'],
        'transporte' => ['uber', '99', 'taxi', 'táxi', 'ônibus', 'onibus', 'metrô', 'metro', 'gasolina', 'combustível', 'combustivel', 'estacionamento', 'pedágio', 'pedagio'],
        'moradia' => ['aluguel', 'condomínio', 'condominio', 'iptu', 'luz', 'água', 'agua', 'internet', 'gás', 'gas'],
        'saúde' => ['farmácia', 'farmacia', 'remédio', 'remedio', 'consulta', 'exame', 'plano de saúde', 'academia'],
        'lazer' => ['cinema', 'show', 'bar', 'balada', 'streaming', 'netflix', 'spotify', 'jogo'],
        'educação' => ['curso', 'livro', 'material escolar', 'faculdade', 'escola', 'mensalidade'],
        'salário' => ['salário', 'salario', 'pagamento'],
        'freelance' => ['freelance', 'projeto', 'bico', 'consultoria'],
    ];

    /**
     * Sugestão de categoria baseada em palavras-chave da descrição.
     *
     * Implementação M9.1 (simples, sem chamada externa): match por
     * keywords conhecidos. DeepSeek/serviço externo fica para iteração
     * futura se a taxa de acerto for baixa. Suficiente para o CT-025k e
     * para o help visual "💡 Sugestão: Alimentação".
     */
    private function suggestCategoryFromDescription(string $description): string
    {
        $desc = mb_strtolower($description);

        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($desc, $kw)) {
                    return ucfirst($category);
                }
            }
        }

        return 'Outros';
    }

    /**
     * Mensagem de erro amigável por campo (específica do wizard).
     */
    private function invalidWizardMessage(string $field, int $attempt, int $max): string
    {
        $hint = match ($field) {
            'type' => 'Responda com <b>despesa</b> ou <b>receita</b>.',
            'amount' => 'Use o formato <code>47,50</code> ou <code>R$ 47,50</code> (valor precisa ser maior que zero).',
            'description' => 'Descreva brevemente a transação (mín. 2 caracteres).',
            'category' => 'Informe o nome da categoria (ex.: <b>Alimentação</b>).',
            'labels' => 'Separe as labels por vírgula, ou envie <code>-</code> / <code>pular</code> para sugestão automática.',
            default => 'Verifique o valor informado e tente de novo.',
        };

        return "⚠️ Valor inválido. {$hint}\n\n(Tentativa {$attempt} de {$max}.)";
    }

    /**
     * Indica se o input do usuário é uma intenção de "pular" a etapa de labels.
     *
     * O input já passou por validateLabels (que retornou [] para keywords de skip),
     * mas precisamos distinguir entre "o usuário digitou algo que foi filtrado
     * para vazio" vs. "o usuário explicitamente pediu para pular".
     *
     * Keywords reconhecidas (case-insensitive, trimmed):
     *  - "pular", "skip", "-", "nenhum", "nenhuma" (já normalizadas pelo validador)
     *  - string vazia após trim (usuário enviou só espaços)
     */
    private function isSkipIntent(string $raw): bool
    {
        $cleaned = trim($raw);

        if ($cleaned === '') {
            return true;
        }

        $keyword = mb_strtolower($cleaned);

        return in_array($keyword, ['pular', 'skip', 'nenhuma', 'nenhum', '-'], true);
    }
}
