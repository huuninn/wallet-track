<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeek;

use App\Actions\ExtractFromText;
use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;
use App\Services\DeepSeek\ChatCompleter;
use App\Services\DeepSeek\DeepSeekService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Testes de feature da camada de extração DeepSeek (M3.10).
 *
 * Mapeia os casos de teste manuais CT-001 a CT-006b, CT-037 (fallback) e
 * falhas estruturais (JSON malformado, input vazio, campos críticos).
 *
 * **Nenhuma chamada real à API é feita.** Bindamos um FakeChatCompleter no
 * container que devolve JSON de fixture pré-gravado (ou lança exceção para
 * simular indisponibilidade). Isto mantém os testes determinísticos e
 * viáveis em CI sem acesso à internet.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter TextExtractionTest
 */
#[CoversClass(DeepSeekService::class)]
#[CoversClass(ExtractFromText::class)]
class TextExtractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ancora "hoje" para asserções determinísticas de data (CT-001/CT-005).
        Carbon::setTestNow('2025-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de montagem
    |--------------------------------------------------------------------------
    */

    /**
     * Binda um FakeChatCompleter que devolve a string $response e devolve
     * uma DeepSeekService pronta para uso.
     */
    private function serviceReturning(string $response): DeepSeekService
    {
        $this->app->bind(ChatCompleter::class, fn () => new FakeChatCompleter(response: $response));

        return $this->app->make(DeepSeekService::class);
    }

    /**
     * Binda um FakeChatCompleter que lança uma exceção ao ser chamado.
     */
    private function serviceThrowing(Throwable $e): DeepSeekService
    {
        $this->app->bind(ChatCompleter::class, fn () => new FakeChatCompleter(throw: $e));

        return $this->app->make(DeepSeekService::class);
    }

    /**
     * Monta uma fixture JSON de saída do DeepSeek (campos snake_case).
     */
    private function fixture(array $data): string
    {
        return json_encode(array_merge([
            'description' => null,
            'amount' => null,
            'type' => null,
            'category' => null,
            'labels' => [],
            'date' => 'hoje',
            'observations' => null,
            'confidence' => 0.9,
        ], $data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-001 — Despesa completa via texto livre
    |--------------------------------------------------------------------------
    */

    public function test_ct001_extracts_complete_expense_with_amount_type_and_category(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Almoço no restaurante italiano',
            'amount' => 47.50,
            'type' => 'expense',
            'category' => 'Alimentação',
            'labels' => ['almoço', 'restaurante'],
            'date' => 'hoje',
        ]));

        $dto = $service->extract('Paguei R$ 47,50 no almoço de hoje no restaurante italiano');

        $this->assertSame('Almoço no restaurante italiano', $dto->description);
        $this->assertSame(47.50, $dto->amount);
        $this->assertSame('expense', $dto->type);
        $this->assertSame('Alimentação', $dto->category);
        $this->assertSame(['almoço', 'restaurante'], $dto->labels);
        // "hoje" → 2025-06-15 (Data normalizada).
        $this->assertSame('2025-06-15', $dto->date);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-002 — Receita via texto livre
    |--------------------------------------------------------------------------
    */

    public function test_ct002_classifies_income_salary(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Salário da empresa',
            'amount' => 5000.00,
            'type' => 'income',
            'category' => 'Salário',
            'date' => 'hoje',
        ]));

        $dto = $service->extract('Recebi R$ 5.000,00 de salário da empresa hoje');

        $this->assertSame('income', $dto->type);
        $this->assertSame('Salário', $dto->category);
        $this->assertSame(5000.00, $dto->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-003 — Texto sem valor numérico (amount = null, NÃO lança)
    |--------------------------------------------------------------------------
    */

    public function test_ct003_missing_amount_returns_null_in_dto_without_throwing(): void
    {
        // O DeepSeek não conseguiu extrair valor → amount null no JSON.
        // Escopo M3: o serviço devolve o DTO com amount=null e NÃO lança.
        // Detectar a falta e pedir o valor ao usuário é responsabilidade de M5.
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Almoço no restaurante',
            'amount' => null,
            'type' => 'expense',
            'category' => 'Alimentação',
        ]));

        $dto = $service->extract('Paguei o almoço no restaurante');

        $this->assertSame('Almoço no restaurante', $dto->description);
        $this->assertNull($dto->amount);
        $this->assertSame('expense', $dto->type);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-004 — Valor presente mas tipo ambíguo (type = null, NÃO lança)
    |--------------------------------------------------------------------------
    */

    public function test_ct004_ambiguous_type_returns_null_in_dto_without_throwing(): void
    {
        // DeepSeek devolve type=null pois "freelance" é ambíguo. O TypeClassifier
        // recebe o texto original como hint, mas nenhuma palavra-chave casa
        // (freelance não é keyword) → permanece null. Perguntar "despesa ou
        // receita?" é fluxo conversacional de M5.
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Freelance',
            'amount' => 200.00,
            'type' => null,
            'category' => 'Freelance',
        ]));

        $dto = $service->extract('R$ 200,00 do freelance que fiz');

        $this->assertSame('Freelance', $dto->description);
        $this->assertSame(200.00, $dto->amount);
        $this->assertNull($dto->type);
        $this->assertSame('Freelance', $dto->category);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-005 — Data no passado ("ontem")
    |--------------------------------------------------------------------------
    */

    public function test_ct005_resolves_ontem_to_previous_day(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Gasolina',
            'amount' => 30.00,
            'type' => 'expense',
            'category' => 'Transporte',
            'date' => 'ontem',
        ]));

        $dto = $service->extract('Gastei R$ 30,00 com gasolina ontem');

        // "ontem" → 2025-06-14.
        $this->assertSame('2025-06-14', $dto->date);
        $this->assertSame('Transporte', $dto->category);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-006 — Formato brasileiro de valor (R$ 1.234,56) pelo pipeline
    |--------------------------------------------------------------------------
    */

    public function test_ct006_parses_brazilian_thousands_through_amount_pipeline(): void
    {
        // Fixture devolve amount como STRING BR para exercitar o AmountParser
        // no interior do serviço (não apenas como número JSON).
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Conserto do carro',
            'amount' => '1.234,56',
            'type' => 'expense',
            'category' => 'Transporte',
        ]));

        $dto = $service->extract('Paguei R$ 1.234,56 no conserto do carro');

        $this->assertSame(1234.56, $dto->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-037 — DeepSeek indisponível (fallback manual)
    |--------------------------------------------------------------------------
    */

    public function test_ct037_api_failure_is_wrapped_as_extraction_exception_api_error(): void
    {
        // O FakeChatCompleter lança um erro bruto (timeout/5xx simulado).
        // O serviço deve encapsulá-lo em ExtractionException(API_ERROR),
        // preservando a exceção original em ->getPrevious().
        $original = new RuntimeException('Connection timed out');
        $service = $this->serviceThrowing($original);

        try {
            $service->extract('Paguei R$ 50,00 no almoço');
            $this->fail('Era esperado que extract() lançasse ExtractionException.');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::API_ERROR, $e->getReason());
            $this->assertSame($original, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Falhas estruturais
    |--------------------------------------------------------------------------
    */

    public function test_malformed_json_response_raises_invalid_json(): void
    {
        $service = $this->serviceReturning('isto não é JSON válido {{{');

        $this->expectException(ExtractionException::class);
        $this->expectExceptionMessageMatches('/JSON/');

        try {
            $service->extract('Paguei 50 reais no almoço');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_JSON, $e->getReason());
            throw $e;
        }
    }

    public function test_empty_json_response_raises_invalid_json(): void
    {
        $service = $this->serviceReturning('');

        $this->expectException(ExtractionException::class);

        try {
            $service->extract('Paguei 50 reais no almoço');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_JSON, $e->getReason());
            throw $e;
        }
    }

    public function test_empty_input_raises_empty_input(): void
    {
        $service = $this->serviceReturning('{}');

        $this->expectException(ExtractionException::class);

        try {
            $service->extract('   ');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::EMPTY_INPUT, $e->getReason());
            throw $e;
        }
    }

    public function test_missing_description_raises_missing_required_fields(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => null,
            'amount' => 50.00,
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extract('algum texto sem descrição extraível');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::MISSING_REQUIRED_FIELDS, $e->getReason());
            throw $e;
        }
    }

    public function test_zero_amount_raises_invalid_amount(): void
    {
        // amount: 0 passa pelo AmountParser (abs=0) e é rejeitado pela
        // validação (> 0). Negativos viram positivo via abs, então só o
        // zero dispara este caminho.
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Algo de valor zero',
            'amount' => 0,
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extract('gastei zero reais');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_AMOUNT, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Action ExtractFromText
    |--------------------------------------------------------------------------
    */

    public function test_extract_from_text_action_returns_dto_on_success(): void
    {
        $this->app->bind(ChatCompleter::class, fn () => new FakeChatCompleter(
            response: $this->fixture([
                'description' => 'Café da manhã',
                'amount' => 12.00,
                'type' => 'expense',
                'category' => 'Alimentação',
            ]),
        ));

        /** @var ExtractFromText $action */
        $action = $this->app->make(ExtractFromText::class);

        $dto = $action->handle('Paguei 12 reais no café da manhã');

        $this->assertInstanceOf(TransactionData::class, $dto);
        $this->assertSame(12.00, $dto->amount);
        $this->assertSame('expense', $dto->type);
    }

    public function test_extract_from_text_action_propagates_extraction_exception(): void
    {
        $this->app->bind(ChatCompleter::class, fn () => new FakeChatCompleter(
            throw: new RuntimeException('API offline'),
        ));

        /** @var ExtractFromText $action */
        $action = $this->app->make(ExtractFromText::class);

        $this->expectException(ExtractionException::class);

        try {
            $action->handle('qualquer texto');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::API_ERROR, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cabeçalho temporal no prompt do sistema (FIX-1)
    |--------------------------------------------------------------------------
    */

    /**
     * O prompt do sistema enviado ao DeepSeek deve conter um cabeçalho temporal
     * com a data de hoje (ISO e PT-BR), para que o LLM interprete "hoje",
     * "ontem" e "anteontem" sem alucinar datas.
     */
    public function test_system_prompt_contains_temporal_header_with_todays_date(): void
    {
        // setUp() ancora "hoje" em 2025-06-15.
        $fake = new FakeChatCompleter(response: $this->fixture([
            'description' => 'Café',
            'amount' => 5.00,
            'type' => 'expense',
        ]));
        $this->app->bind(ChatCompleter::class, fn () => $fake);

        $service = $this->app->make(DeepSeekService::class);
        $service->extract('Paguei 5 reais no café');

        $prompt = $fake->systemPrompt;
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('2025-06-15', $prompt);
        $this->assertStringContainsString('15/06/2025', $prompt);
        $this->assertStringContainsString('INFORMAÇÃO TEMPORAL', $prompt);
    }
}
