<?php

declare(strict_types=1);

namespace App\Support\Telescope;

use App\Services\Google\FirestoreGateway;
use Override;
use Throwable;

/**
 * Decorator que envolve {@see FirestoreGateway} para registrar cada
 * operação no Laravel Telescope.
 *
 * Implementa os 10 métodos da interface. Cada método:
 *  1. Mede latência com `hrtime(true)` (nanossegundos de alta precisão).
 *  2. Chama o `wrapped` (delegação transparente).
 *  3. Em caso de SUCESSO: registra entry com `status: success` e o
 *     snapshot do retorno (ou dos dados escritos).
 *  4. Em caso de ERRO: registra entry com `status: error` e os dados
 *     da exceção (`class`, `message`, `code`), depois re-lança a
 *     exceção original (o decorator é transparente para o caller).
 *
 * **Por que `final class` (sem `readonly`)?** A property
 * `$nestedOperationCount` precisa ser mutável para contar operações
 * dentro de `transaction()`. A spec upstream sugeria `final readonly`,
 * o que PHP 8.4 rejeita para properties mutáveis — daí o `final class`.
 *
 * **Por que `tags` específicas?** Cada operação é classificada em
 * `read` / `write` / `delete` / `transaction` para permitir filtros
 * rápidos na UI do Telescope. Em caso de erro, adiciona-se `error` à
 * pilha de tags.
 *
 * **NÃO mockar `Telescope::recordEvent()` em testes** — é método
 * estático. Como `TelescopeHelper::isActive()` retorna `false` em
 * tests, `recordEvent()` é no-op (Telescope está desligado no
 * phpunit). Validamos indiretamente: mock do wrapped verificando
 * que foi chamado com os args corretos.
 */
final class FirestoreWatcherDecorator implements FirestoreGateway
{
    /**
     * Contador de operações aninhadas dentro de uma `transaction()`.
     * Mutável por design (resetado em cada nova transaction; o PHP é
     * single-threaded por request, então não há race condition entre
     * requests distintos no ciclo de vida de um worker).
     */
    private int $nestedOperationCount = 0;

    public function __construct(
        private readonly FirestoreGateway $wrapped,
    ) {}

    // --- Métodos da interface (10 no total) ---

    #[Override]
    public function createDocument(string $collection, array $data): string
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $id = $this->wrapped->createDocument($collection, $data);
            $this->recordEntry(
                operation: 'createDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: $data,
            );

            return $id;
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'createDocument',
                collection: $collection,
                documentId: null,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: $data,
            );
            throw $e;
        }
    }

    #[Override]
    public function setDocument(string $collection, string $id, array $data): void
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $this->wrapped->setDocument($collection, $id, $data);
            $this->recordEntry(
                operation: 'setDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: $data,
            );
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'setDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: $data,
            );
            throw $e;
        }
    }

    #[Override]
    public function mergeDocument(string $collection, string $id, array $data): void
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $this->wrapped->mergeDocument($collection, $id, $data);
            $this->recordEntry(
                operation: 'mergeDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: $data,
            );
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'mergeDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: $data,
            );
            throw $e;
        }
    }

    #[Override]
    public function getDocument(string $collection, string $id): ?array
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $result = $this->wrapped->getDocument($collection, $id);
            $this->recordEntry(
                operation: 'getDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: $result, // snapshot do RETORNO (operação de leitura)
            );

            return $result;
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'getDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
            );
            throw $e;
        }
    }

    #[Override]
    public function updateFields(string $collection, string $id, array $fields): void
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $this->wrapped->updateFields($collection, $id, $fields);
            $this->recordEntry(
                operation: 'updateFields',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: $fields,
            );
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'updateFields',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: $fields,
            );
            throw $e;
        }
    }

    #[Override]
    public function incrementField(string $collection, string $id, string $field, int $amount = 1): void
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $this->wrapped->incrementField($collection, $id, $field, $amount);
            $this->recordEntry(
                operation: 'incrementField',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: ['field' => $field, 'amount' => $amount],
            );
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'incrementField',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: ['field' => $field, 'amount' => $amount],
            );
            throw $e;
        }
    }

    #[Override]
    public function deleteField(string $collection, string $id, string $field): void
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $this->wrapped->deleteField($collection, $id, $field);
            $this->recordEntry(
                operation: 'deleteField',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: ['field' => $field],
            );
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'deleteField',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: ['field' => $field],
            );
            throw $e;
        }
    }

    #[Override]
    public function deleteDocument(string $collection, string $id): void
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $this->wrapped->deleteDocument($collection, $id);
            $this->recordEntry(
                operation: 'deleteDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'success',
            );
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'deleteDocument',
                collection: $collection,
                documentId: $id,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
            );
            throw $e;
        }
    }

    #[Override]
    public function query(string $collection, array $wheres = [], array $orderBys = [], ?int $limit = null): array
    {
        $start = hrtime(true);
        $this->nestedOperationCount++;
        try {
            $results = $this->wrapped->query($collection, $wheres, $orderBys, $limit);
            $this->recordEntry(
                operation: 'query',
                collection: $collection,
                documentId: null,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: [
                    'wheres' => $wheres,
                    'orderBys' => $orderBys,
                    'limit' => $limit,
                    'result_count' => count($results),
                ],
            );

            return $results;
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'query',
                collection: $collection,
                documentId: null,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: [
                    'wheres' => $wheres,
                    'orderBys' => $orderBys,
                    'limit' => $limit,
                ],
            );
            throw $e;
        }
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        // Caso especial: a closure interna recebe o PRÓPRIO decorator
        // (pois é ele que está bindado como `FirestoreGateway`). Cada
        // operação aninhada (`setDocument`, `getDocument`, etc.) já é
        // capturada individualmente pelo decorator, e cada uma
        // incrementa `$this->nestedOperationCount`.
        //
        // Aqui registramos APENAS uma entry "master" da transação,
        // contendo a duração total e a contagem de operações aninhadas.
        // Isso permite visualizar a transação como um "evento atômico"
        // na UI do Telescope, sem perder as entries individuais.

        $start = hrtime(true);
        $countBefore = $this->nestedOperationCount;
        $this->nestedOperationCount = 0;

        try {
            $result = $this->wrapped->transaction(function (FirestoreGateway $gw) use ($fn) {
                // A closure recebe o wrapped (CloudFirestoreGateway), mas
                // substituímos para passar o decorator — assim as ops
                // internas são contabilizadas e registradas.
                return $fn($this);
            });

            $this->recordEntry(
                operation: 'transaction',
                collection: '(transaction)',
                documentId: null,
                latencyNs: hrtime(true) - $start,
                status: 'success',
                snapshot: [
                    'nested_operations' => $this->nestedOperationCount,
                ],
            );

            return $result;
        } catch (Throwable $e) {
            $this->recordEntry(
                operation: 'transaction',
                collection: '(transaction)',
                documentId: null,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
                snapshot: [
                    'nested_operations' => $this->nestedOperationCount,
                ],
            );
            throw $e;
        } finally {
            // Restaura o contador para o valor anterior à transaction.
            // Importante para que chamadas a `transaction()` fora de outra
            // transaction não contaminem o contador de nível superior.
            $this->nestedOperationCount = $countBefore;
        }
    }

    // --- Método privado de gravação ---

    /**
     * Grava uma entry no Telescope via `recordEvent()`.
     *
     * Centraliza a montagem do content + tags para os 10 métodos.
     * Tags base: `['firestore', $classify]` (read/write/delete/transaction)
     * mais `error` se status === 'error'.
     */
    private function recordEntry(
        string $operation,
        string $collection,
        int $latencyNs,
        string $status,
        ?string $documentId = null,
        ?array $snapshot = null,
        ?Throwable $exception = null,
    ): void {
        $content = [
            'name' => 'firestore',
            'operation' => $operation,
            'collection' => $collection,
            'document_id' => $documentId,
            'latency_ms' => EntryPayload::nsToMs($latencyNs),
            'status' => $status,
            'snapshot' => $snapshot,
            'exception' => EntryPayload::formatException($exception),
        ];

        $tags = ['firestore', $this->classifyOperation($operation)];
        if ($status === 'error') {
            $tags[] = 'error';
        }

        EntryPayload::recordEvent($content, $tags);
    }

    /**
     * Classifica operação para a tag de categoria.
     *
     * @return 'read'|'write'|'delete'|'transaction'
     */
    private function classifyOperation(string $operation): string
    {
        return match ($operation) {
            'getDocument', 'query' => 'read',
            'createDocument', 'setDocument', 'mergeDocument',
            'updateFields', 'incrementField', 'deleteField' => 'write',
            'deleteDocument' => 'delete',
            'transaction' => 'transaction',
        };
    }
}
