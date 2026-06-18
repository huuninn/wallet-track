<?php

declare(strict_types=1);

namespace App\Providers;

use App\Bot\BotLoader;
use App\Bot\Logging\PayloadScrubbingLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Webhook;

/**
 * Registra o singleton do Nutgram (bot do Telegram) no container.
 *
 * O bot é configurado em modo webhook (lê o update do corpo da requisição
 * em POST /webhook/telegram) e recebe os handlers de comandos via BotLoader.
 *
 * Nota de segurança (M1 → M2): o safeMode do running mode Webhook do Nutgram
 * fica DESLIGADO aqui propositalmente. A validação do secret token e a
 * whitelist de chat_id serão implementadas no middleware ValidateTelegramWebhook
 * (M2), que já está aplicado à rota do webhook como placeholder pass-through.
 */
class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Nutgram::class, function ($app) {
            $config = $app->make('config');

            $bot = new Nutgram(
                $config->string('telegram.bot_token'),
                new Configuration(
                    apiUrl: $config->string('telegram.api_url'),
                    // Encaminha os logs internos do Nutgram para o canal do Laravel
                    // (stderr JSON em produção), quando disponível. O logger é
                    // envolvido por PayloadScrubbingLogger para redigir o payload
                    // bruto do update (PII financeira) das mensagens de "Update
                    // processed"/"Update failed" emitidas pelo running mode Webhook.
                    // A facade Log:: do Laravel não passa por este decorator.
                    logger: $app->bound(LoggerInterface::class)
                        ? new PayloadScrubbingLogger($app->make(LoggerInterface::class))
                        : new NullLogger,
                ),
            );

            // Modo webhook: processa o único update recebido por requisição,
            // lendo diretamente de php://input (compatível com FrankenPHP nativo).
            $bot->setRunningMode(new Webhook);

            BotLoader::registerHandlers($bot);

            return $bot;
        });
    }

    /**
     * Diagnóstico de config (W4): detecta typos em TELEGRAM_ALLOWED_CHAT_IDS.
     *
     * O parsing de config/telegram.php descarta silenciosamente entradas
     * não-numéricas (config é carregado cedo demais para logar). Aqui, já
     * com o container de serviços disponível, comparamos a quantidade de
     * entradas brutas não-vazias com a quantidade de chat_ids válidos e
     * emitimos Log::warning quando há divergência — expondo typos como
     * "562987197," (vírgula sobrando) ou valores não-numéricos.
     *
     * Aviso também quando a whitelist está totalmente vazia: ninguém
     * estaria autorizado e o bot não responderia a ninguém.
     *
     * Decisão (ver docs): optou-se por comparar count(raw) vs count(valid)
     * em vez de apenas avisar sobre array vazio, pois isto detecta também
     * descartes parciais (um único chat_id perdido por typo seria grave
     * num bot de uso pessoal com 1 usuário autorizado). O valor bruto é
     * exposto via config('telegram.allowed_chat_ids_raw') para funcionar
     * mesmo com config cacheado (env() retorna null nesse caso).
     */
    public function boot(): void
    {
        $raw = config('telegram.allowed_chat_ids_raw');
        $valid = config('telegram.allowed_chat_ids');

        if (is_string($raw) && trim($raw) !== '') {
            $rawEntries = array_filter(
                explode(',', $raw),
                static fn (string $v): bool => trim($v) !== ''
            );
            $expected = count($rawEntries);
            $actual = is_array($valid) ? count($valid) : 0;

            if ($expected !== $actual) {
                Log::warning('Telegram: algumas entradas de TELEGRAM_ALLOWED_CHAT_IDS foram descartadas por serem inválidas (não-numéricas).', [
                    'raw' => $raw,
                    'expected_count' => $expected,
                    'valid_count' => $actual,
                ]);
            }
        } elseif (! is_array($valid) || $valid === []) {
            Log::warning('Telegram: TELEGRAM_ALLOWED_CHAT_IDS está vazio — nenhum chat autorizado. O bot não responderá a nenhum comando.');
        }
    }
}
