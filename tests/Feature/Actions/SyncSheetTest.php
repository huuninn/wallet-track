<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\SyncSheet;
use App\Dto\TransactionData;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Google\SheetsGateway;
use App\Services\Google\SheetsService;
use App\Services\Store\WalletStore;
use App\Support\ItemsSorter;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes da action {@see SyncSheet} (M6.4/M6.5).
 *
 * Orquestra {@see SheetsService} + {@see WalletStore} usando gateway
 * Sheets in-memory + banco de dados via RefreshDatabase. **Não toca a Sheets API
 * nem rede** — tudo roda em arrays PHP em memória e SQLite.
 *
 * Cobertura:
 *   - Fluxo feliz: append OK → banco de dados sync_status=synced, retorna true,
 *     e a linha está na gateway de Sheets.
 *   - Falha: gateway lança exceção → banco de dados sync_status=failed com
 *     sync_error_message + sync_attempts incrementado, retorna false, e a
 *     exceção NÃO é relançada.
 *
 * Roda isolado: vendor/bin/phpunit --filter SyncSheetTest
 */
#[CoversClass(SyncSheet::class)]
class SyncSheetTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private InMemorySheetsGateway $sheetsGateway;

    private WalletStore $store;

    private SyncSheet $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheetsGateway = new InMemorySheetsGateway;

        $sheetsService = new SheetsService($this->sheetsGateway, new ItemsSorter);
        $this->setUpWalletStore();

        $this->action = new SyncSheet($sheetsService, $this->store);
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
        $txId = $this->store->saveTransaction('C1', $dto);

        $result = $this->action->handle($dto, $txId);

        $this->assertTrue($result);

        // A linha da transação está na planilha (cabeçalho + 1 dado).
        $rows = $this->sheetsGateway->rows();
        $this->assertCount(2, $rows);

        $row = $rows[1];
        $this->assertSame('2026-06-15', $row[0]);              // A — Data ISO (spec §4)
        $this->assertSame('Almoço no restaurante', $row[1]);
        $this->assertSame(47.50, $row[2]);
        $this->assertSame('Despesa', $row[3]);
        // F4: coluna Origem removida — ID está em G (índice 6).
        $this->assertSame((string) $txId, $row[6]);

        // banco de dados marcado como synced, sem erro, sem incremento de tentativas.
        $stored = $this->store->getTransaction($txId);
        $this->assertSame(WalletStore::SYNC_SYNCED, $stored->sync_status);
        $this->assertNull($stored->sync_error_message);
        $this->assertSame(0, $stored->sync_attempts);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluxo de falha
    |--------------------------------------------------------------------------
    */

    public function test_sync_marks_failed_with_error_and_attempts_and_does_not_rethrow(): void
    {
        $dto = $this->dto();
        $txId = $this->store->saveTransaction('C1', $dto);

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
        $action = new SyncSheet($failingSheets, $this->store);

        // Não deve relançar.
        $result = $action->handle($dto, $txId);

        $this->assertFalse($result);

        $stored = $this->store->getTransaction($txId);
        $this->assertSame(WalletStore::SYNC_FAILED, $stored->sync_status);
        $this->assertSame('Sheets API offline (503)', $stored->sync_error_message);
        $this->assertSame(1, $stored->sync_attempts);
    }

    public function test_sync_failure_repeated_increments_attempts_each_time(): void
    {
        $dto = $this->dto();
        $txId = $this->store->saveTransaction('C1', $dto);

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
        $action = new SyncSheet($failingSheets, $this->store);

        $action->handle($dto, $txId);
        $action->handle($dto, $txId);
        $action->handle($dto, $txId);

        // Após 3 tentativas falhas: sync_attempts=3. (Notificação ao usuário
        // após 3 falhas é responsabilidade do M9 — aqui só confirmamos o
        // contador que o M9 vai ler.)
        $stored = $this->store->getTransaction($txId);
        $this->assertSame(WalletStore::SYNC_FAILED, $stored->sync_status);
        $this->assertSame(3, $stored->sync_attempts);
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
        $txId = $this->store->saveTransaction('C1', $this->dto());

        // Mesmo que o gateway esteja saudável, a action PROPAGA a exceção.
        $caught = null;
        try {
            $this->action->handle($incompleteDto, $txId);
        } catch (\InvalidArgumentException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'InvalidArgumentException deve propagar.');
        $this->assertStringContainsString('TransactionData incompleto', $caught->getMessage());

        // E NÃO marca sync_status=failed nem incrementa sync_attempts — bug
        // de programação não conta como tentativa de sync.
        $stored = $this->store->getTransaction($txId);
        $this->assertSame(WalletStore::SYNC_PENDING, $stored->sync_status);
        $this->assertNull($stored->sync_error_message);
        $this->assertSame(0, $stored->sync_attempts);
    }

    /**
     * RuntimeException (rede/timeout) também é tratada como falha esperada
     * de I/O — mesma categoria que GoogleServiceException.
     */
    public function test_sync_marks_failed_when_runtime_exception_occurs(): void
    {
        $dto = $this->dto();
        $txId = $this->store->saveTransaction('C1', $dto);

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
        $action = new SyncSheet($failingSheets, $this->store);

        $result = $action->handle($dto, $txId);

        $this->assertFalse($result);

        $stored = $this->store->getTransaction($txId);
        $this->assertSame(WalletStore::SYNC_FAILED, $stored->sync_status);
        $this->assertSame('connection timeout', $stored->sync_error_message);
        $this->assertSame(1, $stored->sync_attempts);
    }
}
