<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do comando `labels:deduplicate` (F6).
 *
 * Cobertura:
 *  - ["Almoço", "almoco", "ALMOCO"] → 1 doc `labels/almoco` com use_count somado.
 *  - ["Restaurante"] (count 1, id != fold) → renomeado para `labels/restaurante`.
 *  - ["pizza"] (count 1, id == fold) → inalterado.
 *  - --dry-run não modifica estado.
 *  - Coleção `categories/` populada antes → idêntica depois (isolamento).
 *
 * Roda isolado: vendor/bin/phpunit --filter DeduplicateLabelsTest
 */
#[CoversClass(\App\Console\Commands\DeduplicateLabels::class)]
class DeduplicateLabelsTest extends TestCase
{
    private InMemoryFirestoreGateway $gateway;

    private FirestoreService $firestore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemoryFirestoreGateway;
        $this->firestore = new FirestoreService($this->gateway);
        $this->app->instance(FirestoreService::class, $this->firestore);
    }

    public function test_consolidates_duplicates_with_accent_variations(): void
    {
        // ["Almoço", "almoco", "ALMOCO"] → 1 doc `labels/almoco` com use_count somado.
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'Almoço', [
            'name' => 'Almoço', 'use_count' => 3, 'last_used_at' => '2026-06-01T00:00:00.000000Z',
        ]);
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'almoco', [
            'name' => 'almoco', 'use_count' => 2, 'last_used_at' => '2026-06-15T00:00:00.000000Z',
        ]);
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'ALMOCO', [
            'name' => 'ALMOCO', 'use_count' => 1, 'last_used_at' => '2026-06-10T00:00:00.000000Z',
        ]);

        $this->artisan('labels:deduplicate')
            ->expectsConfirmation('1 ação(ões) de deduplicação serão executadas. Continuar?', 'yes')
            ->assertSuccessful();

        $raw = $this->gateway->raw()['labels'] ?? [];

        // Somente o documento canônico existe.
        $this->assertArrayHasKey('almoco', $raw);
        $this->assertArrayNotHasKey('Almoço', $raw);
        $this->assertArrayNotHasKey('ALMOCO', $raw);

        $this->assertSame(6, $raw['almoco']['use_count'], 'use_count deve ser 3+2+1 = 6');
        // last_used_at deve ser o mais recente: 2026-06-15.
        $this->assertSame('2026-06-15T00:00:00.000000Z', $raw['almoco']['last_used_at']);
    }

    public function test_renames_single_label_with_non_folded_id(): void
    {
        // ["Restaurante"] (count 1, id != fold) → renomeado para `labels/restaurante`.
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'Restaurante', [
            'name' => 'Restaurante', 'use_count' => 5, 'last_used_at' => '2026-06-15T00:00:00.000000Z',
        ]);

        $this->artisan('labels:deduplicate')
            ->expectsConfirmation('1 ação(ões) de deduplicação serão executadas. Continuar?', 'yes')
            ->assertSuccessful();

        $raw = $this->gateway->raw()['labels'] ?? [];

        // Renomeado para folded id.
        $this->assertArrayHasKey('restaurante', $raw);
        $this->assertArrayNotHasKey('Restaurante', $raw);
        $this->assertSame(5, $raw['restaurante']['use_count']);
        $this->assertSame('Restaurante', $raw['restaurante']['name']);
    }

    public function test_keeps_already_folded_label_unchanged(): void
    {
        // ["pizza"] (count 1, id == fold) → inalterado.
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'pizza', [
            'name' => 'pizza', 'use_count' => 4, 'last_used_at' => '2026-06-15T00:00:00.000000Z',
        ]);

        $this->artisan('labels:deduplicate')
            ->assertSuccessful();

        $raw = $this->gateway->raw()['labels'] ?? [];

        $this->assertArrayHasKey('pizza', $raw);
        $this->assertSame(4, $raw['pizza']['use_count']);
        $this->assertSame('pizza', $raw['pizza']['name']);
    }

    public function test_dry_run_does_not_modify_state(): void
    {
        // Dry-run com duplicatas — não deve alterar nada.
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'Almoço', [
            'name' => 'Almoço', 'use_count' => 2, 'last_used_at' => '2026-06-01T00:00:00.000000Z',
        ]);
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'almoco', [
            'name' => 'almoco', 'use_count' => 3, 'last_used_at' => '2026-06-15T00:00:00.000000Z',
        ]);

        // Snapshot antes.
        $rawBefore = $this->gateway->raw()['labels'] ?? [];
        $keysBefore = array_keys($rawBefore);

        $this->artisan('labels:deduplicate', ['--dry-run' => true])
            ->assertSuccessful();

        // Estado idêntico.
        $rawAfter = $this->gateway->raw()['labels'] ?? [];
        $keysAfter = array_keys($rawAfter);

        sort($keysBefore);
        sort($keysAfter);
        $this->assertSame($keysBefore, $keysAfter);
        $this->assertSame(2, $rawAfter['Almoço']['use_count']);
        $this->assertSame(3, $rawAfter['almoco']['use_count']);
    }

    public function test_does_not_touch_categories_collection(): void
    {
        // Coleção `categories/` populada antes → idêntica depois (isolamento).
        $this->gateway->setDocument(FirestoreService::COLLECTION_CATEGORIES, 'alimentação', [
            'display_name' => 'Alimentação',
            'default_type' => 'expense',
            'use_count' => 0,
            'created_at' => '2026-06-01T00:00:00.000000Z',
        ]);

        // Labels com duplicata.
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'Almoço', [
            'name' => 'Almoço', 'use_count' => 1, 'last_used_at' => '2026-06-01T00:00:00.000000Z',
        ]);
        $this->gateway->setDocument(FirestoreService::COLLECTION_LABELS, 'almoco', [
            'name' => 'almoco', 'use_count' => 1, 'last_used_at' => '2026-06-01T00:00:00.000000Z',
        ]);

        $categoriesBefore = $this->gateway->raw()['categories'] ?? [];

        $this->artisan('labels:deduplicate')
            ->expectsConfirmation('1 ação(ões) de deduplicação serão executadas. Continuar?', 'yes')
            ->assertSuccessful();

        $categoriesAfter = $this->gateway->raw()['categories'] ?? [];
        $this->assertSame($categoriesBefore, $categoriesAfter, 'Categorias NÃO devem ser alteradas');
        $this->assertArrayHasKey('alimentação', $categoriesAfter);
    }
}
