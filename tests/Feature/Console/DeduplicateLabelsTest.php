<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\DeduplicateLabels;
use App\Models\Category;
use App\Models\Label;
use App\Services\Store\WalletStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes do comando `labels:deduplicate` (F6).
 *
 * Cobertura:
 *  - ["Almoço", "almoco", "ALMOCO"] → 1 registro `labels/almoco` com use_count somado.
 *  - ["Restaurante"] (count 1, folded_name != fold(name)) → renomeado para `labels/restaurante`.
 *  - ["pizza"] (count 1, folded_name == fold(name)) → inalterado.
 *  - --dry-run não modifica estado.
 *  - Tabela `categories` populada antes → idêntica depois (isolamento).
 *
 * Roda isolado: vendor/bin/phpunit --filter DeduplicateLabelsTest
 */
#[CoversClass(DeduplicateLabels::class)]
class DeduplicateLabelsTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private WalletStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->bindStoreToContainer();
    }

    public function test_consolidates_duplicates_with_accent_variations(): void
    {
        // ["Almoço", "almoco", "ALMOCO"] → 1 registro `labels/almoco` com use_count somado.
        Label::factory()->create([
            'folded_name' => 'Almoço',
            'name' => 'Almoço',
            'use_count' => 3,
            'last_used_at' => '2026-06-01 00:00:00',
        ]);
        Label::factory()->create([
            'folded_name' => 'almoco',
            'name' => 'almoco',
            'use_count' => 2,
            'last_used_at' => '2026-06-15 00:00:00',
        ]);
        Label::factory()->create([
            'folded_name' => 'ALMOCO',
            'name' => 'ALMOCO',
            'use_count' => 1,
            'last_used_at' => '2026-06-10 00:00:00',
        ]);

        $this->artisan('labels:deduplicate')
            ->expectsConfirmation('1 ação(ões) de deduplicação serão executadas. Continuar?', 'yes')
            ->assertSuccessful();

        $labels = Label::all();

        // Somente o registro canônico existe.
        $this->assertNotNull($labels->firstWhere('folded_name', 'almoco'), 'Canonical label must exist');
        $this->assertNull($labels->firstWhere('folded_name', 'Almoço'));
        $this->assertNull($labels->firstWhere('folded_name', 'ALMOCO'));

        $canonical = $labels->firstWhere('folded_name', 'almoco');
        $this->assertSame(6, $canonical->use_count, 'use_count deve ser 3+2+1 = 6');
        // last_used_at deve ser o mais recente: 2026-06-15.
        $this->assertSame('2026-06-15 00:00:00', $canonical->last_used_at->format('Y-m-d H:i:s'));
    }

    public function test_renames_single_label_with_non_folded_id(): void
    {
        // ["Restaurante"] (count 1, folded_name != fold(name)) → renomeado para `labels/restaurante`.
        Label::factory()->create([
            'folded_name' => 'Restaurante',
            'name' => 'Restaurante',
            'use_count' => 5,
            'last_used_at' => '2026-06-15 00:00:00',
        ]);

        $this->artisan('labels:deduplicate')
            ->expectsConfirmation('1 ação(ões) de deduplicação serão executadas. Continuar?', 'yes')
            ->assertSuccessful();

        $labels = Label::all();

        // Renomeado para folded id.
        $this->assertNotNull($labels->firstWhere('folded_name', 'restaurante'));
        $this->assertNull($labels->firstWhere('folded_name', 'Restaurante'));

        $renamed = $labels->firstWhere('folded_name', 'restaurante');
        $this->assertSame(5, $renamed->use_count);
        $this->assertSame('Restaurante', $renamed->name);
    }

    public function test_keeps_already_folded_label_unchanged(): void
    {
        // ["pizza"] (count 1, folded_name == fold(name)) → inalterado.
        Label::factory()->create([
            'folded_name' => 'pizza',
            'name' => 'pizza',
            'use_count' => 4,
            'last_used_at' => '2026-06-15 00:00:00',
        ]);

        $this->artisan('labels:deduplicate')
            ->assertSuccessful();

        $labels = Label::all();

        $this->assertNotNull($labels->firstWhere('folded_name', 'pizza'));
        $pizza = $labels->firstWhere('folded_name', 'pizza');
        $this->assertSame(4, $pizza->use_count);
        $this->assertSame('pizza', $pizza->name);
    }

    public function test_dry_run_does_not_modify_state(): void
    {
        // Dry-run com duplicatas — não deve alterar nada.
        Label::factory()->create([
            'folded_name' => 'Almoço',
            'name' => 'Almoço',
            'use_count' => 2,
            'last_used_at' => '2026-06-01 00:00:00',
        ]);
        Label::factory()->create([
            'folded_name' => 'almoco',
            'name' => 'almoco',
            'use_count' => 3,
            'last_used_at' => '2026-06-15 00:00:00',
        ]);

        // Snapshot antes.
        $keysBefore = Label::pluck('folded_name')->sort()->values()->toArray();

        $this->artisan('labels:deduplicate', ['--dry-run' => true])
            ->assertSuccessful();

        // Estado idêntico.
        $keysAfter = Label::pluck('folded_name')->sort()->values()->toArray();

        $this->assertSame($keysBefore, $keysAfter);
        $this->assertSame(2, Label::where('folded_name', 'Almoço')->first()->use_count);
        $this->assertSame(3, Label::where('folded_name', 'almoco')->first()->use_count);
    }

    public function test_does_not_touch_categories_collection(): void
    {
        // Tabela `categories` populada antes → idêntica depois (isolamento).
        Category::factory()->create([
            'slug' => 'alimentação',
            'display_name' => 'Alimentação',
            'default_type' => 'expense',
            'use_count' => 0,
        ]);

        // Labels com duplicata.
        Label::factory()->create([
            'folded_name' => 'Almoço',
            'name' => 'Almoço',
            'use_count' => 1,
            'last_used_at' => '2026-06-01 00:00:00',
        ]);
        Label::factory()->create([
            'folded_name' => 'almoco',
            'name' => 'almoco',
            'use_count' => 1,
            'last_used_at' => '2026-06-01 00:00:00',
        ]);

        $categoriesBefore = Category::all()->toArray();

        $this->artisan('labels:deduplicate')
            ->expectsConfirmation('1 ação(ões) de deduplicação serão executadas. Continuar?', 'yes')
            ->assertSuccessful();

        $categoriesAfter = Category::all()->toArray();
        $this->assertSame($categoriesBefore, $categoriesAfter, 'Categorias NÃO devem ser alteradas');
        $this->assertNotNull(Category::where('slug', 'alimentação')->first());
    }
}
