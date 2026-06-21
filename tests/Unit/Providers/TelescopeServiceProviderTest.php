<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\TelescopeServiceProvider;
use App\Support\Telescope\TelescopeHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do {@see TelescopeServiceProvider}.
 *
 * Foco em:
 *  - Em `testing` env (phpunit), o provider NÃO deve registrar rotas/
 *    views (Telescope desligado).
 *  - A conexão `telescope` é registrada no `boot()` (sempre, mesmo
 *    com Telescope desligado — para que o prune manual funcione).
 *  - O helper `TelescopeHelper::isActive()` é o portão.
 *
 * Não testamos os métodos privados do IP whitelist (T4) — coberto
 * por testes de integração manuais (CT-022..CT-027) + a lógica é
 * simples o suficiente para revisão visual.
 */
#[CoversClass(TelescopeServiceProvider::class)]
class TelescopeServiceProviderTest extends TestCase
{
    public function test_telescope_helper_is_inactive_in_testing_environment(): void
    {
        // phpunit.xml define APP_ENV=testing + TELESCOPE_ENABLED=false
        // (pelo menos um dos dois deve ser suficiente).
        $this->assertFalse(
            TelescopeHelper::isActive(),
            'TelescopeHelper::isActive() deve ser false em testing (phpunit)'
        );
    }

    public function test_connection_telescope_is_registered_at_register(): void
    {
        // O provider é registrado no bootstrap, então o register() já
        // rodou. Verificamos que a conexão `telescope` existe no
        // config (mesmo com Telescope desligado, a conexão é registrada
        // para suportar o `telescope:prune` manual).
        $this->app->register(TelescopeServiceProvider::class);

        $connection = config('database.connections.telescope');

        $this->assertNotNull($connection, 'Conexão telescope deve estar registrada após register()');
        $this->assertSame('sqlite', $connection['driver']);
        $this->assertStringContainsString('telescope.sqlite', $connection['database']);
    }

    public function test_provider_does_not_register_routes_in_testing_environment(): void
    {
        // Re-binda o provider para forçar boot.
        $this->app->register(TelescopeServiceProvider::class);

        // Em testing, Telescope está desligado, então as rotas do
        // Telescope NÃO devem ser registradas.
        $routes = $this->app['router']->getRoutes();

        $telescopeRoutes = 0;
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'telescope')) {
                $telescopeRoutes++;
            }
        }

        $this->assertSame(
            0,
            $telescopeRoutes,
            "Em testing, Telescope routes não devem ser registradas (encontradas: {$telescopeRoutes})"
        );
    }

    public function test_register_method_is_idempotent(): void
    {
        // Chamar register() duas vezes não deve causar erro.
        $provider = $this->app->register(TelescopeServiceProvider::class);

        $this->app->register(TelescopeServiceProvider::class);

        $this->assertNotNull($provider);
    }
}
