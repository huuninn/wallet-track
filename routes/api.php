<?php

declare(strict_types=1);

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas server-to-server
|--------------------------------------------------------------------------
| Registradas via `withRouting(api: ..., apiPrefix: '')` em
| `bootstrap/app.php` para tornar o app verdadeiramente stateless:
| rotas API não recebem sessão, CSRF nem cookies automaticamente.
|
| Endpoints:
|  - GET  /                  → redireciona para /health
|  - GET  /health            → health check
|  - GET  /up                → Laravel built-in health (configurado em bootstrap)
|  - GET  /cron/sync-pending → aciona sync-pending (auth via X-Cron-Token)
|  - POST /webhook/telegram  → recebe updates do Telegram (auth via secret)
*/

/*
| Rota raiz — redireciona para health check.
| App é bot-only (sem visitantes humanos), evita expor welcome page do Laravel.
*/
Route::redirect('/', '/health');

/*
| Health Check (M0.8) — endpoint leve para uptime checks, Cloud Run probes
| e diagnósticos. Stateless, sem serviços externos (cold-start friendly).
|
| Segurança: `version` e `app` só expostos quando APP_DEBUG=true. Em
| produção retorna null — evita info disclosure (CVE matching por versão).
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
| Cron: Sincronização pendente (M9.9)
| Endpoint server-to-server acionado pelo Cloud Scheduler a cada 5 min (M10).
| Executa o command `transactions:sync-pending` in-process e devolve JSON
| estruturado com os contadores.
|
| Segurança:
|  - Header `X-Cron-Token` obrigatório (middleware `cron` /
|    {@see \App\Http\Middleware\VerifyCronToken}); comparação timing-safe.
|  - Em M11 (api.php + apiPrefix:''), CSRF não se aplica (rotas API são
|    stateless). A exclusão de CSRF que existia em `web.php` foi removida.
|  - HTTP 200 mesmo com falhas parciais; 500 só em erro de infraestrutura.
|  - HTTP 401 sem corpo informativo (apenas `{"status":"error"}`) para evitar
|    fingerprinting.
*/
Route::get('/cron/sync-pending', function (Request $request): JsonResponse {
    $start = hrtime(true);

    // Executa o command in-process com output JSON para parse estruturado.
    Artisan::call('transactions:sync-pending', ['--format' => 'json']);
    $output = trim(Artisan::output());

    $result = json_decode($output, true);
    if (! is_array($result)) {
        return response()->json([
            'status' => 'error',
            'error' => 'command failed to produce JSON output',
            'output' => $output,
            'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
        ], 500);
    }

    $duration = (int) ((hrtime(true) - $start) / 1_000_000);

    return response()->json([
        'status' => 'ok',
        'processed' => (int) ($result['processed'] ?? 0),
        'synced' => (int) ($result['synced'] ?? 0),
        'failed' => (int) ($result['failed'] ?? 0),
        'errors' => $result['errors'] ?? [],
        'duration_ms' => $duration,
        'timestamp' => now()->toIso8601ZuluString('millisecond'),
    ]);
})->middleware('cron')->name('cron.sync-pending');

/*
| Webhook do Telegram (M1.4) — recebe updates (POST JSON) enviados pelos
| servidores do Telegram e os repassa ao Nutgram para processamento síncrono.
| Sempre responde 200 (regra do webhook).
|
| O middleware 'telegram.webhook' (ValidateTelegramWebhook) valida o secret
| token e a whitelist de chat_id.
*/
Route::post('webhook/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');
