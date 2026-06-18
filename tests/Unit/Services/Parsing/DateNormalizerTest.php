<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parsing;

use App\Services\Parsing\DateNormalizer;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do DateNormalizer (M3.6).
 *
 * Ancora "agora" via Carbon::setTestNow para resultados determinísticos.
 * Cobre expressões relativas, formatos absolutos (ISO, DD/MM/YYYY, DD-MM-YYYY),
 * default "hoje" para vazio/nulo, rejeição de data futura (→ hoje + warning)
 * e calendário inválido (2025-02-30).
 *
 * Roda isoladamente: vendor/bin/phpunit --filter DateNormalizerTest
 */
#[CoversClass(DateNormalizer::class)]
class DateNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 15 de junho de 2025 (domingo) como "agora" fixo.
        Carbon::setTestNow('2025-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Casos de normalização determinística.
     *
     * @param  string  $expected  Data ISO YYYY-MM-DD esperada.
     */
    #[DataProvider('normalizationProvider')]
    public function test_normalize_resolves_dates_correctly(mixed $input, string $expected): void
    {
        $normalizer = new DateNormalizer;

        $this->assertSame($expected, $normalizer->normalize($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function normalizationProvider(): array
    {
        return [
            // Defaults para "hoje".
            'null vira hoje' => [null, '2025-06-15'],
            'string vazia vira hoje' => ['', '2025-06-15'],
            'somente espacos vira hoje' => ['   ', '2025-06-15'],

            // Expressões relativas.
            'hoje' => ['hoje', '2025-06-15'],
            'HOJE (case-insensitive)' => ['HOJE', '2025-06-15'],
            'ontem' => ['ontem', '2025-06-14'],
            'anteontem' => ['anteontem', '2025-06-13'],

            // ISO YYYY-MM-DD.
            'ISO passado' => ['2025-06-10', '2025-06-10'],

            // DD/MM/YYYY e DD-MM-YYYY.
            'DD/MM/YYYY' => ['10/06/2025', '2025-06-10'],
            'DD-MM-YYYY' => ['10-06-2025', '2025-06-10'],

            // Formato irreconhecível → fallback defensivo para hoje.
            'texto irreconhecivel vira hoje' => ['nao-eh-data', '2025-06-15'],
            'calendario invalido 2025-02-30 vira hoje' => ['2025-02-30', '2025-06-15'],
        ];
    }

    /**
     * Data futura é normalizada para HOJE (M3 — confirmação conversacional é M5).
     */
    public function test_normalize_converts_future_date_to_today(): void
    {
        $normalizer = new DateNormalizer;

        $this->assertSame('2025-06-15', $normalizer->normalize('2030-01-01'));
    }

    /**
     * Quando um callback de warning é injetado, ele é disparado para data futura.
     * Datas passadas válidas NÃO disparam warning.
     */
    public function test_warning_callback_is_invoked_for_future_dates(): void
    {
        $warnings = [];
        $normalizer = new DateNormalizer(
            onWarning: static function (string $message, array $context = []) use (&$warnings): void {
                $warnings[] = ['message' => $message, 'context' => $context];
            },
        );

        // Data passada válida: nenhum warning.
        $normalizer->normalize('2025-06-10');
        $this->assertSame([], $warnings);

        // Data futura: exatamente um warning, com contexto documentando o ajuste.
        $normalizer->normalize('2030-01-01');
        $this->assertCount(1, $warnings);
        $this->assertSame('2030-01-01', $warnings[0]['context']['original']);
        $this->assertStringContainsString('futura', $warnings[0]['message']);
    }

    /**
     * Formato irreconhecível (fallback para hoje) também emite warning quando
     * o callback está injetado — FIX-7. Isto evita normalização silenciosa e
     * permite rastrear entradas inesperadas do LLM.
     */
    public function test_warning_callback_is_invoked_for_unrecognized_format_fallback(): void
    {
        $warnings = [];
        $normalizer = new DateNormalizer(
            onWarning: static function (string $message, array $context = []) use (&$warnings): void {
                $warnings[] = ['message' => $message, 'context' => $context];
            },
        );

        // Formato irreconhecível → fallback hoje + warning.
        $this->assertSame('2025-06-15', $normalizer->normalize('nao-eh-data'));
        $this->assertCount(1, $warnings);
        $this->assertSame('nao-eh-data', $warnings[0]['context']['original']);
        $this->assertStringContainsString('irreconhec', $warnings[0]['message']);
    }
}
