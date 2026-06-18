<?php

declare(strict_types=1);

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\App;
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
| Health Check (M0.8)
|--------------------------------------------------------------------------
| Endpoint leve para uptime checks, Cloud Run health probes e diagnósticos.
| Em M0 retorna apenas status "ok". Em M10 (deploy) será aprimorado para
| verificar Firestore, Sheets e variáveis críticas. Stateless e sem serviços
| externos (cold-start friendly).
|
| Segurança (W1 da revisão M0): `version` e `app` só são expostos quando
| APP_DEBUG=true. Em produção retorna null — evita info disclosure (CVE matching
| por versão exata do framework) mesmo sendo um endpoint aberto para o probe.
*/
Route::get('/health', function () {
    $debug = App::hasDebugModeEnabled();

    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'version' => $debug ? App::version() : null,
        'app' => $debug ? config('app.name') : null,
    ]);
});

/*
|--------------------------------------------------------------------------
| Webhook do Telegram (M1.4)
|--------------------------------------------------------------------------
| Recebe updates (POST JSON) enviados pelos servidores do Telegram e os
| repassa ao Nutgram para processamento síncrono. Sempre responde 200
| (regra do webhook — ver TelegramWebhookController).
|
| O middleware 'telegram.webhook' (ValidateTelegramWebhook) é, em M1, um
| placeholder pass-through. Em M2 ele passará a validar o secret token e a
| whitelist de chat_id. Já aplicado à rota para que M2 só precise completar
| a lógica do middleware, sem tocar nas rotas.
*/
Route::post('webhook/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');
