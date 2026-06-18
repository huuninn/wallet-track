<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Remove o webhook registrado no Telegram (M1.3).
 *
 * Útil ao trocar de ambiente (dev → prod), ao voltar para polling ou para
 * limpar updates pendentes. Chama POST /deleteWebhook na Bot API.
 */
class DeleteTelegramWebhook extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'telegram:delete-webhook
                            {--drop-pending-updates : Descarta updates ainda não entregues}';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Remove o webhook registrado no Telegram';

    public function handle(): int
    {
        // Sem token a URL do endpoint fica ".../bot/deleteWebhook" →
        // 404/401 inespecífico e difícil de debugar. (delete não precisa
        // de TELEGRAM_WEBHOOK_URL, por isso só validamos o token.)
        if (blank(config('telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN não está configurado (verifique o .env).');

            return self::FAILURE;
        }

        $endpoint = sprintf(
            '%s/bot%s/deleteWebhook',
            rtrim((string) config('telegram.api_url'), '/'),
            config('telegram.bot_token'),
        );

        $response = Http::post($endpoint, array_filter([
            'drop_pending_updates' => $this->option('drop-pending-updates') ?: null,
        ]));

        $payload = $response->json() ?? [];

        if ($response->successful() && ($payload['ok'] ?? false) === true) {
            $this->info('✅ Webhook removido com sucesso.');

            return self::SUCCESS;
        }

        $this->error('❌ Falha ao remover webhook.');
        $this->line('   Erro: '.($payload['description'] ?? 'HTTP '.$response->status()));

        return self::FAILURE;
    }
}
