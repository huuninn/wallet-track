<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot (Nutgram)
    |--------------------------------------------------------------------------
    |
    | Configuração do bot do Telegram integrado via SDK Nutgram 4.x.
    | O webhook recebe updates em POST /webhook/telegram e o Nutgram
    | processa síncronamente em M1 (processamento assíncrono é M7).
    |
    | Segurança do webhook (validação do secret token + whitelist de chat_id)
    | é implementada no middleware ValidateTelegramWebhook em M2.
    |
    | Veja docs/02-especificacao-tecnica.md §3 (arquitetura) e §12 (envvars).
    |
    */

    // Token do bot obtido no @BotFather. Formato "<bot_id>:<secret>".
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // Secret token (hex 64) usado para validar a origem do webhook.
    // Registrado no Telegram via setWebhook e devolvido no header
    // X-Telegram-Bot-Api-Secret-Token a cada update. Validação = M2.
    'webhook_secret_token' => env('TELEGRAM_WEBHOOK_SECRET_TOKEN'),

    // Whitelist de chat_ids autorizados (uso pessoal, 1 usuário).
    // Lista separada por vírgula no .env; normalizada para array de ints.
    //
    // W4: parsing robusto via filter_var(FILTER_VALIDATE_INT) — entradas
    // não-numéricas (ex.: vírgula sobrando, typo) são descartadas AQUI de
    // forma silenciosa (config é carregado cedo demais para logar). O
    // TelegramServiceProvider::boot() compara a quantidade de entradas
    // brutas não-vazias com a quantidade de válidas e emite Log::warning
    // quando há divergência, permitindo ao dono detectar typos na config.
    'allowed_chat_ids' => array_values(array_filter(
        array_map(
            static function (string $id): int|false {
                $id = trim($id);

                return $id === '' ? false : filter_var($id, FILTER_VALIDATE_INT);
            },
            explode(',', (string) env('TELEGRAM_ALLOWED_CHAT_IDS', ''))
        ),
        static fn (int|false $id): bool => $id !== false
    )),

    // Valor bruto de TELEGRAM_ALLOWED_CHAT_IDS (string do .env), exposto
    // apenas para diagnóstico de config no TelegramServiceProvider::boot().
    // Não usar em código de aplicação — prefira sempre 'allowed_chat_ids'.
    'allowed_chat_ids_raw' => env('TELEGRAM_ALLOWED_CHAT_IDS'),

    // URL pública do webhook. Definida apenas após deploy (Cloud Run, M10)
    // ou ngrok (dev). Vazio = webhook ainda não registrado no Telegram.
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),

    // Base URL da API do Telegram (raramente alterada; permite
    // test server / local bot api server).
    'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),

];
