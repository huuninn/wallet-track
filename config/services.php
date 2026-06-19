<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cron endpoints (M9.9)
    |--------------------------------------------------------------------------
    |
    | Token de autenticação server-to-server para endpoints cron
    | (atualmente: `GET /cron/sync-pending`). O Cloud Scheduler envia este
    | token no header `X-Cron-Token`; o middleware `VerifyCronToken`
    | compara via `hash_equals` (timing-safe).
    |
    | **Em produção**: gerar com `openssl rand -hex 32` (256 bits de entropia)
    | e armazenar no Secret Manager — NUNCA commitar o valor real.
    */
    'cron' => [
        'secret_token' => env('CRON_SECRET_TOKEN'),
    ],

];
