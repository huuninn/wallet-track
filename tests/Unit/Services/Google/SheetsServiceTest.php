<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Dto\TransactionData;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Google\SheetsGateway;
use App\Services\Google\SheetsService;
use App\Support\ItemsSorter;
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
 *   - appendTransaction: monta a row de 9 colunas na ordem correta, com todas
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
        $this->service = new SheetsService($this->gateway, new ItemsSorter);
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
     * O cabeçalho canônico de 9 colunas esperado na linha 1.
     */
    private function expectedHeaders(): array
    {
        return [
            'Data', 'Descrição', 'Valor', 'Tipo', 'Categoria',
            'Labels', 'ID Transação', 'Observações', 'Itens',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ensureHeaders
    |--------------------------------------------------------------------------
    */

    public function test_ensure_headers_writes_nine_headers_when_sheet_is_empty(): void
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

    public function test_append_transaction_builds_nine_column_row_in_correct_order(): void
    {
        $this->service->appendTransaction($this->dto(), 123);

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
        $this->assertSame('123', $row[6]);                     // G — ID Transação (convertido para string)
        $this->assertSame('', $row[7]);                        // H — Observações null→vazio
        $this->assertSame('', $row[8]);                        // I — Itens (vazio quando items=[])
    }

    public function test_append_transaction_preserves_iso_date(): void
    {
        // Spec §4: "Data: ISO YYYY-MM-DD via API; Sheets formata para
        // DD/MM/AAAA". Enviar ISO é universal (interpretado em qualquer locale).
        $this->service->appendTransaction(
            $this->dto(['date' => '2026-01-09']),
            1,
        );

        $this->assertSame('2026-01-09', $this->gateway->rows()[1][0]);
    }

    public function test_append_transaction_amount_is_numeric_not_string(): void
    {
        $this->service->appendTransaction(
            $this->dto(['amount' => 100.0]),
            2,
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
            3,
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
            4,
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
            5,
        );

        $this->assertSame('', $this->gateway->rows()[1][0]);
    }

    public function test_append_transaction_maps_income_type(): void
    {
        $this->service->appendTransaction(
            $this->dto(['type' => 'income']),
            6,
        );

        $row = $this->gateway->rows()[1];

        $this->assertSame('Receita', $row[3]);  // income→Receita
    }

    public function test_append_transaction_empty_labels_render_as_empty_string(): void
    {
        $this->service->appendTransaction(
            $this->dto(['labels' => []]),
            7,
        );

        $this->assertSame('', $this->gateway->rows()[1][5]);
    }

    public function test_append_transaction_labels_are_comma_separated(): void
    {
        // Labels são formatados com vírgula + espaço (F4).
        $this->service->appendTransaction(
            $this->dto(['labels' => ['#vip', 'promo']]),
            8,
        );

        $this->assertSame('#vip, promo', $this->gateway->rows()[1][5]);
    }

    public function test_append_transaction_sanitizes_formula_injection_in_labels(): void
    {
        // Labels começando com =, +, -, @ devem ser prefixadas com apóstrofo
        // (CWE-1236: prevenção de CSV/formula injection no Google Sheets).
        $this->service->appendTransaction(
            $this->dto(['labels' => ['=SUM(A1:A10)', '+malicious', '-drop', '@mention']]),
            9,
        );

        $this->assertSame(
            "'=SUM(A1:A10), '+malicious, '-drop, '@mention",
            $this->gateway->rows()[1][5],
        );
    }

    public function test_append_transaction_null_observations_render_as_empty_string(): void
    {
        $this->service->appendTransaction(
            $this->dto(['observations' => null]),
            10,
        );

        $this->assertSame('', $this->gateway->rows()[1][7]);
    }

    public function test_append_transaction_string_observations_are_preserved(): void
    {
        $this->service->appendTransaction(
            $this->dto(['observations' => 'pago em dinheiro']),
            11,
        );

        $this->assertSame('pago em dinheiro', $this->gateway->rows()[1][7]);
    }

    public function test_append_transaction_writes_header_before_appending(): void
    {
        // Sheet vazia: appendTransaction deve escrever o cabeçalho primeiro.
        $this->assertNull($this->gateway->getHeaderRow());

        $this->service->appendTransaction($this->dto(), 12);

        $rows = $this->gateway->rows();

        $this->assertSame($this->expectedHeaders(), $rows[0]);
        $this->assertCount(2, $rows); // cabeçalho + 1 transação

        // Coluna G: ID da transação como string (buildRow faz (string) $txId).
        $this->assertSame('12', $rows[1][6]);
    }

    public function test_append_transaction_does_not_rewrite_existing_header(): void
    {
        // Cabeçalho já existe: append apenas adiciona a linha de dados.
        $this->gateway->writeHeaderRow($this->expectedHeaders());

        $this->service->appendTransaction($this->dto(), 13);

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
            14,
        );
    }

    public function test_append_transaction_throws_when_type_is_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TransactionData incompleto');

        $this->service->appendTransaction(
            $this->dto(['type' => null]),
            15,
        );
    }

    public function test_append_transaction_with_null_amount_does_not_write_any_row(): void
    {
        try {
            $this->service->appendTransaction(
                $this->dto(['amount' => null]),
                16,
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

        $service = new SheetsService($failingGateway, new ItemsSorter);

        $service->syncCategories([
            ['display_name' => 'Teste', 'default_type' => 'expense'],
        ]);

        // Chegou aqui sem lançar.
        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Items — coluna I (M-ITENS-2)
    |--------------------------------------------------------------------------
    */

    public function test_build_row_includes_items_column_i(): void
    {
        $dto = $this->dto([
            'items' => [
                ['name' => 'Arroz', 'qty' => 2, 'unitPrice' => 32.90, 'subtotal' => 65.80],
            ],
        ]);

        $this->service->appendTransaction($dto, 456);

        $row = $this->gateway->rows()[1];
        $this->assertCount(9, $row);
        $this->assertStringContainsString('Arroz', $row[8]);
    }

    public function test_format_items_empty_returns_empty_string(): void
    {
        $dto = $this->dto(['items' => []]);

        $this->service->appendTransaction($dto, 457);

        $row = $this->gateway->rows()[1];
        $this->assertSame('', $row[8]);
    }

    public function test_format_items_single_complete_item(): void
    {
        $dto = $this->dto([
            'items' => [
                ['name' => 'Arroz', 'qty' => 2, 'unitPrice' => 32.90, 'subtotal' => 65.80],
            ],
        ]);

        $this->service->appendTransaction($dto, 458);

        $row = $this->gateway->rows()[1];
        // Formato: "1. Arroz (x2 — R$ 32,90 = R$ 65,80)"
        $this->assertSame(
            "1. Arroz (x2 \u{2014} R\$ 32,90 = R\$ 65,80)",
            $row[8],
        );
    }

    public function test_format_items_single_name_only(): void
    {
        $dto = $this->dto([
            'items' => [
                ['name' => 'Detergente', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);

        $this->service->appendTransaction($dto, 459);

        $row = $this->gateway->rows()[1];
        $this->assertSame('1. Detergente', $row[8]);
    }

    public function test_format_items_decimal_qty_uses_comma(): void
    {
        $dto = $this->dto([
            'items' => [
                ['name' => 'Queijo', 'qty' => 1.5, 'unitPrice' => 10.00, 'subtotal' => 15.00],
            ],
        ]);

        $this->service->appendTransaction($dto, 460);

        $row = $this->gateway->rows()[1];
        // qty 1.5 → "x1,5" (vírgula PT-BR).
        $this->assertStringContainsString('x1,5', $row[8]);
    }

    public function test_format_items_sorted_by_subtotal_ascending(): void
    {
        // CT-131/AC-023: ordenação crescente por subtotal.
        $dto = $this->dto([
            'items' => [
                ['name' => 'A', 'qty' => 1, 'unitPrice' => 50.00, 'subtotal' => 50.0],
                ['name' => 'B', 'qty' => 1, 'unitPrice' => 10.00, 'subtotal' => 10.0],
                ['name' => 'C', 'qty' => 1, 'unitPrice' => 30.00, 'subtotal' => 30.0],
            ],
        ]);

        $this->service->appendTransaction($dto, 461);

        $row = $this->gateway->rows()[1];
        $lines = explode("\n", $row[8]);

        $this->assertCount(3, $lines);
        // Ordem crescente: B (10), C (30), A (50).
        $this->assertStringContainsString('B', $lines[0]);
        $this->assertStringContainsString('R$ 10,00', $lines[0]);
        $this->assertStringContainsString('C', $lines[1]);
        $this->assertStringContainsString('R$ 30,00', $lines[1]);
        $this->assertStringContainsString('A', $lines[2]);
        $this->assertStringContainsString('R$ 50,00', $lines[2]);
    }

    public function test_format_items_without_subtotal_at_end_in_input_order(): void
    {
        // CT-132/AC-024: mixtos → com-subtotal primeiro, sem-subtotal ao final.
        $dto = $this->dto([
            'items' => [
                ['name' => 'X', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
                ['name' => 'Y', 'qty' => 1, 'unitPrice' => 10.00, 'subtotal' => 10.0],
                ['name' => 'Z', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);

        $this->service->appendTransaction($dto, 462);

        $row = $this->gateway->rows()[1];
        $lines = explode("\n", $row[8]);

        $this->assertCount(3, $lines);
        // 1. Y (com subtotal)
        $this->assertStringContainsString('Y', $lines[0]);
        $this->assertStringContainsString('R$ 10,00', $lines[0]);
        // 2. X (sem subtotal, ordem original)
        $this->assertStringContainsString('X', $lines[1]);
        $this->assertStringNotContainsString('R$', $lines[1]);
        // 3. Z (sem subtotal, ordem original)
        $this->assertStringContainsString('Z', $lines[2]);
        $this->assertStringNotContainsString('R$', $lines[2]);
    }

    public function test_format_items_pipe_in_name_preserved(): void
    {
        // CT-144/AC-037: pipe no nome é preservado.
        $dto = $this->dto([
            'items' => [
                ['name' => 'Coca-Cola | 2L', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);

        $this->service->appendTransaction($dto, 463);

        $row = $this->gateway->rows()[1];
        $this->assertSame('1. Coca-Cola | 2L', $row[8]);
    }

    public function test_escape_formula_prefixes_dangerous_chars(): void
    {
        // CT-148/AC-041 a AC-044: names que começam com =, +, -, @ são
        // prefixados com apóstrofo (CWE-1236).
        $dto = $this->dto([
            'items' => [
                ['name' => '=HYPERLINK("https://evil.com","Clique")', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
                ['name' => '+SUM(A1:A10)', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
                ['name' => '-1+1', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
                ['name' => '@admin', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);

        $this->service->appendTransaction($dto, 464);

        $row = $this->gateway->rows()[1];
        $lines = explode("\n", $row[8]);

        $this->assertCount(4, $lines);
        // Cada name deve começar com "'".
        $this->assertStringStartsWith("1. '=HYPERLINK", $lines[0]);
        $this->assertStringStartsWith("2. '+SUM", $lines[1]);
        $this->assertStringStartsWith("3. '-1+1", $lines[2]);
        $this->assertStringStartsWith("4. '@admin", $lines[3]);
    }

    public function test_escape_formula_per_item_handles_newline_in_name(): void
    {
        // CT-163/AC-045: escape aplicado por item, não na string inteira.
        // W2: name "\n=evil" → escape por linha → "\n'=evil".
        $dto = $this->dto([
            'items' => [
                ['name' => "\n=evil", 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);

        $this->service->appendTransaction($dto, 465);

        $row = $this->gateway->rows()[1];
        // W2: escapeFormula agora aplica escape por linha.
        // O "=evil" (começando com =) é prefixado com apóstrofo.
        $this->assertStringContainsString("'=evil", $row[8]);
        $this->assertStringNotContainsString("\n=evil", $row[8]);
    }

    public function test_escape_formula_handles_newline_followed_by_equals(): void
    {
        // W2: name "Produto\n=evil" → "Produto\n'=evil" (escape por linha).
        $dto = $this->dto([
            'items' => [
                ['name' => "Produto\n=evil", 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        ]);

        $this->service->appendTransaction($dto, 466);

        $row = $this->gateway->rows()[1];
        $lines = explode("\n", $row[8]);
        // Linha 1: "1. Produto"; Linha 2: "'=evil".
        $this->assertCount(2, $lines);
        $this->assertSame('1. Produto', $lines[0]);
        $this->assertSame("'=evil", $lines[1]);
    }

    public function test_headers_include_itens_column(): void
    {
        // Constante HEADERS tem 9 elementos, último = "Itens".
        $this->service->ensureHeaders();

        $headers = $this->gateway->getHeaderRow();
        $this->assertCount(9, $headers);
        $this->assertSame('Itens', $headers[8]);
    }
}
