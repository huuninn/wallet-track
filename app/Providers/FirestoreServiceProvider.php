<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\SeedCategories;
use App\Services\Google\CloudFirestoreGateway;
use App\Services\Google\FirestoreGateway;
use App\Services\Google\FirestoreService;
use App\Services\Google\GoogleCredentials;
use App\Services\Google\InMemoryFirestoreGateway;
use App\Support\Telescope\FirestoreWatcherDecorator;
use App\Support\Telescope\TelescopeHelper;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registra a camada de persistência Firestore no container (M5).
 *
 * Ligações:
 *
 *  - {@see FirestoreGateway} → {@see CloudFirestoreGateway} ou
 *    {@see FirestoreWatcherDecorator} (condicional, ver abaixo).
 *    O `FirestoreClient` é construído uma única vez (singleton) usando
 *    {@see GoogleCredentials} para resolver o `keyFile` (dev: arquivo;
 *    prod/M10: conteúdo inline via Secret Manager).
 *
 *  - {@see FirestoreService} → resolvedor inline com a gateway injetada.
 *
 *  - Em testes, o serviço é instanciado diretamente com
 *    {@see InMemoryFirestoreGateway}, sem passar por
 *    este provider (que nunca sequer instanciaria o FirestoreClient real,
 *    pois exigiria credenciais válidas).
 *
 * Nota: o command {@see SeedCategories} é
 * auto-descoberto pelo Laravel via path `app/Console/Commands`.
 */
class FirestoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FirestoreClient::class, function ($app): FirestoreClient {
            $config = $app->make('config');

            // resolveKeyFile() e o construtor do FirestoreClient podem lançar
            // exceções cujo stack trace incluiria o conteúdo de `$keyFile`
            // (private_key, etc.). Em produção, esse trace vai para logs/bug
            // tracker — expondo a chave. Wrap relança RuntimeException com
            // mensagem genérica, mantendo a original como `previous` para
            // debug local sem vazar segredo no output público (FIX-7).
            try {
                $keyFile = GoogleCredentials::fromConfig($config->get('google'))->resolveKeyFile();

                return new FirestoreClient([
                    'projectId' => $config->string('google.cloud.project_id'),
                    'database' => $config->string('google.firestore.database_id'),
                    'keyFile' => $keyFile,
                ]);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Falha ao inicializar FirestoreClient: verifique as credenciais '
                    .'Google (keyFile) e o projectId configurados. Detalhes técnicos '
                    .'preservados na exceção anterior para diagnóstico.',
                    previous: $e,
                );
            }
        });

        $this->app->singleton(FirestoreGateway::class, function ($app): FirestoreGateway {
            $gateway = new CloudFirestoreGateway($app->make(FirestoreClient::class));

            // M5-spec: quando Telescope está ativo (env `local` + flag
            // ligado), envolve o gateway real com o decorator que
            // registra cada chamada em /telescope/events. Em produção,
            // tests ou staging, o binding retorna o gateway puro (zero
            // overhead, zero chance de vazar dados via telemetria).
            if (TelescopeHelper::isActive()) {
                return new FirestoreWatcherDecorator($gateway);
            }

            return $gateway;
        });

        $this->app->singleton(FirestoreService::class, function ($app): FirestoreService {
            return new FirestoreService($app->make(FirestoreGateway::class));
        });
    }
}
