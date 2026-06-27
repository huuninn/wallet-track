<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public const array DEFAULT_CATEGORIES = [
        ['name' => 'Alimentação', 'type' => 'expense'],
        ['name' => 'Transporte', 'type' => 'expense'],
        ['name' => 'Moradia', 'type' => 'expense'],
        ['name' => 'Saúde', 'type' => 'expense'],
        ['name' => 'Educação', 'type' => 'expense'],
        ['name' => 'Lazer', 'type' => 'expense'],
        ['name' => 'Outros', 'type' => 'expense'],
        ['name' => 'Salário', 'type' => 'income'],
        ['name' => 'Freelance', 'type' => 'income'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::DEFAULT_CATEGORIES as $category) {
            $slug = mb_strtolower(trim($category['name']));

            $existing = DB::table('categories')->where('slug', $slug)->first();

            if ($existing === null) {
                // Primeira execução: INSERT com todos os campos.
                DB::table('categories')->insert([
                    'slug' => $slug,
                    'display_name' => $category['name'],
                    'default_type' => $category['type'],
                    'use_count' => 0,
                    'is_default' => true,
                    'created_at' => $now,
                ]);
            } else {
                // Re-execução (idempotente): atualiza APENAS campos de configuração.
                // NÃO sobrescreve use_count (contador de uso real, perderia dados)
                // nem created_at (timestamp de criação original).
                DB::table('categories')->where('slug', $slug)->update([
                    'display_name' => $category['name'],
                    'default_type' => $category['type'],
                    'is_default' => true,
                ]);
            }
        }
    }
}
