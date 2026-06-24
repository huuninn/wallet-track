<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Dto\TransactionData;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Google\SheetsGateway;
use App\Services\Google\SheetsService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes da camada de espelhamento Sheets (M6.1/M6.2/M6.3).
 *
 * Estes testes **não tocam a Sheets API real** — constroem o
 * {@see SheetsService} diretamente com um {@see InMemorySheetsGateway} novo em
 * cada teste, o que torna tudo síncrono, rápido e determinístico.
 *
 * Cobertura:
 *   - ensureHeaders: escreve cabeçalho em sheet vazia; é idempotente.
 *   - appendTransaction: monta a row de 8 colunas na ordem correta, com todas
 *     as conversões (data ISO preservada, amount numérico, type map,
 *     labels→vírgula, nulls→vazio) e chama ensureHeaders antes.
 *   - appendTransaction lança InvalidArgumentException com amount/type null.
 *   - formatDate: data inválida → coluna A vazia + Log::warning.
 *   - syncCategories: escreve cabeçalho + 1 linha por categoria (best-effort).
 *
 * Roda isolado: vendor/bin/phpunit --filter SheetsServiceTest
 */
#[CoversClass(SheetsService::class)]
class SheetsServiceTest extends TestCase
{
    private SheetsService $service;

    private InMemorySheetsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemorySheetsGateway;
        $this->service = new SheetsService($this->gateway);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de montagem
    |--------------------------------------------------------------------------
    */

    /**
     * Monta um DTO completo típico para uso nos testes de appendTransaction.
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

    /**
     * O cabeçalho canônico de 8 colunas esperado na linha 1 (F4: sem Origem).
     */
    private function expectedHeaders(): array
    {
        return [
            'Data', 'Descrição', 'Valor', 'Tipo', 'Categoria',
            'Labels', 'ID Firestore', 'Observações',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ensureHeaders
    |--------------------------------------------------------------------------
    */

    public function test_ensure_headers_writes_eight_headers_when_sheet_is_empty(): void
    {
        $this->assertNull($this->gateway->getHeaderRow());

        $this->service->ensureHeaders();

        $this->assertSame($this->expectedHeaders(), $this->gateway->getHeaderRow());
    }

    public function test_ensure_headers_is_idempotent_when_header_already_present(): void
    {
        // Sheet já tem um cabeçalho (diferente do canônico): não deve ser
        // sobrescrito — preserva eventual customização humana da planilha.
        $this->gateway->writeHeaderRow(['Foo', 'Bar']);

        $this->service->ensureHeaders();

        $this->assertSame(['Foo', 'Bar'], $this->gateway->getHeaderRow());
    }

    /*
    |--------------------------------------------------------------------------
    | appendTransaction — mapeamento DTO → row
    |--------------------------------------------------------------------------
    */

    public function test_append_transaction_builds_eight_column_row_in_correct_order(): void
    {
        $this->service->appendTransaction($this->dto(), 'fs-id-123');

        $rows = $this->gateway->rows();

        // Linha 0 = cabeçalho (escrito defensivamente via ensureHeaders);
        // linha 1 = a transação.
        $this->assertCount(2, $rows);
        $this->assertSame($this->expectedHeaders(), $rows[0]);

        $row = $rows[1];

        $this->assertSame('2026-06-15', $row[0]);              // A — Data ISO preservada (spec §4)
        $this->assertSame('Almoço no restaurante', $row[1]);   // B — Descrição
        $this->assertSame(47.50, $row[2]);                     // C — Valor (número)
        $this->assertSame('Despesa', $row[3]);                 // D — Tipo expense→Despesa
        $this->assertSame('Alimentação', $row[4]);             // E — Categoria
        $this->assertSame('almoço, restaurante', $row[5]);     // F — Labels (vírgula)
        $this->assertSame('fs-id-123', $row[6]);               // G — ID Firestore
        $this->assertSame('', $row[7]);                        // H — Observações null→vazio
    }

    public function test_append_transaction_preserves_iso_date(): void
    {
        // Spec §4: "Data: ISO YYYY-MM-DD via API; Sheets formata para
        // DD/MM/AAAA". Enviar ISO é universal (interpretado em qualquer locale).
        $this->service->appendTransaction(
            $this->dto(['date' => '2026-01-09']),
            'fs',
        );

        $this->assertSame('2026-01-09', $this->gateway->rows()[1][0]);
    }

    public function test_append_transaction_amount_is_numeric_not_string(): void
    {
        $this->service->appendTransaction(
            $this->dto(['amount' => 100.0]),
            'fs',
        );

        $value = $this->gateway->rows()[1][2];

        // Deve ser número (float), não string — USER_ENTERED interpreta como número.
        $this->assertIsFloat($value);
        $this->assertSame(100.0, $value);
    }

    /*
    |--------------------------------------------------------------------------
    | formatDate — data inválida (FIX-4)
    |--------------------------------------------------------------------------
    */

    /**
     * Data com mês/dia impossíveis ("2026-13-45") deve resultar em coluna A
     * vazia (não corrompe a linha) + Log::warning para revisão humana.
     */
    public function test_append_transaction_with_invalid_date_logs_warning_and_writes_empty(): void
    {
        $warningSeen = false;

        // Log::listen registra callback no evento MessageLogged disparado
        // pelo logger a cada mensagem. Spy minimalista, sem Mockery.
        Log::listen(function (MessageLogged $event) use (&$warningSeen): void {
            if ($event->level === 'warning'
                && str_contains((string) $event->message, 'formato inválido')) {
                $warningSeen = true;
            }
        });

        $this->service->appendTransaction(
            $this->dto(['date' => '2026-13-45']),
            'fs-invalid',
        );

        $row = $this->gateway->rows()[1];

        // Coluna A fica vazia — não grava o valor cru nem corrompe a linha.
        $this->assertSame('', $row[0]);
        $this->assertTrue($warningSeen, 'Log::warning esperado para data inválida.');
    }

    /**
     * "2026-02-30" (30 de fevereiro — não existe) também é rejeitada pelo
     * round-trip: DateTimeImmutable corrige para 2026-03-02, que difere do
     * input → coluna vazia.
     */
    public function test_append_transaction_with_nonexistent_date_writes_empty(): void
    {
        $this->service->appendTransaction(
            $this->dto(['date' => '2026-02-30']),
            'fs-fev30',
        );

        $this->assertSame('', $this->gateway->rows()[1][0]);
    }

    /**
     * "2026-1-9" (sem zero à esquerra) falha no round-trip: o parser aceita
     * mas re-formata para "2026-01-09", que difere do input → coluna vazia.
     * Garante que só enviamos datas canônicas (estritamente ISO 8601).
     */
    public function test_append_transaction_with_non_canonical_iso_date_writes_empty(): void
    {
        $this->service->appendTransaction(
            $this->dto(['date' => '2026-1-9']),
            'fs-noncanonical',
        );

        $this->assertSame('', $this->gateway->rows()[1][0]);
    }

    public function test_append_transaction_maps_income_type(): void
    {
        $this->service->appendTransaction(
            $this->dto(['type' => 'income']),
            'fs-2',
        );

        $row = $this->gateway->rows()[1];

        $this->assertSame('Receita', $row[3]);  // income→Receita
    }

    public function test_append_transaction_empty_labels_render_as_empty_string(): void
    {
        $this->service->appendTransaction(
            $this->dto(['labels' => []]),
            'fs-3',
        );

        $this->assertSame('', $this->gateway->rows()[1][5]);
    }

    public function test_append_transaction_labels_are_comma_separated(): void
    {
        // Labels são formatados com vírgula + espaço (F4).
        $this->service->appendTransaction(
            $this->dto(['labels' => ['#vip', 'promo']]),
            'fs-4',
        );

        $this->assertSame('#vip, promo', $this->gateway->rows()[1][5]);
    }

    public function test_append_transaction_null_observations_render_as_empty_string(): void
    {
        $this->service->appendTransaction(
            $this->dto(['observations' => null]),
            'fs-5',
        );

        $this->assertSame('', $this->gateway->rows()[1][7]);
    }

    public function test_append_transaction_string_observations_are_preserved(): void
    {
        $this->service->appendTransaction(
            $this->dto(['observations' => 'pago em dinheiro']),
            'fs-6',
        );

        $this->assertSame('pago em dinheiro', $this->gateway->rows()[1][7]);
    }

    public function test_append_transaction_writes_header_before_appending(): void
    {
        // Sheet vazia: appendTransaction deve escrever o cabeçalho primeiro.
        $this->assertNull($this->gateway->getHeaderRow());

        $this->service->appendTransaction($this->dto(), 'fs');

        $rows = $this->gateway->rows();

        $this->assertSame($this->expectedHeaders(), $rows[0]);
        $this->assertCount(2, $rows); // cabeçalho + 1 transação
    }

    public function test_append_transaction_does_not_rewrite_existing_header(): void
    {
        // Cabeçalho já existe: append apenas adiciona a linha de dados.
        $this->gateway->writeHeaderRow($this->expectedHeaders());

        $this->service->appendTransaction($this->dto(), 'fs');

        $rows = $this->gateway->rows();

        $this->assertCount(2, $rows);
        $this->assertSame($this->expectedHeaders(), $rows[0]);
    }

    /*
    |--------------------------------------------------------------------------
    | appendTransaction — guarda de DTO incompleto
    |--------------------------------------------------------------------------
    */

    public function test_append_transaction_throws_when_amount_is_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TransactionData incompleto');

        $this->service->appendTransaction(
            $this->dto(['amount' => null]),
            'fs',
        );
    }

    public function test_append_transaction_throws_when_type_is_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TransactionData incompleto');

        $this->service->appendTransaction(
            $this->dto(['type' => null]),
            'fs',
        );
    }

    public function test_append_transaction_with_null_amount_does_not_write_any_row(): void
    {
        try {
            $this->service->appendTransaction(
                $this->dto(['amount' => null]),
                'fs',
            );
        } catch (\InvalidArgumentException) {
            // esperado
        }

        // Nenhuma linha deve ter sido escrita (nem cabeçalho, nem dados).
        $this->assertSame([], $this->gateway->rows());
    }

    /*
    |--------------------------------------------------------------------------
    | syncCategories
    |--------------------------------------------------------------------------
    */

    public function test_sync_categories_writes_header_and_one_row_per_category(): void
    {
        $this->service->syncCategories([
            ['display_name' => 'Alimentação', 'default_type' => 'expense'],
            ['display_name' => 'Salário', 'default_type' => 'income'],
        ]);

        $ranges = $this->gateway->allRanges();
        $this->assertCount(1, $ranges);

        $range = array_key_first($ranges);
        $this->assertStringStartsWith('Categorias!', $range);

        $rows = $ranges[$range];

        $this->assertSame(['Categoria', 'Tipo padrão'], $rows[0]);
        $this->assertSame(['Alimentação', 'expense'], $rows[1]);
        $this->assertSame(['Salário', 'income'], $rows[2]);
        $this->assertCount(3, $rows); // cabeçalho + 2 categorias
    }

    public function test_sync_categories_range_size_matches_row_count(): void
    {
        $this->service->syncCategories([
            ['display_name' => 'Transporte', 'default_type' => 'expense'],
        ]);

        $range = array_key_first($this->gateway->allRanges());

        // 1 categoria → 2 linhas (cabeçalho + 1) → range A1:B2.
        $this->assertSame('Categorias!A1:B2', $range);
    }

    public function test_sync_categories_is_best_effort_and_does_not_throw(): void
    {
        // Gateway que sempre falha em writeAll: syncCategories deve capturar,
        // logar warning (canal nulo em testes) e NÃO propagar a exceção.
        // (Double anônimo baseado na interface — InMemorySheetsGateway é final.)
        $failingGateway = new class implements SheetsGateway
        {
            public function getHeaderRow(): ?array
            {
                return null;
            }

            public function writeHeaderRow(array $headers): void {}

            public function appendRow(array $row): void {}

            public function deleteColumn(int $sheetId, int $columnIndex): void {}

            public function writeAll(string $range, array $rows): void
            {
                throw new \RuntimeException('aba Categorias não existe');
            }
        };

        $service = new SheetsService($failingGateway);

        $service->syncCategories([
            ['display_name' => 'Teste', 'default_type' => 'expense'],
        ]);

        // Chegou aqui sem lançar.
        $this->addToAssertionCount(1);
    }
}
