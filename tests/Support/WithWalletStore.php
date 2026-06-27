<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Store\WalletStore;
use Illuminate\Support\Facades\Redis;

/**
 * Trait que centraliza o setup do WalletStore + RedisFake para todos os
 * testes Feature que dependem do WalletStore (banco de dados + Redis).
 *
 * Substitui a repetição do boilerplate:
 *
 *   protected function setUp(): void
 *   {
 *       parent::setUp();
 *       $this->store = app(WalletStore::class);
 *       RedisFake::flush();
 *       Redis::swap(new RedisFake);
 *   }
 *
 * Uso:
 *
 *   final class MeuTest extends TestCase
 *   {
 *       use RefreshDatabase;
 *       use WithWalletStore;
 *
 *       protected function setUp(): void
 *       {
 *           parent::setUp();
 *           $this->setUpWalletStore();
 *           // Opsional: expor $this->store ao container para handlers
 *           // que resolvem WalletStore via app():
 *           $this->bindStoreToContainer();
 *       }
 *   }
 *
 * A trait NÃO declara o trait RefreshDatabase — cada teste declara
 * explicitamente para manter a rastreabilidade de quais testes dependem
 * de banco SQLite.
 */
trait WithWalletStore
{
    /**
     * Inicializa o WalletStore resolvido do container e substitui a
     * facade Redis por um RedisFake in-memory com storage limpo.
     *
     * Atribui o resultado a $this->store (o teste deve declarar a
     * propriedade `private WalletStore $store`).
     *
     * Deve ser chamado em setUp() após parent::setUp().
     */
    protected function setUpWalletStore(): void
    {
        $this->store = app(WalletStore::class);
        RedisFake::flush();
        Redis::swap(new RedisFake);
    }

    /**
     * Expõe a instância do WalletStore ($this->store) no container para
     * handlers e commands que resolvem via app(WalletStore::class).
     *
     * Use quando o teste precisar que outras classes (ex.: handlers,
     * ConversationRouter) encontrem a mesma instância do store.
     */
    protected function bindStoreToContainer(): void
    {
        $this->app->instance(WalletStore::class, $this->store);
    }
}
