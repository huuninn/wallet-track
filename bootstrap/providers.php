<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConversationServiceProvider;
use App\Providers\DeepSeekServiceProvider;
use App\Providers\GeminiServiceProvider;
use App\Providers\SheetsServiceProvider;
use App\Providers\StoreServiceProvider;
use App\Providers\TelegramServiceProvider;
use App\Providers\TelescopeServiceProvider;

$providers = [
    AppServiceProvider::class,
    ConversationServiceProvider::class,
    DeepSeekServiceProvider::class,
    GeminiServiceProvider::class,
    SheetsServiceProvider::class,
    StoreServiceProvider::class,
    TelegramServiceProvider::class,
];

// Telescope é dev-only (--no-dev em produção remove o pacote).
// Registrar condicionalmente evita ClassNotFoundException fatal quando
// TelescopeApplicationServiceProvider não existe em vendor.
// NÃO chamamos class_exists(TelescopeServiceProvider::class) aqui —
// isso dispararia o autoload do arquivo, que declara a classe com
// extends TelescopeApplicationServiceProvider, e o PHP tentaria
// resolver a classe-pai (ausente) durante o parse, causando fatal.
// Checamos APENAS a classe-pai, que está no vendor do Telescope.
if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    $providers[] = TelescopeServiceProvider::class;
}

return $providers;
