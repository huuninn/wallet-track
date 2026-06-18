<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parsing;

use App\Services\Parsing\TypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do TypeClassifier (M3.7).
 *
 * Cobre: validação de valor já classificado (expense/income), heurística de
 * palavras-chave quando o LLM devolve null, ambiguidade real (CT-004:
 * "freelance" não é classificado) e ausência total de pistas.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter TypeClassifierTest
 */
#[CoversClass(TypeClassifier::class)]
class TypeClassifierTest extends TestCase
{
    private TypeClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new TypeClassifier;
    }

    /**
     * Valores explícitos do LLM são apenas normalizados (case-insensitive).
     *
     * @param  string|null  $expected
     */
    #[DataProvider('explicitValueProvider')]
    public function test_classify_normalizes_explicit_value(mixed $value, $expected): void
    {
        $this->assertSame($expected, $this->classifier->classify($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function explicitValueProvider(): array
    {
        return [
            'expense' => ['expense', 'expense'],
            'EXPENSE maiusculo' => ['EXPENSE', 'expense'],
            'Expense misto' => ['Expense', 'expense'],
            'income' => ['income', 'income'],
            'valor invalido vira null' => ['despesa', null],
            'string vazia vira null' => ['', null],
        ];
    }

    /**
     * Quando value é null, aplica heurística sobre o texto original.
     *
     * @param  string|null  $expected
     */
    #[DataProvider('hintHeuristicProvider')]
    public function test_classify_infers_from_hint_text(string $hint, $expected): void
    {
        $this->assertSame($expected, $this->classifier->classify(null, $hint));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function hintHeuristicProvider(): array
    {
        return [
            // CT-001: "Paguei" → expense.
            'CT-001 paguei' => ['Paguei R$ 47,50 no almoço de hoje no restaurante italiano', 'expense'],
            'gastei' => ['Gastei R$ 30,00 com gasolina ontem', 'expense'],
            'comprei' => ['Comprei um caderno de 20 reais', 'expense'],
            'aluguel' => ['Paguei 2000 de aluguel', 'expense'],

            // CT-002: "Recebi" + "salário" → income.
            'CT-002 recebi' => ['Recebi R$ 5.000,00 de salário da empresa hoje', 'income'],
            'ganhei' => ['Ganhei 1500 vendendo doces', 'income'],
            'salario' => ['depositaram meu salario', 'income'],

            // CT-004: "freelance" NÃO é palavra-chave → ambíguo (null).
            'CT-004 freelance ambiguo' => ['R$ 200,00 do freelance que fiz', null],

            // Sem nenhuma palavra-chave → null.
            'sem pista' => ['200,00 na conta', null],
        ];
    }

    /**
     * Sem hint de texto, não há como desambiguar mesmo que value seja null.
     */
    public function test_classify_returns_null_without_hint(): void
    {
        $this->assertNull($this->classifier->classify(null));
        $this->assertNull($this->classifier->classify(null, ''));
    }

    /**
     * Quando o texto contém tanto pista de expense quanto de income,
     * permanece ambíguo (null) — defesa contra texto confuso.
     */
    public function test_classify_returns_null_when_both_signals_present(): void
    {
        $this->assertNull($this->classifier->classify(null, 'Paguei e recebi valores na mesma operação'));
    }
}
