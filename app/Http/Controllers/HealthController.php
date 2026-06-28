<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Health check para Cloud Run (M10).
 *
 * Endpoint leve usado como probe de liveness e para diagnóstico de
 * infraestrutura em runtime. Não acessa serviços externos no modo padrão
 * (cold-start friendly); no modo verbose, pinga banco de dados e Redis para validar
 * conectividade.
 *
 * Modos de operação:
 *
 *  1. **Padrão (GET /health)** — verifica que as variáveis de ambiente
 *     críticas estão não-vazias. Não expõe QUAIS estão faltando (apenas
 *     o contador de ausentes). Retorna 200 (ok) ou 503 (degradado).
 *
 *  2. **Verbose (GET /health?verbose=1)** — adicionalmente pinga
 *     banco de dados e Redis e expõe os nomes das env vars ausentes (nunca os valores).
 *     Retorna objeto `checks` detalhado com latência.
 *     **Restrito a APP_DEBUG=true**: em produção (debug=false), o verbose
 *     é ignorado e comporta-se como o modo padrão — o endpoint é público
 *     (--allow-unauthenticated) e o verbose revelaria estrutura interna.
 *
 * Segurança (W1 da revisão M0): `version` e `app` só são expostos quando
 * APP_DEBUG=true. Em produção retornam null.
 *
 * Não usa route:cache (ver Dockerfile para justificativa).
 */
final class HealthController extends Controller
{
    /**
     * Env vars críticas cuja ausência quebra funcionalidades essenciais.
     *
     * Cada entrada mapeia nome legível → callable que retorna true se presente.
     * As verificações usam config() em vez de env() direto para permitir
     * sobrescrita em testes (env() é imutável após boot).
     */
    private const array CRITICAL_ENV_CHECKS = [
        'APP_KEY' => 'checkAppKey',
        'TELEGRAM_BOT_TOKEN' => 'checkTelegramBotToken',
        'GOOGLE_CLOUD_PROJECT_ID' => 'checkGoogleCloudProjectId',
        'GOOGLE_SERVICE_ACCOUNT_JSON' => 'checkGoogleServiceAccount',
        'DEEPSEEK_API_KEY' => 'checkDeepseekApiKey',
        'GEMINI_API_KEY' => 'checkGeminiApiKey',
    ];

    /**
     * Manipula GET /health e GET /health?verbose=1.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $verbose = $request->boolean('verbose');
        $debug = App::hasDebugModeEnabled();

        // 1. Verificação de env vars (sempre executa — rápido, sem rede).
        $envCheck = $this->checkEnvironment();

        $status = $envCheck['ok'] ? 'ok' : 'degraded';

        $response = [
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'version' => $debug ? App::version() : null,
            'app' => $debug ? config('app.name') : null,
            'octane' => $this->isOctaneRuntime(),
        ];

        // 2. Modo verbose: inclui checks detalhados + ping ao banco de dados/Redis.
        //    Restrito a APP_DEBUG=true — em produção, o endpoint é público
        //    (--allow-unauthenticated) e o verbose revela estrutura de
        //    infraestrutura (nomes de env vars, path de secrets, latência).
        if ($verbose && $debug) {
            $response['checks'] = [
                'env' => $envCheck,
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis(),
            ];
        } elseif (! $envCheck['ok']) {
            // Modo não-verbose com falha: expõe apenas contador, não nomes.
            $response['missing_count'] = $envCheck['missing_count'];
        }

        $httpCode = $status === 'ok' ? 200 : 503;

        return response()->json($response, $httpCode);
    }

    /**
     * Verifica as env vars críticas.
     *
     * Em modo não-verbose, o array retornado NÃO inclui `missing` (nomes)
     * — apenas `ok`, `total` e `missing_count`. O controller decide se
     * inclui ou não com base no modo.
     *
     * @return array{ok: bool, total: int, missing_count: int, missing: list<string>}
     */
    private function checkEnvironment(): array
    {
        $missing = [];

        foreach (self::CRITICAL_ENV_CHECKS as $name => $method) {
            if (! $this->{$method}()) {
                $missing[] = $name;
            }
        }

        return [
            'ok' => count($missing) === 0,
            'total' => count(self::CRITICAL_ENV_CHECKS),
            'missing_count' => count($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Tenta executar uma query leve no banco de dados para validar conectividade.
     *
     * Usa `SELECT 1` — não acessa tabelas, apenas verifica se o banco responde.
     *
     * @return array{ok: bool, latency_ms: int|null, error: string|null}
     */
    private function checkDatabase(): array
    {
        $start = hrtime(true);

        try {
            DB::select('SELECT 1');

            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return [
                'ok' => true,
                'latency_ms' => $latency,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            Log::warning('Health check: database ping falhou', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'latency_ms' => $latency,
            ]);

            return [
                'ok' => false,
                'latency_ms' => $latency,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Tenta executar um ping no Redis para validar conectividade.
     *
     * @return array{ok: bool, latency_ms: int|null, error: string|null}
     */
    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            Redis::ping();

            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return [
                'ok' => true,
                'latency_ms' => $latency,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            Log::warning('Health check: Redis ping falhou', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'latency_ms' => $latency,
            ]);

            return [
                'ok' => false,
                'latency_ms' => $latency,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detecta se o request está sendo servido por um worker do Laravel Octane.
     *
     * O comando `php artisan octane:start --server=frankenphp` injeta a env var
     * `LARAVEL_OCTANE=1` no processo do worker (StartFrankenPhpCommand). No modo
     * `php_server` legacy (Caddyfile externo sem Octane) esta variável é ausente,
     * portanto o método retorna `false`.
     *
     * A triple-check (`$_ENV` → `$_SERVER` → `getenv`) cobre diferentes SAPIs do PHP
     * e garante detecção confiável independentemente da configuração `variables_order`.
     *
     * Uso: confirmar via `/health` que o hardening de ativação do Octane está em
     * vigor e detectar regressão para o modo php_server legacy.
     */
    private function isOctaneRuntime(): bool
    {
        return ($_ENV['LARAVEL_OCTANE'] ?? $_SERVER['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE')) === '1';
    }

    // -----------------------------------------------------------------
    // Métodos de verificação individuais (um por env var crítica).
    // Usam config() em vez de env() para permitir sobrescrita em testes.
    // -----------------------------------------------------------------

    private function checkAppKey(): bool
    {
        $val = config('app.key');

        return is_string($val) && $val !== '';
    }

    private function checkTelegramBotToken(): bool
    {
        $val = config('telegram.bot_token');

        return is_string($val) && trim($val) !== '';
    }

    private function checkGoogleCloudProjectId(): bool
    {
        $val = config('google.cloud.project_id');

        return is_string($val) && trim($val) !== '';
    }

    /**
     * Verifica se ao menos uma das duas fontes de credencial Google está presente.
     */
    private function checkGoogleServiceAccount(): bool
    {
        $path = config('google.service_account_json_path');
        $json = config('google.service_account_json');

        return (is_string($path) && trim($path) !== '')
            || (is_string($json) && trim($json) !== '');
    }

    private function checkDeepseekApiKey(): bool
    {
        $val = config('deepseek.api_key');

        return is_string($val) && trim($val) !== '';
    }

    private function checkGeminiApiKey(): bool
    {
        $val = config('gemini.api_key');

        return is_string($val) && trim($val) !== '';
    }
}
