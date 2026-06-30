<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Recebe updates do Telegram em POST /webhook/telegram.
 *
 * Fluxo: resolve o singleton Nutgram (registrado em modo webhook pelo
 * TelegramServiceProvider), chama $bot->run() para processar o update
 * síncronamente (otimização assíncrona é M7) e retorna sempre 200 OK.
 *
 * ⚠️ Regra de ouro do webhook: SEMPRE responder 200, mesmo em falha.
 * O Telegram retenta (com backoff exponencial) updates que não recebem 2xx,
 * o que pode gerar retries infinitos/fila de mensagens atrasadas. Erros são
 * tratados e logados (stderr JSON em produção) — nunca propagados como 5xx.
 *
 * Validação de secret token + whitelist de chat_id = M2 (middleware).
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Nutgram $bot): Response
    {
        try {
            // W2: o log fica DENTRO do try. Se a construção do array de
            // contexto lançar (ex.: payload corrupto em $request->json()),
            // a exceção é capturada e o webhook ainda responde 200.
            Log::info('Telegram webhook: update recebido', [
                'update_id' => $request->json('update_id'),
                'has_message' => $request->has('message'),
                'has_callback_query' => $request->has('callback_query'),
            ]);

            $bot->run();
        } catch (\Error $e) {
            // B1: Erro fatal (bug de programação) — log em critical para
            // destacar nos logs de produção (stderr JSON, futuro). Mesmo assim responde 200
            // (regra de ouro do webhook: nunca propagar como 5xx).
            Log::critical('Telegram webhook: erro fatal no processamento', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (\Exception $e) {
            // Falha de negócio/runtime — loga em error e responde 200.
            Log::error('Telegram webhook: falha ao processar update', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return response('OK', 200);
    }
}
