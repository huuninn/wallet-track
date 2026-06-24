<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\ExtractionException;
use App\Services\Gemini\GeminiService;
use App\Services\Gemini\ImageCompleter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Testes de feature da camada de extração Gemini (imagem → JSON) — M4.
 *
 * Mapeia os casos de teste manuais CT-007 (nota legível), CT-008 (borrada),
 * CT-009 (não-transação / cachorro), CT-010 (múltiplos valores → total), mais
 * falhas estruturais (JSON malformado, input vazio, mimeType inválido) e o
 * cabeçalho temporal no prompt.
 *
 * **Nenhuma chamada real à API é feita.** Bindamos um FakeImageCompleter no
 * container que devolve JSON de fixture pré-gravado (ou lança exceção para
 * simular indisponibilidade). Isto mantém os testes determinísticos e
 * viáveis em CI sem acesso à internet.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter ImageExtractionTest
 */
#[CoversClass(GeminiService::class)]
class ImageExtractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ancora "hoje" para asserções determinísticas de data.
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
     * Binda um FakeImageCompleter que devolve a string $response e devolve
     * uma GeminiService pronta para uso.
     */
    private function serviceReturning(string $response, ?FakeImageCompleter &$capturedFake = null): GeminiService
    {
        $fake = new FakeImageCompleter(response: $response);
        $capturedFake = $fake;
        $this->app->bind(ImageCompleter::class, fn () => $fake);

        return $this->app->make(GeminiService::class);
    }

    /**
     * Binda um FakeImageCompleter que lança uma exceção ao ser chamado.
     */
    private function serviceThrowing(Throwable $e): GeminiService
    {
        $this->app->bind(ImageCompleter::class, fn () => new FakeImageCompleter(throw: $e));

        return $this->app->make(GeminiService::class);
    }

    /**
     * Monta uma fixture JSON de saída do Gemini (campos snake_case).
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
    | CT-007 — Nota fiscal legível → extração completa
    |--------------------------------------------------------------------------
    */

    public function test_ct007_extracts_complete_transaction_from_readable_receipt(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Supermercado XYZ',
            'amount' => 27.10,
            'type' => 'expense',
            'category' => 'Mercado',
            'labels' => ['supermercado', 'alimentação'],
            'date' => 'hoje',
            'observations' => 'CNPJ: 12.345.678/0001-90 — Cartão de crédito',
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');

        $this->assertSame('Supermercado XYZ', $dto->description);
        $this->assertSame(27.10, $dto->amount);
        $this->assertSame('expense', $dto->type);
        $this->assertSame('Mercado', $dto->category);
        $this->assertSame(['Supermercado', 'Alimentação'], $dto->labels);
        // "hoje" → 2025-06-15 (data normalizada).
        $this->assertSame('2025-06-15', $dto->date);
        $this->assertStringContainsString('CNPJ', (string) $dto->observations);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-010 — Nota com múltiplos valores → deve pegar o TOTAL
    |--------------------------------------------------------------------------
    */

    public function test_ct010_preserves_total_amount_when_multiple_values_present(): void
    {
        // O Gemini (via prompt) é instruído a retornar o TOTAL. Aqui validamos
        // que o service PRESERVA o amount retornado pelo completer — ou seja,
        // não substitui por um valor parcial. A fixture já traz o total (27.10).
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Supermercado XYZ',
            'amount' => 27.10, // Total da nota, não parcial.
            'type' => 'expense',
            'category' => 'Mercado',
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/png');

        $this->assertSame(27.10, $dto->amount);
        $this->assertNotSame(5.50, $dto->amount);
        $this->assertNotSame(12.90, $dto->amount);
        $this->assertNotSame(8.70, $dto->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-008 — Foto borrada/ilegível → NOT_A_TRANSACTION
    |--------------------------------------------------------------------------
    */

    public function test_ct008_blurred_image_with_null_critical_fields_raises_not_a_transaction(): void
    {
        // Imagem totalmente borrada → Gemini não consegue extrair nada →
        // description=null, amount=null → NOT_A_TRANSACTION.
        $service = $this->serviceReturning($this->fixture([
            'description' => null,
            'amount' => null,
            'type' => null,
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::NOT_A_TRANSACTION, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CT-009 — Imagem que não é nota fiscal (cachorro) → NOT_A_TRANSACTION
    |--------------------------------------------------------------------------
    */

    public function test_ct009_non_receipt_image_raises_not_a_transaction(): void
    {
        // Foto de cachorro → Gemini identifica que não há transação →
        // description=null, amount=null → NOT_A_TRANSACTION.
        $service = $this->serviceReturning($this->fixture([
            'description' => null,
            'amount' => null,
            'type' => null,
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'image/webp');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::NOT_A_TRANSACTION, $e->getReason());
            throw $e;
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
            $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');
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
            $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_JSON, $e->getReason());
            throw $e;
        }
    }

    public function test_empty_base64_input_raises_empty_input(): void
    {
        $service = $this->serviceReturning($this->fixture([]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('   ', 'image/jpeg');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::EMPTY_INPUT, $e->getReason());
            throw $e;
        }
    }

    public function test_invalid_mime_type_raises_invalid_input(): void
    {
        $service = $this->serviceReturning($this->fixture([]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'image/gif');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_INPUT, $e->getReason());
            throw $e;
        }
    }

    public function test_unsupported_mime_type_raises_invalid_input(): void
    {
        $service = $this->serviceReturning($this->fixture([]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'application/pdf');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_INPUT, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validações internas (espelho do M3)
    |--------------------------------------------------------------------------
    */

    public function test_zero_amount_raises_invalid_amount(): void
    {
        // amount: 0 passa pelo AmountParser (abs=0) e é rejeitado.
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Algo de valor zero',
            'amount' => 0,
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::INVALID_AMOUNT, $e->getReason());
            throw $e;
        }
    }

    public function test_description_present_but_amount_null_is_accepted(): void
    {
        // Como no M3: amount null com description presente é ACEITO (campo
        // pedível → M5). NÃO é NOT_A_TRANSACTION (que exige ambos null).
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Mercado sem valor legível',
            'amount' => null,
            'type' => 'expense',
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');

        $this->assertSame('Mercado sem valor legível', $dto->description);
        $this->assertNull($dto->amount);
    }

    /**
     * Fronteira MISSING_REQUIRED_FIELDS vs NOT_A_TRANSACTION (M4).
     *
     * Quando amount é extraído mas description é null, NÃO é NOT_A_TRANSACTION
     * (que exige AMBOS null) — deve cair em MISSING_REQUIRED_FIELDS, pois a
     * descrição é campo obrigatório e há indícios de que a imagem É uma
     * transação (há valor monetário legível). Isto valida a distinção
     * entre "imagem sem transação clara" e "transação com campo pedível".
     */
    public function test_amount_present_but_description_null_raises_missing_required_fields(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => null,
            'amount' => 50.00,
            'type' => 'expense',
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::MISSING_REQUIRED_FIELDS, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Falha de API (espelho do CT-037 do M3)
    |--------------------------------------------------------------------------
    */

    public function test_api_failure_is_wrapped_as_extraction_exception_api_error(): void
    {
        $original = new RuntimeException('Connection timed out');
        $service = $this->serviceThrowing($original);

        try {
            $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');
            $this->fail('Era esperado que extractFromImage() lançasse ExtractionException.');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::API_ERROR, $e->getReason());
            $this->assertSame($original, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cabeçalho temporal no prompt do sistema (espelho do FIX-1 do M3)
    |--------------------------------------------------------------------------
    */

    /**
     * O prompt do sistema enviado ao Gemini deve conter um cabeçalho temporal
     * com a data de hoje (ISO e PT-BR), para que o LLM interprete datas
     * impressas na nota e use "hoje" como default. Espelho do fix CRITICAL
     * do M3 — NÃO repetir o bug.
     */
    public function test_system_prompt_contains_temporal_header_with_todays_date(): void
    {
        // setUp() ancora "hoje" em 2025-06-15.
        $fake = new FakeImageCompleter(response: $this->fixture([
            'description' => 'Teste',
            'amount' => 10.00,
            'type' => 'expense',
        ]));
        $this->app->bind(ImageCompleter::class, fn () => $fake);

        $service = $this->app->make(GeminiService::class);
        $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');

        $prompt = $fake->systemPrompt;
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('2025-06-15', $prompt);
        $this->assertStringContainsString('15/06/2025', $prompt);
        $this->assertStringContainsString('INFORMAÇÃO TEMPORAL', $prompt);
    }

    /*
    |--------------------------------------------------------------------------
    | Suporte a diferentes MIME types
    |--------------------------------------------------------------------------
    */

    public function test_accepts_png_mime_type(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Nota PNG',
            'amount' => 50.00,
            'type' => 'expense',
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/png');

        $this->assertSame('Nota PNG', $dto->description);
        $this->assertSame(50.00, $dto->amount);
    }

    public function test_accepts_webp_mime_type(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Nota WebP',
            'amount' => 15.00,
            'type' => 'expense',
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/webp');

        $this->assertSame('Nota WebP', $dto->description);
    }

    /*
    |--------------------------------------------------------------------------
    | M1 — Catálogo de labels no prompt (espelho dos testes do DeepSeek)
    |--------------------------------------------------------------------------
    */

    /**
     * O catálogo de labels do usuário deve ser injetado no systemPrompt do
     * Gemini, para que o LLM multimodal prefira labels já usadas pelo usuário.
     */
    public function test_label_catalog_is_injected_into_system_prompt(): void
    {
        $fake = new FakeImageCompleter(response: $this->fixture([
            'description' => 'Mercado',
            'amount' => 50.00,
            'type' => 'expense',
        ]));
        $this->app->bind(ImageCompleter::class, fn () => $fake);

        $service = $this->app->make(GeminiService::class);
        $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg', labelCatalog: ['Mercado', 'Supermercado']);

        $prompt = $fake->systemPrompt;
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('Mercado, Supermercado', $prompt);
        $this->assertStringNotContainsString('ainda não tem labels anteriores', $prompt);
    }

    /**
     * Catálogo vazio → o prompt informa que o usuário ainda não tem labels.
     */
    public function test_empty_label_catalog_shows_informative_message(): void
    {
        $fake = new FakeImageCompleter(response: $this->fixture([
            'description' => 'Mercado',
            'amount' => 50.00,
            'type' => 'expense',
        ]));
        $this->app->bind(ImageCompleter::class, fn () => $fake);

        $service = $this->app->make(GeminiService::class);
        $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg', labelCatalog: []);

        $prompt = $fake->systemPrompt;
        $this->assertStringContainsString('ainda não tem labels anteriores', $prompt);
    }

    /**
     * Labels retornadas pelo Gemini devem ser capitalizadas via LabelFormatter
     * como defesa em profundidade.
     */
    public function test_labels_are_capitalized_via_label_formatter(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Posto Shell',
            'amount' => 150.00,
            'type' => 'expense',
            'labels' => ['gasolina', 'combustivel'],
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');

        // "gasolina" → "Gasolina" (P1), "combustivel" → "Combustivel" (P1).
        $this->assertSame(['Gasolina', 'Combustivel'], $dto->labels);
    }

    /**
     * Marcas com capitalização mista são preservadas pelo LabelFormatter (P7).
     */
    public function test_label_formatter_preserves_mixed_case_brands(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Almoço no iFood',
            'amount' => 32.00,
            'type' => 'expense',
            'labels' => ['iFood', 'PIX'],
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');

        $this->assertSame(['iFood', 'PIX'], $dto->labels);
    }

    /**
     * O Gemini pode devolver labels visualmente diferentes mas com mesmo
     * fold. A deduplicação fold-insensitive deve manter apenas a primeira.
     */
    public function test_labels_are_deduplicated_by_fold(): void
    {
        $service = $this->serviceReturning($this->fixture([
            'description' => 'Supermercado Extra',
            'amount' => 250.00,
            'type' => 'expense',
            'labels' => ['Supermercado', 'supermercado', 'Compras', 'compras'],
        ]));

        $dto = $service->extractFromImage('iVBORw0KGgo=', 'image/jpeg');

        // Após LabelFormatter: "Supermercado", "Supermercado", "Compras", "Compras"
        // Após dedup por fold: "Supermercado" e "Compras" (primeiros de cada grupo).
        $this->assertSame(['Supermercado', 'Compras'], $dto->labels);
    }
}
