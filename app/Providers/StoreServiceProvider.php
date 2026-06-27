<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Store\WalletStore;
use Illuminate\Support\ServiceProvider;

/**
 * Registra a camada de persistência (WalletStore) no container.
 *
 * Substitui o antigo FirestoreServiceProvider (deletado em M7). WalletStore é singleton pois mantém
 * estado mínimo (nenhum — todas as operações são stateless sobre banco de dados/Redis).
 */
class StoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WalletStore::class);
    }
}
