<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Actions\ExtractFromImage;
use App\Dto\TelegramFile;
use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;
use App\Services\Gemini\ImageCompleter;
use App\Services\Telegram\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Testes de feature da Action {@see ExtractFromImage} (M4).
 *
 * Valida o fluxo completo file_id → download (fake) → extração (fake),
 * com ambas as dependências mockadas. **Nenhuma chamada real à rede** —
 * nem ao Telegram, nem ao Gemini.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter ExtractFromImageTest
 */
#[CoversClass(ExtractFromImage::class)]
class ExtractFromImageTest extends TestCase
{
    /**
     * Monta a Action com FakeTelegramFileDownloader + FakeImageCompleter
     * devolvendo JSON de fixture pré-gravado.
     */
    private function actionReturning(string $geminiResponse): ExtractFromImage
    {
        $this->app->bind(TelegramFileDownloader::class, fn () => new FakeTelegramFileDownloader);
        $this->app->bind(ImageCompleter::class, fn () => new FakeImageCompleter(response: $geminiResponse));

        return $this->app->make(ExtractFromImage::class);
    }

    /**
     * Monta a fixture JSON do Gemini.
     */
    private function fixture(array $data): string
    {
        return json_encode(array_merge([
            'description' => null,
            'amount' => null,
            'type' => null,
            'category' => null,
            'labels' => [],
            'date' => 'hoje',
            'observations' => null,
            'items' => [],
            'confidence' => 0.9,
        ], $data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluxo feliz: file_id → download → extrai → DTO
    |--------------------------------------------------------------------------
    */

    public function test_action_downloads_and_extracts_transaction(): void
    {
        $action = $this->actionReturning($this->fixture([
            'description' => 'Supermercado XYZ',
            'amount' => 27.10,
            'type' => 'expense',
            'category' => 'Mercado',
        ]));

        $dto = $action->handle('AgACAgUAAZX...file_id_fake...');

        $this->assertInstanceOf(TransactionData::class, $dto);
        $this->assertSame('Supermercado XYZ', $dto->description);
        $this->assertSame(27.10, $dto->amount);
        $this->assertSame('expense', $dto->type);
        $this->assertSame('Mercado', $dto->category);
    }

    /*
    |--------------------------------------------------------------------------
    | Propagação de NOT_A_TRANSACTION
    |--------------------------------------------------------------------------
    */

    public function test_action_propagates_not_a_transaction_exception(): void
    {
        // Imagem que não é nota fiscal → NOT_A_TRANSACTION (CT-009).
        $action = $this->actionReturning($this->fixture([
            'description' => null,
            'amount' => null,
        ]));

        $this->expectException(ExtractionException::class);

        try {
            $action->handle('fake_file_id');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::NOT_A_TRANSACTION, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Propagação de falha de API
    |--------------------------------------------------------------------------
    */

    public function test_action_propagates_api_error(): void
    {
        $this->app->bind(TelegramFileDownloader::class, fn () => new FakeTelegramFileDownloader);
        $this->app->bind(ImageCompleter::class, fn () => new FakeImageCompleter(
            throw: new RuntimeException('Gemini offline'),
        ));

        $action = $this->app->make(ExtractFromImage::class);

        $this->expectException(ExtractionException::class);

        try {
            $action->handle('fake_file_id');
        } catch (ExtractionException $e) {
            $this->assertSame(ExtractionException::API_ERROR, $e->getReason());
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Propagação de falha de download do Telegram
    |--------------------------------------------------------------------------
    */

    public function test_action_propagates_telegram_download_failure(): void
    {
        // FakeTelegramFileDownloader que lança RuntimeException no download.
        $this->app->bind(TelegramFileDownloader::class, function () {
            return new class implements TelegramFileDownloader
            {
                public function download(string $fileId): TelegramFile
                {
                    throw new RuntimeException('Arquivo não encontrado no Telegram.');
                }
            };
        });
        $this->app->bind(ImageCompleter::class, fn () => new FakeImageCompleter(
            response: $this->fixture(['description' => 'Não chega aqui', 'amount' => 1.00]),
        ));

        $action = $this->app->make(ExtractFromImage::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Arquivo não encontrado');

        $action->handle('file_id_inexistente');
    }
}
