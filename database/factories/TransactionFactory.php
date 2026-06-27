<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            "chat_id" => (string) fake()->numberBetween(100000000, 999999999),
            "date" => fake()->date(),
            "description" => fake()->sentence(4),
            "amount" => fake()->randomFloat(2, 1, 1000),
            "type" => fake()->randomElement(["expense", "income"]),
            "category" => fake()->optional()->word(),
            "observations" => fake()->optional()->sentence(),
            "sync_status" => "pending",
            "sync_attempts" => 0,
            "processing" => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ["sync_status" => "pending"]);
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            "sync_status" => "synced",
            "spreadsheet_row_id" => (string) fake()->randomNumber(5),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            "sync_status" => "failed",
            "sync_error_message" => "Sheets API error",
            "sync_attempts" => 3,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            "processing" => true,
            "processing_since" => now(),
        ]);
    }

    /**
     * Cria a transação com N items associados.
     */
    public function withItems(int $count = 3): static
    {
        return $this->has(
            TransactionItem::factory()
                ->count($count)
                ->sequence(fn ($sequence) => ["position" => $sequence->index]),
            "items"
        );
    }
}
