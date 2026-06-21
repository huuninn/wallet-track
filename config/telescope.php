<?php

use Laravel\Telescope\Http\Middleware\Authorize;
use Laravel\Telescope\Watchers;

return [

    /*
    |--------------------------------------------------------------------------
    | Telescope Master Switch
    |--------------------------------------------------------------------------
    |
    | Liga/desliga o Telescope como um todo. Default `false` (precisa
    | setar TELESCOPE_ENABLED=true explicitamente no .env local). Em
    | produção/staging fica sempre `false`.
    |
    */

    'enabled' => env('TELESCOPE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Telescope Domain
    |--------------------------------------------------------------------------
    |
    | Subdomínio onde o Telescope responde. Null = mesmo domínio do app.
    | Não costumamos usar subdomínio; mantido por completude.
    |
    */

    'domain' => env('TELESCOPE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Telescope Path
    |--------------------------------------------------------------------------
    |
    | URI path do painel Telescope. Default `telescope` (acessa em
    | http://localhost:8000/telescope).
    |
    */

    'path' => env('TELESCOPE_PATH', 'telescope'),

    /*
    |--------------------------------------------------------------------------
    | Telescope Storage Driver
    |--------------------------------------------------------------------------
    |
    | Apenas `database` é suportado. Mantido para simetria com a API
    | upstream; trocar aqui não tem efeito prático.
    |
    */

    'driver' => env('TELESCOPE_DRIVER', 'database'),

    'storage' => [
        'database' => [
            // Conexão DEDICADA para o Telescope (SQLite dedicado em
            // `database/telescope.sqlite`). NUNCA apontar para uma conexão
            // compartilhada com a app — o Telescope escreve MUITO e não
            // deve competir com queries de negócio. A conexão é registrada
            // em runtime no TelescopeServiceProvider (M11-respeitado:
            // `config/database.php` permanece com `connections => []`).
            'connection' => env('TELESCOPE_DB_CONNECTION', 'telescope'),

            // Tamanho do chunk de inserção (otimização interna do Telescope).
            'chunk' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telescope Queue
    |--------------------------------------------------------------------------
    |
    | Conexão e fila usadas pelo job `ProcessPendingUpdate`. Pode ser
    | customizado se a app passar a usar queue no futuro.
    |
    */

    'queue' => [
        'connection' => env('TELESCOPE_QUEUE_CONNECTION'),
        'queue' => env('TELESCOPE_QUEUE'),
        'delay' => env('TELESCOPE_QUEUE_DELAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telescope Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware aplicado a TODAS as rotas /telescope/*. O `Authorize`
    | é o gate que valida IP (substituído pelo IP whitelist no
    | TelescopeServiceProvider customizado).
    |
    */

    'middleware' => [
        'web',
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed / Ignored Paths & Commands
    |--------------------------------------------------------------------------
    |
    | Paths/comandos que NÃO serão observados pelo Telescope.
    | Útil para reduzir ruído de ferramentas de admin.
    |
    */

    'only_paths' => [
        // 'api/*'
    ],

    'ignore_paths' => [
        'livewire*',
        'nova-api*',
        'pulse*',
        '_boost*',
        '.well-known*',
    ],

    'ignore_commands' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Telescope Watchers
    |--------------------------------------------------------------------------
    |
    | Lista de watchers registrados. Os marcados com ❌ OFF são desligados
    | por design (ver justificativas nos comentários).
    |
    */

    'watchers' => [

        // ✅ ON — infraestrutura interna do Telescope (batches de jobs).
        Watchers\BatchWatcher::class => env('TELESCOPE_BATCH_WATCHER', true),

        // ✅ ON — útil para debugar cache hits/misses em dev.
        Watchers\CacheWatcher::class => [
            'enabled' => env('TELESCOPE_CACHE_WATCHER', true),
            'hidden' => [],
            'ignore' => [],
        ],

        // ✅ ON — captura HTTP client requests (Gemini API, DeepSeek API,
        // Telegram API). COMPLEMENTA nossos watchers customizados: eles
        // capturam o DOMÍNIO (prompt, response), este captura o TRANSPORTE
        // (URL, headers, status). Ambos são úteis, não conflitam.
        Watchers\ClientRequestWatcher::class => [
            'enabled' => env('TELESCOPE_CLIENT_REQUEST_WATCHER', true),
            'ignore_hosts' => [],
        ],

        // ✅ ON — monitorar execuções de artisan commands.
        Watchers\CommandWatcher::class => [
            'enabled' => env('TELESCOPE_COMMAND_WATCHER', true),
            'ignore' => [],
        ],

        // ❌ OFF — bot não usa dump()/dd() em produção; barulho desnecessário.
        Watchers\DumpWatcher::class => [
            'enabled' => env('TELESCOPE_DUMP_WATCHER', false),
            'always' => env('TELESCOPE_DUMP_WATCHER_ALWAYS', false),
        ],

        // ✅ ON — nossos 3 watchers customizados usam `Telescope::recordEvent()`,
        // então as entries aparecem nesta aba do painel. Tag = `firestore` /
        // `gemini` / `deepseek` para filtrar.
        Watchers\EventWatcher::class => [
            'enabled' => env('TELESCOPE_EVENT_WATCHER', true),
            'ignore' => [],
        ],

        // ✅ ON — capturar exceções lançadas durante o ciclo de vida do request.
        Watchers\ExceptionWatcher::class => env('TELESCOPE_EXCEPTION_WATCHER', true),

        // ❌ OFF — bot é server-to-server, não tem sistema de authorization
        // com policies/Gate. Manter desligado evita ruído.
        Watchers\GateWatcher::class => [
            'enabled' => env('TELESCOPE_GATE_WATCHER', false),
            'ignore_abilities' => [],
            'ignore_packages' => true,
            'ignore_paths' => [],
        ],

        // ✅ ON — caso jobs sejam usados no futuro (hoje não há fila).
        Watchers\JobWatcher::class => env('TELESCOPE_JOB_WATCHER', true),

        // ✅ ON — capturar todos os logs de dev. `level: 'debug'` em local.
        Watchers\LogWatcher::class => [
            'enabled' => env('TELESCOPE_LOG_WATCHER', true),
            'level' => 'debug',
        ],

        // ✅ ON — não enviado, mas mantém-se ativo por completude (custo zero).
        Watchers\MailWatcher::class => env('TELESCOPE_MAIL_WATCHER', true),

        // ❌ OFF — sem Eloquent models (M11-refactor removeu BD relacional).
        Watchers\ModelWatcher::class => [
            'enabled' => env('TELESCOPE_MODEL_WATCHER', false),
            'events' => ['eloquent.*'],
            'hydrations' => true,
        ],

        // ✅ ON — não enviado, mas mantém-se ativo por completude (custo zero).
        Watchers\NotificationWatcher::class => env('TELESCOPE_NOTIFICATION_WATCHER', true),

        // ❌ OFF — sem queries SQL (M11-refactor). Manter ligado sem DB
        // geraria zero entries, mas optamos por desligar explicitamente
        // para documentar a decisão.
        Watchers\QueryWatcher::class => [
            'enabled' => env('TELESCOPE_QUERY_WATCHER', false),
            'ignore_packages' => true,
            'ignore_paths' => [],
            'slow' => 100,
        ],

        // ✅ ON — Redis não está em uso hoje, mas manter ativo é grátis
        // (zero eventos = zero entries) e prepara o terreno caso seja
        // adotado para cache/queue.
        Watchers\RedisWatcher::class => env('TELESCOPE_REDIS_WATCHER', true),

        // ✅ ON — capturar todo request HTTP (webhook Telegram, cron,
        // health check). Útil para debug end-to-end.
        Watchers\RequestWatcher::class => [
            'enabled' => env('TELESCOPE_REQUEST_WATCHER', true),
            'size_limit' => env('TELESCOPE_RESPONSE_SIZE_LIMIT', 64),
            'ignore_http_methods' => [],
            'ignore_status_codes' => [],
        ],

        // ✅ ON — monitorar execuções do schedule (incluindo o
        // `telescope:prune` diário e qualquer schedule futuro).
        Watchers\ScheduleWatcher::class => env('TELESCOPE_SCHEDULE_WATCHER', true),

        // ❌ OFF — app é bot puro, sem views Blade.
        Watchers\ViewWatcher::class => env('TELESCOPE_VIEW_WATCHER', false),

    ],

];
