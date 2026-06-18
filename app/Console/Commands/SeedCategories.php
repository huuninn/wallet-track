<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Google\FirestoreService;
use Illuminate\Console\Command;

/**
 * Popula o catálogo de categorias padrão no Firestore (M5.3).
 *
 * Comando: `php artisan firestore:seed-categories`
 *
 * Idempotente: para cada categoria da lista fixa abaixo, chama
 * {@see FirestoreService::categoryExists()} e só cria se ainda não existe.
 * Pode ser re-executado quantas vezes for necessário (em deploys, em
 * ambientes novos, em testes) sem duplicar entradas.
 *
 * Categorias e seus `default_type` (spec §5 e catálogo do produto):
 *   - income : Salário, Freelance.
 *   - expense: Alimentação, Transporte, Moradia, Saúde, Educação, Lazer, Outros.
 *
 * Logs informativos mostram quantas foram criadas vs. já existentes,
 * listando as novas no output para fácil auditoria.
 */
class SeedCategories extends Command
{
    /**
     * Catálogo fixo de categorias padrão (spec §5 / catálogo do produto).
     *
     * Ordem deliberada: despesas primeiro, receitas depois — espelha a ordem
     * em que aparecem na UX (despesas são mais frequentes no uso diário).
     *
     * @var array<int, array{name: string, type: string}>
     */
    private const array DEFAULT_CATEGORIES = [
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

    /**
     * {@inheritDoc}
     */
    protected $signature = 'firestore:seed-categories';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Popula o catálogo de categorias padrão no Firestore (idempotente)';

    public function handle(FirestoreService $firestore): int
    {
        $this->info('Seedando categorias padrão no Firestore...');

        $created = 0;
        $skipped = 0;
        $createdNames = [];

        foreach (self::DEFAULT_CATEGORIES as $category) {
            $name = $category['name'];
            $type = $category['type'];

            if ($firestore->categoryExists($name)) {
                $skipped++;

                continue;
            }

            $firestore->createCategory(
                displayName: $name,
                defaultType: $type,
                isDefault: true,
            );

            $created++;
            $createdNames[] = "{$name} ({$type})";
        }

        $this->info("✅ Concluído: {$created} criada(s), {$skipped} já existente(s).");

        if ($createdNames !== []) {
            $this->line('   Novas:');
            foreach ($createdNames as $name) {
                $this->line("   - {$name}");
            }
        } else {
            $this->line('   Nenhuma categoria nova para criar (idempotente).');
        }

        return self::SUCCESS;
    }
}
