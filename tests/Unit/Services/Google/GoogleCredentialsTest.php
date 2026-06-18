<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Google;

use App\Services\Google\GoogleCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Testes do resolvedor de credenciais Google (M5.2).
 *
 * Cobre os dois caminhos de resolução (path de arquivo vs conteúdo inline,
 * incluindo base64) e os modos de falha esperados. Usa arquivos tmp em
 * sys_get_temp_dir() para não acoplar a fixtures reais.
 *
 * Roda isolado: vendor/bin/phpunit --filter GoogleCredentialsTest
 */
#[CoversClass(GoogleCredentials::class)]
class GoogleCredentialsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Helpers de fixture
    |--------------------------------------------------------------------------
    */

    /**
     * Cria um arquivo tmp com o conteúdo passado e devolve o caminho absoluto.
     * O arquivo é removido ao final do teste (tearDownFiles).
     *
     * @var list<string>
     */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            @unlink($path);
        }
        $this->tmpFiles = [];

        parent::tearDown();
    }

    private function tmpFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gcp_cred_');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * Devolve um JSON de service account fictício para uso nos testes.
     */
    private function sampleKeyFileJson(): string
    {
        return json_encode([
            'type' => 'service_account',
            'project_id' => 'wallet-track-test',
            'private_key_id' => 'abc123',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----\n',
            'client_email' => 'wallet-track@test-project.iam.gserviceaccount.com',
            'client_id' => '12345',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolução por path (dev)
    |--------------------------------------------------------------------------
    */

    public function test_resolves_from_path(): void
    {
        $path = $this->tmpFile($this->sampleKeyFileJson());

        $creds = new GoogleCredentials([
            'service_account_json_path' => $path,
            'service_account_json' => null,
        ]);

        $keyFile = $creds->resolveKeyFile();

        $this->assertSame('service_account', $keyFile['type']);
        $this->assertSame('wallet-track-test', $keyFile['project_id']);
        $this->assertSame('wallet-track@test-project.iam.gserviceaccount.com', $keyFile['client_email']);
    }

    public function test_resolves_from_path_even_when_inline_is_blank(): void
    {
        $path = $this->tmpFile($this->sampleKeyFileJson());

        $creds = new GoogleCredentials([
            'service_account_json_path' => $path,
            'service_account_json' => '',
        ]);

        $this->assertSame('service_account', $creds->resolveKeyFile()['type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolução por conteúdo inline (prod/M10)
    |--------------------------------------------------------------------------
    */

    public function test_resolves_from_inline_json(): void
    {
        $creds = new GoogleCredentials([
            'service_account_json' => $this->sampleKeyFileJson(),
            'service_account_json_path' => null,
        ]);

        $keyFile = $creds->resolveKeyFile();

        $this->assertSame('service_account', $keyFile['type']);
        $this->assertSame('wallet-track-test', $keyFile['project_id']);
    }

    public function test_resolves_from_inline_base64(): void
    {
        // Secret Manager às vezes entrega o JSON codificado em base64 para
        // evitar problemas de aspas no env. A heurística do resolver é:
        // se não começa com "{", tenta base64_decode estrito.
        $base64 = base64_encode($this->sampleKeyFileJson());

        $creds = new GoogleCredentials([
            'service_account_json' => $base64,
            'service_account_json_path' => null,
        ]);

        $keyFile = $creds->resolveKeyFile();

        $this->assertSame('service_account', $keyFile['type']);
        $this->assertSame('wallet-track-test', $keyFile['project_id']);
    }

    public function test_inline_takes_precedence_over_path(): void
    {
        $path = $this->tmpFile('not actually json but should not be read');

        $creds = new GoogleCredentials([
            'service_account_json' => $this->sampleKeyFileJson(),
            'service_account_json_path' => $path,
        ]);

        $keyFile = $creds->resolveKeyFile();

        // Inline venceu: deveríamos ver o conteúdo inline (type=service_account)
        // e não o conteúdo do arquivo.
        $this->assertSame('service_account', $keyFile['type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Modos de falha
    |--------------------------------------------------------------------------
    */

    public function test_throws_when_both_sources_empty(): void
    {
        $creds = new GoogleCredentials([
            'service_account_json' => null,
            'service_account_json_path' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/GOOGLE_SERVICE_ACCOUNT_JSON/');

        $creds->resolveKeyFile();
    }

    public function test_throws_when_both_sources_blank_strings(): void
    {
        $creds = new GoogleCredentials([
            'service_account_json' => '   ',
            'service_account_json_path' => '',
        ]);

        $this->expectException(RuntimeException::class);

        $creds->resolveKeyFile();
    }

    public function test_throws_when_path_does_not_exist(): void
    {
        $creds = new GoogleCredentials([
            'service_account_json_path' => '/tmp/esse-arquivo-definitivamente-nao-existe-'.bin2hex(random_bytes(4)).'.json',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inacessível/');

        $creds->resolveKeyFile();
    }

    public function test_throws_when_inline_is_not_json_nor_base64(): void
    {
        $creds = new GoogleCredentials([
            'service_account_json' => 'isto não é JSON nem base64 válido',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/não é JSON válido nem base64/');

        $creds->resolveKeyFile();
    }

    public function test_throws_when_inline_is_invalid_json(): void
    {
        // Começa com "{" mas o corpo é inválido.
        $creds = new GoogleCredentials([
            'service_account_json' => '{"broken": ',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/não decodifica/');

        $creds->resolveKeyFile();
    }

    /*
    |--------------------------------------------------------------------------
    | fromConfig (binding ao Laravel)
    |--------------------------------------------------------------------------
    */

    public function test_from_config_reads_defaults(): void
    {
        $path = $this->tmpFile($this->sampleKeyFileJson());

        config()->set('google', [
            'service_account_json_path' => $path,
            'service_account_json' => null,
        ]);

        $creds = GoogleCredentials::fromConfig();

        $this->assertSame('service_account', $creds->resolveKeyFile()['type']);
    }
}
