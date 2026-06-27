<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\SyncSheet;
use App\Dto\TransactionData;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Google\SheetsGateway;
use App\Services\Google\SheetsService;
use App\Support\ItemsSorter;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes da action {@see SyncSheet} (M6.4/M6.5).
 *
 * Orquestra {@see SheetsService} + {@see FirestoreService} usando as duas
 * gateways in-memory (Sheets + Firestore). **Não toca a Sheets API nem o
 * Firestore real** — tudo roda em arrays PHP em memória.
 *
 * Cobertura:
 *   - Fluxo feliz: append OK → Firestore sync_status=synced, retorna true,
 *     e a linha está na gateway de Sheets.
 *   - Falha: gateway lança exceção → Firestore sync_status=failed com
 *     sync_error_message + sync_attempts incrementado, retorna false, e a
 *     exceção NÃO é relançada.
 *
 * Roda isolado: vendor/bin/phpunit --filter SyncSheetTest
 */
#[CoversClass(SyncSheet::class)]
class SyncSheetTest extends TestCase
{
    private InMemorySheetsGateway $sheetsGateway;

    private InMemoryFirestoreGateway $firestoreGateway;

    private FirestoreService $firestore;

    private SyncSheet $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheetsGateway = new InMemorySheetsGateway;
        $this->firestoreGateway = new InMemoryFirestoreGateway;

        $sheetsService = new SheetsService($this->sheetsGateway, new ItemsSorter);
        $this->firestore = new FirestoreService($this->firestoreGateway);

        $this->action = new SyncSheet($sheetsService, $this->firestore);
    }

    /**
     * Monta um DTO completo típico.
     */
    private function dto(array $override = []): TransactionData
    {
        return TransactionData::fromArray(array_merge([
            'description' => 'Almoço no restaurante',
            'amount' => 47.50,
            'type' => 'expense',
            'category' => 'Alimentação',
            'labels' => ['almoço', 'restaurante'],
            'date' => '2026-06-15',
            'observations' => null,
        ], $override));
    }

    /*
    |--------------------------------------------------------------------------
    | Fluxo feliz
    |--------------------------------------------------------------------------
    */

    public function test_sync_appends_row_and_marks_transaction_synced_on_success(): void
    {
        $dto = $this->dto();
        $firestoreId = $this->firestore->saveTransaction('C1', $dto);

        $result = $this->action->handle($dto, $firestoreId);

        $this->assertTrue($result);

        // A linha da transação está na planilha (cabeçalho + 1 dado).
        $rows = $this->sheetsGateway->rows();
        $this->assertCount(2, $rows);

        $row = $rows[1];
        $this->assertSame('2026-06-15', $row[0]);              // A — Data ISO (spec §4)
        $this->assertSame('Almoço no restaurante', $row[1]);
        $this->assertSame(47.50, $row[2]);
        $this->assertSame('Despesa', $row[3]);
        // F4: coluna Origem removida — ID Firestore está em G (índice 6).
        $this->assertSame($firestoreId, $row[6]);

        // Firestore marcado como synced, sem erro, sem incremento de tentativas.
        $stored = $this->firestore->getTransaction($firestoreId);
        $this->assertSame(FirestoreService::SYNC_SYNCED, $stored['sync_status']);
        $this->assertNull($stored['sync_error_message']);
        $this->assertSame(0, $stored['sync_attempts']);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluxo de falha
    |--------------------------------------------------------------------------
    */

    public function test_sync_marks_failed_with_error_and_attempts_and_does_not_rethrow(): void
    {
        $dto = $this->dto();
        $firestoreId = $this->firestore->saveTransaction('C1', $dto);

        // Gateway de Sheets que sempre falha ao appendar com
        // GoogleServiceException (erro HTTP da API Sheets — 403/404/429/500).
        $failingSheets = new SheetsService(new class implements SheetsGateway
        {
            public function getHeaderRow(): ?array
            {
                return null;
            }

            public function writeHeaderRow(array $headers): void {}

            public function appendRow(array $row): void
            {
                throw new GoogleServiceException('Sheets API offline (503)');
            }

            public function deleteColumn(int $sheetId, int $columnIndex): void {}

            public function writeAll(string $range, array $rows): void {}
        }, new ItemsSorter);
        $action = new SyncSheet($failingSheets, $this->firestore);

        // Não deve relançar.
        $result = $action->handle($dto, $firestoreId);

        $this->assertFalse($result);

        $stored = $this->firestore->getTransaction($firestoreId);
        $this->assertSame(FirestoreService::SYNC_FAILED, $stored['sync_status']);
        $this->assertSame('Sheets API offline (503)', $stored['sync_error_message']);
        $this->assertSame(1, $stored['sync_attempts']);
    }

    public function test_sync_failure_repeated_increments_attempts_each_time(): void
    {
        $dto = $this->dto();
        $firestoreId = $this->firestore->saveTransaction('C1', $dto);

        $failingSheets = new SheetsService(new class implements SheetsGateway
        {
            public function getHeaderRow(): ?array
            {
                return null;
            }

            public function writeHeaderRow(array $headers): void {}

            public function appendRow(array $row): void
            {
                throw new GoogleServiceException('quota excedida (429)');
            }

            public function deleteColumn(int $sheetId, int $columnIndex): void {}

            public function writeAll(string $range, array $rows): void {}
        }, new ItemsSorter);
        $action = new SyncSheet($failingSheets, $this->firestore);

        $action->handle($dto, $firestoreId);
        $action->handle($dto, $firestoreId);
        $action->handle($dto, $firestoreId);

        // Após 3 tentativas falhas: sync_attempts=3. (Notificação ao usuário
        // após 3 falhas é responsabilidade do M9 — aqui só confirmamos o
        // contador que o M9 vai ler.)
        $stored = $this->firestore->getTransaction($firestoreId);
        $this->assertSame(FirestoreService::SYNC_FAILED, $stored['sync_status']);
        $this->assertSame(3, $stored['sync_attempts']);
    }

    /**
     * Bug de programação (DTO incompleto) NÃO deve ser mascarado como falha
     * de sync — precisa propagar para o caller, senão polui o contador de
     * retentativas (lido pelo M9 para notificar o usuário).
     */
    public function test_sync_propagates_invalid_argument_exception_from_incomplete_dto(): void
    {
        // DTO incompleto: appendTransaction lança InvalidArgumentException.
        $incompleteDto = $this->dto(['amount' => null, 'type' => null]);
        $firestoreId = $this->firestore->saveTransaction('C1', $this->dto());

        // Mesmo que o gateway esteja saudável, a action PROPAGA a exceção.
        $caught = null;
        try {
            $this->action->handle($incompleteDto, $firestoreId);
        } catch (\InvalidArgumentException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'InvalidArgumentException deve propagar.');
        $this->assertStringContainsString('TransactionData incompleto', $caught->getMessage());

        // E NÃO marca sync_status=failed nem incrementa sync_attempts — bug
        // de programação não conta como tentativa de sync.
        $stored = $this->firestore->getTransaction($firestoreId);
        $this->assertSame(FirestoreService::SYNC_PENDING, $stored['sync_status']);
        $this->assertNull($stored['sync_error_message']);
        $this->assertSame(0, $stored['sync_attempts']);
    }

    /**
     * RuntimeException (rede/timeout) também é tratada como falha esperada
     * de I/O — mesma categoria que GoogleServiceException.
     */
    public function test_sync_marks_failed_when_runtime_exception_occurs(): void
    {
        $dto = $this->dto();
        $firestoreId = $this->firestore->saveTransaction('C1', $dto);

        $failingSheets = new SheetsService(new class implements SheetsGateway
        {
            public function getHeaderRow(): ?array
            {
                return null;
            }

            public function writeHeaderRow(array $headers): void {}

            public function appendRow(array $row): void
            {
                throw new \RuntimeException('connection timeout');
            }

            public function deleteColumn(int $sheetId, int $columnIndex): void {}

            public function writeAll(string $range, array $rows): void {}
        }, new ItemsSorter);
        $action = new SyncSheet($failingSheets, $this->firestore);

        $result = $action->handle($dto, $firestoreId);

        $this->assertFalse($result);

        $stored = $this->firestore->getTransaction($firestoreId);
        $this->assertSame(FirestoreService::SYNC_FAILED, $stored['sync_status']);
        $this->assertSame('connection timeout', $stored['sync_error_message']);
        $this->assertSame(1, $stored['sync_attempts']);
    }
}
