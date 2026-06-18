<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Registra o webhook do bot junto ao Telegram (M1.3).
 *
 * Usa a Telegram Bot API diretamente via Http facade (POST /setWebhook),
 * enviando a URL pública e o secret token que o Telegram devolverá no
 * header X-Telegram-Bot-Api-Secret-Token a cada update.
 *
 * Executar após apontar TELEGRAM_WEBHOOK_URL para a URL pública definitiva
 * (Cloud Run em M10, ou ngrok em dev). Rode `php artisan config:clear`
 * antes se alterou variáveis de ambiente.
 */
class SetTelegramWebhook extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'telegram:set-webhook';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Registra a URL do webhook no Telegram (com secret token)';

    public function handle(): int
    {
        // Valida token ANTES da URL: sem token a URL do endpoint fica
        // ".../bot/setWebhook" → 404/401 inespecífico e difícil de debugar.
        if (blank(config('telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN não está configurado (verifique o .env).');

            return self::FAILURE;
        }

        $webhookUrl = config('telegram.webhook_url');
        $secretToken = config('telegram.webhook_secret_token');

        if (blank($webhookUrl)) {
            $this->error('TELEGRAM_WEBHOOK_URL não está definido.');
            $this->info('Defina a URL pública (ex.: ngrok em dev, Cloud Run em prod) no .env e rode `php artisan config:clear`.');

            return self::FAILURE;
        }

        $endpoint = sprintf(
            '%s/bot%s/setWebhook',
            rtrim((string) config('telegram.api_url'), '/'),
            config('telegram.bot_token'),
        );

        $response = Http::post($endpoint, array_filter([
            'url' => $webhookUrl,
            'secret_token' => $secretToken,
        ]));

        $payload = $response->json() ?? [];

        if ($response->successful() && ($payload['ok'] ?? false) === true) {
            $this->info('✅ Webhook registrado com sucesso.');
            $this->line("   URL: {$webhookUrl}");
            $this->line('   Descrição: '.($payload['description'] ?? '(sem descrição)'));

            return self::SUCCESS;
        }

        // W1: NÃO exibir o body bruto da resposta — um proxy intermediário
        // ou mudança na API poderia vazar o secret_token no terminal/logs.
        // Lê apenas os campos seguros (error_code/description) do JSON; se o
        // corpo não for JSON (ex.: HTML de erro de proxy), $json é null e
        // mostramos apenas o status HTTP.
        $this->error('❌ Falha ao registrar webhook.');
        $this->line('   Erro: '.($payload['description'] ?? 'HTTP '.$response->status()));

        return self::FAILURE;
    }
}
