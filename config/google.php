<?php

declare(strict_types=1);

/*

|--------------------------------------------------------------------------
| Google Sheets (Service Account + Spreadsheet)
|--------------------------------------------------------------------------
|
| Configuração central da integração com Google Sheets no Wallet Track:
|
|  - Credenciais da service account (lê de arquivo local em dev; em
|    produção, injeta-se via GOOGLE_SERVICE_ACCOUNT_JSON inline).
|  - Identificação da planilha (spreadsheet_id, sheet_name).
|
| Consumidores:
|  - App\Services\Google\GoogleSheetsGateway → keyFile
|    (resolvido por App\Services\Google\GoogleCredentials).
|  - App\Services\Google\SheetsService → spreadsheet_id, sheet_name.
|
| Veja docs/02-especificacao-tecnica.md §12 (envvars).

*/

return [

    /*
|----------------------------------------------------------------------
| Google Sheets (planilha de transações — spec §4)
    |----------------------------------------------------------------------
    |
    |  - spreadsheet_id: ID da planilha compartilhada com a service account.
    |  - sheet_name: aba principal onde cada transação vira uma linha
    |    (cabeçalho na linha 1, dados a partir da linha 2).
    |  - categories_sheet_name: aba auxiliar somente-leitura, sincronizada
    |    best-effort a partir das categorias cadastradas.
    |
    | Consumidores: App\Services\Google\GoogleSheetsGateway e SheetsService.
    |
    | Formatação visual (FORMAT de data/moeda, freeze linha 1) é M10 (polish).
    |
    */
    'sheets' => [
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
        'sheet_name' => env('GOOGLE_SHEETS_SHEET_NAME', 'Transações'),
        'categories_sheet_name' => env('GOOGLE_SHEETS_CATEGORIES_SHEET_NAME', 'Categorias'),
    ],

    /*
    |----------------------------------------------------------------------
    | Credenciais da service account
    |----------------------------------------------------------------------
    |
    | Duas formas mutuamente compatíveis (M5 → M10):
    |
    |  - service_account_json_path: caminho absoluto para arquivo .json da
    |    service account. Padrão em dev (ler do disco).
    |
    |  - service_account_json: conteúdo JSON cru (ou base64 do JSON) da
    |    service account. Em produção será injetado inline.
    |
    | A resolução de qual usar é feita por {@see \App\Services\Google\GoogleCredentials},
    | com prioridade para o conteúdo inline (prod) sobre o path (dev).
    |
    */

    // Caminho absoluto para o JSON da service account (dev).
    'service_account_json_path' => env('GOOGLE_SERVICE_ACCOUNT_JSON_PATH'),

    // Conteúdo JSON cru ou base64 (prod).
    'service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),

];
