<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SuggestCategory;
use App\Actions\SuggestsLabels;
use App\Actions\SyncsSheet;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Conversation\ConversationInput;
use App\Conversation\ConversationRouter;
use App\Conversation\StateMachine;
use App\Conversation\WizardHandler;
use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Enums\ConversationState;
use App\Enums\WizardStep;
use App\Services\Store\WalletStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes do fluxo do wizard `/nova` (M9.3 / T-019).
 *
 * Estes testes exercitam o {@see WizardHandler} indiretamente através do
 * {@see ConversationRouter::route()}, simulando o caminho que o Telegram
 * percorre: MessageRouterHandler → ConversationInput → ConversationRouter →
 * (delegate para WizardHandler quando wizard ativo).
 *
 * **Setup**: idêntico ao {@see ConversationRouterTest}:
 *  - `WalletStore` via `app(WalletStore::class)` com `RedisFake`;
 *  - `InMemoryBotMessenger` capturando todas as chamadas de I/O;
 *  - Stubs anônimos para ExtractsText/Image e SyncsSheet (nunca invocados
 *    no wizard — o wizard é fluxo manual, sem extração LLM);
 *  - `TransactionSummaryFormatter` real (precisamos do `summary()` para
 *    o presentConfirmation enriquecer + mostrar resumo).
 *
 * **Cenários cobertos (CT-025, 025a-025n)**:
 *  - test_wizard_step1_type_desp_advances_to_step2_amount (CT-025 happy)
 *  - test_wizard_step1_type_invalid_retries (CT-025a)
 *  - test_wizard_step2_amount_invalid_retries (CT-025b)
 *  - test_wizard_step2_amount_zero_retries (CT-025c)
 *  - test_wizard_step3_description_too_short_retries (CT-025d)
 *  - test_wizard_step5_labels_pular_yields_empty_array (CT-025i)
 *  - test_wizard_step5_labels_comma_separated_yields_array (CT-025h)
 *  - test_wizard_step5_labels_dedup_and_strip_hash (CT-025j)
 *  - test_wizard_complete_flow_reaches_confirmation (CT-025 full)
 *  - test_wizard_with_existing_session_overrides_previous (CT-025l)
 *  - test_wizard_callback_during_step_is_silent
 *  - test_wizard_photo_during_step_asks_to_use_text
 *  - test_wizard_exceeds_max_retries_clears_session
 *  - test_wizard_default_date_is_today
 *
 * Roda isolado: vendor/bin/phpunit --filter WizardHandlerTest
 */
#[CoversClass(WizardHandler::class)]
#[CoversClass(ConversationRouter::class)]
class WizardHandlerTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private const string CHAT_ID = '12345';

    private WalletStore $store;

    private InMemoryBotMessenger $messenger;

    private object $extractText;

    private object $extractImage;

    private object $syncSheet;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->messenger = new InMemoryBotMessenger;

        // Stubs nunca invocados no wizard, mas o Router exige no construtor.
        $this->extractText = new class implements ExtractsText
        {
            public function handle(string $text, array $labelCatalog = []): TransactionData
            {
                throw new \LogicException('extractText não deve ser chamado durante o wizard.');
            }
        };
        $this->extractImage = new class implements ExtractsImage
        {
            public function handle(string $fileId, array $labelCatalog = []): TransactionData
            {
                throw new \LogicException('extractImage não deve ser chamado durante o wizard.');
            }
        };
        $this->syncSheet = new class implements SyncsSheet
        {
            public function handle(TransactionData $dto, int $txId): bool
            {
                throw new \LogicException('syncSheet não deve ser chamado neste teste (wizard ainda em etapa).');
            }
        };
    }

    private object $suggestLabels;

    private function makeRouter(): ConversationRouter
    {
        $this->suggestLabels = new class implements SuggestsLabels
        {
            public array $toReturn = [];

            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                return $this->toReturn;
            }
        };

        return new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $this->suggestLabels,
            maxDataRetries: 3,
        );
    }

    /**
     * Inicia a sessão wizard (simula o que NovaHandler faz).
     *
     * Por padrão, `_wizard_items_asked=true` para que os testes do fluxo
     * principal não disparem o sub-fluxo de items (M-ITENS-6).
     */
    private function startWizard(): void
    {
        $this->store->setSession(
            self::CHAT_ID,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: [
                    '_wizard_step' => WizardStep::TYPE->value,
                    '_wizard_active' => true,
                    '_wizard_items_asked' => true,
                ],
                awaitingField: WizardStep::TYPE->fieldName(),
                source: 'wizard',
                retryCount: 0,
            ),
        );
    }

    /**
     * Inicia o wizard SEM a flag _wizard_items_asked — permite testar o
     * sub-fluxo de items (M-ITENS-6).
     */
    private function startWizardWithItemsSubflow(): void
    {
        $this->store->setSession(
            self::CHAT_ID,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: [
                    '_wizard_step' => WizardStep::TYPE->value,
                    '_wizard_active' => true,
                ],
                awaitingField: WizardStep::TYPE->fieldName(),
                source: 'wizard',
                retryCount: 0,
            ),
        );
    }

    private function routeText(string $text): void
    {
        $this->makeRouter()->route(ConversationInput::text(self::CHAT_ID, $text));
    }

    private function currentSession(): ?array
    {
        return $this->store->getSession(self::CHAT_ID);
    }

    public function test_wizard_step1_type_desp_advances_to_step2_amount(): void
    {
        // CT-025 happy path (parte 1): step 1 tipo "despesa" → step 2 valor.
        $this->startWizard();

        $this->routeText('despesa');

        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_DATA->value, $session['state']);
        $this->assertSame(2, $session['draft']['_wizard_step']);
        $this->assertTrue($session['draft']['_wizard_active']);
        $this->assertSame('expense', $session['draft']['type']);
        $this->assertSame('amount', $session['awaiting_field']);

        // Mensagem da etapa 2 enviada.
        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $fieldAsks);
        $this->assertSame('amount', $fieldAsks[0]['field']);
        $this->assertStringContainsString('Etapa 2/6', $fieldAsks[0]['prompt']);
    }

    public function test_wizard_step1_type_invalid_retries(): void
    {
        // CT-025a: tipo inválido ("foo") → re-pergunta, não avança.
        // Nota: "ganho" e "gasto" são sinônimos PT-BR já mapeados em
        // validateType, então usamos uma string que realmente não casa.
        $this->startWizard();

        $this->routeText('foo');

        $session = $this->currentSession();
        $this->assertSame(1, $session['draft']['_wizard_step'], 'step NÃO deve avançar com input inválido');
        $this->assertSame(1, $session['retry_count']);

        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $fieldAsks);
        $this->assertStringContainsString('⚠️ Valor inválido', $fieldAsks[0]['prompt']);
    }

    public function test_wizard_step2_amount_invalid_retries(): void
    {
        // CT-025b: valor inválido ("abc") → re-pergunta, não avança.
        $this->startWizard();
        $this->routeText('despesa'); // step 1 OK
        $this->messenger->fieldAsks = []; // limpa pra asserção isolada

        $this->routeText('abc');

        $session = $this->currentSession();
        $this->assertSame(2, $session['draft']['_wizard_step']);
        $this->assertSame(1, $session['retry_count']);

        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $fieldAsks);
        $this->assertStringContainsString('⚠️ Valor inválido', $fieldAsks[0]['prompt']);
    }

    public function test_wizard_step2_amount_zero_retries(): void
    {
        // CT-025c: valor zero rejeitado.
        $this->startWizard();
        $this->routeText('despesa');
        $this->messenger->fieldAsks = [];

        $this->routeText('0');

        $session = $this->currentSession();
        $this->assertSame(2, $session['draft']['_wizard_step']);
        $this->assertSame(1, $session['retry_count']);
    }

    public function test_wizard_step3_description_too_short_retries(): void
    {
        // CT-025d: descrição < 2 chars ("ab" é 2 chars exatos — aceita; "a" não).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->messenger->fieldAsks = [];

        $this->routeText('a'); // 1 char

        $session = $this->currentSession();
        $this->assertSame(3, $session['draft']['_wizard_step']);
        $this->assertSame(1, $session['retry_count']);

        $this->routeText('X3'); // aceita (≥2 chars, keywords curtas)

        $session = $this->currentSession();
        $this->assertSame(4, $session['draft']['_wizard_step']);
    }

    public function test_wizard_step5_labels_pular_yields_empty_array(): void
    {
        // CT-025i: "pular" → array vazio → segue para CONFIRMATION.
        // Usamos descrição curta ("X") para evitar sugestões M8 que
        // adicionariam keywords baseadas na descrição.
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('X1');
        $this->routeText('Alimentação');
        $this->messenger->fieldAsks = [];

        $this->routeText('pular');

        // Após "pular", wizard está completo → AWAITING_CONFIRMATION.
        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        // labels=[] é omitido pelo toDraftArray (filter $v !== []).
        $this->assertArrayNotHasKey('labels', $session['draft']);

        // _wizard_* removido (clearFields no presentConfirmation).
        $this->assertArrayNotHasKey('_wizard_step', $session['draft']);
        $this->assertArrayNotHasKey('_wizard_active', $session['draft']);
    }

    public function test_wizard_step5_labels_comma_separated_yields_array(): void
    {
        // CT-025h: "almoço, italiano, #restaurante" → ["Almoço", "Italiano", "Restaurante"].
        // validateLabels agora aplica LabelFormatter::format() (P1 — Sentence Case).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('Almoço no restaurante italiano');
        $this->routeText('Alimentação');
        $this->messenger->fieldAsks = [];

        $this->routeText('almoço, italiano, #restaurante');

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertContains('Almoço', $session['draft']['labels']);
        $this->assertContains('Italiano', $session['draft']['labels']);
        $this->assertContains('Restaurante', $session['draft']['labels']);
    }

    public function test_wizard_step5_labels_dedup_and_strip_hash(): void
    {
        // CT-025j: "#ok, #ok" deduplicado; "a" (1 char) filtrado.
        // validateLabels agora aplica LabelFormatter::format() (P1 — Sentence Case).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('X2');
        $this->routeText('Alimentação');
        $this->messenger->fieldAsks = [];

        $this->routeText('a, #ok, #ok, #trabalho');

        $session = $this->currentSession();
        // "a" é filtrado (1 char). "#ok" → "Ok" (format), aparece 1x (dedup). "#trabalho" → "Trabalho".
        $this->assertContains('Ok', $session['draft']['labels']);
        $this->assertContains('Trabalho', $session['draft']['labels']);
        // "a" NÃO está presente.
        $this->assertNotContains('a', $session['draft']['labels']);
        // "ok" lowercase também não (já foi formatado).
        $this->assertNotContains('ok', $session['draft']['labels']);
    }

    #[Group('smoke')]
    public function test_wizard_complete_flow_reaches_confirmation(): void
    {
        // CT-025 (happy path completo): tipo → valor → descrição → categoria → labels.
        // validateLabels agora aplica LabelFormatter::format() (P1 — Sentence Case).
        $this->startWizard();

        $this->routeText('despesa');
        $this->routeText('R$ 149,90');
        $this->routeText('Material de escritório');
        $this->routeText('Alimentação');
        $this->routeText('escritorio, material');

        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertSame('expense', $session['draft']['type']);
        $this->assertSame(149.90, $session['draft']['amount']);
        $this->assertSame('Material de escritório', $session['draft']['description']);
        $this->assertSame('Alimentação', $session['draft']['category']);
        // Labels formatados: "Escritorio", "Material" (Sentence Case pelo LabelFormatter).
        $this->assertContains('Escritorio', $session['draft']['labels']);
        $this->assertContains('Material', $session['draft']['labels']);
        $this->assertArrayHasKey('message_id_confirm', $session);

        // Confirmação enviada.
        $confirmations = $this->messenger->confirmations[self::CHAT_ID] ?? [];
        $this->assertCount(1, $confirmations);
    }

    public function test_wizard_with_existing_session_overrides_previous(): void
    {
        // CT-025l: /nova durante AWAITING_CONFIRMATION descarta pendente.
        // (Este teste valida a integração: NovaHandler chama clearSession.)
        // Aqui validamos a parte do ConversationRouter: se wizard_active
        // está true, mesmo que o estado seja outro, o wizard assume.
        $this->store->setSession(
            self::CHAT_ID,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: [
                    '_wizard_step' => WizardStep::TYPE->value,
                    '_wizard_active' => true,
                ],
                awaitingField: WizardStep::TYPE->fieldName(),
                source: 'wizard',
                retryCount: 0,
            ),
        );

        $this->routeText('receita');

        $session = $this->currentSession();
        $this->assertSame(2, $session['draft']['_wizard_step']);
        $this->assertSame('income', $session['draft']['type']);
    }

    public function test_wizard_callback_during_step_is_silent(): void
    {
        // Callback durante wizard → answerCallback vazio, wizard não avança.
        $this->startWizard();
        $router = $this->makeRouter();

        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-xyz',
            callbackData: 'edit:amount',
            callbackMessageId: 5000,
        ));

        $session = $this->currentSession();
        $this->assertSame(1, $session['draft']['_wizard_step'], 'callback não deve avançar wizard');

        // Callback respondido (silencioso).
        $lastAnswer = end($this->messenger->callbackAnswers);
        $this->assertSame('cb-xyz', $lastAnswer['callback_id']);
    }

    public function test_wizard_photo_during_step_asks_to_use_text(): void
    {
        // Foto durante wizard → erro amigável + re-pergunta.
        $this->startWizard();
        $router = $this->makeRouter();

        $router->route(ConversationInput::photo(self::CHAT_ID, 'photo-id'));

        $session = $this->currentSession();
        $this->assertSame(1, $session['draft']['_wizard_step'], 'foto não deve avançar wizard');

        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('responda em texto', $errors[0]['message']);
    }

    public function test_wizard_exceeds_max_retries_clears_session(): void
    {
        // 4 respostas inválidas consecutivas → wizard cancelado, sessão limpa.
        $this->startWizard();

        for ($i = 1; $i <= 4; $i++) {
            $this->routeText('lixo');
        }

        $this->assertNull($this->currentSession(), 'Sessão deve ser limpa após exceder maxRetries');

        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cancelado', $errors[count($errors) - 1]['message']);
    }

    public function test_wizard_default_date_is_today(): void
    {
        // Quando o wizard chega ao fim sem data (não há step de data),
        // o Router preenche com HOJE automaticamente (decisão §3.3 do spec).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Almoço');
        $this->routeText('Alimentação');
        $this->routeText('pular');

        $session = $this->currentSession();
        $this->assertSame(date('Y-m-d'), $session['draft']['date']);
    }

    public function test_wizard_type_receita_advances(): void
    {
        // Variante: tipo "receita" → step 2 amount.
        $this->startWizard();

        $this->routeText('receita');

        $session = $this->currentSession();
        $this->assertSame(2, $session['draft']['_wizard_step']);
        $this->assertSame('income', $session['draft']['type']);
    }

    public function test_wizard_type_aliases_accepted(): void
    {
        // Sinônimos PT-BR aceitos: "gasto" (expense) e "ganho" (income).
        $this->startWizard();
        $this->routeText('gasto');

        $session = $this->currentSession();
        $this->assertSame(2, $session['draft']['_wizard_step']);
        $this->assertSame('expense', $session['draft']['type']);
    }

    public function test_wizard_amount_accepts_pt_br_format(): void
    {
        // Aceita "R$ 1.234,56" (PT-BR com milhar).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('R$ 1.234,56');

        $session = $this->currentSession();
        $this->assertSame(3, $session['draft']['_wizard_step']);
        $this->assertSame(1234.56, $session['draft']['amount']);
    }

    public function test_wizard_suggested_category_shown_in_step4(): void
    {
        // Sugestão de categoria aparece na mensagem da etapa 4 quando a
        // descrição tem keyword conhecida.
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');

        $this->messenger->fieldAsks = [];

        $this->routeText('Almoço no restaurante');
        // Após essa resposta, o wizard avançou para a etapa 4 (category)
        // e enviou o prompt com a sugestão. É a ÚNICA fieldAsk registrada
        // após o reset.
        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $fieldAsks);
        $this->assertSame('category', $fieldAsks[0]['field']);
        $this->assertStringContainsString('Sugestão', $fieldAsks[0]['prompt']);
    }

    /*
    |--------------------------------------------------------------------------
    | M4 — Wizard: skip labels → LLM sugestão (T4.5)
    |--------------------------------------------------------------------------
    */

    public function test_wizard_step5_skip_calls_llm_and_uses_suggested_labels(): void
    {
        // M4: ao pular labels, o wizard chama SuggestsLabels e usa as labels
        // sugeridas pelo LLM. O stub retorna ['Almoço'].
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('Almoço no restaurante');
        $this->routeText('Alimentação');
        $this->messenger->sentTexts = [];

        // Configura o stub ANTES de chamar makeRouter (que cria o stub).
        // Criamos o router manualmente com um stub pré-configurado.
        $suggestLabels = new class implements SuggestsLabels
        {
            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                return ['Almoço'];
            }
        };

        $router = new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $suggestLabels,
            maxDataRetries: 3,
        );

        $router->route(ConversationInput::text(self::CHAT_ID, 'pular'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        // Labels sugeridas pelo LLM devem estar no draft.
        $this->assertArrayHasKey('labels', $session['draft'], 'Labels devem estar presentes no draft');
        $this->assertContains('Almoço', $session['draft']['labels']);
    }

    public function test_wizard_step5_skip_with_llm_empty_follows_without_labels(): void
    {
        // M4: stub do LLM retorna [] → wizard segue sem labels (sem erro).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('X1');
        $this->routeText('Alimentação');
        $this->messenger->sentTexts = [];

        // Stub que retorna vazio (sem sugestões).
        $suggestLabels = new class implements SuggestsLabels
        {
            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                return [];
            }
        };

        $router = new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $suggestLabels,
            maxDataRetries: 3,
        );

        $router->route(ConversationInput::text(self::CHAT_ID, 'pular'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        // labels=[] é omitido pelo toDraftArray (filter $v !== []).
        $this->assertArrayNotHasKey('labels', $session['draft']);
    }

    public function test_wizard_step5_skip_sends_loading_message(): void
    {
        // M4: ao pular labels, uma mensagem de loading é enviada antes
        // da chamada ao LLM.
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('Almoço');
        $this->routeText('Alimentação');
        $this->messenger->sentTexts = [];

        $suggestLabels = new class implements SuggestsLabels
        {
            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                return ['Almoço'];
            }
        };

        $router = new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $suggestLabels,
            maxDataRetries: 3,
        );

        $router->route(ConversationInput::text(self::CHAT_ID, 'pular'));

        // Deve ter enviado a mensagem de loading.
        $texts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $loadingMessages = array_filter(
            $texts,
            fn (array $t): bool => str_contains($t['text'], 'Sugerindo labels'),
        );
        $this->assertCount(1, $loadingMessages, 'Deve enviar mensagem de loading ao pular labels');
    }

    public function test_wizard_step5_explicit_labels_not_skip_bypass_llm(): void
    {
        // M4: se o usuário digita labels explicitamente (ex.: "almoco, trabalho"),
        // o LLM NÃO é chamado — validateLabels retorna array não-vazio.
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('X1');
        $this->routeText('Alimentação');
        $this->messenger->sentTexts = [];

        // Configura stub que NÃO deve ser chamado (explodiria se fosse).
        $suggestLabels = new class implements SuggestsLabels
        {
            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                throw new \LogicException('LLM não deve ser chamado quando labels são explícitas.');
            }
        };

        $router = new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $suggestLabels,
            maxDataRetries: 3,
        );

        $router->route(ConversationInput::text(self::CHAT_ID, 'almoco, trabalho'));

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        // Labels formatadas pelo LabelFormatter (Sentence Case).
        $this->assertContains('Almoco', $session['draft']['labels']);
        $this->assertContains('Trabalho', $session['draft']['labels']);

        // NÃO deve ter enviado mensagem de loading.
        $texts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $loadingMessages = array_filter(
            $texts,
            fn (array $t): bool => str_contains($t['text'], 'Sugerindo labels'),
        );
        $this->assertCount(0, $loadingMessages, 'Não deve enviar loading para labels explícitas');
    }

    public function test_wizard_step5_prompt_reflects_auto_suggestion(): void
    {
        // T4.4: o prompt da etapa 5 deve mencionar que o bot sugere labels
        // automaticamente quando o usuário pula.
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('Almoço');

        // Após responder "Almoço" (step 3), o wizard avança para step 4 (category).
        // Limpa fieldAsks para capturar apenas o prompt da transição 3→4 e 4→5.
        $this->messenger->fieldAsks = [];

        // Responde a categoria (step 4 → step 5).
        $this->routeText('Alimentação');

        // Agora fieldAsks contém o prompt da etapa 5 (labels),
        // que foi enviado na transição 4→5.
        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertCount(1, $fieldAsks);
        $this->assertSame('labels', $fieldAsks[0]['field']);
        $this->assertStringContainsString('sugiro labels automaticamente', $fieldAsks[0]['prompt']);
    }

    /*
    |--------------------------------------------------------------------------
    | M-ITENS-6 — Wizard sub-fluxo de items
    |--------------------------------------------------------------------------
    */

    public function test_after_description_asks_about_items(): void
    {
        // CT-109/AC-009: após validar descrição, pergunta "Detalhar itens?".
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->messenger->sentTexts = []; // limpa para asserção isolada

        $this->routeText('Compra no mercado');

        $session = $this->currentSession();
        // Deve ter parado no step 3 com awaiting_field='items_choice'.
        $this->assertSame(3, $session['draft']['_wizard_step']);
        $this->assertSame('items_choice', $session['awaiting_field']);
        $this->assertTrue($session['draft']['_wizard_items_asked']);

        // Deve ter enviado mensagem "Detalhar itens?".
        $texts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $itemsMsg = array_filter($texts, fn (array $t): bool => str_contains($t['text'], 'Detalhar itens'));
        $this->assertCount(1, $itemsMsg);
    }

    public function test_items_choice_question_sends_keyboard_with_yes_no_callbacks(): void
    {
        // C-1 (CRITICAL): após validar DESCRIPTION, a pergunta "Detalhar itens?"
        // deve ser enviada COM inline keyboard (Sim/Pular), não como texto puro.
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // O keyboard deve ter sido registrado no InMemoryBotMessenger.
        $callbacks = $this->messenger->itemsChoiceKeyboards[self::CHAT_ID] ?? [];
        $this->assertContains('wizard_items_yes', $callbacks, 'Keyboard deve ter callback wizard_items_yes');
        $this->assertContains('wizard_items_no', $callbacks, 'Keyboard deve ter callback wizard_items_no');
        $this->assertCount(2, $callbacks);

        // O message_id deve estar armazenado no draft.
        $session = $this->currentSession();
        $this->assertArrayHasKey('_wizard_message_id_items_choice', $session['draft']);
        $this->assertGreaterThan(0, $session['draft']['_wizard_message_id_items_choice']);
    }

    public function test_callback_yes_enters_items_collection(): void
    {
        // CT-110/AC-010: clica "Sim" → prompt ITEMS_PROMPT + awaiting_field='items'.
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // Agora simula callback "wizard_items_yes".
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        $session = $this->currentSession();
        $this->assertSame('items', $session['awaiting_field']);
        $this->assertTrue($session['draft']['_wizard_items_asked']);

        // Deve ter enviado ITEMS_PROMPT.
        $fieldAsks = $this->messenger->fieldAsks[self::CHAT_ID] ?? [];
        $this->assertNotEmpty($fieldAsks);
        $lastAsk = end($fieldAsks);
        $this->assertSame('items', $lastAsk['field']);
        $this->assertStringContainsString('Itens da transação', $lastAsk['prompt']);
    }

    public function test_callback_no_advances_to_category(): void
    {
        // CT-113/AC-013: clica "Pular" → vai direto a Categoria com items=[].
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // Simula callback "wizard_items_no".
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-no',
            callbackData: 'wizard_items_no',
            callbackMessageId: 0,
        ));

        $session = $this->currentSession();
        // Deve ter avançado para step 4 (CATEGORY).
        $this->assertSame(4, $session['draft']['_wizard_step']);
        $this->assertSame('category', $session['awaiting_field']);
        // items não deve estar no draft (vazio, omitido pelo toDraftArray).
        $this->assertArrayNotHasKey('items', $session['draft']);
    }

    public function test_multiline_items_parsed_correctly(): void
    {
        // CT-111/AC-011: envia items multiline → parseados + avança para CATEGORY.
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // Simula callback "wizard_items_yes".
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes-2',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        // Envia items.
        $router->route(ConversationInput::text(
            self::CHAT_ID,
            "Arroz 5kg x2 32.90\nFeijão\nDetergente x1 4.50",
        ));

        $session = $this->currentSession();
        // Deve ter avançado para step 4 (CATEGORY).
        $this->assertSame(4, $session['draft']['_wizard_step']);
        $this->assertSame('category', $session['awaiting_field']);

        // 3 items.
        $this->assertArrayHasKey('items', $session['draft']);
        $this->assertCount(3, $session['draft']['items']);
        $this->assertSame('Arroz 5kg', $session['draft']['items'][0]['name']);
        $this->assertSame(2.0, $session['draft']['items'][0]['qty']);
        $this->assertSame(32.90, $session['draft']['items'][0]['unitPrice']);
    }

    public function test_pular_in_items_field_advances_with_empty(): void
    {
        // CT-112/AC-012: envia "pular" no campo items → items=[] + avança.
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // Simula callback "wizard_items_yes".
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes-3',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        // Envia "pular" no campo items.
        $router->route(ConversationInput::text(self::CHAT_ID, 'pular'));

        $session = $this->currentSession();
        // Avançou para step 4.
        $this->assertSame(4, $session['draft']['_wizard_step']);
        $this->assertSame('category', $session['awaiting_field']);
        // items=[] omitido.
        $this->assertArrayNotHasKey('items', $session['draft']);
    }

    public function test_total_asked_steps_remains_5(): void
    {
        // CT-115/AC-015: TOTAL_ASKED_STEPS permanece 5.
        $this->assertSame(5, WizardStep::TOTAL_ASKED_STEPS);
    }

    public function test_three_invalid_inputs_cancels_wizard(): void
    {
        // CT-156/AC-058: após sub-fluxo de items, retry de campo inválido ainda funciona.
        // Nota: ItemsParser é permissivo — a rejeição de items (null) ocorre só em
        // cenários de LLM. Aqui testamos que o contador de retry do wizard funciona
        // para o campo de categoria (após items).
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // Simula callback "wizard_items_yes".
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes-retry',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        // Envia items válidos → avança para CATEGORY (step 4).
        $router->route(ConversationInput::text(self::CHAT_ID, 'Arroz'));

        $session = $this->currentSession();
        $this->assertSame(4, $session['draft']['_wizard_step']);
        $this->assertSame('category', $session['awaiting_field']);

        // 4 inputs inválidos de categoria → wizard cancela.
        // Nota: a categoria precisa ser uma string vazia para ser rejeitada
        // (validateCategory rejeita trim vazio). Usamos uma string de espaço
        // que é trimmed para vazio e rejeitada como null.
        for ($i = 1; $i <= 4; $i++) {
            $this->routeText('   ');
        }

        $this->assertNull($this->currentSession(), 'Sessão deve ser limpa após exceder maxRetries');
        $errors = $this->messenger->errors[self::CHAT_ID] ?? [];
        $this->assertNotEmpty($errors);
    }

    public function test_wizard_items_asked_flag_persists(): void
    {
        // CT-157/AC-059: _wizard_items_asked=true após avançar (não re-pergunta).
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        // Simula callback "wizard_items_yes".
        $router = $this->makeRouter();
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes-5',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        // Envia items e avança.
        $router->route(ConversationInput::text(self::CHAT_ID, 'Arroz'));
        $session = $this->currentSession();
        $this->assertSame(4, $session['draft']['_wizard_step']);
        // Flag deve persistir.
        $this->assertTrue($session['draft']['_wizard_items_asked']);
    }

    public function test_double_click_yes_is_idempotent(): void
    {
        // CT-164: 2× clique "Sim" → só 1 prompt de items.
        $this->startWizardWithItemsSubflow();
        $this->routeText('despesa');
        $this->routeText('50');
        $this->routeText('Compra no mercado');

        $router = $this->makeRouter();

        // Primeiro clique "Sim".
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes-1',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        $session = $this->currentSession();
        $this->assertSame('items', $session['awaiting_field']);
        $fieldAsksBefore = count($this->messenger->fieldAsks[self::CHAT_ID] ?? []);

        // Segundo clique "Sim" — deve ser silencioso (awaiting_field não é 'items_choice').
        $router->route(ConversationInput::callback(
            chatId: self::CHAT_ID,
            callbackId: 'cb-yes-2',
            callbackData: 'wizard_items_yes',
            callbackMessageId: 0,
        ));

        $session = $this->currentSession();
        // Ainda em 'items'.
        $this->assertSame('items', $session['awaiting_field']);
        // Nenhum novo fieldAsk (callback cai no branch silencioso).
        $fieldAsksAfter = count($this->messenger->fieldAsks[self::CHAT_ID] ?? []);
        $this->assertSame($fieldAsksBefore, $fieldAsksAfter);
    }

    /*
    |--------------------------------------------------------------------------
    | W-B: presentConfirmation limpa flags stale _wizard_*
    |--------------------------------------------------------------------------
    */

    public function test_present_confirmation_clears_wizard_items_flags(): void
    {
        // W-B: ao finalizar wizard, _wizard_items_asked e
        // _wizard_message_id_items_choice são removidos do draft.
        // Simulamos uma sessão que passou pelo sub-fluxo de items
        // (portanto tem ambos os flags) e está na etapa LABELS.
        $this->store->setSession(
            self::CHAT_ID,
            new SessionData(
                state: ConversationState::AWAITING_DATA->value,
                draft: [
                    'type' => 'expense',
                    'amount' => 50.0,
                    'description' => 'Compra no mercado',
                    'category' => 'Alimentação',
                    '_wizard_step' => WizardStep::LABELS->value,
                    '_wizard_active' => true,
                    '_wizard_items_asked' => true,
                    '_wizard_message_id_items_choice' => 9999,
                ],
                awaitingField: 'labels',
                source: 'wizard',
                retryCount: 0,
            ),
        );

        // Responde "pular" → wizard completo → presentConfirmation.
        $this->routeText('pular');

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);

        // W-B: flags _wizard_* devem ser removidos do draft.
        $this->assertArrayNotHasKey('_wizard_step', $session['draft'],
            'W-B: _wizard_step deve ser limpo no presentConfirmation');
        $this->assertArrayNotHasKey('_wizard_active', $session['draft'],
            'W-B: _wizard_active deve ser limpo no presentConfirmation');
        $this->assertArrayNotHasKey('_wizard_items_asked', $session['draft'],
            'W-B: _wizard_items_asked deve ser limpo no presentConfirmation');
        $this->assertArrayNotHasKey('_wizard_message_id_items_choice', $session['draft'],
            'W-B: _wizard_message_id_items_choice deve ser limpo no presentConfirmation');
    }
}
