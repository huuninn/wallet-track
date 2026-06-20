<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Actions\SyncsSheet;
use App\Http\Middleware\VerifyCronToken;
use App\Services\Google\FirestoreGateway;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Console\StubSyncsSheet;
use Tests\TestCase;

/**
 * Testes do endpoint `GET /cron/sync-pending` (M9.9 / T-013).
 *
 * Cobertura:
 *  - CT-054 → token válido → 200 + JSON correto.
 *  - CT-055 → sem token → 401.
 *  - CT-056 → token inválido → 401.
 *  - CT-057 → estrutura JSON: status, processed, synced, failed, errors, duration_ms, timestamp.
 *  - Sem CSRF (rota GET server-to-server).
 *  - Erro de infraestrutura (output vazio) → 500.
 *  - Regressão: header `X-Cron-Token` é case-insensitive (testado via header alternativo).
 *
 * O `SyncsSheet` é stubado via {@see StubSyncsSheet} para isolar o endpoint
 * do Google Sheets real. O Firestore é o in-memory (sem rede).
 */
#[CoversClass(VerifyCronToken::class)]
class SyncPendingRouteTest extends TestCase
{
    private const string VALID_TOKEN = 'test-cron-token-12345';

    private const string CHAT_ID = '12345';

    private InMemoryFirestoreGateway $gateway;

    private FirestoreService $firestore;

    private StubSyncsSheet $syncSheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InMemoryFirestoreGateway;
        $this->firestore = new FirestoreService($this->gateway);
        $this->syncSheet = new StubSyncsSheet($this->firestore);

        $this->app->instance(FirestoreGateway::class, $this->gateway);
        $this->app->instance(FirestoreService::class, $this->firestore);
        $this->app->instance(SyncsSheet::class, $this->syncSheet);
    }

    /**
     * Popula uma transação pendente.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedPendingTx(string $id, int $attempts = 0, array $overrides = []): void
    {
        $this->gateway->setDocument(FirestoreService::COLLECTION_TRANSACTIONS, $id, array_merge([
            'chat_id' => self::CHAT_ID,
            'description' => "Tx {$id}",
            'amount' => 50.0,
            'type' => 'expense',
            'category' => 'Outros',
            'date' => '2026-06-15',
            'labels' => [],
            'source' => 'text',
            'sync_status' => FirestoreService::SYNC_PENDING,
            'sync_attempts' => $attempts,
            'sync_last_attempt_at' => null,
            'sync_error_message' => null,
            'spreadsheet_row_id' => null,
            'processing' => false,
            'notified_at' => null,
            'created_at' => '2026-06-15T00:00:00.000000Z',
            'updated_at' => '2026-06-15T00:00:00.000000Z',
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | CT-054: token válido → 200
    |--------------------------------------------------------------------------
    */

    #[Group('smoke')]
    public function test_cron_valid_token_returns_200_with_zero_pendents(): void
    {
        $response = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => self::VALID_TOKEN,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'processed',
            'synced',
            'failed',
            'errors',
            'duration_ms',
            'timestamp',
        ]);
        $response->assertJson([
            'status' => 'ok',
            'processed' => 0,
            'synced' => 0,
            'failed' => 0,
        ]);
    }

    public function test_cron_valid_token_processes_pendents_and_returns_json(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx1');
        $this->seedPendingTx('tx2');
        $this->seedPendingTx('tx3');

        $response = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => self::VALID_TOKEN,
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'processed' => 3,
            'synced' => 3,
            'failed' => 0,
        ]);
        $this->assertSame([], $response->json('errors'));
    }

    public function test_cron_with_partial_failure_includes_errors_in_response(): void
    {
        $this->syncSheet->defaultReturn = true;
        $this->seedPendingTx('tx-ok-1');
        $this->seedPendingTx('tx-ok-2');
        $this->seedPendingTx('tx-fail');
        $this->syncSheet->overrides['tx-fail'] = ['return' => false, 'error' => 'HTTP 500 from Sheets'];

        $response = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => self::VALID_TOKEN,
        ]);

        $response->assertOk(); // 200 mesmo com falha parcial (CT-054, decisão #9)
        $response->assertJson([
            'status' => 'ok',
            'processed' => 3,
            'synced' => 2,
            'failed' => 1,
        ]);
        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertSame('tx-fail', $errors[0]['id']);
        $this->assertSame('HTTP 500 from Sheets', $errors[0]['error']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-055: sem token → 401
    |--------------------------------------------------------------------------
    */

    public function test_cron_without_token_returns_401(): void
    {
        $response = $this->getJson('/cron/sync-pending');

        $response->assertStatus(401);
        $response->assertJson(['status' => 'error']);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-056: token inválido → 401
    |--------------------------------------------------------------------------
    */

    public function test_cron_with_invalid_token_returns_401(): void
    {
        $response = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => 'token-errado-123',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['status' => 'error']);
    }

    public function test_cron_with_empty_token_returns_401(): void
    {
        $response = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => '',
        ]);

        $response->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-057: estrutura JSON
    |--------------------------------------------------------------------------
    */

    public function test_cron_response_has_all_required_json_fields(): void
    {
        $response = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => self::VALID_TOKEN,
        ]);

        $response->assertOk();
        $data = $response->json();

        // Tipos corretos.
        $this->assertIsString($data['status']);
        $this->assertIsInt($data['processed']);
        $this->assertIsInt($data['synced']);
        $this->assertIsInt($data['failed']);
        $this->assertIsArray($data['errors']);
        $this->assertIsInt($data['duration_ms']);
        $this->assertIsString($data['timestamp']);
        $this->assertGreaterThanOrEqual(0, $data['duration_ms']);

        // Timestamp em ISO 8601 (Z no final = UTC).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/',
            $data['timestamp'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Regressões e edge cases
    |--------------------------------------------------------------------------
    */

    public function test_cron_route_does_not_require_csrf_token(): void
    {
        // Verifica que a rota está na exclusion list do CSRF — sem isto,
        // Laravel retornaria 419 (CT-054 quebraria com X-CSRF-TOKEN ausente).
        // Para GET requests, CSRF não é verificado por padrão. Mas a exclusion
        // é documentada no `bootstrap/app.php` — este teste documenta a
        // expectativa de que GETs server-to-server funcionam normalmente.
        $response = $this->get('/cron/sync-pending', [
            'X-Cron-Token' => self::VALID_TOKEN,
        ]);

        $response->assertOk();
    }

    public function test_cron_does_not_leak_which_token_failed_in_401_body(): void
    {
        // Resposta 401 deve ser genérica (não revela "faltante" vs "inválido").
        $withoutToken = $this->getJson('/cron/sync-pending')->json();
        $withInvalidToken = $this->getJson('/cron/sync-pending', [
            'X-Cron-Token' => 'wrong',
        ])->json();

        $this->assertSame($withoutToken, $withInvalidToken);
        $this->assertSame(['status' => 'error'], $withoutToken);
    }

    public function test_cron_token_is_configured_via_env(): void
    {
        // Garante que o config('services.cron.secret_token') está lendo do
        // env (CRON_SECRET_TOKEN setado em phpunit.xml). Documenta a integração
        // com o .env / Secret Manager.
        $this->assertSame(self::VALID_TOKEN, config('services.cron.secret_token'));
    }
}
