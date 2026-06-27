<?php

declare(strict_types=1);

/*

|--------------------------------------------------------------------------
| Google Cloud (projeto, credenciais service account)
|--------------------------------------------------------------------------
|
| Configuração central de tudo que toca o Google Cloud no Wallet Track:
|
|  - Identidade do projeto GCP (GOOGLE_CLOUD_PROJECT_ID).
|  - Credenciais da service account (M5 lê de arquivo local; M10 injeta
|    via Secret Manager).
|
| Consumidores atuais:
|  - App\Services\Google\GoogleSheetsGateway → projectId + keyFile
|    (resolvido por App\Services\Google\GoogleCredentials).
|
| Veja docs/02-especificacao-tecnica.md §12 (envvars).

*/

return [

    // Identificador do projeto GCP.
    'cloud' => [
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    ],

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
    |    service account. Em produção (M10) será injetado via Secret Manager
    |    e lido em runtime sem tocar disco.
    |
    | A resolução de qual usar é feita por {@see \App\Services\Google\GoogleCredentials},
    | com prioridade para o conteúdo inline (prod) sobre o path (dev).
    |
    */

    // Caminho absoluto para o JSON da service account (dev).
    'service_account_json_path' => env('GOOGLE_SERVICE_ACCOUNT_JSON_PATH'),

    // Conteúdo JSON cru ou base64 (prod/M10 via Secret Manager).
    'service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),

];
