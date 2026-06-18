<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\SeedCategories;
use App\Services\Google\FirestoreGateway;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes de feature do command `firestore:seed-categories` (M5.3).
 *
 * Idempotência é o ponto central: rodar o command uma vez cria as 9
 * categorias padrão; rodar de novo cria 0 (todas já existem). Em ambos
 * os casos o command deve terminar com SUCCESS e mensagem informativa.
 *
 * Usa um {@see InMemoryFirestoreGateway} **compartilhado entre as duas
 * execuções** — bindamos a mesma instância no container para que o estado
 * persista de uma execução para a outra dentro do mesmo teste. Sem rede.
 *
 * **Nota sobre asserts de output**: `expectsOutputToContain` do Laravel
 * consome um único `doWrite` por substring declarada. Duas substrings que
 * case com a MESMA linha disputam a mesma chamada (Mockery despacha só a
 * primeira). Por isso cada teste abaixo usa apenas UMA substring por
 * invocação `$this->artisan(...)`; para checar strings adicionais do
 * mesmo output, usamos `Artisan::output()` + `assertStringContainsString`.
 *
 * Roda isolado: vendor/bin/phpunit --filter SeedCategoriesCommandTest
 */
#[CoversClass(SeedCategories::class)]
class SeedCategoriesCommandTest extends TestCase
{
    /**
     * O mesmo gateway precisa sobreviver às duas invocações do command
     * dentro de cada teste, por isso guardamos como propriedade e bindamos.
     */
    private InMemoryFirestoreGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemoryFirestoreGateway;

        // Binda o gateway compartilhado e o service que o consome.
        $this->app->instance(FirestoreGateway::class, $this->gateway);
        $this->app->singleton(FirestoreService::class, fn ($app) => new FirestoreService(
            $app->make(FirestoreGateway::class),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Primeira execução: cria 9 categorias
    |--------------------------------------------------------------------------
    */

    public function test_first_run_creates_nine_default_categories(): void
    {
        $this->artisan('firestore:seed-categories')
            ->assertSuccessful()
            ->expectsOutputToContain('9 criada(s)');

        // Lista efetivamente persistida.
        $service = $this->app->make(FirestoreService::class);
        $list = $service->getCategories();

        $names = array_map(
            fn (array $row): string => $row['data']['display_name'],
            $list,
        );

        $this->assertCount(9, $list);

        // Verifica presença de todas as esperadas (ordenação é detalhe
        // de implementação da query e não da semântica do command).
        $expected = [
            'Alimentação', 'Educação', 'Freelance', 'Lazer', 'Moradia',
            'Outros', 'Saúde', 'Salário', 'Transporte',
        ];
        foreach ($expected as $name) {
            $this->assertContains($name, $names, "Categoria '{$name}' deveria existir após o seed.");
        }
    }

    public function test_first_run_reports_zero_existing(): void
    {
        $this->artisan('firestore:seed-categories')
            ->assertSuccessful()
            ->expectsOutputToContain('0 já existente(s)');
    }

    public function test_first_run_marks_categories_as_default(): void
    {
        $this->artisan('firestore:seed-categories')->assertSuccessful();

        $service = $this->app->make(FirestoreService::class);

        $list = $service->getCategories();
        foreach ($list as $row) {
            $this->assertTrue(
                $row['data']['is_default'],
                "Categoria {$row['data']['display_name']} deveria ser is_default=true.",
            );
        }
    }

    public function test_first_run_assigns_correct_default_types(): void
    {
        $this->artisan('firestore:seed-categories')->assertSuccessful();

        $service = $this->app->make(FirestoreService::class);

        // Despesas: expense. Receitas: income.
        $this->assertSame('expense', $service->getCategory('alimentação')['default_type']);
        $this->assertSame('expense', $service->getCategory('transporte')['default_type']);
        $this->assertSame('expense', $service->getCategory('moradia')['default_type']);
        $this->assertSame('expense', $service->getCategory('saúde')['default_type']);
        $this->assertSame('expense', $service->getCategory('educação')['default_type']);
        $this->assertSame('expense', $service->getCategory('lazer')['default_type']);
        $this->assertSame('expense', $service->getCategory('outros')['default_type']);
        $this->assertSame('income', $service->getCategory('salário')['default_type']);
        $this->assertSame('income', $service->getCategory('freelance')['default_type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Segunda execução: idempotente (cria 0)
    |--------------------------------------------------------------------------
    */

    public function test_second_run_creates_nothing(): void
    {
        // 1ª execução (silenciosa quanto a substring; só valida exit code).
        $this->artisan('firestore:seed-categories')->assertSuccessful();

        // Snapshot do estado após a 1ª execução.
        $service = $this->app->make(FirestoreService::class);
        $afterFirst = count($service->getCategories());

        // 2ª execução no mesmo gateway (compartilhado via setUp) — afirma
        // explicitamente "0 criada(s)".
        $this->artisan('firestore:seed-categories')
            ->assertSuccessful()
            ->expectsOutputToContain('0 criada(s)');

        // Mesma quantidade: nada foi duplicado ou sobrescrito.
        $this->assertCount($afterFirst, $service->getCategories());
    }

    public function test_second_run_reports_nine_existing(): void
    {
        $this->artisan('firestore:seed-categories')->assertSuccessful();

        $this->artisan('firestore:seed-categories')
            ->assertSuccessful()
            ->expectsOutputToContain('9 já existente(s)');
    }

    public function test_multiple_runs_keep_store_stable(): void
    {
        // Três execuções seguidas.
        $this->artisan('firestore:seed-categories')->assertSuccessful();
        $this->artisan('firestore:seed-categories')->assertSuccessful();
        $this->artisan('firestore:seed-categories')->assertSuccessful();

        $service = $this->app->make(FirestoreService::class);
        $this->assertCount(9, $service->getCategories());
    }
}
