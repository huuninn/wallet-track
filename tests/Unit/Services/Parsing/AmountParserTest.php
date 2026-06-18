<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parsing;

use App\Services\Parsing\AmountParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do AmountParser (M3.5).
 *
 * Cobre TODOS os formatos de CT-006/CT-006b, além de edge cases defensivos:
 * nulos, strings não-numéricas, valores negativos (abs), valores vindos
 * direto do JSON (int/float) e o caso de milhar de 7 dígitos.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter AmountParserTest
 */
#[CoversClass(AmountParser::class)]
class AmountParserTest extends TestCase
{
    private AmountParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AmountParser;
    }

    /**
     * Casos felizes (incluindo todos os formatos de CT-006/CT-006b).
     *
     * @param  float  $expected
     */
    #[DataProvider('validFormatsProvider')]
    public function test_parse_handles_brazilian_and_ambiguous_formats(mixed $input, $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public static function validFormatsProvider(): array
    {
        return [
            // CT-006: separador de milhar respeitado.
            'CT-006 R$ 1.234,56' => ['R$ 1.234,56', 1234.56],

            // CT-006b: variações de formato de valor.
            'CT-006b 45,90' => ['45,90', 45.90],
            'CT-006b 45.90 (ponto decimal)' => ['45.90', 45.90],
            'CT-006b 2000 (sem separador)' => ['2000', 2000.00],
            'CT-006b R$5,50 (colado)' => ['R$5,50', 5.50],

            // CT-001 / CT-002 / CT-005.
            'CT-001 R$ 47,50' => ['R$ 47,50', 47.50],
            'CT-002 R$ 5.000,00' => ['R$ 5.000,00', 5000.00],
            'CT-005 R$ 30,00' => ['R$ 30,00', 30.00],

            // Edge: milhar de 7 dígitos com vírgula decimal.
            'R$ 1.234.567,89' => ['R$ 1.234.567,89', 1234567.89],

            // Edge: ponto único com 3 casas → interpretado como milhar.
            '1.234 (3 casas, milhar)' => ['1.234', 1234.00],

            // Edge: extração a partir de texto corrido (defensivo).
            'texto "comprei por 99,90 reais"' => ['comprei por 99,90 reais', 99.90],

            // Valores numéricos vindos do JSON (caminho feliz típico).
            'int 2000' => [2000, 2000.00],
            'float 47.5' => [47.5, 47.5],
            'float 1234.56' => [1234.56, 1234.56],
            'string numerica "47.50"' => ['47.50', 47.50],

            // Edge: negativo → abs (valor é sempre positivo).
            '-R$ 5,50' => ['-R$ 5,50', 5.50],
            'int negativo -10' => [-10, 10.0],

            // Edge: zero (parser devolve 0.0; o serviço valida > 0).
            'R$ 0,00' => ['R$ 0,00', 0.0],
        ];
    }

    /**
     * Casos que devem devolver null (não parseável).
     *
     * @param  null  $expected
     */
    #[DataProvider('invalidFormatsProvider')]
    public function test_parse_returns_null_for_unparseable_inputs(mixed $input, $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: null}>
     */
    public static function invalidFormatsProvider(): array
    {
        return [
            'null' => [null, null],
            'string vazia' => ['', null],
            'somente espacos' => ['   ', null],
            'nao numerico "abc"' => ['abc', null],
            'boolean true (tipo invalido)' => [true, null],
            'array (tipo invalido)' => [['47,50'], null],
        ];
    }

    /**
     * Pontuação final de frase deve ser removida antes da normalização,
     * senão "45.90." seria interpretado como milhar (4590) — FIX-2.
     *
     * @param  float  $expected
     */
    #[DataProvider('trailingPunctuationProvider')]
    public function test_parse_strips_trailing_sentence_punctuation(mixed $input, $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public static function trailingPunctuationProvider(): array
    {
        return [
            'ponto final apos decimal' => ['Gastei 45.90.', 45.90],
            'ponto-e-virgula apos milhar' => ['Valor: 1.234,56;', 1234.56],
            'exclamacoes duplas' => ['R$ 47,50!!', 47.50],
            'interrogacao final' => ['Pagou 12,00?', 12.00],
            'dois-pontos apos valor' => ['Total: 99,90:', 99.90],
        ];
    }
}
