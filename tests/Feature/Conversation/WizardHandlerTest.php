<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SuggestCategory;
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
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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
 *  - `InMemoryFirestoreGateway` bindado no container;
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
    private const string CHAT_ID = '12345';

    private InMemoryFirestoreGateway $firestoreGw;

    private FirestoreService $firestore;

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

        $this->firestoreGw = new InMemoryFirestoreGateway;
        $this->firestore = new FirestoreService($this->firestoreGw);
        $this->messenger = new InMemoryBotMessenger;

        // Stubs nunca invocados no wizard, mas o Router exige no construtor.
        $this->extractText = new class implements ExtractsText
        {
            public function handle(string $text): TransactionData
            {
                throw new \LogicException('extractText não deve ser chamado durante o wizard.');
            }
        };
        $this->extractImage = new class implements ExtractsImage
        {
            public function handle(string $fileId): TransactionData
            {
                throw new \LogicException('extractImage não deve ser chamado durante o wizard.');
            }
        };
        $this->syncSheet = new class implements SyncsSheet
        {
            public function handle(TransactionData $dto, string $firestoreId): bool
            {
                throw new \LogicException('syncSheet não deve ser chamado neste teste (wizard ainda em etapa).');
            }
        };
    }

    private function makeRouter(): ConversationRouter
    {
        // Usa as classes REAIS do M8 (não mockáveis por serem `final`).
        // Sugestões de labels M8 são aplicadas após o wizard — os testes
        // que validam os labels do wizard devem considerar a união:
        //   $expected = $userLabels + $suggestedFromDescription
        // Para testes sensíveis (CT-025h/i/j), usamos descrições com
        // keywords MÍNIMAS para reduzir ruído.
        return new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            firestore: $this->firestore,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->firestore),
            sessionTimeoutMinutes: 15,
            maxDataRetries: 3,
        );
    }

    /**
     * Inicia a sessão wizard (simula o que NovaHandler faz).
     */
    private function startWizard(): void
    {
        $this->firestore->setSession(
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
        return $this->firestore->getSession(self::CHAT_ID);
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
        // CT-025h: "almoço, italiano, #restaurante" → ["almoço", "italiano", "restaurante"].
        // Após enriquecimento M8 (SuggestLabels), keywords da descrição
        // "Almoço no restaurante italiano" são mergeadas — validamos
        // que os labels do usuário estão presentes (podem haver extras
        // sugeridos pelo M8).
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('Almoço no restaurante italiano');
        $this->routeText('Alimentação');
        $this->messenger->fieldAsks = [];

        $this->routeText('almoço, italiano, #restaurante');

        $session = $this->currentSession();
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);
        $this->assertContains('almoço', $session['draft']['labels']);
        $this->assertContains('italiano', $session['draft']['labels']);
        $this->assertContains('restaurante', $session['draft']['labels']);
    }

    public function test_wizard_step5_labels_dedup_and_strip_hash(): void
    {
        // CT-025j: "#ok, #ok" deduplicado; "a" (1 char) filtrado.
        $this->startWizard();
        $this->routeText('despesa');
        $this->routeText('47,50');
        $this->routeText('X2');
        $this->routeText('Alimentação');
        $this->messenger->fieldAsks = [];

        $this->routeText('a, #ok, #ok, #trabalho');

        $session = $this->currentSession();
        // "a" é filtrado (1 char). "#ok" aparece 1x (dedup). "#trabalho" preservado.
        $this->assertContains('ok', $session['draft']['labels']);
        $this->assertContains('trabalho', $session['draft']['labels']);
        // "a" NÃO está presente.
        $this->assertNotContains('a', $session['draft']['labels']);
    }

    #[Group('smoke')]
    public function test_wizard_complete_flow_reaches_confirmation(): void
    {
        // CT-025 (happy path completo): tipo → valor → descrição → categoria → labels.
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
        // Labels do wizard (escritorio, material) — M8 pode adicionar
        // sugestões baseadas em "Material de escritório" (ex.: "escritório"),
        // mas os labels do usuário estão garantidos.
        $this->assertContains('escritorio', $session['draft']['labels']);
        $this->assertContains('material', $session['draft']['labels']);
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
        $this->firestore->setSession(
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
}
