<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateTelegramWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias do middleware do webhook (placeholder em M1; validação em M2).
        $middleware->alias([
            'telegram.webhook' => ValidateTelegramWebhook::class,
        ]);

        // O webhook do Telegram recebe POSTs sem token CSRF (chamada de
        // servidor-para-servidor), então deve ficar de fora da verificação
        // VerifyCsrfToken. Sem isto, o Telegram receberia 419 em produção.
        $middleware->validateCsrfTokens(except: [
            'webhook/telegram',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
