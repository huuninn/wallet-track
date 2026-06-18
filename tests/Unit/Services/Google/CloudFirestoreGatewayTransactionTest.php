<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Services\Google\CloudFirestoreGateway;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do roteamento transacional do {@see CloudFirestoreGateway} (FIX-1).
 *
 * Estes testes **não tocam o Firestore real nem emulator**: mockam o
 * `FirestoreClient` e a cadeia `CollectionReference → DocumentReference` com
 * Mockery para verificar **comportamentalmente** que, quando uma transação
 * está ativa, as escritas são enfileiradas na `Transaction` (em vez de
 * cometidas imediatamente no `DocumentReference`). Isto é exatamente o
 * contrato que evita lost update em read-modify-write concorrente.
 *
 * Cobertura:
 *   - Fora de transaction(): escritas chamam `$ref->...` (não `$tx->...`).
 *   - Dentro de transaction(): escritas chamam `$tx->set/create/update/delete`.
 *   - Dentro de transaction(): leitura chama `$tx->snapshot()` (não `$ref->snapshot()`).
 *
 * Roda isolado: vendor/bin/phpunit --filter CloudFirestoreGatewayTransactionTest
 */
#[CoversClass(CloudFirestoreGateway::class)]
class CloudFirestoreGatewayTransactionTest extends TestCase
{
    // Faz com que as expectativas do Mockery contem como assertions do
    // PHPUnit (evita "risky test" quando o teste só verifica mocks).
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de montagem
    |--------------------------------------------------------------------------
    */

    /**
     * Monta o gateway com um FirestoreClient mockado e devolve o mock para
     * que cada teste configure as expectativas de chamada.
     */
    private function makeGateway(): array
    {
        $client = Mockery::mock(FirestoreClient::class);
        $gateway = new CloudFirestoreGateway($client);

        return [$gateway, $client];
    }

    /**
     * Configura o client mock para que `collection($name)` devolva um
     * CollectionReference mockado, e devolve ambos para asserções.
     *
     * @return array{0: MockInterface&CollectionReference, 1: MockInterface&DocumentReference}
     */
    private function mockCollectionAndDocument(MockInterface $client, string $collection, string $id): array
    {
        $docRef = Mockery::mock(DocumentReference::class);
        $colRef = Mockery::mock(CollectionReference::class);

        $client->shouldReceive('collection')
            ->with($collection)
            ->andReturn($colRef);

        $colRef->shouldReceive('document')
            ->with($id)
            ->andReturn($docRef);

        return [$colRef, $docRef];
    }

    /*
    |--------------------------------------------------------------------------
    | Fora de transação — escritas vão ao $ref
    |--------------------------------------------------------------------------
    */

    public function test_set_document_outside_transaction_calls_ref_set_directly(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $docRef->shouldReceive('set')
            ->once()
            ->with(Mockery::on(fn ($data) => $data['amount'] === 10.0));

        $gateway->setDocument('tx', 'doc1', ['amount' => 10.0]);
    }

    public function test_merge_document_outside_transaction_calls_ref_set_with_merge(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $docRef->shouldReceive('set')
            ->once()
            ->with(Mockery::any(), ['merge' => true]);

        $gateway->mergeDocument('tx', 'doc1', ['amount' => 10.0]);
    }

    public function test_update_fields_outside_transaction_calls_ref_update(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $docRef->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function ($updates) {
                return is_array($updates)
                    && $updates[0]['path'] === 'sync_status'
                    && $updates[0]['value'] === 'synced';
            }));

        $gateway->updateFields('tx', 'doc1', ['sync_status' => 'synced']);
    }

    public function test_delete_document_outside_transaction_calls_ref_delete(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $docRef->shouldReceive('delete')->once();

        $gateway->deleteDocument('tx', 'doc1');
    }

    /*
    |--------------------------------------------------------------------------
    | Dentro de transação — escritas vão ao $tx, NÃO ao $ref
    |--------------------------------------------------------------------------
    */

    public function test_set_document_inside_transaction_calls_tx_set_not_ref_set(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $tx = $this->expectTransactionRun($client);

        // CRÍTICO: $ref->set() NÃO deve ser chamado dentro da transação.
        $docRef->shouldNotReceive('set');

        // $tx->set() deve ser chamado exatamente uma vez.
        $tx->shouldReceive('set')
            ->once()
            ->with(Mockery::on(fn ($ref) => $ref === $docRef), Mockery::any());

        $gateway->transaction(function (CloudFirestoreGateway $gw) {
            $gw->setDocument('tx', 'doc1', ['amount' => 10.0]);
        });
    }

    public function test_merge_document_inside_transaction_calls_tx_set_with_merge(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $tx = $this->expectTransactionRun($client);

        $docRef->shouldNotReceive('set');

        $tx->shouldReceive('set')
            ->once()
            ->with(
                Mockery::on(fn ($ref) => $ref === $docRef),
                Mockery::any(),
                ['merge' => true],
            );

        $gateway->transaction(function (CloudFirestoreGateway $gw) {
            $gw->mergeDocument('tx', 'doc1', ['amount' => 10.0]);
        });
    }

    public function test_update_fields_inside_transaction_calls_tx_update_not_ref_update(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $tx = $this->expectTransactionRun($client);

        $docRef->shouldNotReceive('update');

        $tx->shouldReceive('update')
            ->once()
            ->with(Mockery::on(fn ($ref) => $ref === $docRef), Mockery::any());

        $gateway->transaction(function (CloudFirestoreGateway $gw) {
            $gw->updateFields('tx', 'doc1', ['sync_status' => 'synced']);
        });
    }

    public function test_delete_document_inside_transaction_calls_tx_delete_not_ref_delete(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $tx = $this->expectTransactionRun($client);

        $docRef->shouldNotReceive('delete');

        $tx->shouldReceive('delete')
            ->once()
            ->with(Mockery::on(fn ($ref) => $ref === $docRef));

        $gateway->transaction(function (CloudFirestoreGateway $gw) {
            $gw->deleteDocument('tx', 'doc1');
        });
    }

    public function test_get_document_inside_transaction_calls_tx_snapshot_not_ref_snapshot(): void
    {
        [$gateway, $client] = $this->makeGateway();
        [, $docRef] = $this->mockCollectionAndDocument($client, 'tx', 'doc1');

        $tx = $this->expectTransactionRun($client);

        // O snapshot deve vir do $tx, NÃO do $ref.
        $docRef->shouldNotReceive('snapshot');

        $snap = Mockery::mock(DocumentSnapshot::class);
        $snap->shouldReceive('exists')->andReturn(true);
        $snap->shouldReceive('data')->andReturn(['amount' => 10.0]);

        $tx->shouldReceive('snapshot')
            ->once()
            ->with(Mockery::on(fn ($ref) => $ref === $docRef))
            ->andReturn($snap);

        $result = $gateway->transaction(fn (CloudFirestoreGateway $gw) => $gw->getDocument('tx', 'doc1'));

        $this->assertSame(['amount' => 10.0], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers internos
    |--------------------------------------------------------------------------
    */

    /**
     * Configura o client para que `runTransaction(callable)` invoque a
     * closure passando um Transaction mockado — simulando o SDK real sem
     * tocar rede. Devolve o Transaction mock para configuração posterior.
     */
    private function expectTransactionRun(MockInterface $client): MockInterface
    {
        $tx = Mockery::mock(Transaction::class);

        $client->shouldReceive('runTransaction')
            ->once()
            ->andReturnUsing(function (callable $fn) use ($tx) {
                return $fn($tx);
            });

        return $tx;
    }
}
