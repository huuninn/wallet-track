<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConversationServiceProvider;
use App\Providers\DeepSeekServiceProvider;
use App\Providers\FirestoreServiceProvider;
use App\Providers\GeminiServiceProvider;
use App\Providers\SheetsServiceProvider;
use App\Providers\TelegramServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    ConversationServiceProvider::class,
    DeepSeekServiceProvider::class,
    FirestoreServiceProvider::class,
    GeminiServiceProvider::class,
    SheetsServiceProvider::class,
    TelegramServiceProvider::class,
    TelescopeServiceProvider::class,
];
