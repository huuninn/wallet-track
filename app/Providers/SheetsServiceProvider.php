<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\SyncSheet;
use App\Services\Google\FirestoreService;
use App\Services\Google\GoogleCredentials;
use App\Services\Google\GoogleSheetsGateway;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Google\SheetsGateway;
use App\Services\Google\SheetsService;
use Google\Client;
use Google\Service\Sheets;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registra a camada Google Sheets no container (M6).
 *
 * Ligações:
 *
 *  - {@see Sheets} → singleton. Constrói o `Google\Client` com auth de service
 *    account (via {@see GoogleCredentials}, reaproveitado do M5) + scope
 *    `SPREADSHEETS` (read/write) e devolve `new Sheets($client)`. O cliente
 *    usa REST/HTTP (não gRPC), o que roda localmente com `--network host`.
 *
 *  - {@see SheetsGateway} → singleton {@see GoogleSheetsGateway}, recebendo a
 *    instância de {@see Sheets} + o ID da planilha e o nome da aba lidos de
 *    `config('google.sheets')`.
 *
 *  - {@see SheetsService} → singleton resolvido com a gateway + nomes das abas.
 *
 *  - {@see SyncSheet} → singleton resolvido com {@see SheetsService} e
 *    {@see FirestoreService}.
 *
 * Em testes, os serviços são instanciados diretamente com
 * {@see InMemorySheetsGateway}, sem passar por este
 * provider (que nunca instanciaria o `Sheets` real, pois exigiria credenciais
 * válidas e rede).
 */
class SheetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Sheets::class, function ($app): Sheets {
            $config = $app->make('config');

            $keyFile = GoogleCredentials::fromConfig($config->get('google'))->resolveKeyFile();

            // Mesmo cuidado do FirestoreServiceProvider (FIX-7): o trace de
            // exceções aqui poderia incluir o conteúdo do keyFile (private_key).
            // Wrap relança RuntimeException genérica, preservando a original
            // como `previous` para diagnóstico local sem vazar o segredo.
            try {
                $client = new Client;
                $client->setAuthConfig($keyFile);
                $client->addScope(Sheets::SPREADSHEETS);

                return new Sheets($client);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Falha ao inicializar Google Sheets: verifique as credenciais '
                    .'Google (keyFile) e o acesso à planilha. Detalhes técnicos '
                    .'preservados na exceção anterior para diagnóstico.',
                    previous: $e,
                );
            }
        });

        $this->app->singleton(SheetsGateway::class, function ($app): SheetsGateway {
            $config = $app->make('config');

            return new GoogleSheetsGateway(
                $app->make(Sheets::class),
                $config->string('google.sheets.spreadsheet_id'),
                $config->string('google.sheets.sheet_name'),
            );
        });

        $this->app->singleton(SheetsService::class, function ($app): SheetsService {
            $config = $app->make('config');

            return new SheetsService(
                $app->make(SheetsGateway::class),
                $config->string('google.sheets.sheet_name'),
                $config->string('google.sheets.categories_sheet_name'),
            );
        });

        $this->app->singleton(SyncSheet::class, function ($app): SyncSheet {
            return new SyncSheet(
                $app->make(SheetsService::class),
                $app->make(FirestoreService::class),
            );
        });
    }
}
