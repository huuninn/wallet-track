<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ItemsSorter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do helper compartilhado de ordenação de items.
 *
 * Cobre a regra D-P4=c + PC1=a:
 *   - Items com subtotal: ordenados por subtotal ASC (crescente).
 *   - Empate: preserva ordem de entrada.
 *   - Items sem subtotal: ao final, em ordem de entrada.
 */
#[CoversClass(ItemsSorter::class)]
class ItemsSorterTest extends TestCase
{
    private ItemsSorter $sorter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sorter = new ItemsSorter;
    }

    private function item(string $name, ?float $subtotal, ?float $qty = null, ?float $unitPrice = null): array
    {
        return [
            'name' => $name,
            'qty' => $qty,
            'unitPrice' => $unitPrice,
            'subtotal' => $subtotal,
        ];
    }

    public function test_empty_returns_empty(): void
    {
        $this->assertSame([], $this->sorter->sort([]));
    }

    public function test_all_with_subtotal_sorted_ascending(): void
    {
        $items = [
            $this->item('A', 50.0),
            $this->item('B', 10.0),
            $this->item('C', 30.0),
        ];

        $sorted = $this->sorter->sort($items);

        $this->assertSame(10.0, $sorted[0]['subtotal']);
        $this->assertSame('B', $sorted[0]['name']);
        $this->assertSame(30.0, $sorted[1]['subtotal']);
        $this->assertSame('C', $sorted[1]['name']);
        $this->assertSame(50.0, $sorted[2]['subtotal']);
        $this->assertSame('A', $sorted[2]['name']);
    }

    public function test_all_without_subtotal_preserves_input_order(): void
    {
        $items = [
            $this->item('X', null),
            $this->item('Y', null),
        ];

        $sorted = $this->sorter->sort($items);

        $this->assertSame('X', $sorted[0]['name']);
        $this->assertSame('Y', $sorted[1]['name']);
    }

    public function test_mixed_with_subtotal_first_then_without(): void
    {
        $items = [
            $this->item('X', null),       // sem subtotal
            $this->item('Y', 10.0),       // com subtotal
            $this->item('Z', null),       // sem subtotal
        ];

        $sorted = $this->sorter->sort($items);

        // Com subtotal primeiro.
        $this->assertSame('Y', $sorted[0]['name']);
        $this->assertSame(10.0, $sorted[0]['subtotal']);

        // Sem subtotal ao final, em ordem de entrada.
        $this->assertSame('X', $sorted[1]['name']);
        $this->assertNull($sorted[1]['subtotal']);
        $this->assertSame('Z', $sorted[2]['name']);
        $this->assertNull($sorted[2]['subtotal']);
    }

    public function test_tie_in_subtotal_preserves_input_order(): void
    {
        $items = [
            $this->item('A', 10.0),
            $this->item('B', 10.0),
        ];

        $sorted = $this->sorter->sort($items);

        $this->assertSame('A', $sorted[0]['name']);
        $this->assertSame('B', $sorted[1]['name']);
    }

    public function test_negative_subtotal_sorted_correctly(): void
    {
        // CT-106: negativos aceitos (cupons fiscais brasileiros).
        $items = [
            $this->item('Positivo', 10.0),
            $this->item('Negativo', -5.0),
            $this->item('Pequeno', 5.0),
        ];

        $sorted = $this->sorter->sort($items);

        // Ordem crescente: -5, 5, 10.
        $this->assertSame(-5.0, $sorted[0]['subtotal']);
        $this->assertSame('Negativo', $sorted[0]['name']);
        $this->assertSame(5.0, $sorted[1]['subtotal']);
        $this->assertSame('Pequeno', $sorted[1]['name']);
        $this->assertSame(10.0, $sorted[2]['subtotal']);
        $this->assertSame('Positivo', $sorted[2]['name']);
    }

    public function test_single_item_returns_same(): void
    {
        $items = [$this->item('Only', 42.0)];

        $sorted = $this->sorter->sort($items);

        $this->assertCount(1, $sorted);
        $this->assertSame('Only', $sorted[0]['name']);
    }

    public function test_subtotal_null_and_zero_are_distinct(): void
    {
        // 0.0 é um subtotal válido (não é null). Items com subtotal 0.0
        // devem ser tratados como "com subtotal".
        $items = [
            $this->item('Zero', 0.0),
            $this->item('Null', null),
            $this->item('Positive', 5.0),
        ];

        $sorted = $this->sorter->sort($items);

        // Com subtotal primeiro: 0.0, depois 5.0.
        $this->assertSame('Zero', $sorted[0]['name']);
        $this->assertSame(0.0, $sorted[0]['subtotal']);
        $this->assertSame('Positive', $sorted[1]['name']);
        $this->assertSame(5.0, $sorted[1]['subtotal']);

        // Sem subtotal ao final.
        $this->assertSame('Null', $sorted[2]['name']);
        $this->assertNull($sorted[2]['subtotal']);
    }
}
