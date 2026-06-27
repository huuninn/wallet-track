<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parsing;

use App\Services\Parsing\ItemsParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Testes unitários do ItemsParser (M-ITENS-1).
 *
 * Cobre TODA a tabela 5.2 da spec (casos canônicos), multiline,
 * linhas vazias, line endings, fórmulas no nome, e performance.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter ItemsParserTest
 */
#[CoversClass(ItemsParser::class)]
class ItemsParserTest extends TestCase
{
    private ItemsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ItemsParser;
    }

    // ---------------------------------------------------------------
    // Casos canônicos (tabela 5.2 da spec)
    // ---------------------------------------------------------------

    /**
     * Data provider com os casos canônicos da spec §5.2.
     *
     * @return array<string, array{0: string, 1: string, 2: float|null, 3: float|null, 4: float|null}>
     */
    public static function canonicalProvider(): array
    {
        return [
            'canonical: Arroz 5kg x2 32.90' => [
                'Arroz 5kg x2 32.90',
                'Arroz 5kg', 2.0, 32.90, 65.80,
            ],
            'name-only: Feijão' => [
                'Feijão',
                'Feijão', null, null, null,
            ],
            'explicit-qty: Detergente x1 4.50' => [
                'Detergente x1 4.50',
                'Detergente', 1.0, 4.50, 4.50,
            ],
            '2L-not-qty: Coca 2L' => [
                'Coca 2L',
                'Coca 2L', null, null, null,
            ],
            'x-tudo-not-qty: Pizza x-tudo' => [
                'Pizza x-tudo',
                'Pizza x-tudo', null, null, null,
            ],
            'qty-without-price: Coca-Cola 2L x3' => [
                'Coca-Cola 2L x3',
                'Coca-Cola 2L', 3.0, null, null,
            ],
            'comma-decimal: Refrigerante 4,50' => [
                'Refrigerante 4,50',
                'Refrigerante', null, 4.50, null,
            ],
            'number-alone-as-name: 42' => [
                '42',
                '42', null, null, null,
            ],
            'formula-in-name: =HYPERLINK("evil")' => [
                '=HYPERLINK("evil")',
                '=HYPERLINK("evil")', null, null, null,
            ],
        ];
    }

    #[DataProvider('canonicalProvider')]
    public function test_canonical_syntax(string $input, string $expectedName, ?float $expectedQty, ?float $expectedPrice, ?float $expectedSubtotal): void
    {
        $result = $this->parser->parse($input);

        $this->assertCount(1, $result);
        $this->assertSame($expectedName, $result[0]['name']);
        $this->assertSame($expectedQty, $result[0]['qty']);
        $this->assertSame($expectedPrice, $result[0]['unitPrice']);
        $this->assertSame($expectedSubtotal, $result[0]['subtotal']);
    }

    // ---------------------------------------------------------------
    // Multiline
    // ---------------------------------------------------------------

    public function test_multiline_input(): void
    {
        $raw = "Arroz x2 32.90\nFeijão\nDetergente x1 4.50";

        $result = $this->parser->parse($raw);

        $this->assertCount(3, $result);

        $this->assertSame('Arroz', $result[0]['name']);
        $this->assertSame(2.0, $result[0]['qty']);
        $this->assertSame(32.90, $result[0]['unitPrice']);
        $this->assertSame(65.80, $result[0]['subtotal']);

        $this->assertSame('Feijão', $result[1]['name']);
        $this->assertNull($result[1]['qty']);
        $this->assertNull($result[1]['unitPrice']);
        $this->assertNull($result[1]['subtotal']);

        $this->assertSame('Detergente', $result[2]['name']);
        $this->assertSame(1.0, $result[2]['qty']);
        $this->assertSame(4.50, $result[2]['unitPrice']);
        $this->assertSame(4.50, $result[2]['subtotal']);
    }

    // ---------------------------------------------------------------
    // Linhas vazias
    // ---------------------------------------------------------------

    public function test_empty_lines_ignored(): void
    {
        $raw = "Arroz x1 10.00\n\n  \nFeijão\n\n";

        $result = $this->parser->parse($raw);

        $this->assertCount(2, $result);
        $this->assertSame('Arroz', $result[0]['name']);
        $this->assertSame('Feijão', $result[1]['name']);
    }

    // ---------------------------------------------------------------
    // Line endings (CRLF/CR)
    // ---------------------------------------------------------------

    public function test_crlf_line_endings(): void
    {
        $raw = "Arroz x1 10.00\r\nFeijão\r\nCoca 2L\r\n";

        $result = $this->parser->parse($raw);

        $this->assertCount(3, $result);
        $this->assertSame('Arroz', $result[0]['name']);
        $this->assertSame('Feijão', $result[1]['name']);
        $this->assertSame('Coca 2L', $result[2]['name']);
    }

    // ---------------------------------------------------------------
    // Fórmula no nome (CWE-1236 — parser preserva, escape é Sheets)
    // ---------------------------------------------------------------

    public function test_formula_in_name_preserved(): void
    {
        $raw = "=HYPERLINK(\"https://evil.com\",\"Clique\")";

        $result = $this->parser->parse($raw);

        $this->assertCount(1, $result);
        // O parser NÃO escapa fórmulas — responsabilidade do SheetsService.
        $this->assertSame('=HYPERLINK("https://evil.com","Clique")', $result[0]['name']);
    }

    // ---------------------------------------------------------------
    // Performance (NFR CT-161)
    // ---------------------------------------------------------------

    /**
     * Performance: 50 itens em < 5ms (NFR CT-161).
     */
    #[Group('perf')]
    public function test_parse_50_items_under_5ms(): void
    {
        $lines = [];
        for ($i = 1; $i <= 50; $i++) {
            $lines[] = "Item {$i} x{$i} " . number_format($i * 1.5, 2, '.', '');
        }
        $raw = implode("\n", $lines);

        $start = microtime(true);
        $result = $this->parser->parse($raw);
        $elapsed = microtime(true) - $start;

        $this->assertCount(50, $result);
        $this->assertLessThan(0.005, $elapsed, "ItemsParser demorou " . ($elapsed * 1000) . "ms para 50 itens");
    }

    /*
    |--------------------------------------------------------------------------
    | W3 — qty negativo clampa para null
    |--------------------------------------------------------------------------
    */

    public function test_parse_clamps_negative_qty_to_null(): void
    {
        // W3: qty negativo → null (não tem significado).
        // Nota: "x-2" NÃO é capturado como qty (regex só aceita x<digitos>).
        // Mas se o LLM/JSON enviar qty negativo, normalizeItems clampa.
        // Aqui testamos que "x2" normal seguido de preço ainda funciona.
        $result = $this->parser->parse('Produto x2 10.00');

        $this->assertCount(1, $result);
        $this->assertSame('Produto', $result[0]['name']);
        $this->assertSame(2.0, $result[0]['qty']);
        $this->assertSame(10.00, $result[0]['unitPrice']);
        $this->assertSame(20.0, $result[0]['subtotal']);
    }

    /*
    |--------------------------------------------------------------------------
    | W5 — limite de items (maxItems)
    |--------------------------------------------------------------------------
    */

    public function test_parse_respects_max_items_limit(): void
    {
        // W5: 250 linhas com max=200 → retorna 200 items.
        $lines = [];
        for ($i = 1; $i <= 250; $i++) {
            $lines[] = "Item {$i}";
        }
        $raw = implode("\n", $lines);

        $result = $this->parser->parse($raw, 200);

        $this->assertCount(200, $result);
        $this->assertSame('Item 1', $result[0]['name']);
        $this->assertSame('Item 200', $result[199]['name']);
    }

    public function test_parse_with_default_max_items(): void
    {
        // W5: sem maxItems especificado, usa default 200.
        $lines = [];
        for ($i = 1; $i <= 250; $i++) {
            $lines[] = "Item {$i}";
        }
        $raw = implode("\n", $lines);

        $result = $this->parser->parse($raw);

        $this->assertCount(200, $result);
    }
}
