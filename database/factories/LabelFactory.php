<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Label;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'folded_name' => TextNormalizer::fold($name),
            'name' => $name,
            'use_count' => fake()->numberBetween(0, 50),
            'last_used_at' => fake()->optional()->dateTimeThisMonth(),
        ];
    }
}
