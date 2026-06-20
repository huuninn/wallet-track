<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica o token de autenticação do cron (`X-Cron-Token`) para endpoints
 * server-to-server (M9.9 / T-013).
 *
 * Compara o header recebido com o secret configurado em
 * `config('services.cron.secret_token')` (preenchido em runtime via env
 * `CRON_SECRET_TOKEN`). A comparação é **timing-safe** via `hash_equals()`
 * para evitar timing attacks — mesmo princípio aplicado no
 * {@see ValidateTelegramWebhook}.
 *
 * **Fail-closed**: se o secret não estiver configurado (misconfig em
 * produção), rejeita TODAS as requisições com 401. Sem fallback permissivo.
 *
 * **Resposta genérica**: o body de 401 não revela a razão exata (faltante,
 * inválido, misconfig) para evitar fingerprinting. Apenas `{"status":"error"}`
 * com HTTP 401 — alinhado com o princípio de segurança do
 * `ValidateTelegramWebhook`.
 *
 * Uso em `routes/web.php`:
 * ```php
 * Route::get('/cron/sync-pending', ...)->middleware('cron');
 * ```
 *
 * Ref.: docs/planos/m9-plano-tecnico.md T-013, risco 4 da seção 4.
 */
final class VerifyCronToken
{
    /** Nome do header HTTP que carrega o token de autenticação server-to-server. */
    private const string CRON_TOKEN_HEADER = 'X-Cron-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.cron.secret_token', '');

        $provided = (string) ($request->header(self::CRON_TOKEN_HEADER) ?? '');

        // hash_equals exige que os dois operandos tenham o mesmo tamanho
        // (ou um seja string vazia) — cast garante que estamos passando
        // strings puras. A comparação de strings vazias retorna false,
        // que é o comportamento desejado (secret vazio = fail-closed).
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['status' => 'error'], 401);
        }

        return $next($request);
    }
}
