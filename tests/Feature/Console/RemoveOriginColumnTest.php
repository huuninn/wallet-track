<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\RemoveOriginColumn;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Google\SheetsGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do comando `sheets:remove-origin-column` (F4).
 *
 * Cobertura:
 *  - dry-run exibe informações sem deletar
 *  - execução real deleta a coluna G (índice 6)
 *
 * Roda isolado: vendor/bin/phpunit --filter RemoveOriginColumnTest
 */
#[CoversClass(RemoveOriginColumn::class)]
class RemoveOriginColumnTest extends TestCase
{
    private InMemorySheetsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemorySheetsGateway;
        // Popula a gateway com um cabeçalho de 9 colunas + 1 linha de dados.
        $this->gateway->writeHeaderRow([
            'Data', 'Descrição', 'Valor', 'Tipo', 'Categoria',
            'Labels', 'Origem', 'ID Transação', 'Observações',
        ]);
        $this->gateway->appendRow([
            '2026-06-15', 'Teste', 50.0, 'Despesa', 'Outros',
            'teste', 'texto', 'abc123', '',
        ]);
        $this->app->instance(SheetsGateway::class, $this->gateway);
    }

    public function test_dry_run_does_not_delete_column(): void
    {
        $this->artisan('sheets:remove-origin-column', ['--dry-run' => true])
            ->expectsOutput('DRY-RUN: a coluna G (índice 6, "Origem") seria deletada da aba principal.')
            ->assertSuccessful();

        // Dados inalterados — 9 colunas ainda.
        $row = $this->gateway->rows()[1];
        $this->assertCount(9, $row);
        $this->assertSame('texto', $row[6]);
    }

    public function test_execution_deletes_column_g(): void
    {
        $this->artisan('sheets:remove-origin-column')
            ->expectsConfirmation('Deletar coluna G (Origem) da aba Transações? Esta operação é irreversível.', 'yes')
            ->expectsOutput('Coluna G (Origem) removida com sucesso!')
            ->assertSuccessful();

        // Agora são 8 colunas — a coluna "Origem" (índice 6) foi removida.
        $row = $this->gateway->rows()[1];
        $this->assertCount(8, $row);
        // ID Transação (antes índice 7) agora está em índice 6.
        $this->assertSame('abc123', $row[6]);
    }
}
