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

    public function test_provider_does_not_record_in_testing_environment(): void
    {
        // Em `testing`, o TelescopeHelper::isActive() retorna false (porque
        // config('telescope.enabled') é forçado para false no Tests\TestCase).
        // Isso garante que mesmo com TELESCOPE_ENABLED=true vindo do .env
        // local (necessário para dev), nenhum teste acidentalmente grava
        // entry no SQLite do Telescope.

        // Em complemento, verificamos o config efetivo em runtime — é o
        // sinal usado pelos decorators e pelo provider para decidir se
        // vão gravar (isActive() é chamado pelos 3 bindings de providers
        // e pelo boot do TelescopeServiceProvider).
        $this->assertFalse(
            TelescopeHelper::isActive(),
            'TelescopeHelper deve estar inativo em testing, garantindo zero gravação.'
        );
        $this->assertFalse(
            (bool) config('telescope.enabled'),
            'config(telescope.enabled) deve ser false em testing (forçado no TestCase).'
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
