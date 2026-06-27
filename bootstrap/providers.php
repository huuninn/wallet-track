<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConversationServiceProvider;
use App\Providers\DeepSeekServiceProvider;
use App\Providers\GeminiServiceProvider;
use App\Providers\SheetsServiceProvider;
use App\Providers\StoreServiceProvider;
use App\Providers\TelegramServiceProvider;

$providers = [
    AppServiceProvider::class,
    ConversationServiceProvider::class,
    DeepSeekServiceProvider::class,
    GeminiServiceProvider::class,
    SheetsServiceProvider::class,
    StoreServiceProvider::class,
    TelegramServiceProvider::class,
];

return $providers;
