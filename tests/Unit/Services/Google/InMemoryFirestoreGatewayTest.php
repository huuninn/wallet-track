<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do fake {@see InMemoryFirestoreGateway} (M5).
 *
 * Este fake é a base dos testes do FirestoreService e do SeedCategories —
 * se a semântica dele estiver errada, todos os testes que dependem dele
 * ficam invalidados. Aqui exercitamos diretamente cada operação do gateway
 * para garantir que o contrato está implementado corretamente:
 *
 *   - create/set/merge/get/delete
 *   - updateFields com paths pontilhados aninhados
 *   - incrementField atômico (e a partir de zero se não existe)
 *   - query com where, orderBy e limit
 *   - transaction que enxerga alterações parciais
 *   - delete inexistente não lança
 *
 * Roda isolado: vendor/bin/phpunit --filter InMemoryFirestoreGatewayTest
 */
#[CoversClass(InMemoryFirestoreGateway::class)]
class InMemoryFirestoreGatewayTest extends TestCase
{
    private InMemoryFirestoreGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gw = new InMemoryFirestoreGateway;
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD básico
    |--------------------------------------------------------------------------
    */

    public function test_create_document_assigns_unique_id(): void
    {
        $id1 = $this->gw->createDocument('tx', ['amount' => 10.0]);
        $id2 = $this->gw->createDocument('tx', ['amount' => 20.0]);

        $this->assertNotSame($id1, $id2);
        $this->assertSame(['amount' => 10.0], $this->gw->getDocument('tx', $id1));
        $this->assertSame(['amount' => 20.0], $this->gw->getDocument('tx', $id2));
    }

    public function test_set_document_overwrites_existing(): void
    {
        $this->gw->setDocument('cats', 'food', ['name' => 'A', 'extra' => 'keep']);
        $this->gw->setDocument('cats', 'food', ['name' => 'B']);

        // Overwrite total — 'extra' desaparece.
        $this->assertSame(['name' => 'B'], $this->gw->getDocument('cats', 'food'));
    }

    public function test_merge_document_preserves_other_fields(): void
    {
        $this->gw->setDocument('cats', 'food', ['name' => 'A', 'count' => 1]);
        $this->gw->mergeDocument('cats', 'food', ['count' => 2]);

        $this->assertSame(['name' => 'A', 'count' => 2], $this->gw->getDocument('cats', 'food'));
    }

    public function test_merge_document_creates_if_absent(): void
    {
        $this->gw->mergeDocument('cats', 'new', ['name' => 'X']);

        $this->assertSame(['name' => 'X'], $this->gw->getDocument('cats', 'new'));
    }

    public function test_get_document_returns_null_when_absent(): void
    {
        $this->assertNull($this->gw->getDocument('cats', 'ghost'));
    }

    public function test_delete_document_removes(): void
    {
        $this->gw->setDocument('cats', 'food', ['name' => 'A']);
        $this->gw->deleteDocument('cats', 'food');

        $this->assertNull($this->gw->getDocument('cats', 'food'));
    }

    public function test_delete_document_is_idempotent_when_absent(): void
    {
        // Não lança exceção quando o doc não existe.
        $this->gw->deleteDocument('cats', 'never-existed');

        // Mantém ausente.
        $this->assertNull($this->gw->getDocument('cats', 'never-existed'));
    }

    /*
    |--------------------------------------------------------------------------
    | updateFields (com paths pontilhados)
    |--------------------------------------------------------------------------
    */

    public function test_update_fields_sets_individual_fields(): void
    {
        $this->gw->setDocument('tx', '1', ['status' => 'pending', 'amount' => 10.0]);
        $this->gw->updateFields('tx', '1', ['status' => 'synced']);

        $this->assertSame(
            ['status' => 'synced', 'amount' => 10.0],
            $this->gw->getDocument('tx', '1'),
        );
    }

    public function test_update_fields_supports_dotted_paths(): void
    {
        $this->gw->setDocument('tx', '1', ['nested' => ['a' => 1, 'b' => 2]]);
        $this->gw->updateFields('tx', '1', ['nested.a' => 99]);

        $data = $this->gw->getDocument('tx', '1');
        $this->assertSame(['a' => 99, 'b' => 2], $data['nested']);
    }

    public function test_update_fields_creates_doc_if_absent(): void
    {
        $this->gw->updateFields('tx', 'new', ['status' => 'pending']);

        $this->assertSame(['status' => 'pending'], $this->gw->getDocument('tx', 'new'));
    }

    /*
    |--------------------------------------------------------------------------
    | incrementField
    |--------------------------------------------------------------------------
    */

    public function test_increment_field_adds_amount(): void
    {
        $this->gw->setDocument('labels', 'food', ['use_count' => 5]);
        $this->gw->incrementField('labels', 'food', 'use_count', 3);

        $this->assertSame(8, $this->gw->getDocument('labels', 'food')['use_count']);
    }

    public function test_increment_field_starts_from_zero_when_absent(): void
    {
        $this->gw->incrementField('labels', 'new', 'use_count');
        $this->gw->incrementField('labels', 'new', 'use_count');

        $this->assertSame(2, $this->gw->getDocument('labels', 'new')['use_count']);
    }

    public function test_increment_field_resets_non_int_to_zero(): void
    {
        // Robustez: se o campo existir mas não for int (corrupção), parte de 0.
        $this->gw->setDocument('labels', 'broken', ['use_count' => 'not-int']);
        $this->gw->incrementField('labels', 'broken', 'use_count', 1);

        $this->assertSame(1, $this->gw->getDocument('labels', 'broken')['use_count']);
    }

    /*
    |--------------------------------------------------------------------------
    | query
    |--------------------------------------------------------------------------
    */

    public function test_query_filters_by_where(): void
    {
        $this->gw->setDocument('tx', '1', ['chat_id' => 'A', 'amount' => 10.0]);
        $this->gw->setDocument('tx', '2', ['chat_id' => 'B', 'amount' => 20.0]);
        $this->gw->setDocument('tx', '3', ['chat_id' => 'A', 'amount' => 30.0]);

        $results = $this->gw->query('tx', [['field' => 'chat_id', 'op' => '==', 'value' => 'A']]);

        $this->assertCount(2, $results);
        $ids = array_column($results, 'id');
        $this->assertSame(['1', '3'], $ids);
    }

    public function test_query_orders_by_desc_and_asc(): void
    {
        $this->gw->setDocument('tx', '1', ['date' => '2026-06-01']);
        $this->gw->setDocument('tx', '2', ['date' => '2026-06-10']);
        $this->gw->setDocument('tx', '3', ['date' => '2026-06-05']);

        $desc = $this->gw->query('tx', [], [['field' => 'date', 'direction' => 'DESC']]);
        $this->assertSame(['2', '3', '1'], array_column($desc, 'id'));

        $asc = $this->gw->query('tx', [], [['field' => 'date', 'direction' => 'ASC']]);
        $this->assertSame(['1', '3', '2'], array_column($asc, 'id'));
    }

    public function test_query_applies_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->gw->setDocument('tx', (string) $i, ['v' => $i]);
        }

        $results = $this->gw->query('tx', [], [['field' => 'v', 'direction' => 'DESC']], 3);

        $this->assertCount(3, $results);
        $this->assertSame(['5', '4', '3'], array_column($results, 'id'));
    }

    public function test_query_returns_empty_array_when_collection_absent(): void
    {
        $this->assertSame([], $this->gw->query('never'));
    }

    /*
    |--------------------------------------------------------------------------
    | transaction
    |--------------------------------------------------------------------------
    */

    public function test_transaction_sees_intermediate_changes(): void
    {
        $result = $this->gw->transaction(function (InMemoryFirestoreGateway $gw) {
            $gw->setDocument('tx', '1', ['counter' => 0]);
            $gw->incrementField('tx', '1', 'counter');
            $gw->incrementField('tx', '1', 'counter');

            return $gw->getDocument('tx', '1')['counter'];
        });

        $this->assertSame(2, $result);
        $this->assertSame(2, $this->gw->getDocument('tx', '1')['counter']);
    }

    public function test_transaction_returns_closure_value(): void
    {
        $result = $this->gw->transaction(fn (): string => 'hello');

        $this->assertSame('hello', $result);
    }
}
