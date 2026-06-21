<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateTelegramWebhook;
use App\Http\Middleware\VerifyCronToken;
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
        // Alias dos middlewares HTTP. Mantém o ponto único de configuração
        // (Laravel 13+) em vez de poluir o array `$routeMiddleware` legado.
        $middleware->alias([
            'telegram.webhook' => ValidateTelegramWebhook::class,
            'cron' => VerifyCronToken::class,
        ]);

        // O webhook do Telegram recebe POSTs sem token CSRF (chamada de
        // servidor-para-servidor), então deve ficar de fora da verificação
        // VerifyCsrfToken. Sem isto, o Telegram receberia 419 em produção.
        //
        // A rota /cron/sync-pending também é GET server-to-server e
        // precisa ser excluída — o Cloud Scheduler não envia token CSRF.
        //
        // As rotas do Telescope (POST /telescope/telescope-api/*) também
        // são chamadas pela própria UI Vue do Telescope via fetch sem CSRF
        // token — sem essa exclusão, a aba Events fica "scanning" infinita
        // com erro 419 silencioso no console do browser.
        $middleware->validateCsrfTokens(except: [
            'webhook/telegram',
            'cron/sync-pending',
            'telescope/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
