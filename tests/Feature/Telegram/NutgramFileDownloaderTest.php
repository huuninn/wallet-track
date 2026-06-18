<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Services\Telegram\NutgramFileDownloader;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Media\File as TelegramFileObject;
use Tests\TestCase;

/**
 * Testes de feature do {@see NutgramFileDownloader} — M1/M4.
 *
 * O foco principal é o método {@see NutgramFileDownloader::download()} e,
 * indiretamente, `detectMimeType()`. **Nenhuma chamada real à rede** — o
 * Nutgram é mockado (Mockery) e o HTTP é interceptado via Http::fake.
 *
 * FIX-3 (W): garante que um formato não suportado (ex.: GIF) NÃO cai no
 * fallback cego para `image/jpeg` — deve retornar o Content-Type HTTP
 * original (ou `application/octet-stream` se vazio) para que o
 * GeminiService::validateInput() o rejeite com INVALID_INPUT de forma
 * explícita e rastreável.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter NutgramFileDownloaderTest
 */
#[CoversClass(NutgramFileDownloader::class)]
class NutgramFileDownloaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Monta um NutgramFileDownloader com um Nutgram mockado cujo getFile()
     * devolve um File com file_path setado, e downloadUrl() devolve uma URL
     * fake. O Http::fake responde com o Content-Type e body informados.
     */
    private function downloaderWithFakeDownload(string $filePath, string $contentType, string $body = 'fake-image-bytes'): NutgramFileDownloader
    {
        $file = new TelegramFileObject;
        $file->file_id = 'fake_file_id';
        $file->file_unique_id = 'fake_unique_id';
        $file->file_path = $filePath;
        $file->file_size = strlen($body);

        $url = 'https://api.telegram.org/file/botfake-token/'.ltrim($filePath, '/');

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('getFile')->with('fake_file_id')->andReturn($file);
        $bot->shouldReceive('downloadUrl')->with($file)->andReturn($url);

        Http::fake([
            $url => Http::response($body, 200, $contentType !== '' ? ['Content-Type' => $contentType] : []),
        ]);

        return new NutgramFileDownloader($bot);
    }

    /*
    |--------------------------------------------------------------------------
    | FIX-3 — Fallback NÃO deve mascarar formatos não suportados como JPEG
    |--------------------------------------------------------------------------
    */

    /**
     * GIF com Content-Type HTTP vazio: o default do match deve devolver
     * application/octet-stream (NUNCA image/jpeg), para que o GeminiService
     * rejeite explicitamente com INVALID_INPUT.
     */
    public function test_unknown_extension_with_empty_content_type_returns_octet_stream_not_jpeg(): void
    {
        $downloader = $this->downloaderWithFakeDownload('photos/file_123.gif', '');

        $result = $downloader->download('fake_file_id');

        $this->assertNotSame('image/jpeg', $result->mimeType, 'GIF não deve ser mascarado como JPEG.');
        $this->assertSame('application/octet-stream', $result->mimeType);
    }

    /**
     * GIF com Content-Type HTTP explícito (image/gif): devolve o tipo real
     * do HTTP em vez de forçar image/jpeg.
     */
    public function test_unknown_extension_preserves_http_content_type_instead_of_defaulting_to_jpeg(): void
    {
        $downloader = $this->downloaderWithFakeDownload('animations/file_456.gif', 'image/gif');

        $result = $downloader->download('fake_file_id');

        $this->assertSame('image/gif', $result->mimeType);
        $this->assertNotSame('image/jpeg', $result->mimeType);
    }

    /*
    |--------------------------------------------------------------------------
    | Caminho feliz — formatos suportados continuam funcionando
    |--------------------------------------------------------------------------
    */

    public function test_jpeg_extension_returns_image_jpeg(): void
    {
        $downloader = $this->downloaderWithFakeDownload('photos/file_jpg.jpg', '');

        $result = $downloader->download('fake_file_id');

        $this->assertSame('image/jpeg', $result->mimeType);
    }

    public function test_png_extension_returns_image_png(): void
    {
        $downloader = $this->downloaderWithFakeDownload('photos/file_png.png', '');

        $result = $downloader->download('fake_file_id');

        $this->assertSame('image/png', $result->mimeType);
    }

    public function test_webp_extension_returns_image_webp(): void
    {
        $downloader = $this->downloaderWithFakeDownload('photos/file_webp.webp', '');

        $result = $downloader->download('fake_file_id');

        $this->assertSame('image/webp', $result->mimeType);
    }

    /**
     * Quando o Content-Type HTTP é um MIME válido e suportado, ele tem
     * prioridade sobre a extensão do file_path (mesmo se a extensão for
     * genérica/ausente).
     */
    public function test_supported_content_type_header_wins_over_extension(): void
    {
        $downloader = $this->downloaderWithFakeDownload('files/file_noext', 'image/heic');

        $result = $downloader->download('fake_file_id');

        $this->assertSame('image/heic', $result->mimeType);
    }

    /**
     * Garante que o base64 retornado corresponde ao body baixado.
     */
    public function test_download_returns_base64_encoded_body(): void
    {
        $downloader = $this->downloaderWithFakeDownload('photos/legit.jpg', 'image/jpeg', 'rawbytes');

        $result = $downloader->download('fake_file_id');

        $this->assertSame(base64_encode('rawbytes'), $result->base64);
    }
}
