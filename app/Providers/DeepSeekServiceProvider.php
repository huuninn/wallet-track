<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\ExtractFromText;
use App\Services\DeepSeek\ChatCompleter;
use App\Services\DeepSeek\DeepSeekService;
use App\Services\DeepSeek\OpenAIChatCompleter;
use App\Services\Parsing\AmountParser;
use App\Services\Parsing\DateNormalizer;
use App\Services\Parsing\TypeClassifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Registra a camada de extração DeepSeek no container (M3).
 *
 *  - {@see ChatCompleter} → singleton {@see OpenAIChatCompleter} (cliente real).
 *  - {@see DeepSeekService} → construído inline com DateNormalizer que loga
 *    avisos de data futura/irreconhecível via facade Log do Laravel.
 *  - {@see ExtractFromText} → auto-resolvido com o serviço injetado.
 *
 * Em testes, bindamos um FakeChatCompleter para ChatCompleter, mantendo o
 * serviço totalmente isolado da rede (containers de CI sem acesso à internet).
 */
class DeepSeekServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChatCompleter::class, OpenAIChatCompleter::class);

        $this->app->singleton(DeepSeekService::class, function ($app) {
            return new DeepSeekService(
                completer: $app->make(ChatCompleter::class),
                amountParser: new AmountParser,
                dateNormalizer: new DateNormalizer(
                    onWarning: static function (string $message, array $context = []): void {
                        Log::warning($message, $context);
                    },
                ),
                typeClassifier: new TypeClassifier,
            );
        });

        $this->app->singleton(ExtractFromText::class);
    }
}
