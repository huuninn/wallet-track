<?php

declare(strict_types=1);

namespace App\Services\Google;

use Google\Service\Sheets;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\ValueRange;

/**
 * Implementação real de {@see SheetsGateway} sobre `Google\Service\Sheets` (M6).
 *
 * Construída com uma instância de {@see Sheets} (autenticada via service account
 * no {@see SheetsServiceProvider}) + o ID da planilha e o nome da aba principal
 * lidos de `config('google.sheets')`. Traduz as operações de alto nível para a
 * API REST do Sheets v4 (HTTP — não gRPC).
 *
 * **Tradução das options**:
 *  - `getHeaderRow`     → `spreadsheets_values->get("{aba}!A1:I1")`; devolve
 *    null quando o range está vazio.
 *  - `writeHeaderRow`   → `update(...)` com `valueInputOption=RAW` (texto
 *    literal, sem reinterpretação de datas/números).
 *  - `appendRow`        → `append(...)` em `{aba}!A1` com
 *    `valueInputOption=USER_ENTERED` (o Sheets interpreta datas como data e
 *    números como número) + `insertDataOption=INSERT_ROWS` (nunca sobrescreve).
 *  - `writeAll`         → `clear(range)` seguido de `update(range)` com RAW
 *    (sobrescrita integral de abas auxiliares, ex.: Categorias).
 *
 * Esta classe **não deve ser instanciada em testes**: exige credenciais Google
 * válidas e rede. Testes usam {@see InMemorySheetsGateway}.
 */
final class GoogleSheetsGateway implements SheetsGateway
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly string $spreadsheetId,
        private readonly string $sheetName,
    ) {}

    public function getHeaderRow(): ?array
    {
        $range = $this->sheetName.'!A1:I1';

        /** @var ValueRange $response */
        $response = $this->sheets->spreadsheets_values->get($this->spreadsheetId, $range);
        $values = $response->getValues();

        if (empty($values)) {
            return null;
        }

        $header = $values[0] ?? null;

        return $header === null || $header === [] ? null : $header;
    }

    public function writeHeaderRow(array $headers): void
    {
        $range = $this->sheetName.'!A1:I1';

        $body = new ValueRange([
            'values' => [array_values($headers)],
        ]);

        $this->sheets->spreadsheets_values->update(
            $this->spreadsheetId,
            $range,
            $body,
            ['valueInputOption' => 'RAW'],
        );
    }

    public function appendRow(array $row): void
    {
        // Append a partir de A1: o Sheets localiza a próxima linha livre da
        // "tabela" que começa em A1 e insere após a última linha ocupada.
        $range = $this->sheetName.'!A1';

        $body = new ValueRange([
            'values' => [array_values($row)],
        ]);

        $this->sheets->spreadsheets_values->append(
            $this->spreadsheetId,
            $range,
            $body,
            [
                // USER_ENTERED é obrigatório para o Sheets interpretar a data
                // como data e o valor como número (spec §4).
                'valueInputOption' => 'USER_ENTERED',
                'insertDataOption' => 'INSERT_ROWS',
            ],
        );
    }

    public function writeAll(string $range, array $rows): void
    {
        // Sobrescrita integral: limpa o range inteiro e reescreve, evitando
        // linhas "fantasma" de sincronizações anteriores com mais dados.
        $this->sheets->spreadsheets_values->clear(
            $this->spreadsheetId,
            $range,
            new ClearValuesRequest,
        );

        $body = new ValueRange([
            'values' => array_values($rows),
        ]);

        $this->sheets->spreadsheets_values->update(
            $this->spreadsheetId,
            $range,
            $body,
            ['valueInputOption' => 'RAW'],
        );
    }
}
