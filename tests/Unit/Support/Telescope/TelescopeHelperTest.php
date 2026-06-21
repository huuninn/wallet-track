<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Telescope;

use App\Support\Telescope\TelescopeHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do helper estático {@see TelescopeHelper}.
 *
 * O helper tem 2 entradas:
 *   - `config('telescope.enabled')` — master switch.
 *   - `App::environment()` — gate de ambiente.
 *
 * Como o helper é estático e chama a facade `App`, usamos a estratégia
 * `app()->detectEnvironment(...)` para forçar o ambiente em cada teste.
 * Isso é mais robusto que mockar a facade (que tem internals
 * complicados — `environment()` resolve o service 'env' do container).
 *
 * Esta abordagem indiretamente valida o comportamento: como o helper
 * chama `App::environment()` e o container agora reporta o env
 * forçado, a asserção reflete o que o helper DEVE retornar para cada
 * ambiente.
 */
#[CoversClass(TelescopeHelper::class)]
class TelescopeHelperTest extends TestCase
{
    public function test_is_active_returns_false_when_config_disabled(): void
    {
        config(['telescope.enabled' => false]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertFalse(TelescopeHelper::isActive());
    }

    public function test_is_active_returns_false_in_production(): void
    {
        config(['telescope.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(
            TelescopeHelper::isActive(),
            'Telescope deve estar desabilitado em production mesmo com config ligado'
        );
    }

    public function test_is_active_returns_false_in_staging_per_user_restriction(): void
    {
        // Restrição do usuário: Telescope NUNCA roda em staging, mesmo
        // que o config esteja ligado. Esta é a divergência da spec
        // original que mencionava `['local', 'staging']`.
        config(['telescope.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'staging');

        $this->assertFalse(
            TelescopeHelper::isActive(),
            'Telescope deve estar desabilitado em staging (restrição do usuário)'
        );
    }

    public function test_is_active_returns_true_in_local_when_enabled(): void
    {
        config(['telescope.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertTrue(TelescopeHelper::isActive());
    }

    public function test_is_active_returns_false_in_local_when_config_disabled(): void
    {
        // Mesmo em local, sem o master switch ligado, Telescope é inativo.
        config(['telescope.enabled' => false]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertFalse(TelescopeHelper::isActive());
    }

    public function test_is_active_returns_false_in_testing_environment(): void
    {
        // Em testing (phpunit), Telescope deve estar desligado.
        config(['telescope.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'testing');

        $this->assertFalse(TelescopeHelper::isActive());
    }
}
