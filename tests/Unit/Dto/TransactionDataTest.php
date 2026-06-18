<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\TransactionData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do DTO TransactionData (M3.1).
 *
 * Cobre construção via fromArray (chaves snake_case do JSON), normalização
 * de labels, normalização/validação de type, truncamento de description com
 * "..." no limite de 500, e defaults quando apenas a descrição é informada.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter TransactionDataTest
 */
#[CoversClass(TransactionData::class)]
class TransactionDataTest extends TestCase
{
    public function test_from_array_maps_all_snake_case_fields(): void
    {
        $dto = TransactionData::fromArray([
            'description' => 'Almoço no restaurante italiano',
            'amount' => 47.50,
            'type' => 'expense',
            'category' => 'Alimentação',
            'labels' => ['almoço', 'restaurante'],
            'date' => '2025-06-15',
            'observations' => 'Pago no débito',
            'confidence' => 0.95,
        ]);

        $this->assertSame('Almoço no restaurante italiano', $dto->description);
        $this->assertSame(47.50, $dto->amount);
        $this->assertSame('expense', $dto->type);
        $this->assertSame('Alimentação', $dto->category);
        $this->assertSame(['almoço', 'restaurante'], $dto->labels);
        $this->assertSame('2025-06-15', $dto->date);
        $this->assertSame('Pago no débito', $dto->observations);
        $this->assertSame(0.95, $dto->confidence);
    }

    public function test_from_array_uses_defaults_when_only_description_given(): void
    {
        $dto = TransactionData::fromArray(['description' => 'Conta de luz']);

        $this->assertSame('Conta de luz', $dto->description);
        $this->assertNull($dto->amount);
        $this->assertNull($dto->type);
        $this->assertNull($dto->category);
        $this->assertSame([], $dto->labels);
        $this->assertNull($dto->date);
        $this->assertNull($dto->observations);
        $this->assertNull($dto->confidence);
    }

    public function test_from_array_truncates_long_description_with_ellipsis(): void
    {
        // 600 caracteres → deve virar 497 + "..." = 500.
        $long = str_repeat('a', 600);
        $dto = TransactionData::fromArray(['description' => $long]);

        $this->assertSame(500, mb_strlen($dto->description));
        $this->assertStringEndsWith('...', $dto->description);
        $this->assertSame(str_repeat('a', 497).'...', $dto->description);
    }

    public function test_from_array_keeps_description_under_limit_unchanged(): void
    {
        $exact = str_repeat('b', 500);
        $dto = TransactionData::fromArray(['description' => $exact]);

        $this->assertSame($exact, $dto->description);
        $this->assertSame(500, mb_strlen($dto->description));
    }

    public function test_with_description_returns_new_truncated_instance_immutably(): void
    {
        $original = TransactionData::fromArray([
            'description' => 'Original',
            'amount' => 10.0,
        ]);
        $updated = $original->withDescription(str_repeat('c', 600));

        // Imutabilidade: original intacto.
        $this->assertSame('Original', $original->description);
        $this->assertSame(10.0, $original->amount);

        // Nova instância com descrição truncada e demais campos preservados.
        $this->assertSame(500, mb_strlen($updated->description));
        $this->assertStringEndsWith('...', $updated->description);
        $this->assertSame(10.0, $updated->amount);
        $this->assertNotSame($original, $updated);
    }

    public function test_from_array_normalizes_labels_non_array_to_empty(): void
    {
        $dto = TransactionData::fromArray([
            'description' => 'Teste',
            'labels' => 'nao-e-array',
        ]);

        $this->assertSame([], $dto->labels);
    }

    public function test_from_array_filters_empty_labels_and_coerces_to_strings(): void
    {
        $dto = TransactionData::fromArray([
            'description' => 'Teste',
            'labels' => ['ok', '', '  ', 'tambem-ok'],
        ]);

        $this->assertSame(['ok', 'tambem-ok'], $dto->labels);
    }

    public function test_from_array_normalizes_type_case_insensitive_and_rejects_invalid(): void
    {
        $this->assertSame('expense', TransactionData::fromArray([
            'description' => 'x', 'type' => 'EXPENSE',
        ])->type);

        $this->assertSame('income', TransactionData::fromArray([
            'description' => 'x', 'type' => 'Income',
        ])->type);

        $this->assertNull(TransactionData::fromArray([
            'description' => 'x', 'type' => 'despesa',
        ])->type);
    }

    public function test_from_array_normalizes_empty_strings_to_null(): void
    {
        $dto = TransactionData::fromArray([
            'description' => 'Teste',
            'category' => '   ',
            'observations' => '',
        ]);

        $this->assertNull($dto->category);
        $this->assertNull($dto->observations);
    }

    public function test_from_array_accepts_numeric_string_amount(): void
    {
        $dto = TransactionData::fromArray([
            'description' => 'Teste',
            'amount' => '123.45',
        ]);

        $this->assertSame(123.45, $dto->amount);
    }
}
