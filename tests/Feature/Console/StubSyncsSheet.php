<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\SyncsSheet;
use App\Dto\TransactionData;
use App\Services\Store\WalletStore;

/**
 * Stub do {@see SyncsSheet} — simula fielmente o efeito colateral de
 * {@see SyncSheet} no WalletStore (banco de dados).
 *
 * Em produção, o `SyncSheet` real:
 *  - Sucesso → chama `updateSyncStatus(id, SYNC_SYNCED)` (sem increment attempts).
 *  - Falha   → chama `updateSyncStatus(id, SYNC_FAILED, $error)` (com increment).
 *
 * O command então decide a próxima ação (re-enfileirar pending ou marcar
 * definitivo failed). Para que os testes de orquestração sejam fiéis à
 * produção, o stub replica EXATAMENTE este side effect no banco de
 * teste. Sem isso, o command "enxergaria" o doc como se ainda tivesse o
 * attempts original e tomaria a decisão errada.
 *
 * Vive em arquivo próprio (PSR-4) para que possa ser reusado por outros
 * testes (ex.: {@see \Tests\Feature\Commands\SyncHandlerTest}) sem depender
 * do efeito colateral de autoload do arquivo que o definiu originalmente.
 */
class StubSyncsSheet implements SyncsSheet
{
    public bool $defaultReturn = true;

    public string $defaultError = 'unknown';

    /**
     * Mapa de overrides por id de transação (int).
     *
     * @var array<int, array{return: bool, error?: string}>
     */
    public array $overrides = [];

    public int $callCount = 0;

    public function __construct(
        private readonly ?WalletStore $store = null,
    ) {}

    public function handle(TransactionData $dto, int $txId): bool
    {
        $this->callCount++;

        $override = $this->overrides[$txId] ?? null;
        $shouldReturn = $override['return'] ?? $this->defaultReturn;
        $error = $override['error'] ?? $this->defaultError;

        if (! $shouldReturn) {
            // Replica side effect do SyncSheet real em falha: marca failed
            // (com increment) e retorna false.
            if ($this->store !== null) {
                $this->store->updateSyncStatus(
                    $txId,
                    WalletStore::SYNC_FAILED,
                    $error,
                );
            }

            return false;
        }

        // Sucesso: replica side effect (status=synced, sem increment).
        if ($this->store !== null) {
            $this->store->updateSyncStatus(
                $txId,
                WalletStore::SYNC_SYNCED,
            );
        }

        return true;
    }
}
