<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Google\SheetsGateway;
use Illuminate\Console\Command;

/**
 * Remove a coluna G (Origem) da aba principal de transações.
 *
 * Usado na migração de 9→8 colunas (F4): a coluna "Origem" deixa de
 * existir no schema. Este comando deleta fisicamente a coluna via
 * batchUpdate da Sheets API v4.
 *
 * Uso:
 *   php artisan sheets:remove-origin-column           # executa (com confirmação)
 *   php artisan sheets:remove-origin-column --dry-run # preview
 */
final class RemoveOriginColumn extends Command
{
    protected $signature = 'sheets:remove-origin-column {--dry-run : Exibe o que seria feito, sem alterar}';

    protected $description = 'Remove a coluna G (Origem) da aba principal de transações';

    public function handle(): int
    {
        // O sheetId numérico da aba principal (0 = primeira aba criada).
        // Para planilhas padrão, a aba "Transações" tem sheetId=0.
        $sheetId = 0;
        $columnIndex = 6; // Coluna G (0-based: A=0, B=1, ..., G=6)

        // O dry-run resolve apenas metadados estáticos (sheetId, columnIndex)
        // e imprime o que SERIA feito. Ele NÃO instancia SheetsGateway
        // (resolver o gateway exige credenciais Google válidas). Esta
        // separação é deliberada (spec §15 AMB #1) para que --dry-run
        // funcione em qualquer ambiente, mesmo sem GOOGLE_SERVICE_ACCOUNT_JSON
        // ou GOOGLE_SERVICE_ACCOUNT_JSON_PATH configurados.
        if ($this->option('dry-run')) {
            $this->info('DRY-RUN: a coluna G (índice 6, "Origem") seria deletada da aba principal.');
            $this->line('  sheetId: '.$sheetId);
            $this->line('  columnIndex: '.$columnIndex);

            return self::SUCCESS;
        }

        /** @var SheetsGateway $gateway */
        $gateway = app(SheetsGateway::class);

        if (! $this->confirm('Deletar coluna G (Origem) da aba Transações? Esta operação é irreversível.')) {
            $this->info('Operação cancelada.');

            return self::SUCCESS;
        }

        $gateway->deleteColumn($sheetId, $columnIndex);

        $this->info('Coluna G (Origem) removida com sucesso!');

        return self::SUCCESS;
    }
}
