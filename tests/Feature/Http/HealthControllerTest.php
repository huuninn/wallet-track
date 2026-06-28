<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Testes do endpoint `GET /health` (M10 — HealthController).
 *
 * Cobertura:
 *  - CT-M10-H1 → todas env vars presentes → 200, status: ok.
 *  - CT-M10-H2 → env var crítica ausente → 503, status: degraded.
 *  - CT-M10-H3 → verbose=1 → inclui checks.env + checks.database + checks.redis.
 *  - CT-M10-H4 → modo não-verbose NÃO vaza nomes de env vars.
 *  - CT-M10-H5 → version/app só em debug mode (regressão W1 do M0).
 *  - CT-M10-H6 → database/Redis ping retorna ok: true quando conectivos.
 *  - CT-M10-H7 → verbose expõe nomes ausentes para diagnóstico (sem valores).
 *
 * O controller usa config() (não env()) para verificar as variáveis, o que
 * permite sobrescrita por teste via config(['chave' => null]). APP_KEY está
 * sempre presente (Laravel exige para bootar o EncryptionServiceProvider).
 */
#[CoversClass(HealthController::class)]
class HealthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // CT-M10-H1: Todas as env vars presentes → 200, status: ok
    // -----------------------------------------------------------------

    public function test_health_endpoint_returns_ok_when_all_env_vars_present(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
        $response->assertJsonMissing(['missing_count']);
    }

    // -----------------------------------------------------------------
    // M5 — Octane safety: campo `octane` no health response
    // -----------------------------------------------------------------

    /**
     * Valida que o campo `octane` está presente no response do `/health`
     * e reporta `false` em ambiente de teste (sem o env var `LARAVEL_OCTANE`).
     *
     * Este campo é a verificação de que o hardening de ativação do Octane
     * (M1/M2) está em vigor — em produção com FrankenPHP + Octane, o valor
     * será `true`, confirmando que os listeners de reset de estado estão
     * ativos.
     */
    public function test_health_endpoint_reports_octane_runtime_status(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJsonPath('octane', false);
    }

    // -----------------------------------------------------------------
    // CT-M10-H2: Env var crítica ausente → 503, status: degraded
    // -----------------------------------------------------------------

    public function test_health_endpoint_returns_degraded_when_telegram_bot_token_missing(): void
    {
        config(['telegram.bot_token' => null]);

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $response->assertJson(['status' => 'degraded']);
        $response->assertJsonPath('missing_count', 1);
    }

    public function test_health_endpoint_returns_degraded_when_deepseek_api_key_missing(): void
    {
        config(['deepseek.api_key' => null]);

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $response->assertJson(['status' => 'degraded', 'missing_count' => 1]);
    }

    // -----------------------------------------------------------------
    // CT-M10-H3: verbose=1 → checks.env + checks.database + checks.redis
    // -----------------------------------------------------------------

    public function test_verbose_mode_includes_checks_object(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/health?verbose=1');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'version',
            'app',
            'checks' => [
                'env' => ['ok', 'total', 'missing_count', 'missing'],
                'database' => ['ok', 'latency_ms', 'error'],
                'redis' => ['ok', 'latency_ms', 'error'],
            ],
        ]);
        $response->assertJsonPath('checks.env.ok', true);
        $response->assertJsonPath('checks.env.total', 6);
        $response->assertJsonPath('checks.env.missing_count', 0);
    }

    // -----------------------------------------------------------------
    // CT-M10-H4: Não-verbose NÃO vaza nomes de env vars
    // -----------------------------------------------------------------

    public function test_non_verbose_mode_does_not_expose_missing_env_var_names(): void
    {
        // Remove duas env vars para gerar degraded.
        config(['telegram.bot_token' => null]);
        config(['deepseek.api_key' => null]);

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $response->assertJsonPath('missing_count', 2);

        // missing_count presente, mas nomes NÃO devem aparecer.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('TELEGRAM_BOT_TOKEN', $body);
        $this->assertStringNotContainsString('DEEPSEEK_API_KEY', $body);
        $this->assertStringNotContainsString('GEMINI_API_KEY', $body);
    }

    // -----------------------------------------------------------------
    // CT-M10-H5: version/app só em debug mode (regressão W1 do M0)
    // -----------------------------------------------------------------

    public function test_version_and_app_exposed_only_in_debug_mode(): void
    {
        // Debug mode ON (local/dev).
        config(['app.debug' => true]);

        $response = $this->getJson('/health');
        $response->assertOk();
        $data = $response->json();
        $this->assertNotNull($data['version'], 'version deve ser não-nulo com debug=true');
        $this->assertNotNull($data['app'], 'app deve ser não-nulo com debug=true');

        // Debug mode OFF (produção).
        config(['app.debug' => false]);

        $response = $this->getJson('/health');
        $response->assertOk();
        $data = $response->json();
        $this->assertNull($data['version'], 'version deve ser null com debug=false');
        $this->assertNull($data['app'], 'app deve ser null com debug=false');
    }

    // -----------------------------------------------------------------
    // CT-M10-H6: database + Redis checks funcionais em modo verbose
    // -----------------------------------------------------------------

    public function test_verbose_database_and_redis_checks_return_ok_when_connected(): void
    {
        config(['app.debug' => true]);

        // DB::select('SELECT 1') roda nativamente via SQLite :memory: do phpunit.xml;
        // não usamos mock para evitar colisão com SessionManager (SESSION_DRIVER=database).
        Redis::shouldReceive('ping')->once()->andReturn('PONG');

        $response = $this->getJson('/health?verbose=1');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database.ok', true);
        $response->assertJsonPath('checks.redis.ok', true);
        $this->assertNotNull($response->json('checks.database.latency_ms'));
        $this->assertNotNull($response->json('checks.redis.latency_ms'));
    }

    // -----------------------------------------------------------------
    // CT-M10-H7: verbose expõe nomes ausentes para diagnóstico (sem valores)
    // -----------------------------------------------------------------

    public function test_verbose_exposes_missing_env_var_names_for_diagnosis(): void
    {
        config(['app.debug' => true]);
        config(['telegram.bot_token' => null]);
        config(['google.service_account_json_path' => null]);
        config(['google.service_account_json' => null]);

        $response = $this->getJson('/health?verbose=1');

        // Duas vars ausentes: TELEGRAM_BOT_TOKEN + GOOGLE_SERVICE_ACCOUNT_JSON
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'degraded');
        $response->assertJsonPath('checks.env.missing_count', 2);

        $missing = $response->json('checks.env.missing');
        $this->assertIsArray($missing);
        $this->assertContains('TELEGRAM_BOT_TOKEN', $missing);
        $this->assertContains('GOOGLE_SERVICE_ACCOUNT_JSON', $missing);

        // Mas NUNCA os valores — o token simulado de phpunit.xml não deve vazar.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('0000000000', $body);
    }

    // -----------------------------------------------------------------
    // CT-M10-H8: graceful-fail do checkDatabase quando DB indisponível
    // -----------------------------------------------------------------

    public function test_verbose_database_check_fails_gracefully_when_db_unavailable(): void
    {
        config(['app.debug' => true]);
        // SESSION_DRIVER=array evita que o SessionManager tente acessar a
        // conexão broken durante o request HTTP (causaria 500 antes do controller).
        config(['session.driver' => 'array']);
        config(['database.connections.broken' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/that/does/not/exist.db',
        ]]);
        config(['database.default' => 'broken']);

        Redis::shouldReceive('ping')->once()->andReturn('PONG');

        $response = $this->getJson('/health?verbose=1');

        $response->assertOk(); // status overall ainda é 'ok' (env vars presentes para testes)
        $response->assertJsonPath('checks.database.ok', false);
        $this->assertNotNull($response->json('checks.database.error'));
        $this->assertNotNull($response->json('checks.database.latency_ms'));
    }

    // -----------------------------------------------------------------
    // CT-M10-H9: verbose é ignorado quando debug=false (produção)
    // -----------------------------------------------------------------

    public function test_verbose_ignored_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/health?verbose=1');

        $response->assertOk();
        // checks NÃO deve estar presente em produção.
        $data = $response->json();
        $this->assertArrayNotHasKey('checks', $data);
    }
}
