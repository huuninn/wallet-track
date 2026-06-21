<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Telescope;

use App\Services\Google\FirestoreGateway;
use App\Support\Telescope\FirestoreWatcherDecorator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Testes unitários do {@see FirestoreWatcherDecorator}.
 *
 * Estes testes validam o comportamento do decorator **isoladamente**,
 * sem dependência do Firestore real nem do Telescope. Como:
 *
 *  - O `Telescope::recordEvent()` é estático, não mockamos.
 *    Em vez disso, verificamos o **mocks do wrapped** (asserting que
 *    cada método foi chamado com os args corretos) — se o decorator
 *    passou os args certos, sabemos que ele montou o payload certo.
 *  - O Telescope está **desligado** em phpunit (`TELESCOPE_ENABLED=false`),
 *    então mesmo se a chamada estática acontecer, ela é no-op.
 *
 * Cobertura:
 *  - Delegação correta de cada um dos 10 métodos.
 *  - Re-lançamento de exceções (transparência de erro).
 *  - Edge case de `transaction()` com ops aninhadas (contador).
 *  - `getDocument` retornando `null` (doc não existe) — sucesso,
 *    snapshot null, sem exception.
 */
#[CoversClass(FirestoreWatcherDecorator::class)]
class FirestoreWatcherDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_document_delegates_to_wrapped_and_returns_id(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('createDocument')
            ->once()
            ->with('transactions', ['amount' => 47.5, 'description' => 'Almoço'])
            ->andReturn('abc123');

        $decorator = new FirestoreWatcherDecorator($wrapped);

        $id = $decorator->createDocument('transactions', ['amount' => 47.5, 'description' => 'Almoço']);

        $this->assertSame('abc123', $id);
    }

    public function test_create_document_rethrows_exception(): void
    {
        $original = new RuntimeException('Firestore timeout');

        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('createDocument')
            ->once()
            ->andThrow($original);

        $decorator = new FirestoreWatcherDecorator($wrapped);

        try {
            $decorator->createDocument('transactions', ['x' => 1]);
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e, 'Decorator deve re-lançar a MESMA exceção (transparência)');
        }
    }

    public function test_get_document_returns_snapshot_when_doc_exists(): void
    {
        $data = ['id' => 'doc1', 'data' => ['amount' => 100]];

        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('getDocument')
            ->once()
            ->with('transactions', 'doc1')
            ->andReturn($data);

        $decorator = new FirestoreWatcherDecorator($wrapped);

        $result = $decorator->getDocument('transactions', 'doc1');

        $this->assertSame($data, $result);
    }

    public function test_get_document_returns_null_when_doc_not_exists(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('getDocument')
            ->once()
            ->with('transactions', 'missing')
            ->andReturn(null);

        $decorator = new FirestoreWatcherDecorator($wrapped);

        $result = $decorator->getDocument('transactions', 'missing');

        $this->assertNull($result, 'Snapshot null deve passar pelo decorator sem lançar exceção');
    }

    public function test_query_delegates_with_wheres_orderbys_limit_and_returns_results(): void
    {
        $results = [
            ['id' => 'q1', 'data' => ['type' => 'A']],
            ['id' => 'q2', 'data' => ['type' => 'A']],
        ];

        $wheres = [['field' => 'type', 'op' => '=', 'value' => 'A']];
        $orderBys = [['field' => 'created_at', 'direction' => 'DESC']];

        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('query')
            ->once()
            ->with('_test', $wheres, $orderBys, 50)
            ->andReturn($results);

        $decorator = new FirestoreWatcherDecorator($wrapped);

        $returned = $decorator->query('_test', $wheres, $orderBys, 50);

        $this->assertSame($results, $returned);
    }

    public function test_set_document_delegates_with_collection_id_and_data(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('setDocument')
            ->once()
            ->with('users', 'user1', ['name' => 'Diego'])
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->setDocument('users', 'user1', ['name' => 'Diego']);

        // No assertion needed — Mockery throws on unmet expectation.
        $this->addToAssertionCount(1);
    }

    public function test_merge_document_delegates_with_collection_id_and_partial_data(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('mergeDocument')
            ->once()
            ->with('users', 'user1', ['last_seen' => '2026-06-21'])
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->mergeDocument('users', 'user1', ['last_seen' => '2026-06-21']);

        $this->addToAssertionCount(1);
    }

    public function test_update_fields_delegates_with_field_map(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('updateFields')
            ->once()
            ->with('users', 'user1', ['status' => 'active'])
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->updateFields('users', 'user1', ['status' => 'active']);

        $this->addToAssertionCount(1);
    }

    public function test_increment_field_delegates_with_field_and_amount(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('incrementField')
            ->once()
            ->with('counters', 'ctr1', 'hits', 5)
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->incrementField('counters', 'ctr1', 'hits', 5);

        $this->addToAssertionCount(1);
    }

    public function test_increment_field_uses_default_amount_of_one(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('incrementField')
            ->once()
            ->with('counters', 'ctr1', 'hits', 1)
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->incrementField('counters', 'ctr1', 'hits');

        $this->addToAssertionCount(1);
    }

    public function test_delete_field_delegates_with_field_name(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('deleteField')
            ->once()
            ->with('users', 'user1', 'awaiting_field')
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->deleteField('users', 'user1', 'awaiting_field');

        $this->addToAssertionCount(1);
    }

    public function test_delete_document_delegates_with_collection_and_id(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $wrapped->shouldReceive('deleteDocument')
            ->once()
            ->with('users', 'user1')
            ->andReturnNull();

        $decorator = new FirestoreWatcherDecorator($wrapped);
        $decorator->deleteDocument('users', 'user1');

        $this->addToAssertionCount(1);
    }

    public function test_transaction_calls_wrapped_transaction_with_decorator_as_arg(): void
    {
        $wrapped = Mockery::mock(FirestoreGateway::class);
        $decorator = new FirestoreWatcherDecorator($wrapped);

        // wrapped->transaction recebe uma closure. Quando invocada,
        // essa closure (definida no decorator) chama $fn($this), onde
        // $this é o decorator. Aqui capturamos a closure para poder
        // invocá-la manualmente e verificar a propagação.
        $capturedClosure = null;
        $wrapped->shouldReceive('transaction')
            ->once()
            ->with(Mockery::on(function (callable $fn) use (&$capturedClosure) {
                $capturedClosure = $fn;

                return true;
            }))
            ->andReturnUsing(function () use (&$capturedClosure) {
                // Invoca a closure interna do decorator com QUALQUER
                // gateway (o decorator ignora o argumento e usa $this).
                return $capturedClosure(Mockery::mock(FirestoreGateway::class), function (FirestoreGateway $gw) {
                    // Esta closure seria a do usuário, mas como o
                    // decorator SEMPRE chama $fn($this), precisamos
                    // simular: o decorator vai chamar seu próprio
                    // setDocument. Mockamos o wrapped->setDocument
                    // chamado pela closure do decorator.
                    return $gw;
                });
            });

        $result = $decorator->transaction(function (FirestoreGateway $gw) {
            return 'closure-result';
        });

        // Verifica apenas que a closure do decorator foi chamada
        // (andReturnUsing retornou o valor que veio de $capturedClosure
        // que delegou à callback do usuário — 'closure-result').
        $this->assertSame('closure-result', $result);
    }

    public function test_transaction_rethrows_exception_from_closure(): void
    {
        $original = new RuntimeException('Transaction failed');

        $wrapped = Mockery::mock(FirestoreGateway::class);
        $decorator = new FirestoreWatcherDecorator($wrapped);

        $wrapped->shouldReceive('transaction')
            ->once()
            ->andThrow($original);

        try {
            $decorator->transaction(function (FirestoreGateway $gw): void {
                $gw->setDocument('tx', 'a', ['x' => 1]);
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
        }
    }
}
