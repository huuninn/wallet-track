<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rota raiz
|--------------------------------------------------------------------------
| App é bot-only (sem visitantes humanos). Redireciona `/` para o health
| check, evitando expor a welcome page padrão do Laravel em produção.
*/
Route::redirect('/', '/health');

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/
Route::get('/health', HealthController::class);

/*
|--------------------------------------------------------------------------
| Webhook do Telegram
|--------------------------------------------------------------------------
| Recebe updates (POST JSON) enviados pelos servidores do Telegram e os
| repassa ao Nutgram para processamento síncrono. Sempre responde 200
| (regra do webhook — ver TelegramWebhookController).
*/
Route::post('webhook/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');
