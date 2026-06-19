<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes do normalizador textual (M8).
 *
 * Garante que o invariante central do M8 é preservado: a "identidade"
 * usada pela deduplicação/fuzzy match (fold) produz a mesma string para
 * variantes ortográficas e de capitalização da mesma palavra.
 *
 * Roda isolado: bin/dev test --filter TextNormalizerTest
 */
#[CoversClass(TextNormalizer::class)]
class TextNormalizerTest extends TestCase
{
    public function test_fold_lowercases(): void
    {
        $this->assertSame('foo', TextNormalizer::fold('FOO'));
        $this->assertSame('bar', TextNormalizer::fold('Bar'));
        $this->assertSame('baz qux', TextNormalizer::fold('BAZ QUX'));
    }

    public function test_fold_removes_accents(): void
    {
        $this->assertSame('sao paulo', TextNormalizer::fold('São Paulo'));
        $this->assertSame('cafe', TextNormalizer::fold('Café'));
        $this->assertSame('acao', TextNormalizer::fold('Ação'));
        $this->assertSame('coracao', TextNormalizer::fold('Coração'));
        $this->assertSame('nino', TextNormalizer::fold('Niño'));
    }

    public function test_fold_combined_lowercases_and_removes_accents(): void
    {
        $this->assertSame('acai & cafe', TextNormalizer::fold('Açaí & Café'));
        $this->assertSame('almoco no ifood', TextNormalizer::fold('ALMOÇO NO IFOOD'));
        $this->assertSame('energia eletrica', TextNormalizer::fold('Energia Elétrica'));
    }

    public function test_fold_handles_empty_string(): void
    {
        $this->assertSame('', TextNormalizer::fold(''));
    }

    public function test_fold_handles_null(): void
    {
        $this->assertSame('', TextNormalizer::fold(null));
    }

    public function test_fold_handles_already_normalized_text(): void
    {
        // Idempotente: fold(fold(x)) === fold(x).
        $input = 'cafe com leite';
        $this->assertSame($input, TextNormalizer::fold($input));
        $this->assertSame($input, TextNormalizer::fold(TextNormalizer::fold($input)));
    }

    public function test_fold_trims_whitespace(): void
    {
        $this->assertSame('abc', TextNormalizer::fold('   abc   '));
        $this->assertSame('cafe', TextNormalizer::fold("\tCafé\n"));
    }

    public function test_fold_preserves_digits(): void
    {
        $this->assertSame('r$ 47,50', TextNormalizer::fold('R$ 47,50'));
        $this->assertSame('123 abc', TextNormalizer::fold('123 ABC'));
    }

    public function test_fold_preserves_unicode_punctuation(): void
    {
        // Símbolos não-acentos (emoji, símbolos) passam intactos após o fold.
        $this->assertSame('pizza 🍕', TextNormalizer::fold('PIZZA 🍕'));
    }

    public function test_fold_is_idempotent_under_repeated_application(): void
    {
        $inputs = [
            'São Paulo',
            'Açaí & Café',
            'PAGUEI 50 REAIS',
            'Coração Açúcar',
            '',
        ];

        foreach ($inputs as $input) {
            $once = TextNormalizer::fold($input);
            $twice = TextNormalizer::fold($once);
            $this->assertSame($once, $twice, "fold deve ser idempotente para: {$input}");
        }
    }
}
