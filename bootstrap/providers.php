<?php

use App\Providers\AppServiceProvider;
use App\Providers\DeepSeekServiceProvider;
use App\Providers\FirestoreServiceProvider;
use App\Providers\GeminiServiceProvider;
use App\Providers\SheetsServiceProvider;
use App\Providers\TelegramServiceProvider;

return [
    AppServiceProvider::class,
    DeepSeekServiceProvider::class,
    FirestoreServiceProvider::class,
    GeminiServiceProvider::class,
    SheetsServiceProvider::class,
    TelegramServiceProvider::class,
];
