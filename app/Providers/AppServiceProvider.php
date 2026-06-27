<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Migração MySQL → MariaDB: remove a conexão residual 'mysql' que o
        // Laravel framework injeta via merge de base config (LoadConfiguration).
        // Sem isto, config('database.connections.mysql') retorna o array do
        // skeleton do framework mesmo sem o bloco no config/database.php do app.
        $connections = config('database.connections');
        unset($connections['mysql']);
        config(['database.connections' => $connections]);
    }
}
