<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
| Health Check (M0.8 → M10 aprimorado)
|--------------------------------------------------------------------------
| Endpoint para uptime checks do Cloud Run e diagnóstico de infraestrutura.
|
| Modos (ver {@see \App\Http\Controllers\HealthController}):
|  - GET /health           → verifica env vars críticas, sem tocar rede.
|  - GET /health?verbose=1 → adicionalmente pinga o Firestore.
|
| Segurança (W1 da revisão M0): `version` e `app` só são expostos quando
| APP_DEBUG=true. Em produção retornam null.
|
| Em M10 extraímos para controller invokable dedicado (em vez de closure)
| para permitir testes unitários isolados e preparar para route:cache futuro.
*/
Route::get('/health', HealthController::class);

/*
|--------------------------------------------------------------------------
| Cron: Sincronização pendente (M9.9)
|--------------------------------------------------------------------------
| Endpoint server-to-server acionado pelo Cloud Scheduler a cada 5 min
| (M10). Executa o command `transactions:sync-pending` in-process e
| devolve um JSON estruturado com os contadores.
|
| **Segurança**:
|  - Header `X-Cron-Token` obrigatório (verificado pelo middleware `cron`
|    / {@see \App\Http\Middleware\VerifyCronToken}); comparação timing-safe
|    via `hash_equals()`.
|  - Rota excluída da verificação CSRF em `bootstrap/app.php` (GET server-
|    to-server não carrega token CSRF; sem a exclusão, Cloud Scheduler
|    receberia 419).
|  - HTTP 200 mesmo com falhas parciais (recuperáveis); 500 só em erro de
|    infraestrutura (Firestore indisponível, etc).
|  - HTTP 401 SEM corpo informativo (apenas `{"status":"error"}`) para
|    evitar fingerprinting (risco 4 do plano).
|
| **Resposta JSON** (Decisão #9):
|  - `status: "ok"|"error"`
|  - `processed`, `synced`, `failed`: inteiros
|  - `errors`: array `[{id, attempts, error}]` (apenas se failed > 0)
|  - `duration_ms`: tempo de execução em milissegundos (métrica para Cloud Monitoring)
|  - `timestamp`: ISO 8601 UTC
*/
Route::get('/cron/sync-pending', function (Request $request): JsonResponse {
    $start = hrtime(true);

    // Executa o command in-process com output JSON para parse estruturado.
    // O command já tem o design de output (text|json) — aqui usamos json
    // para evitar regex frágil de parsing do output textual.
    Artisan::call('transactions:sync-pending', ['--format' => 'json']);
    $output = trim(Artisan::output());

    $result = json_decode($output, true);
    if (! is_array($result)) {
        // Erro de infraestrutura (command falhou, output vazio ou inválido).
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
|--------------------------------------------------------------------------
| Webhook do Telegram (M1.4)
|--------------------------------------------------------------------------
| Recebe updates (POST JSON) enviados pelos servidores do Telegram e os
| repassa ao Nutgram para processamento síncrono. Sempre responde 200
| (regra do webhook — ver TelegramWebhookController).

| O middleware 'telegram.webhook' (ValidateTelegramWebhook) é, em M1, um
| placeholder pass-through. Em M2 ele passará a validar o secret token e a
| whitelist de chat_id. Já aplicado à rota para que M2 só precise completar
| a lógica do middleware, sem tocar nas rotas.
*/
Route::post('webhook/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');
