<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionItem>
 */
class TransactionItemFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->optional()->randomFloat(3, 1, 10);
        $unitPrice = fake()->optional()->randomFloat(2, 0.5, 100);

        return [
            'position' => 0, // sobreescrito pelo saveTransaction ao criar em sequência
            'name' => fake()->words(2, true),
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'subtotal' => ($qty !== null && $unitPrice !== null)
                ? round($qty * $unitPrice, 2)
                : null,
        ];
    }
}
