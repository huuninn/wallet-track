<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\ExtractFromImage;
use App\Services\Gemini\GeminiImageCompleter;
use App\Services\Gemini\GeminiService;
use App\Services\Gemini\ImageCompleter;
use App\Services\Parsing\AmountParser;
use App\Services\Parsing\DateNormalizer;
use App\Services\Parsing\TypeClassifier;
use App\Services\Telegram\NutgramFileDownloader;
use App\Services\Telegram\TelegramFileDownloader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Registra a camada de extração Gemini (imagem → JSON) no container (M4).
 *
 *  - {@see ImageCompleter} → singleton {@see GeminiImageCompleter} (cliente real).
 *  - {@see GeminiService} → construído inline com DateNormalizer que loga
 *    avisos de data futura/irreconhecível via facade Log do Laravel (mesmo
 *    padrão do DeepSeekServiceProvider do M3).
 *  - {@see TelegramFileDownloader} → singleton {@see NutgramFileDownloader}
 *    (depende do singleton Nutgram registrado no TelegramServiceProvider).
 *  - {@see ExtractFromImage} → resolve ambos do container.
 *
 * Em testes, bindamos FakeImageCompleter e FakeTelegramFileDownloader,
 * mantendo o serviço totalmente isolado da rede (CI sem internet).
 */
class GeminiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageCompleter::class, GeminiImageCompleter::class);

        $this->app->singleton(GeminiService::class, function ($app) {
            return new GeminiService(
                completer: $app->make(ImageCompleter::class),
                amountParser: new AmountParser,
                dateNormalizer: new DateNormalizer(
                    onWarning: static function (string $message, array $context = []): void {
                        Log::warning($message, $context);
                    },
                ),
                typeClassifier: new TypeClassifier,
            );
        });

        $this->app->singleton(TelegramFileDownloader::class, NutgramFileDownloader::class);

        $this->app->singleton(ExtractFromImage::class);
    }
}
