<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => mb_strtolower($name),
            'display_name' => ucfirst($name),
            'default_type' => fake()->randomElement(['expense', 'income']),
            'use_count' => 0,
            'is_default' => false,
        ];
    }
}
